<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Household;
use App\Entity\TasteProfile;
use App\Taste\TasteRules;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<TasteProfile> */
class TasteProfileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TasteProfile::class);
    }

    public function forHousehold(Household $household): ?TasteProfile
    {
        return $this->findOneBy(['household' => $household]);
    }

    /** The household's rules, or none (every recipe fits) if it hasn't said what it likes. */
    public function rulesFor(Household $household): TasteRules
    {
        return $this->forHousehold($household)?->getRules() ?? TasteRules::none();
    }
}
