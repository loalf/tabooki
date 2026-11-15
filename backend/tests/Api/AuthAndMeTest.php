<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Test\ApiTestCase;
use ApiPlatform\Test\Client;
use App\Entity\Household;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AuthAndMeTest extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = false;

    private Client $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();

        $container = self::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $household = new Household('Casa');
        $user = new User('Ana@Example.com', 'Ana', $household);
        $user->setPassword($hasher->hashPassword($user, 'correct-horse'));
        $entityManager->persist($household);
        $entityManager->persist($user);
        $entityManager->flush();
    }

    public function testMeRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/me');

        self::assertResponseStatusCodeSame(401);
    }

    public function testLoginWithWrongPasswordFails(): void
    {
        $this->login('ana@example.com', 'wrong-password');

        self::assertResponseStatusCodeSame(401);
    }

    public function testLoginThenReadAndUpdateMe(): void
    {
        $this->login('ana@example.com', 'correct-horse');
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', '/api/me');
        self::assertResponseIsSuccessful();
        self::assertJsonContains([
            'email' => 'ana@example.com',
            'displayName' => 'Ana',
            'locale' => 'es',
            'householdName' => 'Casa',
            'defaultServings' => 4,
        ]);

        $this->client->request('PATCH', '/api/me', [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['locale' => 'en'],
        ]);
        self::assertResponseIsSuccessful();
        self::assertJsonContains(['locale' => 'en']);
    }

    public function testInvalidLocaleIsRejected(): void
    {
        $this->login('ana@example.com', 'correct-horse');

        $this->client->request('PATCH', '/api/me', [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['locale' => 'fr'],
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testLogout(): void
    {
        $this->login('ana@example.com', 'correct-horse');

        $this->client->request('POST', '/api/logout');
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', '/api/me');
        self::assertResponseStatusCodeSame(401);
    }

    private function login(string $email, string $password): void
    {
        $this->client->request('POST', '/api/login', ['json' => ['email' => $email, 'password' => $password]]);
    }
}
