<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\QueryParameter;
use App\Dto\SourceInput;
use App\Dto\SourceTextInput;
use App\Enum\SourcePlatform;
use App\Enum\SourceStatus;
use App\Filter\SourceHasRecipeFilter;
use App\Repository\SourceRepository;
use App\State\CreateSourceProcessor;
use App\State\RequestExtractionProcessor;
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

/**
 * A link saved to the library (blog post, YouTube video, Instagram reel, ...).
 * Extraction (M2) turns it into one or more recipes; until then it can be turned
 * into a recipe by hand.
 */
#[ORM\Entity(repositoryClass: SourceRepository::class)]
#[ORM\UniqueConstraint(name: 'source_household_url', columns: ['household_id', 'normalized_url'])]
#[ApiResource(
    operations: [
        new GetCollection(
            order: ['createdAt' => 'DESC', 'id' => 'DESC'],
            paginationEnabled: false,
            parameters: [
                'hasRecipe' => new QueryParameter(
                    filter: new SourceHasRecipeFilter(),
                    schema: ['type' => 'boolean'],
                    description: 'Only links that have (true) or have not (false) been turned into a recipe yet',
                    nativeType: new BuiltinType(TypeIdentifier::BOOL),
                    castToNativeType: true,
                    castToArray: false,
                ),
            ],
        ),
        new Get(),
        new Post(denormalizationContext: ['groups' => ['source:create']], input: SourceInput::class, processor: CreateSourceProcessor::class),
        new Post(
            uriTemplate: '/sources/{id}/extract',
            status: 202,
            description: 'Extract the recipe again (after a failure, or once extraction is configured).',
            input: false,
            read: false,
            processor: RequestExtractionProcessor::class,
        ),
        new Post(
            uriTemplate: '/sources/{id}/text',
            status: 202,
            description: 'Give the recipe text by hand (e.g. a caption that couldn\'t be read) and extract from it.',
            denormalizationContext: ['groups' => ['source:text']],
            input: SourceTextInput::class,
            read: false,
            processor: RequestExtractionProcessor::class,
        ),
        new Patch(),
        new Delete(),
    ],
    normalizationContext: ['groups' => ['source:read']],
    denormalizationContext: ['groups' => ['source:write']],
    security: "is_granted('ROLE_USER')",
)]
class Source implements HouseholdOwned
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['source:read', 'recipe:read', 'recipe:list'])]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Household $household;

    /** Same link with tracking parameters and platform variants stripped: used to spot duplicates. */
    #[ORM\Column(length: 2048)]
    private string $normalizedUrl;

    #[ORM\Column(length: 200, nullable: true)]
    #[Groups(['source:read', 'source:write', 'recipe:read', 'recipe:list'])]
    #[Assert\Length(max: 200)]
    private ?string $title = null;

    #[ORM\Column(length: 200, nullable: true)]
    #[Groups(['source:read', 'recipe:read'])]
    private ?string $author = null;

    /** The thumbnail as published by the site (may expire, e.g. Instagram's CDN links). */
    #[ORM\Column(length: 2048, nullable: true)]
    private ?string $thumbnailUrl = null;

    /** Our own copy of the thumbnail, relative to the media directory. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $thumbnailPath = null;

    #[ORM\Column(enumType: SourceStatus::class, length: 20, options: ['default' => 'pending'])]
    #[Groups(['source:read'])]
    private SourceStatus $status = SourceStatus::Pending;

    /** For the cook, when extraction needs them or failed: "The recipe is in the comments", ... */
    #[ORM\Column(length: 500, nullable: true)]
    #[Groups(['source:read'])]
    private ?string $statusMessage = null;

    /**
     * Raw material gathered from the link (page metadata, JSON-LD, later captions and transcripts),
     * kept so recipes can be re-extracted without fetching again.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '{}'])]
    private array $evidence = [];

    /** When the link preview (title, thumbnail) was last fetched, successfully or not. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['source:read'])]
    private ?\DateTimeImmutable $metadataFetchedAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $addedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['source:read'])]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, Recipe> */
    #[ORM\OneToMany(targetEntity: Recipe::class, mappedBy: 'source')]
    private Collection $recipes;

    public function __construct(
        Household $household,
        #[ORM\Column(length: 2048)]
        #[Groups(['source:read', 'recipe:read', 'recipe:list'])]
        private string $url,
        string $normalizedUrl,
        #[ORM\Column(enumType: SourcePlatform::class, length: 20)]
        #[Groups(['source:read', 'recipe:read', 'recipe:list'])]
        private SourcePlatform $platform,
        ?User $addedBy = null,
    ) {
        $this->id = Uuid::v7();
        $this->household = $household;
        $this->normalizedUrl = $normalizedUrl;
        $this->addedBy = $addedBy;
        $this->createdAt = new \DateTimeImmutable();
        $this->recipes = new ArrayCollection();
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

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getNormalizedUrl(): string
    {
        return $this->normalizedUrl;
    }

    public function getPlatform(): SourcePlatform
    {
        return $this->platform;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): void
    {
        $title = null === $title ? null : trim($title);
        $this->title = '' === $title ? null : $title;
    }

    public function getAuthor(): ?string
    {
        return $this->author;
    }

    public function setAuthor(?string $author): void
    {
        $this->author = $author;
    }

    public function getThumbnailUrl(): ?string
    {
        return $this->thumbnailUrl;
    }

    public function setThumbnailUrl(?string $thumbnailUrl): void
    {
        $this->thumbnailUrl = $thumbnailUrl;
    }

    public function getThumbnailPath(): ?string
    {
        return $this->thumbnailPath;
    }

    public function setThumbnailPath(?string $thumbnailPath): void
    {
        $this->thumbnailPath = $thumbnailPath;
    }

    /** Image URL for the app: our cached copy when we have one, else the original. */
    #[Groups(['source:read', 'recipe:read', 'recipe:list'])]
    public function getThumbnail(): ?string
    {
        return null !== $this->thumbnailPath ? '/api/media/'.$this->thumbnailPath : $this->thumbnailUrl;
    }

    public function getStatus(): SourceStatus
    {
        return $this->status;
    }

    public function setStatus(SourceStatus $status, ?string $message = null): void
    {
        $this->status = $status;
        $this->statusMessage = null === $message ? null : mb_substr($message, 0, 500);
    }

    public function getStatusMessage(): ?string
    {
        return $this->statusMessage;
    }

    /** The caption or transcript the recipe came from, for checking it side by side. */
    #[Groups(['recipe:read'])]
    public function getOriginalText(): ?string
    {
        $evidence = $this->evidence;
        $nested = static fn (string $key, string $field): mixed => \is_array($evidence[$key] ?? null) ? ($evidence[$key][$field] ?? null) : null;
        foreach ([$evidence['user_text'] ?? null, $nested('media', 'description'), $evidence['transcript'] ?? null, $nested('metadata', 'description')] as $text) {
            if (\is_string($text) && '' !== trim($text)) {
                return mb_substr(trim($text), 0, 8000);
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function getEvidence(): array
    {
        return $this->evidence;
    }

    public function addEvidence(string $key, mixed $value): void
    {
        $this->evidence[$key] = $value;
    }

    public function getMetadataFetchedAt(): ?\DateTimeImmutable
    {
        return $this->metadataFetchedAt;
    }

    public function markMetadataFetched(): void
    {
        $this->metadataFetchedAt = new \DateTimeImmutable();
    }

    public function getAddedBy(): ?User
    {
        return $this->addedBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return Collection<int, Recipe> */
    public function getRecipes(): Collection
    {
        return $this->recipes;
    }
}
