<?php

declare(strict_types=1);

namespace App\Message;

/** Fill in a saved link's title, author and thumbnail in the background. */
final readonly class FetchSourceMetadata
{
    public function __construct(public string $sourceId)
    {
    }
}
