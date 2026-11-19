<?php

declare(strict_types=1);

namespace App\Planning;

use App\Entity\Recipe;
use App\Enum\MealType;

/** A library recipe as the planner weighs it. */
final readonly class Candidate
{
    /**
     * @param list<MealType> $mealTypes empty: suits any meal
     * @param list<string>   $tags      main tags only (what the dish is, not how)
     * @param list<string>   $fresh     canonical keys of non-staple ingredients
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
    ) {
    }

    public static function fromRecipe(Recipe $recipe): self
    {
        $fresh = [];
        foreach ($recipe->getIngredients() as $ingredient) {
            $key = $ingredient->getCanonicalKey();
            if (null !== $key && !$ingredient->isOptional() && Planner::isFresh($key)) {
                $fresh[] = $key;
            }
        }

        return new self(
            recipe: $recipe,
            id: $recipe->getId()->toRfc4122(),
            mealTypes: $recipe->getMealTypes(),
            minutes: $recipe->getTotalMinutes(),
            tags: array_values(array_filter($recipe->getTags(), Planner::isMainTag(...))),
            fresh: array_values(array_unique($fresh)),
            favorite: $recipe->isFavorite(),
            rating: $recipe->getRating(),
            needsReview: $recipe->getNeedsReview(),
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
