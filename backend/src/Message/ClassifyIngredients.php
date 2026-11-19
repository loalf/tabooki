<?php

declare(strict_types=1);

namespace App\Message;

/** Name new catalog ingredients in both languages and sort them into aisles. */
final readonly class ClassifyIngredients
{
    public function __construct(public string $householdId)
    {
    }
}
