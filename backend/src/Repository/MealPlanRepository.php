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
        return $this->lastDates($household, $before->modify(\sprintf('-%d days', $days)), $before, inclusive: false);
    }

    /**
     * The last day each recipe was on the menu, up to and including a day (usually today).
     *
     * @return array<string, \DateTimeImmutable> by recipe id
     */
    public function lastCooked(Household $household, \DateTimeImmutable $upTo): array
    {
        return $this->lastDates($household, null, $upTo, inclusive: true);
    }

    /** @return array<string, \DateTimeImmutable> by recipe id */
    private function lastDates(Household $household, ?\DateTimeImmutable $from, \DateTimeImmutable $to, bool $inclusive): array
    {
        $query = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(e.recipe) AS recipe', 'MAX(e.date) AS last')
            ->from(MealPlanEntry::class, 'e')
            ->join('e.plan', 'p')
            ->where('p.household = :household')
            ->andWhere('e.recipe IS NOT NULL')
            ->andWhere($inclusive ? 'e.date <= :to' : 'e.date < :to')
            ->groupBy('e.recipe')
            ->setParameter('household', $household->getId(), 'uuid')
            ->setParameter('to', $to, Types::DATE_IMMUTABLE);
        if (null !== $from) {
            $query->andWhere('e.date >= :from')->setParameter('from', $from, Types::DATE_IMMUTABLE);
        }
        /** @var list<array{recipe: string, last: string}> $rows */
        $rows = $query->getQuery()->getArrayResult();

        $last = [];
        foreach ($rows as $row) {
            $last[(string) $row['recipe']] = new \DateTimeImmutable($row['last']);
        }

        return $last;
    }
}
