<?php

declare(strict_types=1);

namespace App\Food;

use App\Entity\Food;
use App\Entity\RecipeIngredient;
use App\Enum\Allergen;

/**
 * @phpstan-type NutritionFacts array{kcal: int, protein: float, fat: float, saturatedFat: float, carbs: float, sugar: float, fiber: float, salt: float, coverage: float, unweighed: int}
 *
 * Adds up what a recipe's lines bring: nutrition per serving from each line's grams and its
 * food's figures per 100 g, and the allergens of its foods. Optional lines are left out of the
 * nutrition, and their allergens are listed apart.
 */
final class Nutrition
{
    public const array FIELDS = ['kcal', 'protein', 'fat', 'saturatedFat', 'carbs', 'sugar', 'fiber', 'salt'];

    /** Below this, the figures say more about what's missing than about the dish. */
    private const float MIN_COVERAGE = 0.5;

    /**
     * Per serving. `coverage` is the share of the weighed grams whose figures are known, and
     * `unweighed` the lines nobody could weigh. Null when too little is known.
     *
     * @param list<RecipeIngredient> $lines
     * @param array<string, Food>    $foods by key
     *
     * @return NutritionFacts|null
     */
    public static function perServing(array $lines, array $foods, int $servings): ?array
    {
        $totals = array_fill_keys(self::FIELDS, 0.0);
        $weighed = 0.0;
        $known = 0.0;
        $unweighed = 0;
        foreach ($lines as $line) {
            if ($line->isOptional()) {
                continue;
            }
            $grams = $line->getGrams();
            if (null === $grams) {
                ++$unweighed;
                continue;
            }
            $weighed += $grams;
            $food = $foods[$line->getCanonicalKey() ?? ''] ?? null;
            if (null === $food || !$food->isDescribed() || null === $food->getKcal()) {
                continue;
            }
            $known += $grams;
            foreach (self::FIELDS as $field) {
                $totals[$field] += $grams * (self::per100g($food, $field) ?? 0.0) / 100;
            }
        }
        if ($weighed <= 0 || $known / $weighed < self::MIN_COVERAGE) {
            return null;
        }
        $servings = max(1, $servings);

        return [
            'kcal' => (int) round($totals['kcal'] / $servings),
            'protein' => round($totals['protein'] / $servings, 1),
            'fat' => round($totals['fat'] / $servings, 1),
            'saturatedFat' => round($totals['saturatedFat'] / $servings, 1),
            'carbs' => round($totals['carbs'] / $servings, 1),
            'sugar' => round($totals['sugar'] / $servings, 1),
            'fiber' => round($totals['fiber'] / $servings, 1),
            'salt' => round($totals['salt'] / $servings, 2),
            'coverage' => round($known / $weighed, 2),
            'unweighed' => $unweighed,
        ];
    }

    /**
     * @param list<RecipeIngredient> $lines
     * @param array<string, Food>    $foods by key
     *
     * Also whether every line the dish needs has a described food: otherwise what it is free of isn't known
     *
     * @return array{list<Allergen>, list<Allergen>, bool} needed, only optional, complete
     */
    public static function allergens(array $lines, array $foods): array
    {
        $needed = [];
        $optional = [];
        $complete = true;
        foreach ($lines as $line) {
            $food = $foods[$line->getCanonicalKey() ?? ''] ?? null;
            if (null === $food || !$food->isDescribed()) {
                $complete = $complete && $line->isOptional();
                continue;
            }
            foreach ($food->getAllergens() as $allergen) {
                $line->isOptional() ? $optional[] = $allergen : $needed[] = $allergen;
            }
        }
        $needed = Allergen::listFrom($needed);

        return [$needed, array_values(array_filter(Allergen::listFrom($optional), static fn (Allergen $a): bool => !\in_array($a, $needed, true))), $complete && [] !== $lines];
    }

    private static function per100g(Food $food, string $field): ?float
    {
        return match ($field) {
            'kcal' => $food->getKcal(),
            'protein' => $food->getProtein(),
            'fat' => $food->getFat(),
            'saturatedFat' => $food->getSaturatedFat(),
            'carbs' => $food->getCarbs(),
            'sugar' => $food->getSugar(),
            'fiber' => $food->getFiber(),
            'salt' => $food->getSalt(),
            default => null,
        };
    }
}
