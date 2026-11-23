<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Ingredient;
use App\Entity\Recipe;
use App\Food\RecipeWeigher;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Saves a fix to a household's catalog ingredient. When it now points at another food, the
 * household's recipes with that ingredient are weighed and added up again with it.
 *
 * @implements ProcessorInterface<Ingredient, mixed>
 */
final readonly class IngredientProcessor implements ProcessorInterface
{
    public function __construct(
        private HouseholdOwnedProcessor $persist,
        private RecipeWeigher $weigher,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        $before = $context['previous_data'] ?? null;
        $result = $this->persist->process($data, $operation, $uriVariables, $context);
        if ($before instanceof Ingredient && $before->getFood()?->getId()->toRfc4122() !== $data->getFood()?->getId()->toRfc4122()) {
            /** @var list<Recipe> $recipes */
            $recipes = $this->entityManager->createQueryBuilder()
                ->select('r', 'i')->from(Recipe::class, 'r')->join('r.ingredients', 'i')
                ->where('r.household = :household')
                ->andWhere('r.id IN (SELECT IDENTITY(ri.recipe) FROM App\Entity\RecipeIngredient ri WHERE ri.canonicalKey = :key)')
                ->setParameter('household', $data->getHousehold())->setParameter('key', $data->getCanonicalKey())
                ->getQuery()->getResult();
            foreach ($recipes as $recipe) {
                $this->weigher->weigh($recipe);
            }
            $this->entityManager->flush();
        }

        return $result;
    }
}
