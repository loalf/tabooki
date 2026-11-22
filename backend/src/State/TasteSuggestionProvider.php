<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Security\CurrentHousehold;
use App\Taste\SwapLearning;
use App\Taste\TasteSuggestion;

/** @implements ProviderInterface<TasteSuggestion> */
final readonly class TasteSuggestionProvider implements ProviderInterface
{
    public function __construct(private CurrentHousehold $current, private SwapLearning $learning)
    {
    }

    /** @return list<TasteSuggestion> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        return $this->learning->suggestions($this->current->household());
    }
}
