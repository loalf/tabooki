<?php

declare(strict_types=1);

namespace App\Message;

/** Embed a recipe for search by meaning, unless its digest hasn't changed since the last time. */
final readonly class EmbedRecipe
{
    public function __construct(public string $recipeId)
    {
    }
}
