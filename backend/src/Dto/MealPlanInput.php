<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/** What to plan for a week: which meals, and how to choose them. */
final class MealPlanInput
{
    /** Any day of the week to plan (its Monday is used), YYYY-MM-DD. */
    #[Assert\NotBlank]
    #[Assert\Date]
    public string $weekStart = '';

    /** @var list<MealPlanSlotInput> */
    #[Assert\Count(min: 1, max: 14)]
    #[Assert\Valid]
    public array $slots = [];

    /** Cook dinner for two meals: the next day's lunch is its leftovers. */
    public bool $leftovers = false;

    /** Longest recipe (prep + cook) for Monday to Friday; null for no limit. */
    #[Assert\Range(min: 5, max: 600)]
    public ?int $weekdayMaxMinutes = null;

    /** A wish for this week only ("my mother comes: no pork"), in the household's words. */
    #[Assert\Length(max: 500)]
    public ?string $tweak = null;
}
