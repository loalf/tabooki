<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/** A change to one planned meal. Only the fields that are set are changed. */
final class MealPlanEntryAction
{
    #[Assert\NotBlank]
    #[Assert\Uuid]
    public string $entry = '';

    public ?bool $locked = null;

    #[Assert\Range(min: 1, max: 50)]
    public ?int $servings = null;

    /** How it turned out (1-5): rates the meal and its recipe. */
    #[Assert\Range(min: 1, max: 5)]
    public ?int $rating = null;

    /** Id of a library recipe to cook for this meal. */
    #[Assert\Uuid]
    public ?string $recipe = null;

    /** Make it the leftovers of another meal of the plan, with the same recipe (undoing "No lo hicimos"). */
    #[Assert\Uuid]
    public ?string $leftoverOf = null;

    /**
     * Cooked (true), not cooked (false), or back to nobody has said (null). Left uninitialised
     * when the request doesn't send it, as null is a value here (undoing "No lo hicimos").
     */
    public ?bool $cooked;

    public function setsCooked(): bool
    {
        return (new \ReflectionProperty($this, 'cooked'))->isInitialized($this);
    }
}
