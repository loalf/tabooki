<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\QueryParameter;
use App\Dto\MealPlanEntryAction;
use App\Dto\MealPlanInput;
use App\Dto\MealPlanSlotInput;
use App\Dto\MealPlanTweakInput;
use App\Enum\MealType;
use App\Filter\MealPlanWeekFilter;
use App\Repository\MealPlanRepository;
use App\State\CreateMealPlanProcessor;
use App\State\MealPlanActionProcessor;
use App\Taste\TasteRules;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * A week's lunches and dinners (docs/PLAN.md §6). Weeks start on Monday. Each planned meal is an
 * entry; a meal that isn't planned (eating out) has none. Every action answers with the whole
 * plan, so the app redraws the week from one response.
 */
#[ORM\Entity(repositoryClass: MealPlanRepository::class)]
#[ORM\UniqueConstraint(name: 'meal_plan_household_week', columns: ['household_id', 'week_start'])]
#[ApiResource(
    operations: [
        new GetCollection(
            uriTemplate: '/meal-plans',
            order: ['weekStart' => 'DESC'],
            paginationEnabled: false,
            parameters: [
                'week' => new QueryParameter(
                    filter: new MealPlanWeekFilter(),
                    schema: ['type' => 'string', 'format' => 'date'],
                    description: 'Only the plan of the week containing this day (YYYY-MM-DD)',
                    castToArray: false,
                ),
            ],
        ),
        new Get(uriTemplate: '/meal-plans/{id}'),
        new Post(
            uriTemplate: '/meal-plans',
            description: 'Plan a week: the meals to fill and how, filled from the library.',
            input: MealPlanInput::class,
            processor: CreateMealPlanProcessor::class,
        ),
        new Post(
            uriTemplate: '/meal-plans/{id}/regenerate',
            status: 200,
            description: 'Pick again every meal that isn\'t locked.',
            input: false,
            read: false,
            processor: MealPlanActionProcessor::class,
            name: 'meal_plan_regenerate',
        ),
        new Post(
            uriTemplate: '/meal-plans/{id}/tweak',
            status: 200,
            description: 'Set (or clear, with null) a wish for this week only, then pick again every meal that isn\'t locked.',
            input: MealPlanTweakInput::class,
            read: false,
            processor: MealPlanActionProcessor::class,
            name: 'meal_plan_tweak',
        ),
        new Post(
            uriTemplate: '/meal-plans/{id}/entries',
            status: 200,
            description: 'Plan one more meal (and fill it).',
            input: MealPlanSlotInput::class,
            read: false,
            processor: MealPlanActionProcessor::class,
            name: 'meal_plan_add_entry',
        ),
        new Post(
            uriTemplate: '/meal-plans/{id}/entries/swap',
            status: 200,
            description: 'Pick another recipe for one meal.',
            input: MealPlanEntryAction::class,
            read: false,
            processor: MealPlanActionProcessor::class,
            name: 'meal_plan_swap_entry',
        ),
        new Post(
            uriTemplate: '/meal-plans/{id}/entries/update',
            status: 200,
            description: 'Lock or unlock a meal, change its servings, or choose its recipe.',
            input: MealPlanEntryAction::class,
            read: false,
            processor: MealPlanActionProcessor::class,
            name: 'meal_plan_update_entry',
        ),
        new Post(
            uriTemplate: '/meal-plans/{id}/entries/remove',
            status: 200,
            description: 'Unplan one meal (eating out).',
            input: MealPlanEntryAction::class,
            read: false,
            processor: MealPlanActionProcessor::class,
            name: 'meal_plan_remove_entry',
        ),
        new Delete(uriTemplate: '/meal-plans/{id}'),
    ],
    normalizationContext: ['groups' => ['meal_plan:read', 'recipe:list']],
    security: "is_granted('ROLE_USER')",
)]
class MealPlan implements HouseholdOwned
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['meal_plan:read'])]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Household $household;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $weekStart;

    /** Cook dinner for two meals: the next day's lunch is its leftovers. */
    #[ORM\Column(options: ['default' => false])]
    #[Groups(['meal_plan:read'])]
    private bool $leftovers = false;

    /** Longest recipe (prep + cook) for Monday to Friday; null for no limit. */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    #[Groups(['meal_plan:read'])]
    private ?int $weekdayMaxMinutes = null;

    /** A wish for this week only ("my mother comes: no pork"), as the household wrote it; null without one. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['meal_plan:read'])]
    private ?string $tweak = null;

    /**
     * The wish as rules on top of the household's (TasteRules::toArray()); null without a wish, or
     * when the text model couldn't read it (the week is then planned without it).
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    #[Groups(['meal_plan:read'])]
    #[ApiProperty(schema: ['type' => ['object', 'null'], 'properties' => TasteRules::API_SCHEMA['properties']])]
    private ?array $tweakRules = null;

    /** @var array{es: string, en: string}|null what the week's rules do, in a few words ("Sin cerdo, más ligera") */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    #[Groups(['meal_plan:read'])]
    #[ApiProperty(schema: ['type' => ['object', 'null'], 'required' => ['es', 'en'], 'properties' => ['es' => ['type' => 'string'], 'en' => ['type' => 'string']]])]
    private ?array $tweakLabel = null;

    /**
     * Recipes the week's rules have something against, when the wish was read: the app leaves
     * them out of its swap suggestions this week.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '[]'])]
    #[Groups(['meal_plan:read'])]
    private array $tweakAvoids = [];

    /** @var Collection<int, MealPlanEntry> */
    #[ORM\OneToMany(targetEntity: MealPlanEntry::class, mappedBy: 'plan', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $entries;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['meal_plan:read'])]
    private \DateTimeImmutable $createdAt;

    public function __construct(Household $household, \DateTimeImmutable $anyDayOfTheWeek)
    {
        $this->id = Uuid::v7();
        $this->household = $household;
        $this->weekStart = self::mondayOf($anyDayOfTheWeek);
        $this->entries = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public static function mondayOf(\DateTimeImmutable $day): \DateTimeImmutable
    {
        $day = $day->setTime(0, 0);

        return $day->modify(\sprintf('-%d days', (int) $day->format('N') - 1));
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

    public function getWeekStartDate(): \DateTimeImmutable
    {
        return $this->weekStart;
    }

    #[Groups(['meal_plan:read'])]
    #[ApiProperty(description: 'Monday of the week, YYYY-MM-DD', schema: ['type' => 'string', 'format' => 'date'])]
    public function getWeekStart(): string
    {
        return $this->weekStart->format('Y-m-d');
    }

    /** Whether a day (YYYY-MM-DD or a date) is in this plan's week. */
    public function contains(\DateTimeImmutable $day): bool
    {
        return self::mondayOf($day) == $this->weekStart;
    }

    public function hasLeftovers(): bool
    {
        return $this->leftovers;
    }

    public function setLeftovers(bool $leftovers): void
    {
        $this->leftovers = $leftovers;
    }

    public function getWeekdayMaxMinutes(): ?int
    {
        return $this->weekdayMaxMinutes;
    }

    public function setWeekdayMaxMinutes(?int $minutes): void
    {
        $this->weekdayMaxMinutes = $minutes;
    }

    public function getTweak(): ?string
    {
        return $this->tweak;
    }

    /** @return array<string, mixed>|null */
    public function getTweakRules(): ?array
    {
        return $this->tweakRules;
    }

    /** @return array{es: string, en: string}|null */
    public function getTweakLabel(): ?array
    {
        return $this->tweakLabel;
    }

    /** @return list<string> */
    public function getTweakAvoids(): array
    {
        return $this->tweakAvoids;
    }

    /** The week's rules, applied on top of the household's (none without a wish, or when it couldn't be read). */
    public function weekRules(): TasteRules
    {
        return null === $this->tweakRules ? TasteRules::none() : TasteRules::fromArray($this->tweakRules);
    }

    /**
     * @param array{es: string, en: string}|null $label
     * @param list<string>                       $avoids
     */
    public function setTweak(?string $wish, ?TasteRules $rules, ?array $label = null, array $avoids = []): void
    {
        $wish = null === $wish ? '' : trim($wish);
        $this->tweak = '' === $wish ? null : mb_substr($wish, 0, 500);
        $this->tweakRules = null === $this->tweak ? null : $rules?->toArray();
        $this->tweakLabel = null === $this->tweakRules ? null : $label;
        $this->tweakAvoids = null === $this->tweakRules ? [] : $avoids;
    }

    /** @return list<MealPlanEntry> in order: by day, lunch before dinner */
    #[Groups(['meal_plan:read'])]
    public function getEntries(): array
    {
        $entries = $this->entries->toArray();
        usort($entries, static fn (MealPlanEntry $a, MealPlanEntry $b): int => $a->sortKey() <=> $b->sortKey());

        return $entries;
    }

    public function entry(string $id): ?MealPlanEntry
    {
        foreach ($this->entries as $entry) {
            if ($entry->getId()->toRfc4122() === $id) {
                return $entry;
            }
        }

        return null;
    }

    public function entryAt(\DateTimeImmutable $date, MealType $slot): ?MealPlanEntry
    {
        foreach ($this->entries as $entry) {
            if ($entry->getDateValue() == $date->setTime(0, 0) && $entry->getSlotType() === $slot) {
                return $entry;
            }
        }

        return null;
    }

    public function addEntry(MealPlanEntry $entry): void
    {
        $this->entries->add($entry);
    }

    public function removeEntry(MealPlanEntry $entry): void
    {
        foreach ($this->entries as $other) {
            if ($other->getLeftoverOf() === $entry) {
                $other->setLeftoverOf(null);
            }
        }
        $this->entries->removeElement($entry);
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
