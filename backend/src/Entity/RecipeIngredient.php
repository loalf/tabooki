<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\GramsSource;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One ingredient line of a recipe. `rawText` is the line exactly as written ("200 g de harina");
 * the structured fields (quantity, unit, name) are filled by extraction from M2 on and are
 * what the shopping list merges. `grams` is what the line weighs, for nutrition
 * (docs/INGREDIENT-KNOWLEDGE.md, phase 2), and `gramsSource` says how it was worked out.
 */
#[ORM\Entity]
#[ORM\Index(name: 'recipe_ingredient_recipe_position', columns: ['recipe_id', 'position'])]
class RecipeIngredient
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(inversedBy: 'ingredients')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Recipe $recipe;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $position = 0;

    /** Sub-heading the line belongs to ("For the sauce"), if the recipe has groups. */
    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['recipe:read', 'recipe:write'])]
    #[Assert\Length(max: 100)]
    private ?string $groupName = null;

    #[ORM\Column(length: 255)]
    #[Groups(['recipe:read', 'recipe:write'])]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $rawText = '';

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    #[Groups(['recipe:read', 'recipe:write'])]
    #[Assert\PositiveOrZero]
    private ?float $quantity = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[Groups(['recipe:read', 'recipe:write'])]
    #[Assert\Length(max: 20)]
    private ?string $unit = null;

    #[ORM\Column(length: 150, nullable: true)]
    #[Groups(['recipe:read', 'recipe:write'])]
    #[Assert\Length(max: 150)]
    private ?string $name = null;

    #[ORM\Column(length: 150, nullable: true)]
    #[Groups(['recipe:read', 'recipe:write'])]
    #[Assert\Length(max: 150)]
    private ?string $prep = null;

    /** Language-independent key ("onion" for "cebolla") the shopping list merges on. */
    #[ORM\Column(length: 80, nullable: true)]
    #[Groups(['recipe:read', 'recipe:write'])]
    #[Assert\Length(max: 80)]
    private ?string $canonicalKey = null;

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['recipe:read', 'recipe:write'])]
    private bool $optional = false;

    /** The whole line's weight in grams, for the recipe's servings; null while unknown. */
    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    #[Groups(['recipe:read', 'recipe:write'])]
    #[Assert\Range(min: 0, max: 100000)]
    private ?float $grams = null;

    #[ORM\Column(enumType: GramsSource::class, length: 10, nullable: true)]
    #[Groups(['recipe:read', 'recipe:write'])]
    private ?GramsSource $gramsSource = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
    }

    public function attachTo(Recipe $recipe, int $position): void
    {
        $this->recipe = $recipe;
        $this->position = $position;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getRecipe(): Recipe
    {
        return $this->recipe;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getGroupName(): ?string
    {
        return $this->groupName;
    }

    public function setGroupName(?string $groupName): void
    {
        $groupName = null === $groupName ? null : trim($groupName);
        $this->groupName = '' === $groupName ? null : $groupName;
    }

    public function getRawText(): string
    {
        return $this->rawText;
    }

    public function setRawText(string $rawText): void
    {
        $this->rawText = trim($rawText);
    }

    public function getQuantity(): ?float
    {
        return $this->quantity;
    }

    public function setQuantity(?float $quantity): void
    {
        $this->quantity = $quantity;
    }

    public function getUnit(): ?string
    {
        return $this->unit;
    }

    public function setUnit(?string $unit): void
    {
        $this->unit = $unit;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): void
    {
        $this->name = $name;
    }

    public function getPrep(): ?string
    {
        return $this->prep;
    }

    public function setPrep(?string $prep): void
    {
        $this->prep = $prep;
    }

    public function getCanonicalKey(): ?string
    {
        return $this->canonicalKey;
    }

    public function setCanonicalKey(?string $canonicalKey): void
    {
        $this->canonicalKey = $canonicalKey;
    }

    public function isOptional(): bool
    {
        return $this->optional;
    }

    public function setOptional(bool $optional): void
    {
        $this->optional = $optional;
    }

    public function getGrams(): ?float
    {
        return $this->grams;
    }

    /** As sent by the app: kept as it was (a line that didn't change), or a weight typed in (no source, or "manual"). */
    public function setGrams(?float $grams): void
    {
        $this->grams = null === $grams ? null : round($grams, 1);
    }

    public function getGramsSource(): ?GramsSource
    {
        return $this->gramsSource;
    }

    public function setGramsSource(?GramsSource $source): void
    {
        $this->gramsSource = $source;
    }

    /** What RecipeWeigher worked out. */
    public function weigh(?float $grams, ?GramsSource $source): void
    {
        $this->grams = null === $grams ? null : round($grams, 1);
        $this->gramsSource = null === $grams ? null : $source;
    }

    /**
     * The model's reading of a line nobody structured (typed by hand): what it is and how much.
     * Never overwrites what extraction found.
     */
    public function fillIn(?string $canonicalKey, ?string $name, ?float $quantity, ?string $unit): void
    {
        if (null !== $this->canonicalKey) {
            return;
        }
        $this->canonicalKey = $canonicalKey;
        $this->name ??= $name;
        if (null === $this->quantity) {
            $this->quantity = $quantity;
            $this->unit = $unit;
        }
    }
}
