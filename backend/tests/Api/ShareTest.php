<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Test\ApiTestCase;
use ApiPlatform\Test\Client;
use App\Entity\Source;
use App\Entity\User;
use App\Library\LinkSaver;
use App\Message\FetchSourceMetadata;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class ShareTest extends ApiTestCase
{
    use SignsInUsers;

    protected static ?bool $alwaysBootKernel = false;

    private Client $client;
    private User $ana;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['headers' => ['Accept' => 'application/json']]);
        $this->ana = $this->createUser('ana@example.com');
        $this->signIn($this->client, $this->ana);
    }

    public function testATokenIsShownOnceAndOnlyItsHashIsStored(): void
    {
        $created = $this->createToken('  iPhone de Ana ');

        self::assertResponseStatusCodeSame(201);
        self::assertSame('iPhone de Ana', $created['name']);
        self::assertStringStartsWith('tbk_', $created['token']);

        /** @var list<array<string, mixed>> $list */
        $list = $this->client->request('GET', '/api/share-tokens')->toArray();
        self::assertCount(1, $list);
        self::assertSame($created['id'], $list[0]['id']);
        self::assertArrayNotHasKey('token', $list[0]);

        $stored = self::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchOne('SELECT token_hash FROM share_token');
        self::assertSame(hash('sha256', $created['token']), $stored);
    }

    public function testATokenNeedsAName(): void
    {
        $this->client->request('POST', '/api/share-tokens', ['json' => ['name' => '']]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testTheShortcutSavesALinkWithItsToken(): void
    {
        $token = $this->token();
        $shortcut = self::createClient();

        $shortcut->request('POST', '/api/share', [
            'headers' => ['Authorization' => 'Bearer '.$token],
            'json' => ['url' => 'Mira esta receta 😋 https://www.instagram.com/reel/ABC123/?igsh=xyz'],
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertJsonContains(['status' => 'saved', 'message' => 'Guardado en Tabooki', 'url' => 'https://www.instagram.com/reel/ABC123/?igsh=xyz']);
        $source = self::getContainer()->get(EntityManagerInterface::class)->getRepository(Source::class)->findOneBy([]);
        self::assertNotNull($source);
        self::assertSame('ana@example.com', $source->getAddedBy()?->getEmail());

        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        self::assertInstanceOf(FetchSourceMetadata::class, $transport->getSent()[0]->getMessage());

        // Sharing it again (same link, other tracking params) says so instead of failing.
        $shortcut->request('POST', '/api/share', [
            'headers' => ['Authorization' => 'Bearer '.$token, 'Content-Type' => 'text/plain'],
            'body' => 'https://instagram.com/reel/ABC123',
        ]);
        self::assertResponseStatusCodeSame(200);
        self::assertJsonContains(['status' => 'duplicate', 'id' => $source->getId()->toRfc4122()]);
    }

    public function testTextWithoutALinkIsRefused(): void
    {
        $token = $this->token();

        self::createClient()->request('POST', '/api/share', [
            'headers' => ['Authorization' => 'Bearer '.$token],
            'json' => ['text' => 'pollo al horno'],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertJsonContains(['status' => 'no_link']);
    }

    public function testTheShareEndpointNeedsAValidToken(): void
    {
        $shortcut = self::createClient();

        $shortcut->request('POST', '/api/share', ['json' => ['url' => 'https://example.com/receta']]);
        self::assertResponseStatusCodeSame(401);

        $shortcut->request('POST', '/api/share', ['headers' => ['Authorization' => 'Bearer tbk_not-a-token'], 'json' => ['url' => 'https://example.com/receta']]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testARevokedTokenStopsWorking(): void
    {
        $created = $this->createToken();
        $this->client->request('DELETE', '/api/share-tokens/'.$created['id']);
        self::assertResponseStatusCodeSame(204);

        self::createClient()->request('POST', '/api/share', ['headers' => ['Authorization' => 'Bearer '.$created['token']], 'json' => ['url' => 'https://example.com/receta']]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testTokensArePersonal(): void
    {
        $created = $this->createToken();

        $luis = $this->createUser('luis@example.com', household: $this->ana->getHousehold());
        $other = self::createClient([], ['headers' => ['Accept' => 'application/json']]);
        $this->signIn($other, $luis);

        self::assertCount(0, $other->request('GET', '/api/share-tokens')->toArray());
        $other->request('DELETE', '/api/share-tokens/'.$created['id']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testFindsTheLinkInSharedText(): void
    {
        self::assertSame('https://youtu.be/abc', LinkSaver::findUrl(null, 'Receta: https://youtu.be/abc.'));
        self::assertSame('https://a.com/x', LinkSaver::findUrl('no link', null, 'see (https://a.com/x)'));
        self::assertNull(LinkSaver::findUrl('pollo', null));
    }

    private function token(): string
    {
        return $this->createToken()['token'];
    }

    /** @return array{id: string, name: string, token: string} */
    private function createToken(string $name = 'iPhone'): array
    {
        $data = $this->client->request('POST', '/api/share-tokens', ['json' => ['name' => $name]])->toArray();
        self::assertIsString($data['id'] ?? null);
        self::assertIsString($data['name'] ?? null);
        self::assertIsString($data['token'] ?? null);

        return ['id' => $data['id'], 'name' => $data['name'], 'token' => $data['token']];
    }
}
