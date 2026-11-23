<?php

declare(strict_types=1);

namespace App\Taste;

use App\Enum\Allergen;
use App\Enum\Diet;
use App\Enum\FoodGroup;
use App\Enum\Heaviness;
use App\Enum\MealType;
use App\Planning\Candidate;

/** A household's rules applied to recipes: what they rule out, and how much each is wanted. */
final readonly class Taste
{
    /** Identifies the notes the recipes were judged against (NotesJudge); null without notes. */
    public ?string $notesHash;

    public function __construct(public TasteRules $rules, public ?string $notes = null)
    {
        $this->notesHash = self::notesHash($notes);
    }

    public static function notesHash(?string $notes): ?string
    {
        $notes = null === $notes ? '' : trim($notes);

        return '' === $notes ? null : hash('xxh128', $notes);
    }

    /**
     * What the model made of the recipe against the notes, when it was judged against these and
     * isn't neutral.
     *
     * @return array{fit: string, when: string, reason: array{es: string, en: string}|null}|null
     */
    public function notesJudgement(Candidate $recipe): ?array
    {
        $judgement = $recipe->recipe->notesJudgementFor($this->notesHash);

        return null === $judgement || 'neutral' === $judgement['fit'] ? null : $judgement;
    }

    /** "good" or "poor" when the notes have something to say about this recipe for this meal; null otherwise. */
    public function notesFit(Candidate $recipe, MealType $slot, bool $weekday): ?string
    {
        $judgement = $this->notesJudgement($recipe);
        $applies = match ($judgement['when'] ?? null) {
            'always' => true,
            'weekdays' => $weekday,
            'weekends' => !$weekday,
            'lunch' => MealType::Lunch === $slot,
            'dinner' => MealType::Dinner === $slot,
            default => false,
        };

        return $applies ? $judgement['fit'] : null;
    }

    /** @return list<Conflict> firmest first */
    public function conflicts(Candidate $recipe): array
    {
        $rules = $this->rules;
        $never = [];
        $avoid = [];
        $unknown = false;

        if (null !== $rules->diet) {
            if (!$recipe->hasFacts) {
                $unknown = true;
            } elseif (!self::suits(Diet::of($recipe->foodGroups), $rules->diet)) {
                $never[] = new Conflict(Strength::Never, Conflict::DIET, $rules->diet->value);
            }
        }

        foreach ($rules->excludeGroups as $rule) {
            $firm = Strength::Never === $rule->strength;
            if (!$recipe->hasFacts) {
                // An allergy needs to know; a dislike gives the recipe the benefit of the doubt.
                $unknown = $unknown || $firm;
                continue;
            }
            $groups = $firm ? [...$recipe->foodGroups, ...$recipe->optionalFoodGroups] : $recipe->foodGroups;
            // An allergy also goes by the foods' allergens ("may contain" included): a dislike only by the dish.
            $allergens = $firm ? [...$recipe->allergens, ...$recipe->optionalAllergens] : [];
            if (\in_array($rule->group, $groups, true) || [] !== array_filter($rule->group->allergens(), static fn (Allergen $a): bool => \in_array($a, $allergens, true))) {
                $conflict = new Conflict($rule->strength, Conflict::GROUP, $rule->group->value);
                $firm ? $never[] = $conflict : $avoid[] = $conflict;
            }
        }

        foreach ($rules->excludeAllergens as $rule) {
            $firm = Strength::Never === $rule->strength;
            if (\in_array($rule->allergen, $firm ? [...$recipe->allergens, ...$recipe->optionalAllergens] : $recipe->allergens, true)) {
                $conflict = new Conflict($rule->strength, Conflict::ALLERGEN, $rule->allergen->value);
                $firm ? $never[] = $conflict : $avoid[] = $conflict;
            } elseif ($firm && !$recipe->allergensComplete) {
                // Only the foods can tell, and one of them isn't described yet.
                $unknown = true;
            }
        }

        foreach ($rules->excludeIngredients as $rule) {
            $firm = Strength::Never === $rule->strength;
            if (IngredientMatch::any($rule->keys, $firm ? [...$recipe->keys, ...$recipe->optionalKeys] : $recipe->keys)) {
                $conflict = new Conflict($rule->strength, Conflict::INGREDIENT, null, $rule->name);
                $firm ? $never[] = $conflict : $avoid[] = $conflict;
            }
        }

        if ($unknown) {
            $never[] = new Conflict(Strength::Never, Conflict::UNKNOWN);
        }

        return [...$never, ...$avoid];
    }

    /** Ruled out for good: an allergy, the diet, or not knowing what it contains when that matters. */
    public function forbids(Candidate $recipe): bool
    {
        return [] !== array_filter($this->conflicts($recipe), static fn (Conflict $c): bool => Strength::Never === $c->strength);
    }

    /** Has something the household doesn't like (and nothing it can't eat). */
    public function dislikes(Candidate $recipe): bool
    {
        return [] !== $this->conflicts($recipe) && !$this->forbids($recipe);
    }

    /** How much more (or less) likely to be picked for this meal: lunch and dinner heaviness, spice, more or less of something, the notes. */
    public function weight(Candidate $recipe, MealType $slot, bool $weekday = true): float
    {
        $weight = match ($this->heavinessFit($recipe, $slot)) {
            1 => 1.6,
            -1 => 0.3,
            0 => 0.8,
            default => 1.0,
        };
        if (null !== $this->rules->maxSpice && null !== $recipe->spice && $recipe->spice > $this->rules->maxSpice) {
            $weight *= 0.15;
        }
        foreach ($this->rules->prefer as $preference) {
            if ([] !== array_intersect($preference->tags, $recipe->tags)) {
                $weight *= $preference->more ? 1.6 : 0.4;
            }
        }
        $weight *= match ($this->notesFit($recipe, $slot, $weekday)) {
            'good' => 1.4,
            'poor' => 0.25,
            default => 1.0,
        };

        return $weight;
    }

    /** 1 what the household wants for this meal, -1 the opposite (light for hearty), 0 in between; null when nobody said or it isn't known. */
    public function heavinessFit(Candidate $recipe, MealType $slot): ?int
    {
        $wanted = $this->rules->heavinessFor($slot);
        if (null === $wanted || null === $recipe->heaviness) {
            return null;
        }
        if ($wanted === $recipe->heaviness) {
            return 1;
        }

        return Heaviness::Medium === $wanted || Heaviness::Medium === $recipe->heaviness ? 0 : -1;
    }

    /** Whether a meal of this recipe counts for a weekly limit (only when what it contains is known). */
    public function counts(Candidate $recipe, WeeklyLimit $limit): bool
    {
        return $recipe->hasFacts && [] !== array_uintersect($recipe->foodGroups, $limit->groups, static fn (FoodGroup $a, FoodGroup $b): int => strcmp($a->value, $b->value));
    }

    private static function suits(?Diet $dish, Diet $wanted): bool
    {
        $order = [Diet::Vegan, Diet::Vegetarian, Diet::Pescatarian];

        return null !== $dish && array_search($dish, $order, true) <= array_search($wanted, $order, true);
    }
}
