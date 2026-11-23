<?php

declare(strict_types=1);

namespace App\Food\Bedca;

/**
 * A BEDCA food's values as Tabooki keeps them, per 100 g: energy in kcal (BEDCA gives kJ), salt
 * from its sodium (× 2.5), the rest as given. A component BEDCA doesn't give is null, and stays
 * the estimate. Energy that doesn't agree with the food's own macronutrients (more than a fifth
 * off) marks an error in the entry: such a food isn't imported, since its data can't be altered.
 */
final readonly class BedcaFigures
{
    /** Tabooki's field => BEDCA's EuroFIR code. */
    public const array FIELDS = [
        'protein' => 'PROT',
        'fat' => 'FAT',
        'saturatedFat' => 'FASAT',
        'carbs' => 'CHO',
        'sugar' => 'SUGAR',
        'fiber' => 'FIBT',
    ];

    /**
     * @param array<string, float|null> $values kcal, protein, fat, saturatedFat, carbs, sugar, fiber, salt
     */
    private function __construct(
        public array $values,
        public ?string $problem,
    ) {
    }

    /** @param array<string, array{value: float|null, unit: string}> $components */
    public static function of(array $components): self
    {
        $value = static fn (string $code): ?float => $components[$code]['value'] ?? null;
        $values = [];
        $energy = $components['ENERC'] ?? null;
        $values['kcal'] = null === $energy || null === $energy['value']
            ? null
            : round('kcal' === strtolower($energy['unit']) ? $energy['value'] : $energy['value'] / 4.184, 1);
        foreach (self::FIELDS as $field => $code) {
            $values[$field] = $value($code);
        }
        $sodium = $components['NA'] ?? null;
        $values['salt'] = null === $sodium || null === $sodium['value'] ? null : round($sodium['value'] * ('g' === $sodium['unit'] ? 1000 : 1) * 2.5 / 1000, 2);

        if (null === $values['kcal']) {
            return new self($values, 'no energy');
        }
        $atwater = 4 * ($values['protein'] ?? 0) + 9 * ($values['fat'] ?? 0) + 4 * ($values['carbs'] ?? 0) + 2 * ($values['fiber'] ?? 0) + 7 * ($value('ALC') ?? 0);
        if (abs($values['kcal'] - $atwater) > max(25, 0.2 * $atwater)) {
            return new self($values, \sprintf('energy %d kcal, but its nutrients add up to %d', (int) round($values['kcal']), (int) round($atwater)));
        }

        return new self($values, null);
    }
}
