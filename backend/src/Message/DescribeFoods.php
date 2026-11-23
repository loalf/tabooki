<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Fill in nutrition, allergens and weights of the shared foods that lack them. Foods belong to no
 * household: the one given only pays for the call on the costs page (whoever brought them in).
 */
final readonly class DescribeFoods
{
    public function __construct(public string $householdId)
    {
    }
}
