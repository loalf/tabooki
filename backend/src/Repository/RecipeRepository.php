<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Household;
use App\Entity\Recipe;
use App\Enum\Language;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Recipe> */
final class RecipeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Recipe::class);
    }

    /**
     * The household's whole library with ingredients, for the meal planner.
     *
     * @return list<Recipe>
     */
    public function libraryOf(Household $household): array
    {
        /* @var list<Recipe> */
        return $this->createQueryBuilder('r')
            ->addSelect('i')
            ->leftJoin('r.ingredients', 'i')
            ->where('r.household = :household')
            ->setParameter('household', $household->getId(), 'uuid')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<array{tag: string, count: int}> every tag used in the household's library, in the
     *                                              reader's language where recipes have been translated, most used first
     */
    public function tagCounts(Household $household, Language $language): array
    {
        /** @var list<array{tag: string, count: int|string}> $rows */
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT t.tag, COUNT(*) AS count
               FROM recipe r, jsonb_array_elements_text(
                        CASE WHEN r.language <> :language AND r.translation IS NOT NULL THEN r.translation->\'text\'->\'tags\' ELSE r.tags END
                    ) AS t(tag)
              WHERE r.household_id = :household
           GROUP BY t.tag
           ORDER BY count DESC, t.tag ASC',
            ['household' => $household->getId()->toRfc4122(), 'language' => $language->value],
        );

        return array_map(static fn (array $row): array => ['tag' => $row['tag'], 'count' => (int) $row['count']], $rows);
    }
}
