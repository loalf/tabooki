<?php

declare(strict_types=1);

namespace App\Food\Usda;

use App\Food\TableEntry;

/**
 * A USDA food's values as Tabooki keeps them, per 100 g. Carbohydrate is USDA's "by difference",
 * which counts the fibre: Tabooki's is without it, so the fibre is taken off. Salt from sodium
 * (× 2.5). Energy: kcal, else the Atwater kcal Foundation foods give, else kJ.
 */
final class UsdaFigures
{
    public static function entry(UsdaFood $food): TableEntry
    {
        $value = static function (string ...$numbers) use ($food): ?float {
            foreach ($numbers as $number) {
                if (isset($food->nutrients[$number])) {
                    return $food->nutrients[$number]['value'];
                }
            }

            return null;
        };
        $kcal = $value('208', '958', '957');
        if (null === $kcal && null !== ($kj = $value('268'))) {
            $kcal = round($kj / 4.184, 1);
        }
        $fiber = $value('291');
        $carbs = $value('205');
        $sodium = $food->nutrients['307'] ?? null;

        return new TableEntry(
            \sprintf('%d %s (%s)', $food->fdcId, $food->description, $food->dataType),
            [
                'kcal' => $kcal,
                'protein' => $value('203'),
                'fat' => $value('204'),
                'saturatedFat' => $value('606'),
                'carbs' => null === $carbs ? null : round(max(0.0, $carbs - ($fiber ?? 0.0)), 2),
                'sugar' => $value('269', '269.3'),
                'fiber' => $fiber,
                'salt' => null === $sodium ? null : round($sodium['value'] * ('g' === $sodium['unit'] ? 1000 : 1) * 2.5 / 1000, 2),
            ],
            $value('221') ?? 0.0,
        );
    }
}
