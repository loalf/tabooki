<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\Dto\FoodSubstitutesInput;
use App\Entity\Food;
use App\Entity\FoodSubstitution;
use App\Enum\FoodSource;
use App\Message\SuggestSubstitutes;
use App\Security\CurrentHousehold;
use App\Taste\IngredientMatch;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * POST /api/foods/{id}/substitutes: what can stand in for a food, chosen by hand, replacing the
 * model's (which then leaves them alone); POST /api/foods/{id}/substitutes/suggest: back to the
 * model's, asked again in the background. Foods are shared, so whoever is signed in may, and the
 * choice says who made it. Recipes and shopping lists read the substitutes live.
 *
 * @implements ProcessorInterface<FoodSubstitutesInput|null, Food>
 */
final readonly class FoodSubstitutesProcessor implements ProcessorInterface
{
    public function __construct(
        private CurrentHousehold $current,
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $bus,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Food
    {
        $id = $uriVariables['id'] ?? null;
        $uuid = $id instanceof Uuid ? $id : (\is_string($id) && Uuid::isValid($id) ? Uuid::fromString($id) : null);
        $food = null === $uuid ? null : $this->entityManager->find(Food::class, $uuid);
        if (null === $food) {
            throw new NotFoundHttpException('Not Found');
        }

        if ($data instanceof FoodSubstitutesInput) {
            $rows = $this->rows($food, $data);
            $this->entityManager->wrapInTransaction(function () use ($food, $rows): void {
                // The old ones go first: a kept substitute would clash with itself on the unique key.
                $this->removeSubstitutes($food);
                foreach ($rows as $row) {
                    $this->entityManager->persist($row);
                }
                $food->substitutesChosen($this->current->user());
            });
            $this->entityManager->refresh($food);

            return $food;
        }

        $this->entityManager->wrapInTransaction(function () use ($food): void {
            $this->removeSubstitutes($food);
            $food->forgetSubstitutes();
        });
        $this->entityManager->refresh($food);
        $this->bus->dispatch(new SuggestSubstitutes($this->current->household()->getId()->toRfc4122()));

        return $food;
    }

    /** @return list<FoodSubstitution> */
    private function rows(Food $food, FoodSubstitutesInput $data): array
    {
        $text = static fn (?string $value): ?string => null === $value || '' === trim($value) ? null : trim($value);
        $slugger = new AsciiSlugger('en');
        $rows = [];
        $violations = new ConstraintViolationList();
        foreach ($data->substitutes as $i => $substitute) {
            $es = trim($substitute->nameEs);
            $en = trim($substitute->nameEn);
            $key = IngredientMatch::cleanKeys([$text($substitute->key) ?? $slugger->slug($en, '_')->lower()->toString()])[0] ?? null;
            $problem = match (true) {
                null === $key => 'This name can\'t be made into a key.',
                $key === $food->getKey() => 'A food can\'t stand in for itself.',
                isset($rows[$key]) => 'This substitute is already in the list.',
                default => null,
            };
            if (null !== $problem) {
                $violations->add(new ConstraintViolation($problem, null, [], $data, \sprintf('substitutes[%d].key', $i), $substitute->key));
                continue;
            }
            $noteEs = $text($substitute->noteEs);
            $noteEn = $text($substitute->noteEn);
            // A note in one language only is shown in both.
            $rows[$key] = new FoodSubstitution($food, $key, mb_strtolower($es), mb_strtolower($en), round($substitute->ratio, 2), $noteEs ?? $noteEn, $noteEn ?? $noteEs, \count($rows), FoodSource::Manual);
        }
        if ($violations->count() > 0) {
            throw new ValidationException($violations);
        }

        return array_values($rows);
    }

    private function removeSubstitutes(Food $food): void
    {
        $this->entityManager->createQueryBuilder()->delete(FoodSubstitution::class, 's')
            ->where('s.food = :food')->setParameter('food', $food)
            ->getQuery()->execute();
    }
}
