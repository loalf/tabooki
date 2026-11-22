<?php

declare(strict_types=1);

namespace App\Dto;

use ApiPlatform\Metadata\ApiProperty;
use App\Taste\TasteRules;

final class TasteRulesInput
{
    /** @var array<string, mixed> */
    #[ApiProperty(schema: TasteRules::API_SCHEMA)]
    public array $rules = [];
}
