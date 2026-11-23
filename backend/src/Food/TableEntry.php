<?php

declare(strict_types=1);

namespace App\Food;

/**
 * A food of a composition table (BEDCA, USDA) as Tabooki would keep it, per 100 g: a value for
 * each nutrient it gives (null for those it doesn't), where it is in the table, and why it can't be
 * used when its energy contradicts its own nutrients.
 */
final readonly class TableEntry
{
    public const array NUTRIENTS = ['kcal', 'protein', 'fat', 'saturatedFat', 'carbs', 'sugar', 'fiber', 'salt'];

    public ?string $problem;

    /** @param array<string, float|null> $values by NUTRIENTS */
    public function __construct(
        public string $ref,
        public array $values,
        float $alcohol = 0.0,
    ) {
        $this->problem = self::check($values, $alcohol);
    }

    /** @return list<string> the nutrients it doesn't give */
    public function missing(): array
    {
        return array_values(array_filter(self::NUTRIENTS, fn (string $n): bool => null === ($this->values[$n] ?? null)));
    }

    /**
     * Energy that doesn't agree with the protein, fat, available carbohydrate, fibre and alcohol
     * (more than a fifth, and 25 kcal, off) marks an error in the entry.
     *
     * @param array<string, float|null> $values
     */
    private static function check(array $values, float $alcohol): ?string
    {
        $kcal = $values['kcal'] ?? null;
        if (null === $kcal) {
            return 'no energy';
        }
        $atwater = 4 * ($values['protein'] ?? 0) + 9 * ($values['fat'] ?? 0) + 4 * ($values['carbs'] ?? 0) + 2 * ($values['fiber'] ?? 0) + 7 * $alcohol;

        return abs($kcal - $atwater) > max(25, 0.2 * $atwater)
            ? \sprintf('energy %d kcal, but its nutrients add up to %d', (int) round($kcal), (int) round($atwater))
            : null;
    }
}
