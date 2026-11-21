<?php

declare(strict_types=1);

namespace App\Llm;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** What an LLM call cost, from the list prices in config/services.yaml (app.llm_prices). */
final readonly class Pricing
{
    /** @param array<string, array{input: float, output: float}> $prices USD per million tokens, by model */
    public function __construct(
        #[Autowire('%app.llm_prices%')]
        private array $prices = [],
    ) {
    }

    /** null when the model has no price configured. */
    public function cost(string $model, int $inputTokens, int $outputTokens): ?float
    {
        $price = $this->prices[$model] ?? null;

        return null === $price ? null : round(($inputTokens * $price['input'] + $outputTokens * $price['output']) / 1_000_000, 6);
    }
}
