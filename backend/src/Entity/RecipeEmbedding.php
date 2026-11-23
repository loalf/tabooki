<?php

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\VectorType;
use App\Llm\Embedding;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A recipe as a point in meaning (App\Search\RecipeSearch): the embedding of App\Search\RecipeDigest,
 * and what it was made from, so it's made again only when that changes (or the model does).
 */
#[ORM\Entity]
class RecipeEmbedding
{
    #[ORM\Id]
    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Recipe $recipe;

    /** @var list<float> */
    #[ORM\Column(type: VectorType::NAME, length: Embedding::DIMENSIONS)]
    private array $vector;

    #[ORM\Column(length: 100)]
    private string $model;

    /** Of the text and the model. */
    #[ORM\Column(length: 32)]
    private string $digestHash;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $embeddedAt;

    /** @param list<float> $vector */
    public function __construct(Recipe $recipe, array $vector, string $model, string $digestHash)
    {
        $this->recipe = $recipe;
        $this->vector = $vector;
        $this->model = $model;
        $this->digestHash = $digestHash;
        $this->embeddedAt = new \DateTimeImmutable();
    }

    public function getDigestHash(): string
    {
        return $this->digestHash;
    }

    /** @param list<float> $vector */
    public function update(array $vector, string $model, string $digestHash): void
    {
        $this->vector = $vector;
        $this->model = $model;
        $this->digestHash = $digestHash;
        $this->embeddedAt = new \DateTimeImmutable();
    }
}
