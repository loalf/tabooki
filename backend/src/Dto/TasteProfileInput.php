<?php

declare(strict_types=1);

namespace App\Dto;

use ApiPlatform\Metadata\ApiProperty;
use App\Taste\TasteRules;
use Symfony\Component\Validator\Constraints as Assert;

/** The likings confirmed on the review screen. Rules are read leniently (TasteRules::fromArray()). */
final class TasteProfileInput
{
    /** @var array<string, mixed> */
    #[ApiProperty(schema: ['type' => 'object', 'additionalProperties' => true])]
    public array $answers = [];

    /** @var array<string, mixed> */
    #[ApiProperty(schema: TasteRules::API_SCHEMA)]
    public array $rules = [];

    #[Assert\Length(max: 2000)]
    public ?string $notes = null;

    /** @var array<mixed>|null checked by TasteProfileProcessor */
    #[ApiProperty(schema: ['type' => ['object', 'null'], 'required' => ['es', 'en'], 'properties' => ['es' => ['type' => 'string'], 'en' => ['type' => 'string']]])]
    public ?array $summary = null;
}
