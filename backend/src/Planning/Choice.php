<?php

declare(strict_types=1);

namespace App\Planning;

use App\Entity\MealPlanEntry;

/** What a sampled week puts in one meal: a recipe (or none), or another meal's leftovers, and why. */
final readonly class Choice
{
    /** @param list<array<string, mixed>> $reasons see MealPlanEntry::$reasons */
    public function __construct(
        public ?Candidate $candidate,
        public ?MealPlanEntry $leftoverOf = null,
        public array $reasons = [],
    ) {
    }
}
