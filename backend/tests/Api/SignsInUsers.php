<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Test\Client;
use App\Entity\Household;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

trait SignsInUsers
{
    private function createUser(string $email, string $householdName = 'Casa', ?Household $household = null): User
    {
        $container = self::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        // Re-fetch: entities from before a kernel reboot (a second client) are detached.
        $household = null === $household ? new Household($householdName) : $entityManager->find(Household::class, $household->getId());
        self::assertNotNull($household);
        $user = new User($email, ucfirst(explode('@', $email)[0]), $household);
        $user->setPassword($hasher->hashPassword($user, 'secret-password'));
        $entityManager->persist($household);
        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }

    private function signIn(Client $client, User $user): void
    {
        $client->request('POST', '/api/login', ['json' => ['email' => $user->getEmail(), 'password' => 'secret-password']]);
        self::assertResponseStatusCodeSame(204);
    }
}
