<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Household;
use App\Entity\MealPlan;
use App\Entity\MealPlanEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<MealPlan> */
final class MealPlanRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MealPlan::class);
    }

    public function findForWeek(Household $household, \DateTimeImmutable $anyDay): ?MealPlan
    {
        return $this->findOneBy(['household' => $household, 'weekStart' => MealPlan::mondayOf($anyDay)]);
    }

    /**
     * When each recipe was last planned in the days before a date (other weeks' plans).
     *
     * @return array<string, \DateTimeImmutable> by recipe id
     */
    public function lastPlanned(Household $household, \DateTimeImmutable $before, int $days): array
    {
        /** @var list<array{recipe: string, last: string}> $rows */
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(e.recipe) AS recipe', 'MAX(e.date) AS last')
            ->from(MealPlanEntry::class, 'e')
            ->join('e.plan', 'p')
            ->where('p.household = :household')
            ->andWhere('e.recipe IS NOT NULL')
            ->andWhere('e.date >= :from AND e.date < :before')
            ->groupBy('e.recipe')
            ->setParameter('household', $household->getId(), 'uuid')
            ->setParameter('from', $before->modify(\sprintf('-%d days', $days)), Types::DATE_IMMUTABLE)
            ->setParameter('before', $before, Types::DATE_IMMUTABLE)
            ->getQuery()
            ->getArrayResult();

        $last = [];
        foreach ($rows as $row) {
            $last[(string) $row['recipe']] = new \DateTimeImmutable($row['last']);
        }

        return $last;
    }
}
