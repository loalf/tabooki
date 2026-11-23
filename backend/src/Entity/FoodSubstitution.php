<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\FoodSource;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * What can stand in for a food in a recipe ("nata" → "yogur griego", 1:1, "más ácido"), shared
 * like the foods. One way: what replaces butter isn't what butter replaces. The substitute is a key
 * and its names, so it needn't be in the catalog. Filled in by the text model
 * (App\MessageHandler\SuggestSubstitutesHandler).
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'food_substitution_food_substitute', columns: ['food_id', 'substitute_key'])]
class FoodSubstitution
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(inversedBy: 'substitutions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Food $food;

    /** Canonical English key, like the foods' ("greek_yogurt"). */
    #[ORM\Column(length: 80)]
    private string $substituteKey;

    #[ORM\Column(length: 100)]
    private string $nameEs;

    #[ORM\Column(length: 100)]
    private string $nameEn;

    /** Grams of the substitute for each gram of the food. */
    #[ORM\Column(type: Types::FLOAT, options: ['default' => 1])]
    private float $ratio;

    /** How it changes the dish, or what to add ("más ácido", "añade un chorrito de limón"). */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $noteEs;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $noteEn;

    /** Best first. */
    #[ORM\Column(type: Types::SMALLINT)]
    private int $position;

    #[ORM\Column(enumType: FoodSource::class, length: 10)]
    private FoodSource $source;

    public function __construct(Food $food, string $substituteKey, string $nameEs, string $nameEn, float $ratio, ?string $noteEs, ?string $noteEn, int $position, FoodSource $source = FoodSource::Llm)
    {
        $this->id = Uuid::v7();
        $this->food = $food;
        $this->substituteKey = $substituteKey;
        $this->nameEs = $nameEs;
        $this->nameEn = $nameEn;
        $this->ratio = $ratio;
        $this->noteEs = $noteEs;
        $this->noteEn = $noteEn;
        $this->position = $position;
        $this->source = $source;
    }

    public function getFood(): Food
    {
        return $this->food;
    }

    /** @return array{key: string, name: array{es: string, en: string}, ratio: float, note: array{es: string, en: string}|null} */
    public function toArray(): array
    {
        return [
            'key' => $this->substituteKey,
            'name' => ['es' => $this->nameEs, 'en' => $this->nameEn],
            'ratio' => $this->ratio,
            'note' => null === $this->noteEs || null === $this->noteEn ? null : ['es' => $this->noteEs, 'en' => $this->noteEn],
        ];
    }
}
