<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Doctrine\Orm\State\CollectionProvider;
use ApiPlatform\Doctrine\Orm\State\ItemProvider;
use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\Food;
use App\Entity\Ingredient;
use App\Enum\Language;
use App\Repository\RecipeRepository;
use App\Security\CurrentHousehold;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The household's ingredients as Doctrine loads them, plus how many of its recipes use each; one
 * of them also with those recipes (titles in the reader's language), and the food with its own key
 * when it points at another.
 *
 * @implements ProviderInterface<Ingredient>
 */
final readonly class IngredientProvider implements ProviderInterface
{
    /**
     * @param ProviderInterface<Ingredient> $collection
     * @param ProviderInterface<Ingredient> $item
     */
    public function __construct(
        #[Autowire(service: CollectionProvider::class)]
        private ProviderInterface $collection,
        #[Autowire(service: ItemProvider::class)]
        private ProviderInterface $item,
        private CurrentHousehold $current,
        private RecipeRepository $recipes,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $household = $this->current->household();
        if ($operation instanceof CollectionOperationInterface) {
            $ingredients = $this->collection->provide($operation, $uriVariables, $context);
            $counts = [];
            foreach ($this->entityManager->getConnection()->fetchAllAssociative(
                'SELECT ri.canonical_key, COUNT(DISTINCT r.id) AS n FROM recipe_ingredient ri JOIN recipe r ON r.id = ri.recipe_id WHERE r.household_id = ? GROUP BY ri.canonical_key',
                [$household->getId()->toRfc4122()],
            ) as $row) {
                if (\is_string($row['canonical_key'] ?? null) && is_numeric($row['n'] ?? null)) {
                    $counts[$row['canonical_key']] = (int) $row['n'];
                }
            }
            foreach (is_iterable($ingredients) ? $ingredients : [] as $ingredient) {
                $ingredient->showUse($counts[$ingredient->getCanonicalKey()] ?? 0);
            }

            return $ingredients;
        }

        $ingredient = $this->item->provide($operation, $uriVariables, $context);
        if (!$ingredient instanceof Ingredient) {
            return $ingredient;
        }
        $language = Language::ofLocale($this->current->user()->getLocale());
        $recipes = [];
        foreach ($this->recipes->libraryOf($household) as $recipe) {
            foreach ($recipe->getIngredients() as $line) {
                if ($line->getCanonicalKey() === $ingredient->getCanonicalKey()) {
                    $recipes[] = ['id' => $recipe->getId()->toRfc4122(), 'title' => $recipe->titleIn($language)];
                    break;
                }
            }
        }
        usort($recipes, static fn (array $a, array $b): int => strcmp($a['title'], $b['title']));
        $own = $ingredient->getFood()?->getKey() === $ingredient->getCanonicalKey()
            ? null
            : $this->entityManager->getRepository(Food::class)->findOneBy(['key' => $ingredient->getCanonicalKey()]);
        $ingredient->showUse(\count($recipes), $recipes, $own?->getId()->toRfc4122());

        return $ingredient;
    }
}
