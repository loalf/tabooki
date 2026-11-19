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
use App\Entity\MealPlan;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;

/** `?week=2026-11-26`: the plan of the week containing that day, if there is one. */
final class MealPlanWeekFilter implements FilterInterface, OpenApiParameterFilterInterface
{
    use BackwardCompatibleFilterDescriptionTrait;
    use OpenApiFilterTrait;

    public function apply(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null, array $context = []): void
    {
        $parameter = $context['parameter'] ?? null;
        $value = $parameter instanceof Parameter ? $parameter->getValue() : null;
        $day = \is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        if (false === $day) {
            $queryBuilder->andWhere('1 = 0');

            return;
        }

        $alias = $queryBuilder->getRootAliases()[0];
        $name = $queryNameGenerator->generateParameterName('week');
        $queryBuilder
            ->andWhere(\sprintf('%s.weekStart = :%s', $alias, $name))
            ->setParameter($name, MealPlan::mondayOf($day), Types::DATE_IMMUTABLE);
    }
}
