<?php

declare(strict_types=1);

namespace App\Llm;

final readonly class Prompt
{
    /**
     * @param list<Content>        $content    the user message
     * @param array<string, mixed> $toolSchema JSON Schema of the tool's input: the shape of the answer
     * @param bool                 $thinking   let models that think before answering do so (slower, costs output
     *                                         tokens); ignored by models that can't switch it off
     */
    public function __construct(
        public string $model,
        public string $system,
        public array $content,
        public string $toolName,
        public string $toolDescription,
        public array $toolSchema,
        public int $maxTokens = 16000,
        public bool $thinking = true,
    ) {
    }
}
