<?php

declare(strict_types=1);

namespace App\Dto;

use ApiPlatform\Metadata\ApiProperty;

/** The questionnaire, as frontend/src/api/taste.ts (TasteAnswers) sends it; with followUps once they're answered. */
final class TasteDraftInput
{
    /** @var array<string, mixed> */
    #[ApiProperty(schema: ['type' => 'object', 'additionalProperties' => true])]
    public array $answers = [];
}
