<?php

declare(strict_types=1);

namespace App\Llm;

/** A text as a vector, compared by cosine similarity. */
final readonly class Embedding
{
    /** The size of every vector we store (recipe_embedding.vector). */
    public const int DIMENSIONS = 512;

    /** @param list<float> $vector */
    public function __construct(
        public array $vector,
        public string $model,
        public int $tokens,
    ) {
    }
}
