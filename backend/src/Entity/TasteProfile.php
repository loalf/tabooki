<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TasteProfileRepository;
use App\Taste\TasteRules;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * What a household likes (docs/PLAN.md §6.1): the questionnaire's answers (to show them again),
 * the rules the planner applies, wishes no rule can say, and a summary to read. Served by
 * App\ApiResource\TasteProfileView.
 */
#[ORM\Entity(repositoryClass: TasteProfileRepository::class)]
class TasteProfile implements HouseholdOwned
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private Household $household;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $answers = [];

    /** @var array<string, mixed> TasteRules::toArray() */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $rules = [];

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    /** @var array{es: string, en: string}|null */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $summary = null;

    /** @var list<string> suggestions (App\Taste\SwapLearning) the household turned down or took: not offered again */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '[]'])]
    private array $dismissedSuggestions = [];

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $updatedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Household $household)
    {
        $this->id = Uuid::v7();
        $this->household = $household;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getHousehold(): Household
    {
        return $this->household;
    }

    public function assignHousehold(Household $household): void
    {
        $this->household = $household;
    }

    /** @return array<string, mixed> */
    public function getAnswers(): array
    {
        return $this->answers;
    }

    public function getRules(): TasteRules
    {
        return TasteRules::fromArray($this->rules);
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    /** @return array{es: string, en: string}|null */
    public function getSummary(): ?array
    {
        return $this->summary;
    }

    public function getUpdatedBy(): ?User
    {
        return $this->updatedBy;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** @return list<string> */
    public function getDismissedSuggestions(): array
    {
        return $this->dismissedSuggestions;
    }

    public function dismissSuggestion(string $key): void
    {
        if (!\in_array($key, $this->dismissedSuggestions, true)) {
            $this->dismissedSuggestions[] = $key;
        }
    }

    /** A suggestion taken: the new rules or notes. The summary was written for the old ones, so it goes. */
    public function adopt(TasteRules $rules, ?string $notes, ?User $by): void
    {
        $this->update($this->answers, $rules, $notes, null, $by);
    }

    /**
     * @param array<string, mixed>               $answers
     * @param array{es: string, en: string}|null $summary
     */
    public function update(array $answers, TasteRules $rules, ?string $notes, ?array $summary, ?User $by): void
    {
        $this->answers = $answers;
        $this->rules = $rules->toArray();
        $notes = null === $notes ? '' : trim($notes);
        $this->notes = '' === $notes ? null : mb_substr($notes, 0, 2000);
        $this->summary = $summary;
        $this->updatedBy = $by;
        $this->updatedAt = new \DateTimeImmutable();
    }
}
