<?php

declare(strict_types=1);

namespace App\Planning;

use App\Entity\Recipe;
use App\Enum\Allergen;
use App\Enum\FoodGroup;
use App\Enum\Heaviness;
use App\Enum\Language;
use App\Enum\MealType;

/** A library recipe as the planner weighs it. */
final readonly class Candidate
{
    /**
     * @param list<MealType>                               $mealTypes          empty: suits any meal
     * @param list<string>                                 $tags               main tags only (what the dish is, not how)
     * @param list<string>                                 $fresh              canonical keys of non-staple ingredients
     * @param list<FoodGroup>                              $foodGroups         what the dish needs (see $hasFacts)
     * @param list<FoodGroup>                              $optionalFoodGroups
     * @param list<string>                                 $keys               canonical keys of the ingredients it needs
     * @param list<string>                                 $optionalKeys
     * @param array<string, array{es: string, en: string}> $freshNames         the fresh ingredients' names, by key
     * @param list<Allergen>                               $allergens          from the foods of the lines it needs (see $allergensComplete)
     * @param list<Allergen>                               $optionalAllergens
     */
    public function __construct(
        public Recipe $recipe,
        public string $id,
        public array $mealTypes,
        public ?int $minutes,
        public array $tags,
        public array $fresh,
        public bool $favorite,
        public ?int $rating,
        public bool $needsReview,
        public bool $planNext = false,
        public ?Heaviness $heaviness = null,
        public ?int $spice = null,
        public array $foodGroups = [],
        public array $optionalFoodGroups = [],
        public bool $hasFacts = false,
        public array $keys = [],
        public array $optionalKeys = [],
        public array $freshNames = [],
        public array $allergens = [],
        public array $optionalAllergens = [],
        public bool $allergensComplete = false,
    ) {
    }

    public static function fromRecipe(Recipe $recipe): self
    {
        $language = $recipe->getLanguage();
        $translated = $recipe->getTranslation()->ingredients ?? [];
        $fresh = [];
        $freshNames = [];
        $keys = [];
        $optionalKeys = [];
        foreach ($recipe->getIngredients() as $i => $ingredient) {
            $key = $ingredient->getCanonicalKey();
            if (null === $key || '' === $key) {
                continue;
            }
            if ($ingredient->isOptional()) {
                $optionalKeys[] = $key;
                continue;
            }
            $keys[] = $key;
            if (Planner::isFresh($key)) {
                $fresh[] = $key;
                // The ingredient's name alone ("cilantro"), never its whole line with the amount.
                $name = $ingredient->getName() ?? $translated[$i]['name'] ?? null;
                $other = $translated[$i]['name'] ?? $name;
                if (null !== $name && null !== $other) {
                    $freshNames[$key] ??= Language::Es === $language ? ['es' => $name, 'en' => $other] : ['es' => $other, 'en' => $name];
                }
            }
        }

        return new self(
            recipe: $recipe,
            id: $recipe->getId()->toRfc4122(),
            mealTypes: $recipe->getMealTypes(),
            minutes: $recipe->getTotalMinutes(),
            // In one language for every recipe, so "pollo" and "chicken" count as the same dish.
            tags: array_values(array_filter($recipe->tagsIn(Language::En), Planner::isMainTag(...))),
            fresh: array_values(array_unique($fresh)),
            favorite: $recipe->isFavorite(),
            rating: $recipe->getRating(),
            needsReview: $recipe->getNeedsReview(),
            planNext: $recipe->isPlanNext(),
            heaviness: $recipe->getHeaviness(),
            spice: $recipe->getSpice(),
            foodGroups: $recipe->getFoodGroups(),
            optionalFoodGroups: $recipe->getOptionalFoodGroups(),
            hasFacts: $recipe->hasFoodGroups(),
            keys: array_values(array_unique($keys)),
            optionalKeys: array_values(array_unique($optionalKeys)),
            freshNames: $freshNames,
            allergens: $recipe->getAllergens(),
            optionalAllergens: $recipe->getOptionalAllergens(),
            allergensComplete: $recipe->isAllergensComplete(),
        );
    }

    public function suits(MealType $slot): bool
    {
        return [] === $this->mealTypes || \in_array($slot, $this->mealTypes, true);
    }

    /**
     * How likely it is to be picked, given what the week already has.
     *
     * @param array<string, int> $weekTags  main tags of the week's recipes, with counts
     * @param list<string>       $weekFresh fresh ingredients of the week's recipes
     */
    public function score(array $weekTags, array $weekFresh): float
    {
        $score = 1.0;
        if ($this->favorite) {
            $score *= 2.0;
        }
        if (null !== $this->rating) {
            $score *= 0.6 + 0.2 * $this->rating; // 1★ 0.8 … 5★ 1.6
        }
        if ($this->needsReview) {
            $score *= 0.8;
        }

        foreach ($this->tags as $tag) {
            $score *= 0.6 ** min($weekTags[$tag] ?? 0, 3);
        }
        $shared = \count(array_intersect($this->fresh, $weekFresh));
        $score *= 1 + 0.15 * min($shared, 4);

        return max($score, 0.01);
    }
}
