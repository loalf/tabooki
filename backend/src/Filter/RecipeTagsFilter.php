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
use Doctrine\ORM\QueryBuilder;

/** `?tags[]=vegetarian&tags[]=quick`: recipes having all the given tags (JSONB containment). */
final class RecipeTagsFilter implements FilterInterface, OpenApiParameterFilterInterface
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
        $tags = array_values(array_filter(
            array_map(static fn (mixed $tag): string => \is_string($tag) ? mb_strtolower(trim($tag)) : '', (array) $value),
            static fn (string $tag): bool => '' !== $tag,
        ));
        if ([] === $tags) {
            return;
        }

        $alias = $queryBuilder->getRootAliases()[0];
        $parameter = $queryNameGenerator->generateParameterName('tags');
        $queryBuilder
            ->andWhere(\sprintf('JSONB_CONTAINS(%s.tags, :%s) = true', $alias, $parameter))
            ->setParameter($parameter, json_encode($tags, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE));
    }
}
