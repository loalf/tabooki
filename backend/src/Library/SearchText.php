<?php

declare(strict_types=1);

namespace App\Library;

/** Folds text for accent- and case-insensitive matching: "Puré de Patatas" → "pure de patatas". */
final class SearchText
{
    private static ?\Transliterator $transliterator = null;

    public static function normalize(string $text): string
    {
        self::$transliterator ??= \Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC; Lower()')
            ?? throw new \LogicException('The intl transliterator is not available.');

        $folded = self::$transliterator->transliterate($text);
        if (false === $folded) {
            $folded = mb_strtolower($text);
        }

        return trim((string) preg_replace('/\s+/u', ' ', $folded));
    }

    /** @return list<string> the distinct words of a search query, normalized */
    public static function words(string $query): array
    {
        $words = preg_split('/\s+/u', self::normalize($query), -1, \PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique($words));
    }
}
