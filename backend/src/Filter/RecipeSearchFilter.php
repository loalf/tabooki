<?php

declare(strict_types=1);

namespace App\Filter;

use ApiPlatform\Doctrine\Common\Filter\OpenApiFilterTrait;
use ApiPlatform\Doctrine\Orm\Filter\FilterInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\BackwardCompatibleFilterDescriptionTrait;
use ApiPlatform\Metadata\OpenApiParameterFilterInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Parameter;
use App\Library\SearchText;
use Doctrine\ORM\QueryBuilder;

/** `?q=pure patata`: every word must appear in the recipe's search text (see Recipe::refreshSearchText). */
final class RecipeSearchFilter implements FilterInterface, OpenApiParameterFilterInterface
{
    use BackwardCompatibleFilterDescriptionTrait;
    use OpenApiFilterTrait;

    public function apply(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null, array $context = []): void
    {
        $parameter = $context['parameter'] ?? null;
        if (!$parameter instanceof Parameter) {
            return;
        }
        $value = $parameter->getValue();
        if (!\is_string($value)) {
            return;
        }

        $alias = $queryBuilder->getRootAliases()[0];
        foreach (\array_slice(SearchText::words($value), 0, 10) as $word) {
            $parameter = $queryNameGenerator->generateParameterName('q');
            $queryBuilder
                ->andWhere(\sprintf('%s.searchText LIKE :%s', $alias, $parameter))
                ->setParameter($parameter, '%'.addcslashes($word, '%_\\').'%');
        }
    }
}
