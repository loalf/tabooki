<?php

declare(strict_types=1);

namespace App\Shopping;

/**
 * Adds up amounts of one ingredient (docs/PLAN.md §4.3, §7). Units of a kind are converted and
 * summed (200 g + 0.5 kg = 700 g; 2 tbsp + 100 ml = 130 ml); different kinds stay separate lines
 * ("2 cans + 400 g"). Totals are rounded up to something you can buy.
 */
final class Quantities
{
    private const array MASS = ['g' => 1, 'kg' => 1000];
    private const array VOLUME = ['ml' => 1, 'l' => 1000, 'tsp' => 5, 'tbsp' => 15];
    private const array SPOONS = ['tsp', 'tbsp'];

    /** @var array<string, float> base amount by kind ("mass", "volume", "count", or the unit itself) */
    private array $totals = [];

    /** @var array<string, array<string, true>> units seen by kind */
    private array $units = [];

    private bool $unmeasured = false;

    public function add(?float $quantity, ?string $unit): void
    {
        if (null === $quantity || $quantity <= 0) {
            $this->unmeasured = true;

            return;
        }
        $kind = self::kind($unit);
        $this->totals[$kind] = ($this->totals[$kind] ?? 0) + $quantity * (self::MASS[$unit ?? ''] ?? self::VOLUME[$unit ?? ''] ?? 1);
        $this->units[$kind][$unit ?? ''] = true;
    }

    /** Some recipe gives no amount ("salt to taste"), and none gives one. */
    public function isUnmeasured(): bool
    {
        return [] === $this->totals && $this->unmeasured;
    }

    /** @return list<array{quantity: float, unit: string|null}> */
    public function amounts(): array
    {
        $amounts = [];
        foreach ($this->totals as $kind => $total) {
            $amounts[] = self::display($kind, $total, array_keys($this->units[$kind] ?? []));
        }

        return $amounts;
    }

    private static function kind(?string $unit): string
    {
        return match (true) {
            null === $unit, 'piece' === $unit => 'count',
            isset(self::MASS[$unit]) => 'mass',
            isset(self::VOLUME[$unit]) => 'volume',
            default => $unit,
        };
    }

    /**
     * @param list<string> $units
     *
     * @return array{quantity: float, unit: string|null}
     */
    private static function display(string $kind, float $total, array $units): array
    {
        return match ($kind) {
            'mass' => $total >= 1000 ? ['quantity' => self::up($total / 1000, 0.05), 'unit' => 'kg'] : ['quantity' => self::up($total, $total < 100 ? 5 : 10), 'unit' => 'g'],
            'volume' => match (true) {
                [] === array_diff($units, self::SPOONS) => $total >= 15
                    ? ['quantity' => self::up($total / 15, 0.5), 'unit' => 'tbsp']
                    : ['quantity' => self::up($total / 5, 0.5), 'unit' => 'tsp'],
                $total >= 1000 => ['quantity' => self::up($total / 1000, 0.05), 'unit' => 'l'],
                default => ['quantity' => self::up($total, $total < 100 ? 5 : 10), 'unit' => 'ml'],
            },
            'count' => ['quantity' => max(1.0, ceil($total - 0.01)), 'unit' => ['piece'] === $units ? 'piece' : null],
            default => ['quantity' => max(1.0, ceil($total - 0.01)), 'unit' => $kind],
        };
    }

    /** Rounds up to a multiple of $step (233 g → 240 g), ignoring float noise. */
    private static function up(float $value, float $step): float
    {
        return round(ceil(round($value / $step, 6)) * $step, 2);
    }
}
