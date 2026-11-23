<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\RecipeSearchResult;
use App\Llm\LlmException;
use App\Search\RecipeSearch;
use App\Security\CurrentHousehold;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/** @implements ProviderInterface<RecipeSearchResult> */
final readonly class RecipeSearchProvider implements ProviderInterface
{
    public function __construct(
        private CurrentHousehold $current,
        private RecipeSearch $search,
    ) {
    }

    /** @return list<RecipeSearchResult> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $query = $operation->getParameters()?->get('q')?->getValue();
        $query = \is_string($query) ? mb_substr(trim($query), 0, 200) : '';
        if ('' === $query) {
            return [];
        }
        try {
            $found = $this->search->search($this->current->household(), $query);
        } catch (LlmException $e) {
            throw new ServiceUnavailableHttpException(30, 'Search by meaning is unavailable right now.', $e);
        }

        return array_map(static fn (array $r): RecipeSearchResult => new RecipeSearchResult($r['id'], $r['score']), $found);
    }
}
