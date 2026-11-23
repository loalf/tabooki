<?php

declare(strict_types=1);

namespace App\Food;

use App\Entity\Food;

/**
 * Turns a recipe amount into grams. Measured amounts (g, kg, ml, l, spoons) are reliable;
 * counted ones need the food's weight per piece, or fall back on a usual weight.
 */
final class Grams
{
    private const array MASS = ['g' => 1, 'kg' => 1000];
    private const array VOLUME = ['ml' => 1, 'l' => 1000, 'tsp' => 5, 'tbsp' => 15];

    /** Usual weights when the food doesn't say: a can drained, a garlic clove, a pinch. */
    private const array USUAL = ['can' => 400, 'clove' => 5, 'pinch' => 0.4];

    /** Grams of a measured amount (mass, or volume through the food's density, water's when unknown); null when it isn't measured. */
    public static function measured(?float $quantity, ?string $unit, ?Food $food): ?float
    {
        if (null === $quantity || $quantity <= 0 || null === $unit) {
            return null;
        }
        if (isset(self::MASS[$unit])) {
            return $quantity * self::MASS[$unit];
        }
        if (isset(self::VOLUME[$unit])) {
            return $quantity * self::VOLUME[$unit] * ($food?->getDensity() ?? 1.0);
        }

        return null;
    }

    /** Grams of a counted amount ("2 onions", "1 can"); null when the weight of one isn't known. */
    public static function converted(?float $quantity, ?string $unit, ?Food $food): ?float
    {
        if (null === $quantity || $quantity <= 0) {
            return null;
        }
        $piece = $food?->getGramsPerPiece();

        return match ($unit) {
            null, 'piece' => null === $piece ? null : $quantity * $piece,
            // A food's piece is a clove for garlic, but a head would be too much for "1 clove".
            'clove' => $quantity * (null !== $piece && $piece <= 10 ? $piece : self::USUAL['clove']),
            'can' => $quantity * ($piece ?? self::USUAL['can']),
            'pinch' => $quantity * self::USUAL['pinch'],
            default => null,
        };
    }
}
