<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/** One meal to plan: a day of the week, lunch or dinner, and for how many. */
final class MealPlanSlotInput
{
    #[Assert\NotBlank]
    #[Assert\Date]
    public string $date = '';

    #[Assert\Choice(choices: ['lunch', 'dinner'])]
    public string $slot = 'lunch';

    /** null: the household's default. */
    #[Assert\Range(min: 1, max: 50)]
    public ?int $servings = null;
}
