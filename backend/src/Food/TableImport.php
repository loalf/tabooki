<?php

declare(strict_types=1);

namespace App\Food;

use App\Entity\Food;
use App\Entity\Ingredient;
use App\Enum\FoodSource;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The second half of importing a composition table (app:foods:bedca, app:foods:usda): shows each
 * food with its pair and the kcal now and in the table, then, once confirmed, keeps the table's
 * figures and works out the recipes again. Left out: entries whose energy contradicts their own
 * nutrients (a table's data can't be fixed here), and pairs far from the estimate (a wrong pairing,
 * or a bad estimate), unless the food was named. What the table doesn't give stays the estimate.
 */
final readonly class TableImport
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private RecipeWeigher $weigher,
    ) {
    }

    /**
     * @param list<Food>                     $foods
     * @param array<string, TableEntry|null> $entries by food key; null or absent: no pair
     * @param list<string>                   $named   foods named on the command line
     */
    public function run(SymfonyStyle $io, FoodSource $source, string $command, array $foods, array $entries, array $named, bool $apply): int
    {
        $table = strtoupper($source->value);
        $rows = [];
        $imports = [];
        foreach ($foods as $food) {
            $entry = $entries[$food->getKey()] ?? null;
            if (null === $entry) {
                $rows[] = [$food->getKey(), '–', self::kcal($food->getKcal()), '', 'no '.$table.' food'];
                continue;
            }
            $missing = $entry->missing();
            $note = $entry->problem ?? ([] === $missing ? '' : 'estimated: '.implode(', ', $missing));
            $now = $food->getKcal();
            $then = $entry->values['kcal'] ?? null;
            $far = null !== $now && null !== $then && abs($now - $then) > 40 && max($now, $then) > 1.5 * max(1.0, min($now, $then));
            if (null === $entry->problem && $far && !\in_array($food->getKey(), $named, true)) {
                $note = 'far from the estimate: check, then '.$command.' '.$food->getKey();
            } elseif (null === $entry->problem) {
                $imports[] = [$food, $entry];
            }
            $rows[] = [$food->getKey(), mb_strimwidth($entry->ref, 0, 60, '…'), self::kcal($now), self::kcal($then), $note];
        }
        $io->table(['Food', $table, 'kcal now', 'kcal '.$table, 'Note'], $rows);

        if ([] === $imports) {
            $io->success('Nothing to import.');

            return Command::SUCCESS;
        }
        if (!$apply && !$io->confirm(\sprintf('Import %s\'s figures for %d food(s)?', $table, \count($imports)), false)) {
            $io->text('Nothing imported.');

            return Command::SUCCESS;
        }

        $changed = [];
        foreach ($imports as [$food, $entry]) {
            $value = static fn (string $field, ?float $current): float => $entry->values[$field] ?? $current ?? 0.0;
            $food->adopt(new FoodFacts(
                nameEs: null,
                nameEn: null,
                kcal: $value('kcal', $food->getKcal()),
                protein: $value('protein', $food->getProtein()),
                fat: $value('fat', $food->getFat()),
                saturatedFat: $value('saturatedFat', $food->getSaturatedFat()),
                carbs: $value('carbs', $food->getCarbs()),
                sugar: $value('sugar', $food->getSugar()),
                fiber: $value('fiber', $food->getFiber()),
                salt: $value('salt', $food->getSalt()),
                foodGroups: $food->getFoodGroups(),
                allergens: $food->getAllergens(),
                spice: $food->getSpice() ?? 0,
                gramsPerPiece: $food->getGramsPerPiece(),
                density: $food->getDensity(),
            ), $source, $entry->ref, $entry->missing());
            $changed[] = $food;
        }
        $this->entityManager->flush();

        // Their recipes, by key or through an ingredient pointed at them, worked out again.
        $recipeKeys = array_map(static fn (Food $f): string => $f->getKey(), $changed);
        foreach ($this->entityManager->getRepository(Ingredient::class)->findBy(['food' => $changed]) as $ingredient) {
            $recipeKeys[] = $ingredient->getCanonicalKey();
        }
        $recipes = $this->weigher->weighRecipesWith(array_values(array_unique($recipeKeys)));
        $this->entityManager->flush();

        $io->success(\sprintf('%d food(s) now from %s; %d recipe(s) worked out again.', \count($changed), $table, $recipes));

        return Command::SUCCESS;
    }

    private static function kcal(?float $kcal): string
    {
        return null === $kcal ? '?' : (string) (int) round($kcal);
    }
}
