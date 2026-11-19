<?php

declare(strict_types=1);

namespace App\Shopping;

use App\ApiResource\ShoppingListView;
use App\ApiResource\ShoppingListViewItem;
use App\Entity\Household;
use App\Entity\MealPlan;
use App\Entity\ShoppingList;
use App\Enum\Aisle;
use App\Repository\MealPlanRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A week's shopping list from its plan (docs/PLAN.md §7): every planned meal's ingredients,
 * scaled to its servings (leftovers are meals too, so a dish cooked for two meals is bought
 * once, doubled), merged by ingredient, named in the viewer's language and sorted by aisle.
 */
final readonly class ShoppingListBuilder
{
    public function __construct(
        private MealPlanRepository $plans,
        private IngredientCatalog $catalog,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /** @param \DateTimeImmutable|null $from only meals from this day on (what's left of the week) */
    public function build(Household $household, \DateTimeImmutable $week, string $locale, ?\DateTimeImmutable $from = null): ShoppingListView
    {
        $monday = MealPlan::mondayOf($week);
        $plan = $this->plans->findForWeek($household, $monday);
        $list = $this->entityManager->getRepository(ShoppingList::class)->findOneBy(['household' => $household, 'weekStart' => $monday]);

        $view = new ShoppingListView();
        $view->week = $monday->format('Y-m-d');
        $view->planned = null !== $plan;
        $view->from = $from?->format('Y-m-d');

        /** @var array<string, array{quantities: Quantities, name: string, language: string, recipes: array<string, string>}> $lines */
        $lines = [];
        foreach ($plan?->getEntries() ?? [] as $entry) {
            $recipe = $entry->getRecipe();
            if (null === $recipe || (null !== $from && $entry->getDateValue() < $from)) {
                continue;
            }
            ++$view->meals;
            $factor = $entry->getServings() / max(1, $recipe->getServings());
            foreach ($recipe->getIngredients() as $ingredient) {
                if ($ingredient->isOptional()) {
                    continue;
                }
                $key = IngredientCatalog::keyFor($ingredient->getCanonicalKey(), $ingredient->getName(), $ingredient->getRawText());
                $lines[$key] ??= [
                    'quantities' => new Quantities(),
                    'name' => $ingredient->getName() ?? $ingredient->getRawText(),
                    'language' => $recipe->getLanguage()->value,
                    'recipes' => [],
                ];
                $quantity = $ingredient->getQuantity();
                $lines[$key]['quantities']->add(null === $quantity ? null : $quantity * $factor, $ingredient->getUnit());
                $lines[$key]['recipes'][$recipe->getId()->toRfc4122()] = $recipe->getTitle();
            }
        }

        $catalog = $this->catalog->forKeys($household, array_map(
            static fn (array $line): array => ['name' => $line['name'], 'language' => $line['language']],
            $lines,
        ));
        foreach ($lines as $key => $line) {
            $ingredient = $catalog[$key] ?? null;
            $item = new ShoppingListViewItem();
            $item->key = $key;
            $item->ingredientId = $ingredient?->getId()->toRfc4122();
            $item->name = $ingredient?->name($locale) ?? $line['name'];
            $item->aisle = ($ingredient?->getAisle() ?? Aisle::Other)->value;
            $item->staple = $ingredient?->isStaple() ?? false;
            $item->amounts = $line['quantities']->amounts();
            $item->recipes = array_values(array_unique($line['recipes']));
            $item->checked = $list?->isChecked($key) ?? false;
            $view->items[] = $item;
        }
        usort($view->items, static fn (ShoppingListViewItem $a, ShoppingListViewItem $b): int => [Aisle::from($a->aisle)->order(), mb_strtolower($a->name)] <=> [Aisle::from($b->aisle)->order(), mb_strtolower($b->name)]);
        $view->classifying = array_any($catalog, static fn ($ingredient): bool => !$ingredient->isClassified());

        foreach ($list?->getItems() ?? [] as $extra) {
            $item = new ShoppingListViewItem();
            $item->key = $extra->checkKey();
            $item->name = $extra->getText();
            $item->manual = true;
            $item->checked = $list->isChecked($extra->checkKey());
            $view->items[] = $item;
        }

        return $view;
    }
}
