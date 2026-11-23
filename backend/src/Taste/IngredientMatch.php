<?php

declare(strict_types=1);

namespace App\Taste;

/**
 * Whether a recipe ingredient is the one a rule is about. Keys are the extraction's canonical
 * English keys ("fresh_coriander"): a rule's key matches a recipe's when its words appear in it,
 * in order, plural or not ("coriander" matches "fresh_coriander", "mushroom" "button_mushrooms";
 * "egg" doesn't match "eggplant").
 */
final class IngredientMatch
{
    public static function matches(string $rule, string $key): bool
    {
        if ($rule === $key) {
            return true;
        }
        $wanted = self::words($rule);
        $words = self::words($key);
        $n = \count($wanted);
        for ($i = 0; $i + $n <= \count($words); ++$i) {
            if (\array_slice($words, $i, $n) === $wanted) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $rules
     * @param list<string> $keys
     */
    public static function any(array $rules, array $keys): bool
    {
        foreach ($rules as $rule) {
            foreach ($keys as $key) {
                if (self::matches($rule, $key)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return list<string> lowercase snake_case keys, once each */
    public static function cleanKeys(mixed $keys): array
    {
        $clean = [];
        foreach (\is_array($keys) ? $keys : [] as $key) {
            $key = \is_string($key) ? trim((string) preg_replace('/[^a-z0-9]+/', '_', mb_strtolower($key)), '_') : '';
            if ('' !== $key && \strlen($key) <= 80 && !\in_array($key, $clean, true)) {
                $clean[] = $key;
            }
        }

        return \array_slice($clean, 0, 20);
    }

    /** @return list<string> a key's words, singular */
    public static function words(string $key): array
    {
        // Singular, roughly: tomatoes → tomato, olives → olive (both sides go through the same).
        return array_map(
            static fn (string $word): string => (string) preg_replace(['/(o|ch|sh|x|ss)es$/', '/([^s])s$/'], ['$1', '$1'], $word),
            array_values(array_filter(explode('_', $key), static fn (string $w): bool => '' !== $w)),
        );
    }
}
