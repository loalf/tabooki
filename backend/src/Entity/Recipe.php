<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\ExactFilter;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\QueryParameter;
use App\Enum\Diet;
use App\Enum\FoodGroup;
use App\Enum\Heaviness;
use App\Enum\Language;
use App\Enum\MealType;
use App\Enum\SourceStatus;
use App\Filter\RecipeSearchFilter;
use App\Filter\RecipeTagsFilter;
use App\Library\SearchText;
use App\Planning\RecipeFacts;
use App\Repository\RecipeRepository;
use App\State\RecipeProcessor;
use App\State\RecipeProvider;
use App\State\ReextractRecipeProcessor;
use App\Taste\Conflict;
use App\Translation\RecipeText;
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
            provider: RecipeProvider::class,
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
        new Get(provider: RecipeProvider::class),
        new Post(processor: RecipeProcessor::class),
        new Patch(provider: RecipeProvider::class, processor: RecipeProcessor::class),
        new Post(
            uriTemplate: '/recipes/{id}/extract',
            status: 202,
            description: 'Extract the recipe again from its link, with the current model, rewriting it in place (rating, favorite and planned meals stay). Follow it through source.status.',
            input: false,
            read: false,
            processor: ReextractRecipeProcessor::class,
            name: 'recipe_reextract',
        ),
        new Delete(),
    ],
    normalizationContext: ['groups' => ['recipe:read']],
    denormalizationContext: ['groups' => ['recipe:write']],
    security: "is_granted('ROLE_USER')",
)]
class Recipe implements HouseholdOwned
{
    public const int MAX_TAGS = 20;

    /** The facts someone can fix by hand. */
    public const array FACTS = ['heaviness', 'spice', 'foodGroups', 'optionalFoodGroups'];

    /** 2: the facts include each ingredient line's weight (docs/INGREDIENT-KNOWLEDGE.md). */
    private const int FACTS_VERSION = 2;

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

    /** Wanted in the next menu: the planner picks it before anything else, then sets this back to false. */
    #[ORM\Column(options: ['default' => false])]
    #[Groups(['recipe:read', 'recipe:list', 'recipe:write'])]
    private bool $planNext = false;

    /**
     * What it has against the household's likings (App\Taste\Taste::conflicts()), firmest first.
     * Not stored: RecipeProvider fills it in when the household has said what it likes.
     *
     * @var list<array<string, mixed>>|null
     */
    #[Groups(['recipe:read', 'recipe:list'])]
    #[ApiProperty(schema: ['type' => ['array', 'null'], 'items' => Conflict::API_SCHEMA])]
    private ?array $tasteConflicts = null;

