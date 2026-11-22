<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\MealType;
use App\Enum\SwapReason;
use App\Repository\SwapFeedbackRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Why someone swapped a planned meal (docs/PLAN.md §6.3): the recipe they swapped out, as it was
 * then (how long, how filling), the meal, and the reason. Repeated reasons become suggestions
 * for the household's likings (App\Taste\SwapLearning); a dish nobody likes comes up less.
 */
#[ORM\Entity(repositoryClass: SwapFeedbackRepository::class)]
#[ORM\Index(name: 'swap_feedback_household_created', columns: ['household_id', 'created_at'])]
class SwapFeedback implements HouseholdOwned
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Household $household;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Recipe $recipe;

    /** Kept for the suggestions' examples once the recipe is gone. */
    #[ORM\Column(length: 200)]
    private string $recipeTitle;

    #[ORM\Column(enumType: SwapReason::class, length: 20)]
    private SwapReason $reason;

    /**
     * What they don't like, for a dislike: a food group, an ingredient, or the whole dish.
     *
     * @var array{kind: string, value?: string, name?: array{es: string, en: string}}|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $what;

    #[ORM\Column(enumType: MealType::class, length: 10)]
    private MealType $slot;

    #[ORM\Column]
    private bool $weekday;

    /** The recipe's total minutes then. */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $minutes;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $createdBy;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** @param array{kind: string, value?: string, name?: array{es: string, en: string}}|null $what */
    public function __construct(Recipe $recipe, SwapReason $reason, ?array $what, MealType $slot, bool $weekday, ?User $by)
    {
        $this->id = Uuid::v7();
        $this->household = $recipe->getHousehold();
        $this->recipe = $recipe;
        $this->recipeTitle = $recipe->getTitle();
        $this->reason = $reason;
        $this->what = SwapReason::Dislike === $reason ? $what : null;
        $this->slot = $slot;
        $this->weekday = $weekday;
        $this->minutes = $recipe->getTotalMinutes();
        $this->createdBy = $by;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getHousehold(): Household
    {
        return $this->household;
    }

    public function assignHousehold(Household $household): void
    {
        $this->household = $household;
    }

    public function getRecipe(): ?Recipe
    {
        return $this->recipe;
    }

    public function getRecipeTitle(): string
    {
        return $this->recipeTitle;
    }

    public function getReason(): SwapReason
    {
        return $this->reason;
    }

    /** @return array{kind: string, value?: string, name?: array{es: string, en: string}}|null */
    public function getWhat(): ?array
    {
        return $this->what;
    }

    public function getSlot(): MealType
    {
        return $this->slot;
    }

    public function isWeekday(): bool
    {
        return $this->weekday;
    }

    public function getMinutes(): ?int
    {
        return $this->minutes;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
