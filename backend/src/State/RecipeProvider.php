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
use App\Repository\MealPlanRepository;
use App\Security\CurrentHousehold;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Recipes as Doctrine loads them, plus when each was last on the menu (one query for the whole
 * library): the app uses it to bring back recipes nobody has made in a while. Before an edit, it
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
    ) {
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
        $last = $this->plans->lastCooked($household, new \DateTimeImmutable('today'));
        foreach ($recipes as $recipe) {
            $recipe->setLastCookedOn($last[$recipe->getId()->toRfc4122()] ?? null);
        }

        return $result;
    }
}
