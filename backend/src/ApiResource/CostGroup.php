<?php

declare(strict_types=1);

namespace App\ApiResource;

final class CostGroup
{
    /** The step ("text", "video", "classify") or the model id. */
    public string $name = '';

    public float $usd = 0.0;

    public int $calls = 0;

    public int $failed = 0;

    public int $inputTokens = 0;

    public int $outputTokens = 0;
}
