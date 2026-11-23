<?php

declare(strict_types=1);

namespace App\Food;

use App\Enum\Allergen;
use App\Enum\FoodGroup;

/**
 * What is known about a food, per 100 g as a recipe uses it (raw, or drained when it comes
 * tinned), with what's needed to turn kitchen amounts into grams.
 */
final readonly class FoodFacts
{
    /**
     * @param list<FoodGroup> $foodGroups
     * @param list<Allergen>  $allergens
     */
    public function __construct(
        public ?string $nameEs,
        public ?string $nameEn,
        public float $kcal,
        public float $protein,
        public float $fat,
        public float $saturatedFat,
        public float $carbs,
        public float $sugar,
        public float $fiber,
        public float $salt,
        public array $foodGroups,
        public array $allergens,
        /** 0 not spicy, 1 mild, 2 hot: the same scale as a recipe's spice. */
        public int $spice,
        /** One piece as bought or counted in a recipe (1 onion, 1 egg, 1 garlic clove); null when it isn't counted. */
        public ?float $gramsPerPiece,
        /** Grams per millilitre, to turn spoons and cups into grams; null when it's never measured by volume. */
        public ?float $density,
    ) {
    }
}
