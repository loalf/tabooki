<?php

declare(strict_types=1);

namespace App\Planning;

use App\Entity\MealPlan;
use App\Entity\MealPlanEntry;
use App\Entity\Recipe;
use App\Enum\MealType;
use App\Repository\MealPlanRepository;
use App\Repository\RecipeRepository;
use Random\Randomizer;

/**
 * Picks recipes for a week's meals from the library (docs/PLAN.md §6). Deterministic rules and a
 * weighted random pick, so "regenerate" gives a different week each time:
 *
 * - a recipe only goes to a meal it suits (lunch, dinner), and on weekdays within the time limit;
 * - no recipe twice in a week, nor one planned in the previous three weeks, while the library
 *   has others (then those rules relax, in that order);
 * - favorites and well-rated recipes come up more, unchecked extractions a little less;
 * - recipes sharing a main tag with the week's others come up less (variety), recipes sharing
 *   fresh ingredients more (half a bunch of coriander gets used twice);
 * - with leftovers on, a lunch is the previous dinner, cooked once for both.
 */
final readonly class Planner
{
    public const int NO_REPEAT_DAYS = 21;
    private const int RELAXED_NO_REPEAT_DAYS = 7;

    /** Tags about how, not what: sharing them isn't a lack of variety. */
    private const array GENERIC_TAGS = ['rápida', 'rapida', 'quick', 'fácil', 'facil', 'easy', 'sana', 'saludable', 'healthy', 'casera', 'homemade'];

    /** Always in the kitchen: sharing them saves nothing. */
    private const array STAPLES = [
        'salt', 'sea_salt', 'pepper', 'black_pepper', 'water', 'olive_oil', 'extra_virgin_olive_oil', 'oil', 'vegetable_oil',
        'sunflower_oil', 'sugar', 'flour', 'wheat_flour', 'vinegar', 'garlic', 'onion', 'paprika', 'sweet_paprika', 'cumin',
        'oregano', 'bay_leaf', 'butter',
    ];

    private Randomizer $random;

    public function __construct(
        private RecipeRepository $recipes,
        private MealPlanRepository $plans,
        ?Randomizer $random = null,
    ) {
        $this->random = $random ?? new Randomizer();
    }

    /** Fills every meal of a new plan, or every unlocked one of an existing plan. */
    public function fill(MealPlan $plan): void
    {
        $this->refill($plan, array_values(array_filter($plan->getEntries(), static fn (MealPlanEntry $e): bool => !$e->isLocked())));
    }

    /** One meal: a new one, or another recipe for an existing one ("swap"). */
    public function fillOne(MealPlan $plan, MealPlanEntry $entry, bool $swap = false): void
    {
        $previous = $entry->getRecipe();
        $this->refill($plan, [$entry], avoid: $swap && null !== $previous ? [$previous->getId()->toRfc4122()] : [], leftovers: !$swap);
    }

    /** A recipe chosen by hand; meals that were its leftovers follow it. */
    public function choose(MealPlan $plan, MealPlanEntry $entry, ?Recipe $recipe): void
    {
        $entry->setRecipe($recipe);
        $entry->setLeftoverOf(null);
        $this->syncLeftovers($plan);
    }

    /**
     * @param list<MealPlanEntry> $entries
     * @param list<string>        $avoid   recipe ids not to pick
     */
    private function refill(MealPlan $plan, array $entries, array $avoid = [], bool $leftovers = true): void
    {
        $library = array_map(Candidate::fromRecipe(...), $this->recipes->libraryOf($plan->getHousehold()));
        $lastPlanned = $this->plans->lastPlanned($plan->getHousehold(), $plan->getWeekStartDate(), self::NO_REPEAT_DAYS);

        usort($entries, static fn (MealPlanEntry $a, MealPlanEntry $b): int => $a->sortKey() <=> $b->sortKey());
        foreach ($entries as $entry) {
            $entry->setRecipe(null);
            $entry->setLeftoverOf(null);
        }

        foreach ($entries as $entry) {
            $dinner = $leftovers && $plan->hasLeftovers() && MealType::Lunch === $entry->getSlotType()
                ? $plan->entryAt($entry->getDateValue()->modify('-1 day'), MealType::Dinner)
                : null;
            if (null !== $dinner && null !== $dinner->getRecipe() && null === $dinner->getLeftoverOf()) {
                $entry->setRecipe($dinner->getRecipe());
                $entry->setLeftoverOf($dinner);
                continue;
            }

            $entry->setRecipe($this->pick($plan, $entry, $library, $lastPlanned, $avoid)?->recipe);
        }

        $this->syncLeftovers($plan);
    }

    /** Leftovers are the same dish: they follow their meal, or stop being leftovers if locked. */
    private function syncLeftovers(MealPlan $plan): void
    {
        foreach ($plan->getEntries() as $entry) {
            $source = $entry->getLeftoverOf();
            if (null === $source || $source->getRecipe() === $entry->getRecipe()) {
                continue;
            }
            if ($entry->isLocked() || null === $source->getRecipe()) {
                $entry->setLeftoverOf(null);
            } else {
                $entry->setRecipe($source->getRecipe());
            }
        }
    }

    /**
     * @param list<Candidate>                   $library
     * @param array<string, \DateTimeImmutable> $lastPlanned
     * @param list<string>                      $avoid
     */
    private function pick(MealPlan $plan, MealPlanEntry $entry, array $library, array $lastPlanned, array $avoid): ?Candidate
    {
        $week = array_values(array_filter(
            array_map(static fn (MealPlanEntry $e): ?Recipe => $e === $entry ? null : $e->getRecipe(), $plan->getEntries()),
        ));
        $inWeek = array_map(static fn (Recipe $r): string => $r->getId()->toRfc4122(), $week);
        $weekTags = array_count_values(array_merge(...array_map(static fn (Recipe $r): array => Candidate::fromRecipe($r)->tags, $week)));
        $weekFresh = array_values(array_unique(array_merge(...array_map(static fn (Recipe $r): array => Candidate::fromRecipe($r)->fresh, $week))));

        $slot = $entry->getSlotType();
        $suits = array_values(array_filter($library, static fn (Candidate $c): bool => $c->suits($slot) && !\in_array($c->id, $avoid, true)));
        $limit = $entry->isWeekday() ? $plan->getWeekdayMaxMinutes() : null;
        $inTime = array_values(array_filter($suits, static fn (Candidate $c): bool => null === $limit || null === $c->minutes || $c->minutes <= $limit));
        $fits = [] !== $inTime ? $inTime : $suits;

        $recentSince = $entry->getDateValue()->modify(\sprintf('-%d days', self::RELAXED_NO_REPEAT_DAYS));
        $tiers = [
            static fn (Candidate $c): bool => !\in_array($c->id, $inWeek, true) && !isset($lastPlanned[$c->id]),
            static fn (Candidate $c): bool => !\in_array($c->id, $inWeek, true) && (!isset($lastPlanned[$c->id]) || $lastPlanned[$c->id] < $recentSince),
            static fn (Candidate $c): bool => !\in_array($c->id, $inWeek, true),
            static fn (Candidate $c): bool => true,
        ];
        foreach ($tiers as $allowed) {
            $candidates = array_values(array_filter($fits, $allowed));
            if ([] !== $candidates) {
                return $this->weightedPick($candidates, static fn (Candidate $c): float => $c->score($weekTags, $weekFresh));
            }
        }

        return null;
    }

    /**
     * @param non-empty-list<Candidate>  $candidates
     * @param callable(Candidate): float $weight
     */
    private function weightedPick(array $candidates, callable $weight): Candidate
    {
        $weights = array_map($weight, $candidates);
        $target = $this->random->getFloat(0, array_sum($weights));
        foreach ($candidates as $i => $candidate) {
            $target -= $weights[$i];
            if ($target < 0) {
                return $candidate;
            }
        }

        return $candidates[array_key_last($candidates)];
    }

    /** @internal for Candidate */
    public static function isMainTag(string $tag): bool
    {
        return !\in_array($tag, self::GENERIC_TAGS, true);
    }

    /** @internal for Candidate */
    public static function isFresh(string $canonicalKey): bool
    {
        return !\in_array($canonicalKey, self::STAPLES, true);
    }
}
