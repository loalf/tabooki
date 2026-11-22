<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One paid LLM call: extracting a link ("text", "video") or sorting new ingredients into aisles
 * ("classify"). What it cost and whether it worked (docs/PLAN.md §5); the costs page adds them up.
 */
#[ORM\Entity]
#[ORM\Index(name: 'extraction_run_created', columns: ['created_at'])]
#[ORM\Index(name: 'extraction_run_household_created', columns: ['household_id', 'created_at'])]
class ExtractionRun
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Household $household,
        /** The link extracted; null for calls about no link (classify), or once the link is deleted. */
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(onDelete: 'SET NULL')]
        private ?Source $source,
        /** "text", "transcript" (speech to text of a video), "video" (its stills) or "classify" */
        #[ORM\Column(length: 20)]
        private string $step,
        #[ORM\Column(length: 30)]
        private string $provider,
        #[ORM\Column(length: 120)]
        private string $model,
        #[ORM\Column]
        private int $inputTokens,
        #[ORM\Column]
        private int $outputTokens,
        #[ORM\Column(nullable: true)]
        private ?float $costUsd,
        #[ORM\Column]
        private bool $ok,
        #[ORM\Column(length: 1000, nullable: true)]
        private ?string $error,
        #[ORM\Column]
        private int $durationMs,
    ) {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->error = null === $error ? null : mb_substr($error, 0, 1000);
    }

    public function getHousehold(): Household
    {
        return $this->household;
    }

    public function getSource(): ?Source
    {
        return $this->source;
    }

    public function getStep(): string
    {
        return $this->step;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getInputTokens(): int
    {
        return $this->inputTokens;
    }

    public function getOutputTokens(): int
    {
        return $this->outputTokens;
    }

    public function getCostUsd(): ?float
    {
        return $this->costUsd;
    }

    public function isOk(): bool
    {
        return $this->ok;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getDurationMs(): int
    {
        return $this->durationMs;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }
}
