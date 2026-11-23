<?php

declare(strict_types=1);

namespace App\Food\Bedca;

use App\Food\TableEntry;

/**
 * A BEDCA food's values as Tabooki keeps them, per 100 g: energy in kcal (BEDCA gives kJ), salt
 * from its sodium (× 2.5), the rest as given; a component BEDCA doesn't give is null.
 */
final class BedcaFigures
{
    /** Tabooki's field => BEDCA's EuroFIR code. */
    private const array FIELDS = [
        'protein' => 'PROT',
        'fat' => 'FAT',
        'saturatedFat' => 'FASAT',
        'carbs' => 'CHO',
        'sugar' => 'SUGAR',
        'fiber' => 'FIBT',
    ];

    /** @param array<string, array{value: float|null, unit: string}> $components */
    public static function entry(BedcaFood $food, array $components): TableEntry
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

        return new TableEntry($food->id.' '.$food->nameEs, $values, $value('ALC') ?? 0.0);
    }
}
