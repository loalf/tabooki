<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/** What can stand in for a food, chosen by hand: all of them at once, best first. None is an answer too. */
final class FoodSubstitutesInput
{
    /** @var list<FoodSubstituteInput> */
    #[Assert\Count(max: 3)]
    #[Assert\Valid]
    public array $substitutes = [];
}
