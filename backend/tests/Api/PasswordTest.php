<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Test\ApiTestCase;
use ApiPlatform\Test\Client;
use App\Entity\PasswordResetToken;
use App\Entity\User;
use App\Message\SendPasswordResetLink;
use App\MessageHandler\SendPasswordResetLinkHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Mime\Email;

final class PasswordTest extends ApiTestCase
{
    use MailerAssertionsTrait;
    use SignsInUsers;

    protected static ?bool $alwaysBootKernel = false;

    private Client $client;
    private User $ana;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['headers' => ['Accept' => 'application/json']]);
        $this->ana = $this->createUser('ana@example.com');
        // Limits are kept in a cache pool that outlives a test.
        self::getContainer()->get('cache.rate_limiter')->clear();
    }

    public function testChangingThePasswordKeepsThisDeviceAndSignsOutTheOthers(): void
    {
        $phone = self::createClient([], ['headers' => ['Accept' => 'application/json']]);
        $this->signIn($phone, $this->ana);
        // Assertions read the newest client: this one.
        $this->client = self::createClient([], ['headers' => ['Accept' => 'application/json']]);
        $this->signIn($this->client, $this->ana);

        $this->client->request('POST', '/api/me/password', ['json' => ['currentPassword' => 'nope', 'newPassword' => 'a-new-password']]);
        self::assertResponseStatusCodeSame(422);
        self::assertJsonContains(['violations' => [['propertyPath' => 'currentPassword']]]);

        $this->client->request('POST', '/api/me/password', ['json' => ['currentPassword' => 'secret-password', 'newPassword' => 'short']]);
        self::assertResponseStatusCodeSame(422);
        self::assertJsonContains(['violations' => [['propertyPath' => 'newPassword']]]);

        $this->client->request('POST', '/api/me/password', ['json' => ['currentPassword' => 'secret-password', 'newPassword' => 'a-new-password']]);
        self::assertResponseStatusCodeSame(204);
        self::assertNotNull($this->client->getCookieJar()->get('REMEMBERME'), 'a fresh remember-me cookie');
        // (Emails are forgotten at the next request: check it now.)
        $email = $this->lastEmail();
        self::assertSame('Tu contraseña de Tabooki ha cambiado', $email->getSubject());
        self::assertSame('ana@example.com', $email->getTo()[0]->getAddress());

        $this->client->request('GET', '/api/me');
        self::assertResponseIsSuccessful();
        $phone->request('GET', '/api/me');
        self::assertSame(401, $phone->getResponse()?->getStatusCode(), 'other devices are signed out');

        $this->assertLogin('secret-password', 401);
        $this->assertLogin('a-new-password', 204);
    }

    public function testChangingThePasswordNeedsSignIn(): void
    {
        $this->client->request('POST', '/api/me/password', ['json' => ['currentPassword' => 'secret-password', 'newPassword' => 'a-new-password']]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testAForgottenPasswordIsResetWithTheEmailedLink(): void
    {
        $this->requestLink(' Ana@Example.com ');

        $email = $this->lastEmail();
        self::assertSame('Cambia tu contraseña de Tabooki', $email->getSubject());
        $token = self::match('#https://localhost/reset-password\?token=([0-9a-f]{24}\.[0-9a-f]{48})#', (string) $email->getTextBody(), 'a link without the :443 port');

        $this->client->request('POST', '/api/password/reset', ['json' => ['token' => $token, 'password' => 'short']]);
        self::assertResponseStatusCodeSame(422);

        $this->client->request('POST', '/api/password/reset', ['json' => ['token' => $token, 'password' => 'brand-new-password']]);
        self::assertResponseStatusCodeSame(204);
        self::assertSame('Tu contraseña de Tabooki ha cambiado', $this->lastEmail()->getSubject());
        $this->assertLogin('brand-new-password', 204);

        // Once only.
        $this->client->request('POST', '/api/password/reset', ['json' => ['token' => $token, 'password' => 'yet-another-password']]);
        self::assertResponseStatusCodeSame(422);
        self::assertJsonContains(['violations' => [['propertyPath' => 'token']]]);
    }

    public function testUnknownAddressesGetTheSameAnswerAndNoEmail(): void
    {
        // The same answer and the same work in the request (a queued job), so the timing doesn't
        // tell either; the worker then finds nobody to write to.
        $this->requestLink('nobody@example.com');
        self::assertEmailCount(0);
        self::assertQueuedEmailCount(0);
    }

    public function testOnlyTheLatestLinkWorksAndLinksExpire(): void
    {
        $this->requestLink('ana@example.com');
        $first = $this->tokenFrom($this->lastEmail());
        $this->requestLink('ana@example.com');
        $second = $this->tokenFrom($this->lastEmail());

        $this->client->request('POST', '/api/password/reset', ['json' => ['token' => $first, 'password' => 'brand-new-password']]);
        self::assertResponseStatusCodeSame(422, 'replaced by the newer link');

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->getConnection()->executeStatement("UPDATE password_reset_token SET expires_at = NOW() - INTERVAL '1 minute'");
        $this->client->request('POST', '/api/password/reset', ['json' => ['token' => $second, 'password' => 'brand-new-password']]);
        self::assertResponseStatusCodeSame(422, 'expired');

        $this->client->request('POST', '/api/password/reset', ['json' => ['token' => 'not-a-token', 'password' => 'brand-new-password']]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(1, $entityManager->getRepository(PasswordResetToken::class)->count(), 'nothing new was created');
    }

    public function testEmailsAreRateLimited(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->client->request('POST', '/api/password/forgot', ['json' => ['email' => 'ana@example.com']]);
            self::assertResponseStatusCodeSame(202);
        }
        $this->client->request('POST', '/api/password/forgot', ['json' => ['email' => 'ana@example.com']]);
        self::assertResponseStatusCodeSame(429);
    }

    /**
     * Asks for a link, checks the request only queued the job (whatever the address), then runs
     * that job as the worker would.
     */
    private function requestLink(string $email): void
    {
        $this->client->request('POST', '/api/password/forgot', ['json' => ['email' => $email]]);
        self::assertResponseStatusCodeSame(202);
        self::assertEmailCount(0, null, 'nothing is emailed during the request');
        self::assertQueuedEmailCount(0);

        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $messages = array_map(static fn ($envelope): object => $envelope->getMessage(), $transport->getSent());
        self::assertEquals([new SendPasswordResetLink(mb_strtolower(trim($email)))], $messages);
        self::assertInstanceOf(SendPasswordResetLink::class, $messages[0]);

        $handler = self::getContainer()->get(SendPasswordResetLinkHandler::class);
        self::assertInstanceOf(SendPasswordResetLinkHandler::class, $handler);
        $handler($messages[0]);
    }

    /** With its own client, keeping $this->client the one the assertion helpers read. */
    private function assertLogin(string $password, int $status): void
    {
        $client = self::createClient();
        $client->request('POST', '/api/login', ['json' => ['email' => 'ana@example.com', 'password' => $password]]);
        self::assertSame($status, $client->getResponse()?->getStatusCode());
        $this->client = self::createClient([], ['headers' => ['Accept' => 'application/json']]);
    }

    private function lastEmail(): Email
    {
        $messages = self::getMailerMessages();
        $email = end($messages);
        self::assertInstanceOf(Email::class, $email);

        return $email;
    }

    private function tokenFrom(Email $email): string
    {
        return self::match('#token=([0-9a-f.]+)#', (string) $email->getTextBody());
    }

    /** The first group of a pattern that must match. */
    private static function match(string $pattern, string $text, string $message = ''): string
    {
        if (1 !== preg_match($pattern, $text, $m) || !isset($m[1])) {
            self::fail($message ?: "No match for $pattern in: $text");
        }

        return $m[1];
    }
}
