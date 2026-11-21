<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;

final class CostMonth
{
    /** YYYY-MM */
    #[ApiProperty(schema: ['type' => 'string', 'pattern' => '^\d{4}-\d{2}$'])]
    public string $month = '';

    public float $usd = 0.0;

    public int $calls = 0;
}
