<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\FoodCorrectionInput;
use App\Entity\Food;
use App\Entity\Ingredient;
use App\Enum\Allergen;
use App\Food\FoodFacts;
use App\Food\RecipeWeigher;
use App\Message\DescribeFoods;
use App\Security\CurrentHousehold;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * POST /api/foods/{id}/correction: a food's figures fixed by hand, which the model then leaves
 * alone; POST /api/foods/{id}/estimate: back to the model's (described again in the background).
 * Foods are shared, so whoever is signed in may (Tabooki has no public sign-up), and the fix says
 * who made it. Every recipe with the food is weighed and added up again.
 *
 * @implements ProcessorInterface<FoodCorrectionInput|null, Food>
 */
final readonly class FoodCorrectionProcessor implements ProcessorInterface
{
    public function __construct(
        private CurrentHousehold $current,
        private EntityManagerInterface $entityManager,
        private RecipeWeigher $weigher,
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

        if ($data instanceof FoodCorrectionInput) {
            $food->correct(new FoodFacts(
                nameEs: null,
                nameEn: null,
                kcal: $data->kcal,
                protein: $data->protein,
                fat: $data->fat,
                saturatedFat: $data->saturatedFat,
                carbs: $data->carbs,
                sugar: $data->sugar,
                fiber: $data->fiber,
                salt: $data->salt,
                foodGroups: $food->getFoodGroups(),
                allergens: Allergen::listFrom($data->allergens),
                spice: $data->spice,
                gramsPerPiece: $data->gramsPerPiece,
                density: $data->density,
            ), by: $this->current->user());
            $this->entityManager->flush();
            $this->weigher->weighRecipesWith($this->keysOf($food));
            $this->entityManager->flush();

            return $food;
        }

        // Back to the model's estimate: described again; describing weighs its recipes again.
        $food->forgetCorrection();
        $this->entityManager->flush();
        $this->bus->dispatch(new DescribeFoods($this->current->household()->getId()->toRfc4122()));

        return $food;
    }

    /** @return list<string> its key, and those of the households' ingredients pointed at it */
    private function keysOf(Food $food): array
    {
        $keys = [$food->getKey()];
        foreach ($this->entityManager->getRepository(Ingredient::class)->findBy(['food' => $food]) as $ingredient) {
            $keys[] = $ingredient->getCanonicalKey();
        }

        return array_values(array_unique($keys));
    }
}
