<?php

declare(strict_types=1);

namespace App\ApiResource;

final class CostTotal
{
    public float $usd = 0.0;

    public int $calls = 0;

    public int $inputTokens = 0;

    public int $outputTokens = 0;
}
