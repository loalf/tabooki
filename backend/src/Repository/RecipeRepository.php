<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Household;
use App\Entity\Recipe;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Recipe> */
final class RecipeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Recipe::class);
    }

    /** @return list<array{tag: string, count: int}> every tag used in the household's library, most used first */
    public function tagCounts(Household $household): array
    {
        /** @var list<array{tag: string, count: int|string}> $rows */
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT t.tag, COUNT(*) AS count
               FROM recipe r, jsonb_array_elements_text(r.tags) AS t(tag)
              WHERE r.household_id = :household
           GROUP BY t.tag
           ORDER BY count DESC, t.tag ASC',
            ['household' => $household->getId()->toRfc4122()],
        );

        return array_map(static fn (array $row): array => ['tag' => $row['tag'], 'count' => (int) $row['count']], $rows);
    }
}
