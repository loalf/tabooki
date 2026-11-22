<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;

final class CostCall
{
    public \DateTimeImmutable $at;

    #[ApiProperty(schema: ['type' => 'string', 'enum' => ['text', 'transcript', 'video', 'images', 'classify', 'translate', 'annotate', 'taste_profile', 'notes_fit']])]
    public string $step = '';

    public string $model = '';

    public int $inputTokens = 0;

    public int $outputTokens = 0;

    /** null when the model has no price configured. */
    public ?float $usd = null;

    public bool $ok = true;

    public ?string $error = null;

    /** The link it was about (null for classify, or once the link is deleted). */
    public ?string $sourceTitle = null;

    public ?string $sourceUrl = null;

    /** A recipe of that link, to open it. */
    #[ApiProperty(schema: ['type' => ['string', 'null'], 'format' => 'uuid'])]
    public ?string $recipeId = null;

    public function __construct()
    {
        $this->at = new \DateTimeImmutable();
    }
}
