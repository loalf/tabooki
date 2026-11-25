<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use App\Enum\MealType;
use App\Taste\Conflict;
use App\Taste\Names;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/** One planned lunch or dinner of a MealPlan. */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'meal_plan_entry_slot', columns: ['plan_id', 'date', 'slot'])]
#[ORM\Index(name: 'meal_plan_entry_date', columns: ['date'])]
class MealPlanEntry
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['meal_plan:read'])]
    private Uuid $id;

    /** null when the library has nothing that fits (or the recipe was deleted). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    #[Groups(['meal_plan:read'])]
    private ?Recipe $recipe = null;

    #[ORM\Column(type: Types::SMALLINT)]
    #[Groups(['meal_plan:read'])]
    private int $servings;

    /** Kept as it is when the plan is regenerated. */
    #[ORM\Column(options: ['default' => false])]
    #[Groups(['meal_plan:read'])]
    private bool $locked = false;

    /** How it turned out this time (1-5), given after eating it; also becomes the recipe's rating. */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    #[Groups(['meal_plan:read'])]
    private ?int $rating = null;

    /**
     * Whether it was cooked: true once cook mode reaches the end or it's rated, false when the
     * household says it wasn't ("No lo hicimos"), null while nobody has said.
     */
    #[ORM\Column(nullable: true)]
    #[Groups(['meal_plan:read'])]
    #[ApiProperty(description: 'true: cooked (finished in cook mode, or rated); false: it wasn\'t; null: nobody has said', schema: ['type' => ['boolean', 'null']])]
    private ?bool $cooked = null;

    /**
     * Why the planner chose this recipe, or which of the household's likings it had to give up
     * on (Planner). Codes: asked, heaviness {value}, shares {names}, notesFit {names},
     * notesMisfit {names}, overTime, repeat,
     * relaxedWeekly, relaxedAvoid {conflicts}, nothingAllowed, breaks {conflicts} (chosen by hand).
     *
     * @var list<array<string, mixed>>
     */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '[]'])]
    #[Groups(['meal_plan:read'])]
    #[ApiProperty(schema: [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'required' => ['code'],
            'properties' => [
                'code' => ['type' => 'string', 'enum' => ['asked', 'heaviness', 'shares', 'notesFit', 'notesMisfit', 'overTime', 'repeat', 'relaxedWeekly', 'relaxedAvoid', 'nothingAllowed', 'breaks']],
                'value' => ['type' => 'string'],
                'names' => ['type' => 'array', 'items' => Names::API_SCHEMA],
                'conflicts' => ['type' => 'array', 'items' => Conflict::API_SCHEMA],
            ],
        ],
    ])]
    private array $reasons = [];

    /** This meal is what was left of another one (cook once, eat twice). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?self $leftoverOf = null;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'entries')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private MealPlan $plan,
        #[ORM\Column(type: Types::DATE_IMMUTABLE)]
        private \DateTimeImmutable $date,
        #[ORM\Column(enumType: MealType::class, length: 10)]
        private MealType $slot,
        int $servings,
    ) {
        $this->id = Uuid::v7();
        $this->date = $date->setTime(0, 0);
        $this->servings = $servings;
    }

    /** A meal taken off the menu, back with the id it had (the undo). */
    public static function restored(Uuid $id, MealPlan $plan, \DateTimeImmutable $date, MealType $slot, int $servings): self
    {
        $entry = new self($plan, $date, $slot, $servings);
        $entry->id = $id;

        return $entry;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getPlan(): MealPlan
    {
        return $this->plan;
    }

    public function getDateValue(): \DateTimeImmutable
    {
        return $this->date;
    }

    #[Groups(['meal_plan:read'])]
    #[ApiProperty(description: 'YYYY-MM-DD', schema: ['type' => 'string', 'format' => 'date'])]
    public function getDate(): string
    {
        return $this->date->format('Y-m-d');
    }

    public function getSlotType(): MealType
    {
        return $this->slot;
    }

    #[Groups(['meal_plan:read'])]
    #[ApiProperty(schema: ['type' => 'string', 'enum' => ['lunch', 'dinner']])]
    public function getSlot(): string
    {
        return $this->slot->value;
    }

    /** Monday to Friday. */
    public function isWeekday(): bool
    {
        return (int) $this->date->format('N') <= 5;
    }

    public function sortKey(): string
    {
        return $this->date->format('Y-m-d').(MealType::Lunch === $this->slot ? '0' : '1');
    }

    public function getRecipe(): ?Recipe
    {
        return $this->recipe;
    }

    public function setRecipe(?Recipe $recipe): void
    {
        if ($recipe !== $this->recipe) {
            $this->rating = null;
            $this->cooked = null;
        }
        $this->recipe = $recipe;
    }

    public function getServings(): int
    {
        return $this->servings;
    }

    public function setServings(int $servings): void
    {
        $this->servings = $servings;
    }

    public function isLocked(): bool
    {
        return $this->locked;
    }

    #[Groups(['meal_plan:read'])]
    public function getLocked(): bool
    {
        return $this->locked;
    }

    public function setLocked(bool $locked): void
    {
        $this->locked = $locked;
    }

    public function getRating(): ?int
    {
        return $this->rating;
    }

    /**
     * Rating a meal rates its recipe too (the latest meal is the best guide for the planner), and
     * locks it: it was eaten, regenerating the week mustn't rewrite it.
     */
    public function rate(int $rating): void
    {
        $this->rating = $rating;
        $this->cooked = true;
        $this->locked = true;
        $this->recipe?->setRating($rating);
    }

    /** Back as it was before being taken off the menu: the recipe already has this rating. */
    public function restoreRating(?int $rating): void
    {
        $this->rating = $rating;
    }

    public function getCooked(): ?bool
    {
        return $this->cooked;
    }

    /** Cooked or not is what happened: like a rating, it locks the meal so regenerating keeps it. */
    public function setCooked(?bool $cooked): void
    {
        $this->cooked = $cooked;
        if (null !== $cooked) {
            $this->locked = true;
        }
    }

    /** @return list<array<string, mixed>> */
    public function getReasons(): array
    {
        return $this->reasons;
    }

    /** @param list<array<string, mixed>> $reasons */
    public function setReasons(array $reasons): void
    {
        $this->reasons = $reasons;
    }

    public function getLeftoverOf(): ?self
    {
        return $this->leftoverOf;
    }

    public function setLeftoverOf(?self $entry): void
    {
        $this->leftoverOf = $entry;
    }

    #[Groups(['meal_plan:read'])]
    #[ApiProperty(description: 'Id of the meal this one is the leftovers of', schema: ['type' => ['string', 'null'], 'format' => 'uuid'])]
    public function getLeftoverOfId(): ?string
    {
        return $this->leftoverOf?->getId()->toRfc4122();
    }
}
