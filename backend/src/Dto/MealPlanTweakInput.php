<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/** A wish for one week only, in the household's words; null or empty clears it. */
final class MealPlanTweakInput
{
    #[Assert\Length(max: 500)]
    public ?string $tweak = null;
}
