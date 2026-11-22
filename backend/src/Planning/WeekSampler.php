<?php

declare(strict_types=1);

namespace App\Planning;

use App\Entity\MealPlan;
use App\Entity\MealPlanEntry;
use App\Entity\Recipe;
use App\Enum\MealType;
use App\Taste\Conflict;
use App\Taste\Strength;
use App\Taste\Taste;
use Random\Randomizer;

/**
 * Fills a week's meals once, slot by slot, with a weighted random pick (docs/PLAN.md §6 and
 * §6.3), and says how much of the household's likings it had to give up: the penalty. The
 * Planner samples several weeks and keeps the one with the lowest.
 *
 * For each meal, in this order:
 * - leftovers: with them on, a lunch is the previous dinner;
 * - what the household can't eat (an allergy, its diet) is out for good;
 * - recipes asked for ("en el próximo menú") come first;
 * - then the rest, relaxing only when nothing else is left: first the weekly limits, then the
 *   dislikes, and within each, the no-repeat rules (not in the last three weeks, not in the last
 *   week, not twice this week);
 * - a weekly minimum that can't wait any longer takes only recipes that count for it.
 */
final class WeekSampler
{
    private const float NOTHING_ALLOWED = 50;
    private const float RELAXED_AVOID = 100;
    private const float RELAXED_WEEKLY = 30;
    private const float SHORT_OF_MINIMUM = 20;
    private const float REPEAT_TIER = 4;
    private const float WRONG_HEAVINESS = 2;
    private const float OVER_TIME = 1;

    /** @var array<int, ?Candidate> meals not being filled, by spl_object_id of their entry */
    private array $fixed = [];

    /** @var array<int, bool> */
    private array $fixedLeftover = [];

    /** @var array<int, true> spl_object_id of the entries being filled */
    private array $filling = [];

    /** @var array<string, Candidate> recipes already planned this week but no longer in the library view */
    private array $extra = [];

    /** @var array<string, bool> */
    private array $forbidden = [];

    /** @var array<string, bool> */
    private array $disliked = [];

    /** @var array<string, array<string, float>> by recipe id, then slot */
    private array $tasteWeight = [];

    /** @var array<string, list<int>> weekly limits (by index) each recipe counts for */
    private array $countsFor = [];

    /**
     * @param list<MealPlanEntry>               $entries     the meals to fill, in order
     * @param array<string, Candidate>          $library     by id
     * @param array<string, \DateTimeImmutable> $lastPlanned by recipe id, the weeks before this one
     * @param list<string>                      $avoid       recipe ids not to pick
     */
    public function __construct(
        private readonly MealPlan $plan,
        private readonly array $entries,
        private readonly array $library,
        private readonly array $lastPlanned,
        private readonly array $avoid,
        private readonly bool $leftovers,
        private readonly Taste $taste,
        private readonly Randomizer $random,
    ) {
        foreach ($library as $candidate) {
            $this->remember($candidate);
        }
        foreach ($entries as $entry) {
            $this->filling[spl_object_id($entry)] = true;
        }
        foreach ($plan->getEntries() as $entry) {
            if (isset($this->filling[spl_object_id($entry)])) {
                continue;
            }
            $recipe = $entry->getRecipe();
            $this->fixed[spl_object_id($entry)] = null === $recipe ? null : $this->candidateOf($recipe);
            $this->fixedLeftover[spl_object_id($entry)] = null !== $entry->getLeftoverOf();
        }
    }

    /** @return array{array<int, Choice>, float} a choice for each meal being filled (by spl_object_id), and the penalty */
    public function sample(): array
    {
        $assigned = $this->fixed;
        $leftover = $this->fixedLeftover;
        $choices = [];
        $penalty = 0.0;
        $remaining = \count($this->entries);

        foreach ($this->entries as $entry) {
            $self = spl_object_id($entry);
            $dinner = $this->leftovers && $this->plan->hasLeftovers() && MealType::Lunch === $entry->getSlotType()
                ? $this->plan->entryAt($entry->getDateValue()->modify('-1 day'), MealType::Dinner)
                : null;
            $cooked = null === $dinner ? null : ($assigned[spl_object_id($dinner)] ?? null);
            if (null !== $dinner && null !== $cooked && !($leftover[spl_object_id($dinner)] ?? false)) {
                $assigned[$self] = $cooked;
                $leftover[$self] = true;
                $choices[$self] = new Choice($cooked, $dinner);
                --$remaining;
                continue;
            }

            [$candidate, $reasons, $cost] = $this->pick($entry, $assigned, $self, $remaining);
            $assigned[$self] = $candidate;
            $leftover[$self] = false;
            $choices[$self] = new Choice($candidate, null, $reasons);
            $penalty += $cost;
            --$remaining;
        }

        foreach ($this->taste->rules->weekly as $k => $limit) {
            if (null !== $limit->min) {
                $penalty += max(0, $limit->min - $this->count($assigned, $k)) * self::SHORT_OF_MINIMUM;
            }
        }

        return [$choices, $penalty];
    }

