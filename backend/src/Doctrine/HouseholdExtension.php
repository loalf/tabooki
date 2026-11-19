<?php

declare(strict_types=1);

namespace App\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\HouseholdOwned;
use App\Security\CurrentHousehold;
use Doctrine\ORM\QueryBuilder;

/**
 * Every API query on household-owned data only sees the signed-in user's household.
 * Items of other households answer 404, and IRIs pointing at them don't resolve.
 */
final readonly class HouseholdExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    public function __construct(private CurrentHousehold $current)
    {
    }

    public function applyToCollection(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null, array $context = []): void
    {
        $this->restrict($queryBuilder, $queryNameGenerator, $resourceClass);
    }

    public function applyToItem(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, array $identifiers, ?Operation $operation = null, array $context = []): void
    {
        $this->restrict($queryBuilder, $queryNameGenerator, $resourceClass);
    }

    private function restrict(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass): void
    {
        if (!is_a($resourceClass, HouseholdOwned::class, true)) {
            return;
        }

        $household = $this->current->householdOrNull();
        if (null === $household) {
            $queryBuilder->andWhere('1 = 0');

            return;
        }

        $alias = $queryBuilder->getRootAliases()[0];
        $parameter = $queryNameGenerator->generateParameterName('household');
        $queryBuilder
            ->andWhere(\sprintf('%s.household = :%s', $alias, $parameter))
            ->setParameter($parameter, $household->getId(), 'uuid');
    }
}
