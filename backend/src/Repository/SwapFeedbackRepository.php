<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Household;
use App\Entity\SwapFeedback;
use App\Enum\SwapReason;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<SwapFeedback> */
class SwapFeedbackRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SwapFeedback::class);
    }

    /** @return list<SwapFeedback> newest first */
    public function since(Household $household, \DateTimeImmutable $since): array
    {
        /* @var list<SwapFeedback> */
        return $this->createQueryBuilder('f')
            ->where('f.household = :household')->andWhere('f.createdAt >= :since')
            ->setParameter('household', $household->getId(), 'uuid')->setParameter('since', $since)
            // Ids are UUID v7 (time-ordered): the order within the same second.
            ->orderBy('f.createdAt', 'DESC')->addOrderBy('f.id', 'DESC')
            ->getQuery()->getResult();
    }

    /**
     * Recipes swapped out because nobody likes the dish, lately: the planner brings them up less.
     *
     * @return array<string, true> by recipe id
     */
    public function dislikedDishes(Household $household): array
    {
        $ids = [];
        foreach ($this->since($household, new \DateTimeImmutable('-180 days')) as $feedback) {
            $recipe = $feedback->getRecipe();
            if (null !== $recipe && SwapReason::Dislike === $feedback->getReason() && 'dish' === ($feedback->getWhat()['kind'] ?? null)) {
                $ids[$recipe->getId()->toRfc4122()] = true;
            }
        }

        return $ids;
    }
}