    /**
     * @param array<int, ?Candidate> $assigned every meal of the week so far
     *
     * @return array{?Candidate, list<array<string, mixed>>, float}
     */
    private function pick(MealPlanEntry $entry, array $assigned, int $self, int $remaining): array
    {
        $others = $assigned;
        unset($others[$self]);
        /** @var list<Candidate> $week */
        $week = array_values(array_filter($others));
        $inWeek = array_flip(array_map(static fn (Candidate $c): string => $c->id, $week));
        $weekTags = array_count_values(array_merge(...array_map(static fn (Candidate $c): array => $c->tags, $week)));
        $weekFresh = array_values(array_unique(array_merge(...array_map(static fn (Candidate $c): array => $c->fresh, $week))));

        $slot = $entry->getSlotType();
        $suits = array_filter($this->library, fn (Candidate $c): bool => $c->suits($slot) && !\in_array($c->id, $this->avoid, true));
        $allowed = array_filter($suits, fn (Candidate $c): bool => !$this->forbidden[$c->id]);
        if ([] === $allowed) {
            // Nothing in the library at all is just an empty meal; nothing the household can eat is worth saying.
            return [] === $suits ? [null, [], 0.0] : [null, [['code' => 'nothingAllowed']], self::NOTHING_ALLOWED];
        }

        $reasons = [];
        $cost = 0.0;
        $limit = $entry->isWeekday() ? $this->plan->getWeekdayMaxMinutes() : null;
        $fits = array_filter($allowed, static fn (Candidate $c): bool => null === $limit || null === $c->minutes || $c->minutes <= $limit);
        if ([] === $fits) {
            $fits = $allowed;
            $reasons[] = ['code' => 'overTime'];
            $cost += self::OVER_TIME;
        }

        // Weekly limits: how many meals already count, and what this one would add (a dinner whose
        // leftovers will be tomorrow's lunch is two meals).
        $meals = $this->mealsFor($entry);
        $counts = [];
        $needs = [];
        foreach ($this->taste->rules->weekly as $k => $rule) {
            $counts[$k] = $this->count($others, $k);
            if (null !== $rule->min && $counts[$k] < $rule->min) {
                $needs[$k] = $rule->min - $counts[$k];
            }
        }
        $over = function (Candidate $c) use ($counts, $meals): bool {
            foreach ($this->countsFor[$c->id] ?? [] as $k) {
                $max = $this->taste->rules->weekly[$k]->max;
                if (null !== $max && $counts[$k] + $meals > $max) {
                    return true;
                }
            }

            return false;
        };
        $weight = function (Candidate $c) use ($slot, $weekTags, $weekFresh, $needs, $remaining): float {
            $weight = $c->score($weekTags, $weekFresh) * $this->tasteWeight[$c->id][$slot->value];
            foreach ($needs as $k => $need) {
                if (\in_array($k, $this->countsFor[$c->id] ?? [], true)) {
                    $weight *= 1 + 4 * $need / max(1, $remaining);
                }
            }

            return $weight;
        };

        // Asked for: first, wherever they fit, even against a dislike or a weekly limit.
        $wanted = array_values(array_filter($fits, static fn (Candidate $c): bool => $c->planNext && !isset($inWeek[$c->id])));
        if ([] !== $wanted) {
            $chosen = $this->weightedPick($wanted, $weight);
            $breaks = $this->avoidConflicts($chosen);

            return [$chosen, [...$reasons, ['code' => 'asked'], ...([] === $breaks ? [] : [['code' => 'breaks', 'conflicts' => $breaks]]), ...$this->explain($chosen, $slot, self::freshOf($week, except: $chosen))], $cost];
        }

        $recentSince = $entry->getDateValue()->modify('-7 days');
        $repeatTiers = [
            fn (Candidate $c): bool => !isset($inWeek[$c->id]) && !isset($this->lastPlanned[$c->id]),
            fn (Candidate $c): bool => !isset($inWeek[$c->id]) && (!isset($this->lastPlanned[$c->id]) || $this->lastPlanned[$c->id] < $recentSince),
            static fn (Candidate $c): bool => !isset($inWeek[$c->id]),
            static fn (Candidate $c): bool => true,
        ];
        $levels = [
            fn (Candidate $c): bool => !$this->disliked[$c->id] && !$over($c),
            fn (Candidate $c): bool => !$this->disliked[$c->id],
            static fn (Candidate $c): bool => true,
        ];

        foreach ($levels as $level => $allowedAt) {
            foreach ($repeatTiers as $tier => $fresh) {
                $candidates = array_values(array_filter($fits, static fn (Candidate $c): bool => $allowedAt($c) && $fresh($c)));
                if ([] === $candidates) {
                    continue;
                }
                // A minimum that can't wait any longer: only what counts for it, if there is any.
                foreach ($needs as $k => $need) {
                    if ($need >= $remaining) {
                        $only = array_values(array_filter($candidates, fn (Candidate $c): bool => \in_array($k, $this->countsFor[$c->id] ?? [], true)));
                        $candidates = [] === $only ? $candidates : $only;
                    }
                }
                $chosen = $this->weightedPick($candidates, $weight);

                if ($level >= 1 && $over($chosen)) {
                    $reasons[] = ['code' => 'relaxedWeekly'];
                    $cost += self::RELAXED_WEEKLY;
                }
                if (2 === $level && $this->disliked[$chosen->id]) {
                    $reasons[] = ['code' => 'relaxedAvoid', 'conflicts' => $this->avoidConflicts($chosen)];
                    $cost += self::RELAXED_AVOID;
                }
                if ($tier > 0 && (isset($inWeek[$chosen->id]) || isset($this->lastPlanned[$chosen->id]))) {
                    $reasons[] = ['code' => 'repeat'];
                    $cost += $tier * self::REPEAT_TIER;
                }
                if (-1 === $this->taste->heavinessFit($chosen, $slot)) {
                    $cost += self::WRONG_HEAVINESS;
                }

                return [$chosen, [...$reasons, ...$this->explain($chosen, $slot, self::freshOf($week, except: $chosen))], $cost];
            }
        }

        return [null, [], self::NOTHING_ALLOWED];
    }

