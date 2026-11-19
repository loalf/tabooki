<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\RecipeTag;
use App\Repository\RecipeRepository;
use App\Security\CurrentHousehold;

/** @implements ProviderInterface<RecipeTag> */
final readonly class RecipeTagProvider implements ProviderInterface
{
    public function __construct(
        private CurrentHousehold $current,
        private RecipeRepository $recipes,
    ) {
    }

    /** @return list<RecipeTag> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        return array_map(
            static fn (array $row): RecipeTag => new RecipeTag($row['tag'], $row['count']),
            $this->recipes->tagCounts($this->current->household()),
        );
    }
}
