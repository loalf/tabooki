<?php

declare(strict_types=1);

namespace App\Translation;

use App\Llm\Completion;

final readonly class Translated
{
    public function __construct(
        public RecipeText $text,
        public Completion $completion,
    ) {
    }
}
