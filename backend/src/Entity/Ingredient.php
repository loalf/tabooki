<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Patch;
use App\Enum\Aisle;
use App\State\IngredientProcessor;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The household's catalog of ingredients, one per canonical key ("onion", whatever the recipe
 * language), so a shopping list can merge them, name them in each viewer's language and put
 * them in the right aisle (docs/PLAN.md §7). New ones are classified by the text model
 * (ClassifyIngredientsHandler); a fix made in the app is kept for good. Each points at the shared
 * Food with the same key (nutrition, allergens…), or at another one chosen by hand.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'ingredient_household_key', columns: ['household_id', 'canonical_key'])]
#[ApiResource(
    operations: [
        new Patch(processor: IngredientProcessor::class),
    ],
    normalizationContext: ['groups' => ['ingredient:read']],
    denormalizationContext: ['groups' => ['ingredient:write']],
    security: "is_granted('ROLE_USER')",
)]
class Ingredient implements HouseholdOwned
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['ingredient:read'])]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Household $household;

    #[ORM\Column(length: 80)]
    #[Groups(['ingredient:read'])]
    private string $canonicalKey;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['ingredient:read', 'ingredient:write'])]
    #[Assert\Length(max: 100)]
    private ?string $nameEs = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['ingredient:read', 'ingredient:write'])]
    #[Assert\Length(max: 100)]
    private ?string $nameEn = null;

    #[ORM\Column(enumType: Aisle::class, length: 20, options: ['default' => 'other'])]
    #[Groups(['ingredient:read', 'ingredient:write'])]
    private Aisle $aisle = Aisle::Other;

    /** Always in the kitchen (salt, oil…): listed apart, to check rather than buy. */
    #[ORM\Column(options: ['default' => false])]
    #[Groups(['ingredient:read', 'ingredient:write'])]
    private bool $staple = false;

    /** What it is, nutritionally: the food with the same key when it was added, or another one set by hand. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    #[Groups(['ingredient:read', 'ingredient:write'])]
    private ?Food $food = null;

    /** When the text model filled in names and aisle; null while it hasn't (yet). */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $classifiedAt = null;

    /** Changed by hand: the model leaves it alone. */
    #[ORM\Column(options: ['default' => false])]
    private bool $edited = false;

    public function __construct(Household $household, string $canonicalKey)
    {
        $this->id = Uuid::v7();
        $this->household = $household;
        $this->canonicalKey = $canonicalKey;
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

    public function getCanonicalKey(): string
    {
        return $this->canonicalKey;
    }

    public function getNameEs(): ?string
    {
        return $this->nameEs;
    }

    public function setNameEs(?string $name): void
    {
        $this->nameEs = self::clean($name);
        $this->edited = true;
    }

    public function getNameEn(): ?string
    {
        return $this->nameEn;
    }

    public function setNameEn(?string $name): void
    {
        $this->nameEn = self::clean($name);
        $this->edited = true;
    }

    public function name(string $locale): ?string
    {
        return 'en' === $locale ? ($this->nameEn ?? $this->nameEs) : ($this->nameEs ?? $this->nameEn);
    }

    public function getAisle(): Aisle
    {
        return $this->aisle;
    }

    public function setAisle(Aisle $aisle): void
    {
        $this->aisle = $aisle;
        $this->edited = true;
    }

    public function isStaple(): bool
    {
        return $this->staple;
    }

    #[Groups(['ingredient:read'])]
    public function getStaple(): bool
    {
        return $this->staple;
    }

    public function setStaple(bool $staple): void
    {
        $this->staple = $staple;
        $this->edited = true;
    }

    public function getFood(): ?Food
    {
        return $this->food;
    }

    public function setFood(?Food $food): void
    {
        $this->food = $food;
    }

    public function isEdited(): bool
    {
        return $this->edited;
    }

    public function isClassified(): bool
    {
        return null !== $this->classifiedAt;
    }

    /** What the text model says, unless someone already fixed it by hand. */
    public function classify(?string $nameEs, ?string $nameEn, Aisle $aisle, bool $staple): void
    {
        $this->classifiedAt = new \DateTimeImmutable();
        if ($this->edited) {
            return;
        }
        $this->nameEs = self::clean($nameEs) ?? $this->nameEs;
        $this->nameEn = self::clean($nameEn) ?? $this->nameEn;
        $this->aisle = $aisle;
        $this->staple = $staple;
    }

    private static function clean(?string $name): ?string
    {
        $name = null === $name ? '' : trim($name);

        return '' === $name ? null : mb_substr($name, 0, 100);
    }
}
