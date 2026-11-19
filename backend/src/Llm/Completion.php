<?php

declare(strict_types=1);

namespace App\Llm;

final readonly class Completion
{
    /** @param array<string, mixed>|null $toolInput what the model passed to the tool, null if it didn't call it */
    public function __construct(
        public ?array $toolInput,
        public string $text,
        public string $model,
        public int $inputTokens,
        public int $outputTokens,
        public string $stopReason,
    ) {
    }
}
