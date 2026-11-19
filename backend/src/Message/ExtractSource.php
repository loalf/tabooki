<?php

declare(strict_types=1);

namespace App\Message;

/** Turn a saved link into recipes (docs/PLAN.md §4). */
final readonly class ExtractSource
{
    public function __construct(public string $sourceId)
    {
    }
}
