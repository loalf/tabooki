<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Data that belongs to one household. API queries on these resources are scoped to the
 * signed-in user's household (see App\Doctrine\HouseholdExtension), and new ones are
 * assigned to it on creation (see App\State\HouseholdOwnedProcessor).
 */
interface HouseholdOwned
{
    public function getHousehold(): Household;

    public function assignHousehold(Household $household): void;
}
