<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Turn a saved link into recipes (docs/PLAN.md §4). With a recipe id, extract it again from the
 * link's stored material and update that recipe in place.
 */
final readonly class ExtractSource
{
    public function __construct(public string $sourceId, public ?string $recipeId = null)
    {
    }
}