    /**
     * What the text model made of the recipe against the household's free-text notes
     * (App\Taste\NotesJudge), and what it read: the notes (hash) and the recipe
     * (factsFingerprint()). Stale as soon as either changes; see notesJudgementFor().
     *
     * @var array{notes: string, recipe: string, fit: string, when: string, reason: array{es: string, en: string}|null}|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $notesJudgement = null;

    /**
     * The judgement for the household's notes as they are now, when it is good or poor.
     * Not stored: RecipeProvider fills it in.
     *
     * @var array{fit: string, when: string, reason: array{es: string, en: string}|null}|null
     */
    #[Groups(['recipe:read', 'recipe:list'])]
    #[ApiProperty(schema: ['type' => ['object', 'null'], 'required' => ['fit', 'when'], 'properties' => [
        'fit' => ['type' => 'string', 'enum' => ['good', 'poor']],
        'when' => ['type' => 'string', 'enum' => ['always', 'weekdays', 'weekends', 'lunch', 'dinner']],
        'reason' => ['type' => ['object', 'null'], 'required' => ['es', 'en'], 'properties' => ['es' => ['type' => 'string'], 'en' => ['type' => 'string']]],
    ]])]
    private ?array $notesFit = null;

    /** Last day it was on the menu, up to today; null if never. Not stored: RecipeProvider fills it in. */
    private ?\DateTimeImmutable $lastCookedOn = null;

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

    /** When it was (last) extracted; null for recipes typed by hand. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['recipe:read'])]
    private ?\DateTimeImmutable $extractedAt = null;

    /**
     * How filling it is, how spicy, and what it contains (docs/PLAN.md §6.2): read by the text
     * model (App\Planning\RecipeAnnotator) after it is added and when its title or ingredients
     * change. Null and empty until then. A field fixed by hand is never overwritten.
     */
    #[ORM\Column(enumType: Heaviness::class, length: 10, nullable: true)]
    #[Groups(['recipe:read', 'recipe:list', 'recipe:write'])]
    private ?Heaviness $heaviness = null;

    /** 0 not spicy, 1 mild, 2 hot. */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    #[Groups(['recipe:read', 'recipe:list', 'recipe:write'])]
    #[Assert\Range(min: 0, max: 2)]
    private ?int $spice = null;

    /** @var list<string> FoodGroup values of the ingredients the dish needs */
    #[ORM\Column(name: 'food_groups', type: Types::JSON, options: ['jsonb' => true, 'default' => '[]'])]
    private array $foodGroupValues = [];

    /** @var list<string> FoodGroup values only its optional ingredients bring */
    #[ORM\Column(name: 'optional_food_groups', type: Types::JSON, options: ['jsonb' => true, 'default' => '[]'])]
    private array $optionalFoodGroupValues = [];

    /** Fingerprint of the text the model read the facts from (factsFingerprint()); null if it hasn't. */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $factsFrom = null;

    /** @var list<string> facts fixed by hand (FACTS), which the model leaves alone */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '[]'])]
    private array $factsEdited = [];

    /** Title, tags and ingredients, lowercased and without accents: what the library search matches. */
    #[ORM\Column(type: Types::TEXT, options: ['default' => ''])]
    private string $searchText = '';

    /**
     * The recipe's text in the other language (App\Translation\RecipeText::toArray()), and the hash
     * of the text it was translated from. Dropped as soon as that text changes; the background
     * translation writes it again.
     *
     * @var array{from: string, text: array<mixed>}|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $translation = null;

    /** The text and its translation as they were before an edit (RecipeProvider remembers them). Not stored. */
    private ?RecipeText $textBeforeEdit = null;

    private ?RecipeText $translationBeforeEdit = null;

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
        $this->tags = self::normalizeTags($tags);
    }

    /**
     * Lowercase, single spaces, no blanks or repeats.
     *
     * @param list<string> $tags
     *
     * @return list<string>
     */
    public static function normalizeTags(array $tags): array
    {
        $normalized = [];
        foreach ($tags as $tag) {
            $tag = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $tag)));
            if ('' !== $tag && !\in_array($tag, $normalized, true)) {
                $normalized[] = $tag;
            }
        }

        return $normalized;
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
        $this->steps = self::normalizeSteps($steps);
    }

    /**
     * @param list<string> $steps
     *
     * @return list<string> trimmed, without blank ones
     */
    public static function normalizeSteps(array $steps): array
    {
        return array_values(array_filter(array_map(trim(...), $steps), static fn (string $step): bool => '' !== $step));
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

    public function isPlanNext(): bool
    {
        return $this->planNext;
    }

    public function setPlanNext(bool $planNext): void
    {
        $this->planNext = $planNext;
    }

    /** Last day it was on the menu, up to today (YYYY-MM-DD); null if never. */
    #[Groups(['recipe:read', 'recipe:list'])]
    #[ApiProperty(schema: ['type' => ['string', 'null'], 'format' => 'date'])]
    public function getLastCookedOn(): ?string
    {
        return $this->lastCookedOn?->format('Y-m-d');
    }

    public function setLastCookedOn(?\DateTimeImmutable $lastCookedOn): void
    {
        $this->lastCookedOn = $lastCookedOn;
    }

    /** @return list<array<string, mixed>>|null */
    public function getTasteConflicts(): ?array
    {
        return $this->tasteConflicts;
    }

    /** @param list<array<string, mixed>>|null $conflicts */
    public function setTasteConflicts(?array $conflicts): void
    {
        $this->tasteConflicts = $conflicts;
    }

    /** @return array{fit: string, when: string, reason: array{es: string, en: string}|null}|null */
    public function getNotesFit(): ?array
    {
        return $this->notesFit;
    }

    /** @param array{fit: string, when: string, reason: array{es: string, en: string}|null}|null $fit */
    public function setNotesFit(?array $fit): void
    {
        $this->notesFit = $fit;
    }

    /**
     * The model's judgement against these notes, if it was made for them and for the recipe as it is now.
     *
     * @return array{fit: string, when: string, reason: array{es: string, en: string}|null}|null
     */
    public function notesJudgementFor(?string $notesHash): ?array
    {
        $judgement = $this->notesJudgement;
        if (null === $notesHash || null === $judgement || $judgement['notes'] !== $notesHash || $judgement['recipe'] !== $this->factsFingerprint()) {
            return null;
        }

        $reason = $judgement['reason'];

        // (jsonb keeps object keys in its own order.)
        return ['fit' => $judgement['fit'], 'when' => $judgement['when'], 'reason' => null === $reason ? null : ['es' => $reason['es'], 'en' => $reason['en']]];
    }

    public function needsNotesJudgement(string $notesHash): bool
    {
        return null === $this->notesJudgementFor($notesHash);
    }

    /**
     * Keeps what the model said, if the recipe is still what it read ($from: its fingerprint then).
     *
     * @param array{es: string, en: string}|null $reason
     */
    public function judgeNotes(string $notesHash, string $from, string $fit, string $when, ?array $reason): bool
    {
        if ($from !== $this->factsFingerprint()) {
            return false;
        }
        $this->notesJudgement = ['notes' => $notesHash, 'recipe' => $from, 'fit' => $fit, 'when' => $when, 'reason' => $reason];

        return true;
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

    public function getExtractedAt(): ?\DateTimeImmutable
    {
        return $this->extractedAt;
    }

    public function markExtracted(string $model, float $confidence, bool $needsReview): void
    {
        $this->extractedBy = $model;
        $this->extractedAt = new \DateTimeImmutable();
        $this->confidence = $confidence;
        $this->needsReview = $needsReview;
    }

    /** The language the text was originally written in, when the text shown is a translation. Filled in by RecipeNormalizer. */
    #[Groups(['recipe:read'])]
    public function getTranslatedFrom(): ?Language
    {
        return null;
    }

    /**
     * The text in the other language, if it has been translated. Every flush drops a translation
     * whose text has changed since, so what is stored is current (reading it doesn't need the
     * ingredients, which matters for the library list).
     */
    public function getTranslation(): ?RecipeText
    {
        $text = null === $this->translation ? null : RecipeText::fromArray($this->translation['text']);

        return $text?->language === $this->language->other() ? $text : null;
    }

    /** Keeps a translation of the recipe as it is now; one made from an older text is ignored. */
    public function translate(RecipeText $translation, string $from): bool
    {
        $text = RecipeText::of($this);
        if ($from !== $text->hash() || $translation->language !== $this->language->other() || !$text->matches($translation)) {
            return false;
        }
        $this->translation = ['from' => $from, 'text' => $translation->toArray()];

        return true;
    }

    /** No translation yet, or it was made from a text that has changed since. */
    public function needsTranslation(): bool
    {
        return null === $this->getTranslation() || ($this->translation['from'] ?? null) !== RecipeText::of($this)->hash();
    }

    /** The recipe's text in a language: the translation when there is one, the original otherwise. */
    public function textIn(Language $language): RecipeText
    {
        $text = RecipeText::of($this);
        if ($language === $this->language) {
            return $text;
        }

        return $this->getTranslation() ?? $text;
    }

    public function titleIn(Language $language): string
    {
        return $language === $this->language ? $this->title : ($this->getTranslation()->title ?? $this->title);
    }

    /** @return list<string> */
    public function tagsIn(Language $language): array
    {
        return $language === $this->language ? $this->tags : ($this->getTranslation()->tags ?? $this->tags);
    }

    /** Before an edit is applied: see keepOriginalIfUntouched(). */
    public function rememberText(): void
    {
        $this->textBeforeEdit = RecipeText::of($this);
        $this->translationBeforeEdit = $this->getTranslation();
    }

    /**
     * Whoever reads the translation and saves the form sends the translated text back, in their
     * language. When they didn't change it (they only marked it reviewed, or changed the
     * servings), the recipe keeps its original text rather than becoming a translation of a
     * translation. When they did, their text is the recipe now, and the other language is
     * translated again from it.
     */
    public function keepOriginalIfUntouched(): void
    {
        $before = $this->textBeforeEdit;
        $translation = $this->translationBeforeEdit;
        if (null === $before || null === $translation || $this->language !== $translation->language) {
            return;
        }
        if (RecipeText::of($this)->equals($translation)) {
            $before->applyTo($this);
        }
    }

    public function getHeaviness(): ?Heaviness
    {
        return $this->heaviness;
    }

    public function setHeaviness(?Heaviness $heaviness): void
    {
        if ($heaviness !== $this->heaviness) {
            $this->heaviness = $heaviness;
            $this->markFactEdited('heaviness');
        }
    }

    public function getSpice(): ?int
    {
        return $this->spice;
    }

    public function setSpice(?int $spice): void
    {
        if ($spice !== $this->spice) {
            $this->spice = $spice;
            $this->markFactEdited('spice');
        }
    }

    /** @return list<FoodGroup> */
    #[Groups(['recipe:read', 'recipe:list'])]
    public function getFoodGroups(): array
    {
        return array_map(FoodGroup::from(...), $this->foodGroupValues);
    }

    /** @param list<FoodGroup> $groups */
    #[Groups(['recipe:write'])]
    public function setFoodGroups(array $groups): void
    {
        $values = self::groupValues($groups);
        if ($values !== $this->foodGroupValues) {
            $this->foodGroupValues = $values;
            $this->markFactEdited('foodGroups');
        }
    }

    /** @return list<FoodGroup> */
    #[Groups(['recipe:read'])]
    public function getOptionalFoodGroups(): array
    {
        return array_map(FoodGroup::from(...), $this->optionalFoodGroupValues);
    }

    /** @param list<FoodGroup> $groups */
    #[Groups(['recipe:write'])]
    public function setOptionalFoodGroups(array $groups): void
    {
        $values = self::groupValues($groups);
        if ($values !== $this->optionalFoodGroupValues) {
            $this->optionalFoodGroupValues = $values;
            $this->markFactEdited('optionalFoodGroups');
        }
    }

    /** The strictest diet it suits; null while nobody knows what it contains. */
    #[Groups(['recipe:read', 'recipe:list'])]
    public function getDiet(): ?Diet
    {
        return $this->hasFoodGroups() ? Diet::of($this->getFoodGroups()) : null;
    }

    /** Whether what it contains is known (read by the model or set by hand): an empty list then means "nothing to flag". */
    public function hasFoodGroups(): bool
    {
        return null !== $this->factsFrom || \in_array('foodGroups', $this->factsEdited, true);
    }

    /** @return list<string> */
    public function getFactsEdited(): array
    {
        return $this->factsEdited;
    }

    /**
     * What the facts were read for: what is read (FACTS_VERSION; raising it has every recipe read
     * again by app:annotate), from the title and ingredient lines (steps hardly change them).
     */
    public function factsFingerprint(): string
    {
        $ingredients = array_map(
            static fn (RecipeIngredient $i): array => [$i->getRawText(), $i->isOptional()],
            array_values($this->ingredients->toArray()),
        );

        return hash('xxh128', json_encode([self::FACTS_VERSION, $this->title, $ingredients], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE));
    }

    /** Never read, or read from a title or ingredients that have changed since; and something is left to fill in (a fact, or the lines' weights). */
    public function needsFacts(): bool
    {
        return $this->factsFrom !== $this->factsFingerprint() && (\count(array_diff(self::FACTS, $this->factsEdited)) > 0 || !$this->ingredients->isEmpty());
    }

    /** What the model read from the recipe as it is now (one read from an older text is ignored), except the facts fixed by hand. */
    public function applyFacts(RecipeFacts $facts, string $from): bool
    {
        if ($from !== $this->factsFingerprint()) {
            return false;
        }
        $edited = fn (string $fact): bool => \in_array($fact, $this->factsEdited, true);
        if (!$edited('heaviness')) {
            $this->heaviness = $facts->heaviness;
        }
        if (!$edited('spice')) {
            $this->spice = $facts->spice;
        }
        if (!$edited('foodGroups')) {
            $this->foodGroupValues = self::groupValues($facts->foodGroups);
        }
        if (!$edited('optionalFoodGroups')) {
            // Whatever the dish needs anyway isn't brought by its optional ingredients.
            $this->optionalFoodGroupValues = array_values(array_diff(self::groupValues($facts->optionalFoodGroups), $this->foodGroupValues));
        }
        // Lines typed by hand: what they are, so they reach the shopping list and the food catalog.
        foreach ($this->ingredients as $line) {
            $read = $facts->lines[$line->getPosition()] ?? null;
            if (null !== $read && null !== $read['key']) {
                $line->fillIn($read['key'], $read['name'], $read['quantity'], $read['unit']);
            }
        }
        $this->factsFrom = $from;

        return true;
    }

    private function markFactEdited(string $fact): void
    {
        if (!\in_array($fact, $this->factsEdited, true)) {
            $this->factsEdited[] = $fact;
        }
    }

    /**
     * @param iterable<mixed> $groups
     *
     * @return list<string>
     */
    private static function groupValues(iterable $groups): array
    {
        return array_map(static fn (FoodGroup $g): string => $g->value, FoodGroup::listFrom($groups));
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
     * recipe itself dirty), so neither the translation nor the search index can go stale.
     */
    #[ORM\PreFlush]
    public function refreshSearchText(): void
    {
        if (null !== $this->translation && $this->needsTranslation()) {
            $this->translation = null;
        }
        $translation = $this->getTranslation();

        // Both languages: whoever searches finds the recipe in theirs.
        $searchText = SearchText::normalize(implode(' ', [
            $this->title,
            ...$this->tags,
            ...array_map(static fn (RecipeIngredient $i): string => $i->getRawText(), $this->ingredients->toArray()),
            ...(null === $translation ? [] : [$translation->title, ...$translation->tags, ...array_column($translation->ingredients, 'rawText')]),
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
