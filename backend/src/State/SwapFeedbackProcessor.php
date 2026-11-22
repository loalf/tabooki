<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\SwapFeedbackInput;
use App\Entity\Recipe;
use App\Entity\SwapFeedback;
use App\Enum\FoodGroup;
use App\Enum\Language;
use App\Enum\MealType;
use App\Enum\SwapReason;
use App\Security\CurrentHousehold;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Keeps why a meal was swapped. For a disliked ingredient, it is named from the recipe itself,
 * in both languages.
 *
 * @implements ProcessorInterface<SwapFeedbackInput, null>
 */
final readonly class SwapFeedbackProcessor implements ProcessorInterface
{
    public function __construct(
        private CurrentHousehold $current,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $recipe = $this->entityManager->find(Recipe::class, Uuid::fromString($data->recipe));
        if (null === $recipe || !$recipe->getHousehold()->getId()->equals($this->current->household()->getId())) {
            throw new UnprocessableEntityHttpException('That recipe is not in your library.');
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $data->date) ?: throw new UnprocessableEntityHttpException('Dates are YYYY-MM-DD.');
        $reason = SwapReason::from($data->reason);

        $this->entityManager->persist(new SwapFeedback(
            $recipe,
            $reason,
            SwapReason::Dislike === $reason ? $this->what($recipe, $data) : null,
            MealType::from($data->slot),
            (int) $date->format('N') <= 5,
            $this->current->user(),
        ));
        $this->entityManager->flush();

        return null;
    }

    /** @return array{kind: string, value?: string, name?: array{es: string, en: string}} */
    private function what(Recipe $recipe, SwapFeedbackInput $input): array
    {
        $value = $input->whatValue;
        if ('group' === $input->whatKind && null !== $value && null !== FoodGroup::tryFrom($value)) {
            return ['kind' => 'group', 'value' => $value];
        }
        if ('ingredient' === $input->whatKind && null !== $value) {
            $translated = $recipe->getTranslation()->ingredients ?? [];
            foreach ($recipe->getIngredients() as $i => $ingredient) {
                if ($ingredient->getCanonicalKey() !== $value) {
                    continue;
                }
                $name = $ingredient->getName() ?? str_replace('_', ' ', $value);
                $other = $translated[$i]['name'] ?? $name;

                return ['kind' => 'ingredient', 'value' => $value, 'name' => Language::Es === $recipe->getLanguage() ? ['es' => $name, 'en' => $other] : ['es' => $other, 'en' => $name]];
            }
            throw new UnprocessableEntityHttpException('That recipe has no such ingredient.');
        }

        return ['kind' => 'dish'];
    }
}
