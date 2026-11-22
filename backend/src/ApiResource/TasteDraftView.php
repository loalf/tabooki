<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use App\Taste\TasteImpactView;
use App\Taste\TasteRules;

/** What the questionnaire became (TasteCompiler), for the review screen. */
final class TasteDraftView
{
    /** @var array<string, mixed> */
    #[ApiProperty(schema: TasteRules::API_SCHEMA)]
    public array $rules = [];

    public ?string $notes = null;

    /** @var array{es: string, en: string}|null */
    #[ApiProperty(schema: ['type' => ['object', 'null'], 'required' => ['es', 'en'], 'properties' => ['es' => ['type' => 'string'], 'en' => ['type' => 'string']]])]
    public ?array $summary = null;

    /** @var list<array{question: string, options: list<string>}> */
    #[ApiProperty(schema: ['type' => 'array', 'items' => [
        'type' => 'object',
        'required' => ['question', 'options'],
        'properties' => ['question' => ['type' => 'string'], 'options' => ['type' => 'array', 'items' => ['type' => 'string']]],
    ]])]
    public array $questions = [];

    /** The model couldn't be reached: the rules are only what the tapped answers say, and the free text is kept as notes. */
    public bool $fallback = false;

    public TasteImpactView $impact;

    public function __construct()
    {
        $this->impact = new TasteImpactView();
    }
}
