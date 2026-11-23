<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Ask what can stand in for the shared foods nobody has asked about yet. Like DescribeFoods, the
 * household given only pays for the call on the costs page.
 */
final readonly class SuggestSubstitutes
{
    public function __construct(public string $householdId)
    {
    }
}