    /**
     * What's worth saying about a good pick: it's what the household wants for this meal, and
     * what it shares with the rest of the week.
     *
     * @param list<string> $weekFresh
     *
     * @return list<array<string, mixed>>
     */
    private function explain(Candidate $chosen, MealType $slot, array $weekFresh): array
    {
        $reasons = [];
        if (1 === $this->taste->heavinessFit($chosen, $slot) && null !== $chosen->heaviness) {
            $reasons[] = ['code' => 'heaviness', 'value' => $chosen->heaviness->value];
        }
        $shared = \array_slice(array_values(array_intersect($chosen->fresh, $weekFresh)), 0, 2);
        $names = array_values(array_filter(array_map(static fn (string $key): ?array => $chosen->freshNames[$key] ?? null, $shared)));
        if ([] !== $names) {
            $reasons[] = ['code' => 'shares', 'names' => $names];
        }

        return $reasons;
    }

    /**
     * Fresh ingredients of the rest of the week: a repeated recipe doesn't "share" with itself.
     *
     * @param list<Candidate> $week
     *
     * @return list<string>
     */
    private static function freshOf(array $week, Candidate $except): array
    {
        $others = array_filter($week, static fn (Candidate $c): bool => $c->id !== $except->id);

        return array_values(array_unique(array_merge(...array_map(static fn (Candidate $c): array => $c->fresh, array_values($others)))));
    }

    /** @return list<array<string, mixed>> */
    private function avoidConflicts(Candidate $candidate): array
    {
        return array_values(array_map(
            static fn (Conflict $c): array => $c->toArray(),
            array_filter($this->taste->conflicts($candidate), static fn (Conflict $c): bool => Strength::Avoid === $c->strength),
        ));
    }

    /** A dinner whose next-day lunch is being filled too, with leftovers on, feeds two meals. */
    private function mealsFor(MealPlanEntry $entry): int
    {
        if (!$this->leftovers || !$this->plan->hasLeftovers() || MealType::Dinner !== $entry->getSlotType()) {
            return 1;
        }
        $lunch = $this->plan->entryAt($entry->getDateValue()->modify('+1 day'), MealType::Lunch);

        return null !== $lunch && isset($this->filling[spl_object_id($lunch)]) ? 2 : 1;
    }

    /** @param array<int, ?Candidate> $meals */
    private function count(array $meals, int $limit): int
    {
        return \count(array_filter($meals, fn (?Candidate $c): bool => null !== $c && \in_array($limit, $this->countsFor[$c->id] ?? [], true)));
    }

    private function candidateOf(Recipe $recipe): Candidate
    {
        $id = $recipe->getId()->toRfc4122();
        if (isset($this->library[$id])) {
            return $this->library[$id];
        }
        if (!isset($this->extra[$id])) {
            $this->extra[$id] = Candidate::fromRecipe($recipe);
            $this->remember($this->extra[$id]);
        }

        return $this->extra[$id];
    }

    /** What the household's likings say about a recipe never changes during a fill: worked out once. */
    private function remember(Candidate $candidate): void
    {
        $conflicts = $this->taste->conflicts($candidate);
        $never = [] !== array_filter($conflicts, static fn (Conflict $c): bool => Strength::Never === $c->strength);
        $this->forbidden[$candidate->id] = $never;
        $this->disliked[$candidate->id] = !$never && [] !== $conflicts;
        foreach (MealType::cases() as $slot) {
            $this->tasteWeight[$candidate->id][$slot->value] = $this->taste->weight($candidate, $slot);
        }
        $this->countsFor[$candidate->id] = array_keys(array_filter(
            $this->taste->rules->weekly,
            fn ($limit): bool => $this->taste->counts($candidate, $limit),
        ));
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
}
