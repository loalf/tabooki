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
use App\Entity\Recipe;
use Doctrine\ORM\QueryBuilder;

/** `?hasRecipe=false`: links not yet turned into a recipe (the library's inbox). */
final class SourceHasRecipeFilter implements FilterInterface, OpenApiParameterFilterInterface
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
        if (!\is_bool($value)) {
            return;
        }

        $alias = $queryBuilder->getRootAliases()[0];
        $recipeAlias = $queryNameGenerator->generateJoinAlias('recipe');
        $subquery = $queryBuilder->getEntityManager()->createQueryBuilder()
            ->select('1')
            ->from(Recipe::class, $recipeAlias)
            ->where(\sprintf('%s.source = %s', $recipeAlias, $alias))
            ->getDQL();

        $queryBuilder->andWhere(\sprintf($value ? 'EXISTS (%s)' : 'NOT EXISTS (%s)', $subquery));
    }
}
