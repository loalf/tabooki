<?php

declare(strict_types=1);

namespace App\Planning;

use App\Entity\MealPlan;
use App\Entity\MealPlanEntry;
use App\Entity\Recipe;
use App\Repository\MealPlanRepository;
use App\Repository\RecipeRepository;
use App\Repository\SwapFeedbackRepository;
use App\Repository\TasteProfileRepository;
use App\Taste\Conflict;
use App\Taste\Taste;
use Random\Randomizer;

/**
 * Picks recipes for a week's meals from the library (docs/PLAN.md §6), keeping to what the
 * household likes (§6.3). Rules and a weighted random pick (WeekSampler), so "regenerate" gives
 * a different week each time:
 *
 * - a recipe only goes to a meal it suits (lunch, dinner), and on weekdays within the time limit;
 * - what the household can't eat (an allergy, its diet) never; what it doesn't like, only when
 *   nothing else is left; weekly limits ("meat or fish twice at most", "fish at least twice")
 *   over the whole week, leftover and locked meals included;
 * - no recipe twice in a week, nor one planned in the previous three weeks, while the library
 *   has others (then those rules relax, in that order);
 * - a dish someone swapped out lately because nobody likes it comes up much less;
 * - favorites and well-rated recipes come up more, unchecked extractions a little less, and so do
 *   dishes as filling as the household wants that meal, and what it asked for more of;
 * - recipes sharing a main tag with the week's others come up less (variety), recipes sharing
 *   fresh ingredients more (half a bunch of coriander gets used twice);
 * - with leftovers on, a lunch is the previous dinner, cooked once for both;
 * - recipes the cook asked for ("en el próximo menú") come first, wherever they fit, and stop
 *   being asked for once planned.
 *
 * A whole week is sampled several times and the one that gives up least wins; each meal keeps
 * why it was chosen (MealPlanEntry::$reasons).
 */
final readonly class Planner
{
    public const int NO_REPEAT_DAYS = 21;

    /** Weeks tried when filling a plan (one is enough when nothing had to give). */
    private const int SAMPLES = 30;

    /** Tags about how, not what: sharing them isn't a lack of variety. */
    private const array GENERIC_TAGS = ['rápida', 'rapida', 'quick', 'fácil', 'facil', 'easy', 'sana', 'saludable', 'healthy', 'casera', 'homemade'];

    /** Always in the kitchen: sharing them saves nothing. */
    public const array STAPLES = [
        'salt', 'sea_salt', 'pepper', 'black_pepper', 'water', 'olive_oil', 'extra_virgin_olive_oil', 'oil', 'vegetable_oil',
        'sunflower_oil', 'sugar', 'flour', 'wheat_flour', 'vinegar', 'garlic', 'onion', 'paprika', 'sweet_paprika', 'cumin',
        'oregano', 'bay_leaf', 'butter',
    ];

    private Randomizer $random;

    public function __construct(
        private RecipeRepository $recipes,
        private MealPlanRepository $plans,
        private TasteProfileRepository $tastes,
        private SwapFeedbackRepository $feedback,
        ?Randomizer $random = null,
    ) {
        $this->random = $random ?? new Randomizer();
    }

    /** Fills every meal of a new plan, or every unlocked one of an existing plan. */
    public function fill(MealPlan $plan): void
    {
        $this->refill($plan, array_values(array_filter($plan->getEntries(), static fn (MealPlanEntry $e): bool => !$e->isLocked())), samples: self::SAMPLES);
    }

    /** One meal: a new one, or another recipe for an existing one ("swap"). */
    public function fillOne(MealPlan $plan, MealPlanEntry $entry, bool $swap = false): void
    {
        $previous = $entry->getRecipe();
        $this->refill($plan, [$entry], avoid: $swap && null !== $previous ? [$previous->getId()->toRfc4122()] : [], leftovers: !$swap);
    }

    /** A recipe chosen by hand; meals that were its leftovers follow it. It may go against the household's likings: the meal says so. */
    public function choose(MealPlan $plan, MealPlanEntry $entry, ?Recipe $recipe): void
    {
        $entry->setRecipe($recipe);
        $entry->setLeftoverOf(null);
        $conflicts = null === $recipe ? [] : $this->taste($plan)->conflicts(Candidate::fromRecipe($recipe));
        $entry->setReasons([] === $conflicts ? [] : [['code' => 'breaks', 'conflicts' => array_map(static fn (Conflict $c): array => $c->toArray(), $conflicts)]]);
        $recipe?->setPlanNext(false);
        $this->syncLeftovers($plan);
    }

    /**
     * @param list<MealPlanEntry> $entries
     * @param list<string>        $avoid   recipe ids not to pick
     */
    private function refill(MealPlan $plan, array $entries, array $avoid = [], bool $leftovers = true, int $samples = 1): void
    {
        $library = [];
        foreach ($this->recipes->libraryOf($plan->getHousehold()) as $recipe) {
            $candidate = Candidate::fromRecipe($recipe);
            $library[$candidate->id] = $candidate;
        }
        $lastPlanned = $this->plans->lastPlanned($plan->getHousehold(), $plan->getWeekStartDate(), self::NO_REPEAT_DAYS);

        usort($entries, static fn (MealPlanEntry $a, MealPlanEntry $b): int => $a->sortKey() <=> $b->sortKey());
        $sampler = new WeekSampler($plan, $entries, $library, $lastPlanned, $avoid, $leftovers, $this->taste($plan), $this->random, $this->feedback->dislikedDishes($plan->getHousehold()));

        $best = null;
        $bestPenalty = \INF;
        for ($i = 0; $i < $samples; ++$i) {
            [$choices, $penalty] = $sampler->sample();
            if ($penalty < $bestPenalty) {
                [$best, $bestPenalty] = [$choices, $penalty];
            }
            if ($penalty <= 0) {
                break;
            }
        }

        foreach ($entries as $entry) {
            $choice = $best[spl_object_id($entry)] ?? new Choice(null);
            $entry->setRecipe($choice->candidate?->recipe);
            $entry->setLeftoverOf($choice->leftoverOf);
            $entry->setReasons($choice->reasons);
            if (null === $choice->leftoverOf) {
                $choice->candidate?->recipe->setPlanNext(false);
            }
        }

        $this->syncLeftovers($plan);
    }

    /** The household's likings, with this week's wish on top. */
    private function taste(MealPlan $plan): Taste
    {
        $household = $this->tastes->tasteFor($plan->getHousehold());

        // A wish for this week only goes on top of the household's likings.
        return new Taste($household->rules->with($plan->weekRules()), $household->notes);
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
                $entry->setReasons([]);
            }
        }
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
