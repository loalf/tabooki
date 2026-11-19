<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Household;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/** The signed-in user and their household, for code that runs inside an HTTP request. */
final readonly class CurrentHousehold
{
    public function __construct(private Security $security)
    {
    }

    public function user(): User
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedException();
        }

        return $user;
    }

    public function household(): Household
    {
        return $this->user()->getHousehold();
    }

    public function householdOrNull(): ?Household
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user->getHousehold() : null;
    }
}
