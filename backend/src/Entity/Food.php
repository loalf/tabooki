<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\QueryParameter;
use App\Enum\Allergen;
use App\Enum\Diet;
use App\Enum\FoodGroup;
use App\Enum\FoodSource;
use App\Food\FoodFacts;
use App\State\FoodSearchProvider;
use App\Taste\Names;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * What a food is, shared by every household: nutrition per 100 g, allergens, food groups, spice,
 * and how much a piece or a millilitre weighs (docs/INGREDIENT-KNOWLEDGE.md). One per canonical
 * key; a household's Ingredient points at one, and can be pointed at another by hand. Filled in
 * by the text model (DescribeFoodsHandler); a fix made by hand is kept for good.
 */
#[ORM\Entity]
#[ApiResource(
    operations: [
        new Get(uriTemplate: '/foods/{id}'),
        new GetCollection(
            uriTemplate: '/foods',
            paginationEnabled: false,
            provider: FoodSearchProvider::class,
            parameters: [
                'q' => new QueryParameter(
                    schema: ['type' => 'string'],
                    description: 'Words in its key or one of its names (case and accent insensitive), to point an ingredient at it; up to 30, those used most first',
                    castToArray: false,
                ),
            ],
        ),
    ],
    normalizationContext: ['groups' => ['food:read']],
    order: ['key' => 'ASC'],
    security: "is_granted('ROLE_USER')",
)]
class Food
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['food:read', 'ingredient:read'])]
    private Uuid $id;

    #[ORM\Column(length: 80, unique: true)]
    #[Groups(['food:read', 'ingredient:read'])]
    private string $key;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['food:read', 'ingredient:read'])]
    private ?string $nameEs = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['food:read', 'ingredient:read'])]
    private ?string $nameEn = null;

    // Per 100 g. Null until described.

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    #[Groups(['food:read', 'ingredient:read'])]
    private ?float $kcal = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    #[Groups(['food:read', 'ingredient:read'])]
    private ?float $protein = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    #[Groups(['food:read', 'ingredient:read'])]
    private ?float $fat = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    #[Groups(['food:read', 'ingredient:read'])]
    private ?float $saturatedFat = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    #[Groups(['food:read', 'ingredient:read'])]
    private ?float $carbs = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    #[Groups(['food:read', 'ingredient:read'])]
    private ?float $sugar = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    #[Groups(['food:read', 'ingredient:read'])]
    private ?float $fiber = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    #[Groups(['food:read', 'ingredient:read'])]
    private ?float $salt = null;

    /** @var list<string> FoodGroup values */
    #[ORM\Column(name: 'food_groups', type: Types::JSON, options: ['jsonb' => true, 'default' => '[]'])]
    private array $foodGroupValues = [];

    /** @var list<string> Allergen values */
    #[ORM\Column(name: 'allergens', type: Types::JSON, options: ['jsonb' => true, 'default' => '[]'])]
    private array $allergenValues = [];

    /** 0 not spicy, 1 mild, 2 hot. */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    #[Groups(['food:read', 'ingredient:read'])]
    private ?int $spice = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    #[Groups(['food:read', 'ingredient:read'])]
    private ?float $gramsPerPiece = null;

    /** Grams per millilitre. */
    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    #[Groups(['food:read', 'ingredient:read'])]
    private ?float $density = null;

    #[ORM\Column(enumType: FoodSource::class, length: 10, nullable: true)]
    #[Groups(['food:read', 'ingredient:read'])]
    private ?FoodSource $source = null;

    /** When the text model filled it in; null while it hasn't (yet). */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $describedAt = null;

    /** Changed by hand: the model leaves it alone. */
    #[ORM\Column(options: ['default' => false])]
    private bool $edited = false;

    /** @var Collection<int, FoodSubstitution> best first */
    #[ORM\OneToMany(targetEntity: FoodSubstitution::class, mappedBy: 'food')]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $substitutions;

    /** When the text model was asked what can stand in for it (FoodSubstitution); null while it hasn't. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $substitutesAt = null;

    public function __construct(string $key)
    {
        $this->id = Uuid::v7();
        $this->key = $key;
        $this->substitutions = new ArrayCollection();
    }

    /**
     * What can stand in for it, best first.
     *
     * @return list<array{key: string, name: array{es: string, en: string}, ratio: float, note: array{es: string, en: string}|null}>
     */
    #[Groups(['food:read', 'ingredient:read'])]
    #[ApiProperty(schema: ['type' => 'array', 'items' => [
        'type' => 'object',
        'required' => ['key', 'name', 'ratio', 'note'],
        'properties' => [
            'key' => ['type' => 'string'],
            'name' => Names::API_SCHEMA,
            'ratio' => ['type' => 'number'],
            'note' => ['anyOf' => [Names::API_SCHEMA, ['type' => 'null']]],
        ],
    ]])]
    public function getSubstitutes(): array
    {
        return array_values(array_map(static fn (FoodSubstitution $s): array => $s->toArray(), $this->substitutions->toArray()));
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getNameEs(): ?string
    {
        return $this->nameEs;
    }

    public function getNameEn(): ?string
    {
        return $this->nameEn;
    }

    public function name(string $locale): ?string
    {
        return 'en' === $locale ? ($this->nameEn ?? $this->nameEs) : ($this->nameEs ?? $this->nameEn);
    }

    public function getKcal(): ?float
    {
        return $this->kcal;
    }

    public function getProtein(): ?float
    {
        return $this->protein;
    }

    public function getFat(): ?float
    {
        return $this->fat;
    }

    public function getSaturatedFat(): ?float
    {
        return $this->saturatedFat;
    }

    public function getCarbs(): ?float
    {
        return $this->carbs;
    }

    public function getSugar(): ?float
    {
        return $this->sugar;
    }

    public function getFiber(): ?float
    {
        return $this->fiber;
    }

    public function getSalt(): ?float
    {
        return $this->salt;
    }

    /** @return list<FoodGroup> */
    #[Groups(['food:read', 'ingredient:read'])]
    public function getFoodGroups(): array
    {
        return array_map(FoodGroup::from(...), $this->foodGroupValues);
    }

    /** @return list<Allergen> */
    #[Groups(['food:read', 'ingredient:read'])]
    public function getAllergens(): array
    {
        return array_map(Allergen::from(...), $this->allergenValues);
    }

    /** The strictest diet it suits (vegan, vegetarian, pescatarian); null for meat, or while undescribed. */
    #[Groups(['food:read', 'ingredient:read'])]
    public function getDiet(): ?Diet
    {
        return $this->isDescribed() ? Diet::of($this->getFoodGroups()) : null;
    }

    public function getSpice(): ?int
    {
        return $this->spice;
    }

    public function getGramsPerPiece(): ?float
    {
        return $this->gramsPerPiece;
    }

    public function getDensity(): ?float
    {
        return $this->density;
    }

    public function getSource(): ?FoodSource
    {
        return $this->source;
    }

    #[Groups(['food:read', 'ingredient:read'])]
    public function isDescribed(): bool
    {
        return null !== $this->describedAt;
    }

    public function substitutesAsked(): void
    {
        $this->substitutesAt = new \DateTimeImmutable();
    }

    public function isEdited(): bool
    {
        return $this->edited;
    }

    /** What the text model says, unless someone already fixed it by hand. */
    public function describe(FoodFacts $facts): void
    {
        $this->describedAt = new \DateTimeImmutable();
        if ($this->edited) {
            return;
        }
        $this->apply($facts);
        $this->source = FoodSource::Llm;
    }

    /** A fix made by hand (or figures from a better source): the model won't overwrite it. */
    public function correct(FoodFacts $facts, FoodSource $source = FoodSource::Manual): void
    {
        $this->describedAt ??= new \DateTimeImmutable();
        $this->apply($facts);
        $this->source = $source;
        $this->edited = true;
    }

    private function apply(FoodFacts $facts): void
    {
        $this->nameEs = self::clean($facts->nameEs) ?? $this->nameEs;
        $this->nameEn = self::clean($facts->nameEn) ?? $this->nameEn;
        $this->kcal = self::between($facts->kcal, 0, 900);
        $this->protein = self::between($facts->protein, 0, 100);
        $this->fat = self::between($facts->fat, 0, 100);
        $this->saturatedFat = min(self::between($facts->saturatedFat, 0, 100), $this->fat);
        $this->carbs = self::between($facts->carbs, 0, 100);
        $this->sugar = min(self::between($facts->sugar, 0, 100), $this->carbs);
        $this->fiber = self::between($facts->fiber, 0, 100);
        $this->salt = self::between($facts->salt, 0, 100);
        $this->foodGroupValues = array_map(static fn (FoodGroup $g): string => $g->value, FoodGroup::listFrom($facts->foodGroups));
        $this->allergenValues = array_map(static fn (Allergen $a): string => $a->value, Allergen::listFrom($facts->allergens));
        $this->spice = max(0, min(2, $facts->spice));
        $this->gramsPerPiece = null === $facts->gramsPerPiece || $facts->gramsPerPiece <= 0 ? null : min($facts->gramsPerPiece, 20000.0);
        $this->density = null === $facts->density || $facts->density <= 0 ? null : min($facts->density, 5.0);
    }

    private static function between(float $value, float $min, float $max): float
    {
        return round(max($min, min($max, $value)), 2);
    }

    private static function clean(?string $name): ?string
    {
        $name = null === $name ? '' : trim($name);

        return '' === $name ? null : mb_substr($name, 0, 100);
    }
}
