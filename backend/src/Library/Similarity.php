<?php

declare(strict_types=1);

namespace App\Library;

use App\Planning\Candidate;
use App\Taste\IngredientMatch;

/**
 * "More like this": the library's recipes that share the most with one, by what they're made of
 * and a little by what they are (main tags). An ingredient counts for more the more of the dish
 * it is (its share of the weighed grams) and the rarer it is in the library: two chickpea stews are
 * alike, two dishes with a pinch of paprika aren't. Over the whole library in memory, like the
 * planner: household scale.
 */
final class Similarity
{
    private const float INGREDIENTS = 0.75;
    private const float TAGS = 0.25;
    /** Below this, two recipes only happen to share something. */
    private const float MIN_SCORE = 0.15;
    /** For a line nobody could weigh: a modest amount. */
    private const float UNWEIGHED_GRAMS = 50.0;

    /** How an ingredient comes, not what it is: canned or cooked chickpeas are chickpeas. */
    private const array STATES = ['canned', 'tinned', 'jarred', 'cooked', 'boiled', 'dried', 'frozen', 'raw', 'fresh', 'baby', 'whole'];

    /**
     * @param list<Candidate> $library the household's recipes (the recipe itself among them or not)
     *
     * @return list<array{recipe: Candidate, score: float, shared: list<string>}> most alike first; shared: the
     *                                                                            recipe's keys of the ingredients both need, what matters most first
     */
    public static function to(Candidate $recipe, array $library, int $limit = 6): array
    {
        $others = array_values(array_filter($library, static fn (Candidate $c): bool => $c->id !== $recipe->id));
        $count = \count($others) + 1;

        // Each recipe's ingredients by family, with their grams; and the recipe's own key for each (to name it).
        $grams = [];
        $keyOf = [];
        foreach ([$recipe, ...$others] as $candidate) {
            $grams[$candidate->id] = [];
            foreach ($candidate->fresh as $key) {
                $family = self::family($key);
                $grams[$candidate->id][$family] = ($grams[$candidate->id][$family] ?? 0.0) + ($candidate->freshGrams[$key] ?? self::UNWEIGHED_GRAMS);
                if ($candidate === $recipe) {
                    $keyOf[$family] ??= $key;
                }
            }
        }
        $documents = [];
        foreach ($grams as $families) {
            foreach (array_keys($families) as $family) {
                $documents[$family] = ($documents[$family] ?? 0) + 1;
            }
        }
        // sqrt(share of the dish) × rarity: a vector per recipe, compared by cosine.
        $vectors = [];
        foreach ($grams as $id => $families) {
            $total = max(1.0, array_sum($families));
            $vectors[$id] = [];
            foreach ($families as $family => $g) {
                $vectors[$id][$family] = sqrt($g / $total) * log(1 + $count / $documents[$family]);
            }
        }
        $lengths = array_map(static fn (array $v): float => sqrt(array_sum(array_map(static fn (float $x): float => $x * $x, $v))), $vectors);
        $mine = $vectors[$recipe->id];

        $found = [];
        foreach ($others as $other) {
            $theirs = $vectors[$other->id];
            $shared = array_keys(array_intersect_key($mine, $theirs));
            $tags = array_intersect($recipe->tags, $other->tags);
            if ([] === $shared && [] === $tags) {
                continue;
            }
            $dot = array_sum(array_map(static fn (string $f): float => $mine[$f] * $theirs[$f], $shared));
            $ingredients = $dot > 0 ? $dot / ($lengths[$recipe->id] * $lengths[$other->id]) : 0.0;
            $allTags = array_unique([...$recipe->tags, ...$other->tags]);
            $score = self::INGREDIENTS * $ingredients + self::TAGS * \count($tags) / max(1, \count($allTags));
            if ($score < self::MIN_SCORE) {
                continue;
            }
            usort($shared, static fn (string $a, string $b): int => $mine[$b] * $theirs[$b] <=> $mine[$a] * $theirs[$a] ?: strcmp($a, $b));
            $found[] = ['recipe' => $other, 'score' => round($score, 3), 'shared' => array_map(static fn (string $family): string => $keyOf[$family] ?? $family, $shared)];
        }
        usort($found, static fn (array $a, array $b): int => $b['score'] <=> $a['score'] ?: strcmp($a['recipe']->recipe->getTitle(), $b['recipe']->recipe->getTitle()));

        return \array_slice($found, 0, $limit);
    }

    /** "chickpeas_canned" and "chickpeas_cooked": "chickpea". */
    private static function family(string $key): string
    {
        $words = IngredientMatch::words($key);
        $kept = array_values(array_diff($words, self::STATES));

        return implode('_', [] === $kept ? $words : $kept);
    }
}
