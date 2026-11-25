<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * A meal taken off the menu, put back as it was (the undo): same id, day, recipe and state, and
 * linked again to its leftovers, or to the meal it was the leftovers of.
 */
final class MealPlanEntryRestore
{
    /** Its id, as it was. */
    #[Assert\NotBlank]
    #[Assert\Uuid]
    public string $entry = '';

    #[Assert\NotBlank]
    #[Assert\Date]
    public string $date = '';

    #[Assert\Choice(choices: ['lunch', 'dinner'])]
    public string $slot = 'lunch';

    #[Assert\Range(min: 1, max: 50)]
    public int $servings = 1;

    #[Assert\Uuid]
    public ?string $recipe = null;

    public bool $locked = false;

    #[Assert\Range(min: 1, max: 5)]
    public ?int $rating = null;

    public ?bool $cooked = null;

    /**
     * Why the planner chose it, as the plan gave it.
     *
     * @var list<array<string, mixed>>
     */
    public array $reasons = [];

    /** The meal it was the leftovers of. */
    #[Assert\Uuid]
    public ?string $leftoverOf = null;

    /**
     * Meals that were its leftovers.
     *
     * @var list<string>
     */
    #[Assert\All([new Assert\Uuid()])]
    public array $leftovers = [];
}
