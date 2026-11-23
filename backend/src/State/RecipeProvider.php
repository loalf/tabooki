<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Doctrine\Orm\State\CollectionProvider;
use ApiPlatform\Doctrine\Orm\State\ItemProvider;
use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\State\ProviderInterface;
use App\Entity\Recipe;
use App\Food\Substitutes;
use App\Planning\Candidate;
use App\Repository\MealPlanRepository;
use App\Repository\TasteProfileRepository;
use App\Security\CurrentHousehold;
use App\Taste\Conflict;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Recipes as Doctrine loads them, plus when each was last on the menu (one query for the whole
 * library): the app uses it to bring back recipes nobody has made in a while. And what each has
 * against the household's likings, and what its notes say about it, when it has said. Before an edit, it
 * remembers the recipe's text (see Recipe::keepOriginalIfUntouched()).
 *
 * @implements ProviderInterface<Recipe>
 */
final readonly class RecipeProvider implements ProviderInterface
{
    /**
     * @param ProviderInterface<Recipe> $collection
     * @param ProviderInterface<Recipe> $item
     */
    public function __construct(
        #[Autowire(service: CollectionProvider::class)]
        private ProviderInterface $collection,
        #[Autowire(service: ItemProvider::class)]
        private ProviderInterface $item,
        private MealPlanRepository $plans,
        private CurrentHousehold $current,
        private TasteProfileRepository $tastes,
        private EntityManagerInterface $entityManager,
        private Substitutes $substitutes,
    ) {
    }

    /**
     * The list doesn't load ingredients, and what a recipe has against the household's likings
     * needs them: one query for all, instead of one per recipe.
     *
     * @param iterable<Recipe> $recipes
     */
    private function loadIngredients(iterable $recipes): void
    {
        $ids = [];
        foreach ($recipes as $recipe) {
            $ids[] = $recipe->getId();
        }
        if ([] !== $ids) {
            $this->entityManager->createQueryBuilder()
                ->select('r', 'i')->from(Recipe::class, 'r')->leftJoin('r.ingredients', 'i')
                ->where('r.id IN (:ids)')->setParameter('ids', array_map(static fn ($id): string => $id->toRfc4122(), $ids))
                ->getQuery()->getResult();
        }
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $result = $operation instanceof CollectionOperationInterface
            ? $this->collection->provide($operation, $uriVariables, $context)
            : $this->item->provide($operation, $uriVariables, $context);

        if ($result instanceof Recipe && $operation instanceof Patch) {
            $result->rememberText();
        }
        $recipes = $result instanceof Recipe ? [$result] : (is_iterable($result) ? $result : []);
        $household = $this->current->householdOrNull();
        if (null === $household) {
            return $result;
        }
        if ($result instanceof Recipe) {
            $keys = array_values(array_filter(array_map(static fn ($i): ?string => $i->getCanonicalKey(), $result->getIngredients())));
            $result->setSubstitutes($this->substitutes->forKeys($household, $keys));
        }
        $last = $this->plans->lastCooked($household, new \DateTimeImmutable('today'));
        $taste = null === $this->tastes->forHousehold($household) ? null : $this->tastes->tasteFor($household);
        if (null !== $taste && !$result instanceof Recipe) {
            $this->loadIngredients($recipes);
        }
        foreach ($recipes as $recipe) {
            $recipe->setLastCookedOn($last[$recipe->getId()->toRfc4122()] ?? null);
            if (null === $taste) {
                continue;
            }
            $candidate = Candidate::fromRecipe($recipe);
            $recipe->setTasteConflicts(array_map(static fn (Conflict $c): array => $c->toArray(), $taste->conflicts($candidate)));
            $recipe->setNotesFit($taste->notesJudgement($candidate));
        }

        return $result;
    }
}
