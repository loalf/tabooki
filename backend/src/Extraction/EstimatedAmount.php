<?php

declare(strict_types=1);

namespace App\Extraction;

use App\Enum\Language;

/**
 * The ingredient line the cook sees for an amount the model estimated from a video: the
 * material only says "garbanzos", so the line becomes "~380 g garbanzos". The "~" shows it's a
 * guess wherever the line appears (recipe, cook mode, editing), and scaling still finds the number.
 */
final class EstimatedAmount
{
    /** Kitchen units as the shopping list writes them; metric ones stay as they are. */
    private const array UNITS = [
        'es' => ['tsp' => ['cucharadita', 'cucharaditas'], 'tbsp' => ['cucharada', 'cucharadas'], 'pinch' => ['pizca', 'pizcas'], 'clove' => ['diente', 'dientes'], 'can' => ['lata', 'latas'], 'slice' => ['loncha', 'lonchas'], 'bunch' => ['manojo', 'manojos'], 'sprig' => ['ramita', 'ramitas'], 'pack' => ['paquete', 'paquetes']],
        'en' => ['tsp' => ['tsp', 'tsp'], 'tbsp' => ['tbsp', 'tbsp'], 'pinch' => ['pinch', 'pinches'], 'clove' => ['clove', 'cloves'], 'can' => ['can', 'cans'], 'slice' => ['slice', 'slices'], 'bunch' => ['bunch', 'bunches'], 'sprig' => ['sprig', 'sprigs'], 'pack' => ['pack', 'packs']],
    ];

    public static function line(string $raw, float $quantity, ?string $unit, Language $language): string
    {
        $raw = trim($raw);
        // The model already wrote a number in the line: just mark it.
        if (1 === preg_match('/\d/', $raw)) {
            return mb_substr('~'.$raw, 0, 255);
        }

        $number = rtrim(rtrim(number_format($quantity, 2, Language::Es === $language ? ',' : '.', ''), '0'), ',.');
        $label = match (true) {
            null === $unit, 'piece' === $unit => '',
            isset(self::UNITS[$language->value][$unit]) => self::UNITS[$language->value][$unit][abs($quantity - 1.0) < 1e-9 ? 0 : 1],
            default => $unit,
        };

        // "~380 g de garbanzos" in Spanish; counted items need no "de" ("~2 berenjenas").
        $of = '' !== $label && Language::Es === $language ? ' de' : '';

        return mb_substr(trim((string) preg_replace('/\s+/', ' ', \sprintf('~%s %s%s %s', $number, $label, $of, $raw))), 0, 255);
    }
}
