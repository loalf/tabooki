<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Household;
use App\Entity\MealPlan;
use App\Entity\MealPlanEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
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
     * When each recipe was last planned in the days before a date (other weeks' plans), leaving
     * out meals that weren't cooked: a dish that was skipped can come back sooner.
     *
     * @return array<string, \DateTimeImmutable> by recipe id
     */
    public function lastPlanned(Household $household, \DateTimeImmutable $before, int $days): array
    {
        $query = $this->lastDates($household)
            ->andWhere('e.date >= :from AND e.date < :to')
            ->andWhere('e.cooked IS NULL OR e.cooked = true')
            ->setParameter('from', $before->modify(\sprintf('-%d days', $days)), Types::DATE_IMMUTABLE)
            ->setParameter('to', $before, Types::DATE_IMMUTABLE);

        return $this->byRecipe($query);
    }

    /**
     * The last day each recipe was cooked, up to and including a day (usually today). Cooked is
     * what the household said (cook mode's end, a rating), or a past meal nobody said wasn't;
     * not today's meal until it's done, and not leftovers, which aren't cooked again.
     *
     * @return array<string, \DateTimeImmutable> by recipe id
     */
    public function lastCooked(Household $household, \DateTimeImmutable $upTo): array
    {
        $query = $this->lastDates($household)
            ->andWhere('e.leftoverOf IS NULL')
            ->andWhere('(e.cooked = true AND e.date <= :to) OR (e.cooked IS NULL AND e.date < :to)')
            ->setParameter('to', $upTo, Types::DATE_IMMUTABLE);

        return $this->byRecipe($query);
    }

    private function lastDates(Household $household): QueryBuilder
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(e.recipe) AS recipe', 'MAX(e.date) AS last')
            ->from(MealPlanEntry::class, 'e')
            ->join('e.plan', 'p')
            ->where('p.household = :household')
            ->andWhere('e.recipe IS NOT NULL')
            ->groupBy('e.recipe')
            ->setParameter('household', $household->getId(), 'uuid');
    }

    /** @return array<string, \DateTimeImmutable> by recipe id */
    private function byRecipe(QueryBuilder $query): array
    {
        /** @var list<array{recipe: string, last: string}> $rows */
        $rows = $query->getQuery()->getArrayResult();

        $last = [];
        foreach ($rows as $row) {
            $last[(string) $row['recipe']] = new \DateTimeImmutable($row['last']);
        }

        return $last;
    }
}
