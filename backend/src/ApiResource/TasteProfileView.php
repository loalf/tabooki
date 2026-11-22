<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Dto\TasteDraftInput;
use App\Dto\TasteProfileInput;
use App\Dto\TasteRulesInput;
use App\State\TasteProfileProcessor;
use App\State\TasteProfileProvider;
use App\Taste\TasteImpactView;
use App\Taste\TasteRules;

/**
 * What the household likes (docs/PLAN.md §6.1): the questionnaire's answers, the rules the
 * planner follows, the notes and summary, and what the rules do to the library. 404 until the
 * household has said.
 */
#[ApiResource(
    shortName: 'TasteProfile',
    operations: [
        new Get(uriTemplate: '/taste-profile', uriVariables: [], provider: TasteProfileProvider::class),
        new Put(
            uriTemplate: '/taste-profile',
            uriVariables: [],
            description: 'Save the likings the household confirmed on the review screen. Plans already made stay as they are.',
            input: TasteProfileInput::class,
            read: false,
            processor: TasteProfileProcessor::class,
            name: 'taste_profile_save',
        ),
        new Delete(uriTemplate: '/taste-profile', uriVariables: [], read: false, processor: TasteProfileProcessor::class, name: 'taste_profile_delete'),
        new Post(
            uriTemplate: '/taste-profile/draft',
            uriVariables: [],
            status: 200,
            description: 'Turn the questionnaire into rules, notes, a summary and maybe follow-up questions (the text model; nothing is saved).',
            input: TasteDraftInput::class,
            output: TasteDraftView::class,
            read: false,
            processor: TasteProfileProcessor::class,
            name: 'taste_profile_draft',
        ),
        new Post(
            uriTemplate: '/taste-profile/impact',
            uriVariables: [],
            status: 200,
            description: 'What some rules would do to the library (after removing one on the review screen).',
            input: TasteRulesInput::class,
            output: TasteImpactView::class,
            read: false,
            processor: TasteProfileProcessor::class,
            name: 'taste_profile_impact',
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
final class TasteProfileView
{
    #[ApiProperty(identifier: true)]
    public string $id = '';

    /** @var array<string, mixed> as the questionnaire sent them, to show them again */
    #[ApiProperty(schema: ['type' => 'object', 'additionalProperties' => true])]
    public array $answers = [];

    /** @var array<string, mixed> */
    #[ApiProperty(schema: TasteRules::API_SCHEMA)]
    public array $rules = [];

    public ?string $notes = null;

    /** @var array{es: string, en: string}|null */
    #[ApiProperty(schema: ['type' => ['object', 'null'], 'required' => ['es', 'en'], 'properties' => ['es' => ['type' => 'string'], 'en' => ['type' => 'string']]])]
    public ?array $summary = null;

    /** Being written again in the background (the likings changed since the last one). */
    public bool $summaryPending = false;

    /** Who last changed it (display name). */
    public ?string $updatedBy = null;

    public ?\DateTimeImmutable $updatedAt = null;

    public TasteImpactView $impact;

    public function __construct()
    {
        $this->impact = new TasteImpactView();
    }
}
