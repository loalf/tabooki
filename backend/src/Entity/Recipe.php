<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\ExactFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\QueryParameter;
use App\Enum\Language;
use App\Enum\MealType;
use App\Enum\SourceStatus;
use App\Filter\RecipeSearchFilter;
use App\Filter\RecipeTagsFilter;
use App\Library\SearchText;
use App\Repository\RecipeRepository;
use App\State\HouseholdOwnedProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\TypeInfo\Type\BuiltinType;
use Symfony\Component\TypeInfo\TypeIdentifier;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: RecipeRepository::class)]
#[ORM\Index(name: 'recipe_household_created', columns: ['household_id', 'created_at'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    operations: [
        // The whole library in one response (household scale), in a light shape for the grid.
        new GetCollection(
            normalizationContext: ['groups' => ['recipe:list']],
            order: ['createdAt' => 'DESC', 'id' => 'DESC'],
            paginationEnabled: false,
            parameters: [
                'q' => new QueryParameter(
                    filter: new RecipeSearchFilter(),
                    schema: ['type' => 'string'],
                    description: 'Words to look for in the title, tags and ingredients (case and accent insensitive)',
                    castToArray: false,
                ),
                'tags' => new QueryParameter(
                    filter: new RecipeTagsFilter(),
                    schema: ['type' => 'string'],
                    description: 'Only recipes that have all of these tags',
                    castToArray: true,
                ),
                'needsReview' => new QueryParameter(
                    filter: new ExactFilter(),
                    property: 'needsReview',
                    schema: ['type' => 'boolean'],
                    description: 'Only extracted recipes nobody has checked yet',
                    nativeType: new BuiltinType(TypeIdentifier::BOOL),
                    castToNativeType: true,
                    castToArray: false,
                ),
                'favorite' => new QueryParameter(
                    filter: new ExactFilter(),
                    property: 'favorite',
                    schema: ['type' => 'boolean'],
                    description: 'Only favorites (true) or only non-favorites (false)',
                    nativeType: new BuiltinType(TypeIdentifier::BOOL),
                    castToNativeType: true,
                    castToArray: false,
                ),
            ],
        ),
        new Get(),
        new Post(processor: HouseholdOwnedProcessor::class),
        new Patch(processor: HouseholdOwnedProcessor::class),
        new Delete(),
    ],
    normalizationContext: ['groups' => ['recipe:read']],
    denormalizationContext: ['groups' => ['recipe:write']],
    security: "is_granted('ROLE_USER')",
)]
class Recipe implements HouseholdOwned
{
    public const int MAX_TAGS = 20;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['recipe:read', 'recipe:list'])]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Household $household;

    /** The link this recipe comes from; null for recipes typed in by hand. */
    #[ORM\ManyToOne(inversedBy: 'recipes')]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    #[Groups(['recipe:read', 'recipe:list', 'recipe:write'])]
    private ?Source $source = null;

    #[ORM\Column(length: 200)]
    #[Groups(['recipe:read', 'recipe:list', 'recipe:write'])]
    #[Assert\NotBlank]
    #[Assert\Length(max: 200)]
    private string $title = '';

    #[ORM\Column(enumType: Language::class, length: 2)]
    #[Groups(['recipe:read', 'recipe:list', 'recipe:write'])]
    private Language $language = Language::Es;

    #[ORM\Column(type: Types::SMALLINT)]
    #[Groups(['recipe:read', 'recipe:list', 'recipe:write'])]
    #[Assert\Range(min: 1, max: 50)]
    private int $servings = Household::DEFAULT_SERVINGS;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    #[Groups(['recipe:read', 'recipe:write'])]
    #[Assert\Range(min: 0, max: 1440)]
    private ?int $prepMinutes = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    #[Groups(['recipe:read', 'recipe:write'])]
    #[Assert\Range(min: 0, max: 1440)]
    private ?int $cookMinutes = null;

    /** @var list<string> MealType values */
    #[ORM\Column(name: 'meal_types', type: Types::JSON, options: ['jsonb' => true])]
    private array $mealTypeValues = [MealType::Lunch->value, MealType::Dinner->value];

    /** @var list<string> lowercase, unique */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    #[Groups(['recipe:read', 'recipe:list', 'recipe:write'])]
    #[Assert\Count(max: self::MAX_TAGS)]
    #[Assert\All([new Assert\Length(max: 40)])]
    private array $tags = [];

    /** @var Collection<int, RecipeIngredient> */
    #[ORM\OneToMany(targetEntity: RecipeIngredient::class, mappedBy: 'recipe', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => \SortDirection::Ascending])]
    #[Assert\Valid]
    #[Assert\Count(max: 150)]
    private Collection $ingredients;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    #[Groups(['recipe:read', 'recipe:write'])]
    #[Assert\Count(max: 100)]
    #[Assert\All([new Assert\NotBlank(), new Assert\Length(max: 4000)])]
    private array $steps = [];

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['recipe:read', 'recipe:write'])]
    #[Assert\Length(max: 10000)]
    private ?string $notes = null;

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['recipe:read', 'recipe:list', 'recipe:write'])]
    private bool $favorite = false;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    #[Groups(['recipe:read', 'recipe:list', 'recipe:write'])]
    #[Assert\Range(min: 1, max: 5)]
    private ?int $rating = null;

    /** Extracted automatically and not checked by anyone yet (some quantities or steps may be missing). */
    #[ORM\Column(options: ['default' => false])]
    #[Groups(['recipe:read', 'recipe:list', 'recipe:write'])]
    private bool $needsReview = false;

    /** The extraction's own estimate (0-1); null for recipes typed by hand. */
    #[ORM\Column(nullable: true)]
    #[Groups(['recipe:read'])]
    private ?float $confidence = null;

    /** Model that extracted it; null for recipes typed by hand. */
    #[ORM\Column(length: 120, nullable: true)]
    #[Groups(['recipe:read'])]
    private ?string $extractedBy = null;

    /** Title, tags and ingredients, lowercased and without accents: what the library search matches. */
    #[ORM\Column(type: Types::TEXT, options: ['default' => ''])]
    private string $searchText = '';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['recipe:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['recipe:read'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->ingredients = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
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

    public function getSource(): ?Source
    {
        return $this->source;
    }

    public function setSource(?Source $source): void
    {
        $this->source = $source;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = trim($title);
    }

    public function getLanguage(): Language
    {
        return $this->language;
    }

    public function setLanguage(Language $language): void
    {
        $this->language = $language;
    }

    public function getServings(): int
    {
        return $this->servings;
    }

    public function setServings(int $servings): void
    {
        $this->servings = $servings;
    }

    public function getPrepMinutes(): ?int
    {
        return $this->prepMinutes;
    }

    public function setPrepMinutes(?int $prepMinutes): void
    {
        $this->prepMinutes = $prepMinutes;
    }

    public function getCookMinutes(): ?int
    {
        return $this->cookMinutes;
    }

    public function setCookMinutes(?int $cookMinutes): void
    {
        $this->cookMinutes = $cookMinutes;
    }

    #[Groups(['recipe:read', 'recipe:list'])]
    public function getTotalMinutes(): ?int
    {
        if (null === $this->prepMinutes && null === $this->cookMinutes) {
            return null;
        }

        return ($this->prepMinutes ?? 0) + ($this->cookMinutes ?? 0);
    }

    /** @return list<MealType> */
    #[Groups(['recipe:read', 'recipe:list'])]
    public function getMealTypes(): array
    {
        return array_map(MealType::from(...), $this->mealTypeValues);
    }

    /** @param list<MealType> $mealTypes */
    #[Groups(['recipe:write'])]
    public function setMealTypes(array $mealTypes): void
    {
        $values = array_map(static fn (MealType $type): string => $type->value, $mealTypes);
        // Keep a stable order (lunch, dinner) whatever order the client sends.
        $this->mealTypeValues = array_values(array_filter(
            array_map(static fn (MealType $type): string => $type->value, MealType::cases()),
            static fn (string $value): bool => \in_array($value, $values, true),
        ));
    }

    /** @return list<string> */
    public function getTags(): array
    {
        return $this->tags;
    }

    /** @param list<string> $tags */
    public function setTags(array $tags): void
    {
        $normalized = [];
        foreach ($tags as $tag) {
            $tag = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $tag)));
            if ('' !== $tag && !\in_array($tag, $normalized, true)) {
                $normalized[] = $tag;
            }
        }
        $this->tags = $normalized;
    }

    /** @return list<RecipeIngredient> */
    #[Groups(['recipe:read'])]
    public function getIngredients(): array
    {
        return array_values($this->ingredients->toArray());
    }

    /**
     * Replaces the whole ingredient list (the API always sends it complete), keeping the given order.
     *
     * @param list<RecipeIngredient> $ingredients
     */
    #[Groups(['recipe:write'])]
    public function setIngredients(array $ingredients): void
    {
        $this->ingredients->clear();
        foreach ($ingredients as $position => $ingredient) {
            $ingredient->attachTo($this, $position);
            $this->ingredients->add($ingredient);
        }
    }

    /** @return list<string> */
    public function getSteps(): array
    {
        return $this->steps;
    }

    /** @param list<string> $steps */
    public function setSteps(array $steps): void
    {
        $this->steps = array_values(array_filter(array_map(trim(...), $steps), static fn (string $step): bool => '' !== $step));
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): void
    {
        $notes = null === $notes ? null : trim($notes);
        $this->notes = '' === $notes ? null : $notes;
    }

    public function isFavorite(): bool
    {
        return $this->favorite;
    }

    public function setFavorite(bool $favorite): void
    {
        $this->favorite = $favorite;
    }

    public function getRating(): ?int
    {
        return $this->rating;
    }

    public function setRating(?int $rating): void
    {
        $this->rating = $rating;
    }

    public function getNeedsReview(): bool
    {
        return $this->needsReview;
    }

    /** Marking the last unchecked recipe of a link as reviewed makes the link "ready". */
    public function setNeedsReview(bool $needsReview): void
    {
        $this->needsReview = $needsReview;
        $source = $this->source;
        if (!$needsReview && null !== $source && SourceStatus::NeedsReview === $source->getStatus()) {
            $pending = $source->getRecipes()->filter(fn (Recipe $r): bool => $r !== $this && $r->getNeedsReview());
            if ($pending->isEmpty()) {
                $source->setStatus(SourceStatus::Ready);
            }
        }
    }

    public function getConfidence(): ?float
    {
        return $this->confidence;
    }

    public function getExtractedBy(): ?string
    {
        return $this->extractedBy;
    }

    public function markExtracted(string $model, float $confidence, bool $needsReview): void
    {
        $this->extractedBy = $model;
        $this->confidence = $confidence;
        $this->needsReview = $needsReview;
    }

    public function getSearchText(): string
    {
        return $this->searchText;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Runs on every flush (also for changes made only to ingredients, which don't make the
     * recipe itself dirty), so the search index can never go stale.
     */
    #[ORM\PreFlush]
    public function refreshSearchText(): void
    {
        $searchText = SearchText::normalize(implode(' ', [
            $this->title,
            ...$this->tags,
            ...array_map(static fn (RecipeIngredient $i): string => $i->getRawText(), $this->ingredients->toArray()),
        ]));

        if ($searchText !== $this->searchText) {
            $this->searchText = $searchText;
        }
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
