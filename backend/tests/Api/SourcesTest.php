<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Test\ApiTestCase;
use ApiPlatform\Test\Client;
use App\Entity\Source;
use App\Entity\User;
use App\Media\MediaStorage;
use App\Message\FetchSourceMetadata;
use App\MessageHandler\FetchSourceMetadataHandler;
use App\State\CreateSourceProcessor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

final class SourcesTest extends ApiTestCase
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

    protected function tearDown(): void
    {
        new \Symfony\Component\Filesystem\Filesystem()->remove(self::getContainer()->getParameter('app.media_dir'));
        parent::tearDown();
    }

    public function testSavingALinkQueuesItsPreview(): void
    {
        $response = $this->client->request('POST', '/api/sources', ['json' => ['url' => 'youtube.com/shorts/dQw4w9WgXcQ?si=abc']]);

        self::assertResponseStatusCodeSame(201);
        self::assertJsonContains([
            'url' => 'https://youtube.com/shorts/dQw4w9WgXcQ?si=abc',
            'platform' => 'youtube',
            'status' => 'pending',
        ]);
        // Null values are left out of responses.
        self::assertArrayNotHasKey('title', $response->toArray());
        self::assertArrayNotHasKey('metadataFetchedAt', $response->toArray());

        $sent = $this->asyncTransport()->getSent();
        self::assertCount(1, $sent);
        $message = $sent[0]->getMessage();
        self::assertInstanceOf(FetchSourceMetadata::class, $message);
        self::assertSame($response->toArray()['id'], $message->sourceId);
    }

    public function testTheSameLinkCannotBeSavedTwice(): void
    {
        $this->client->request('POST', '/api/sources', ['json' => ['url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ']]);
        self::assertResponseStatusCodeSame(201);

        $this->client->request('POST', '/api/sources', ['json' => ['url' => 'https://youtu.be/dQw4w9WgXcQ?si=other']]);
        self::assertResponseStatusCodeSame(422);
        self::assertJsonContains(['violations' => [['propertyPath' => 'url', 'code' => CreateSourceProcessor::DUPLICATE_ERROR]]]);
    }

    public function testInvalidLinksAreRejected(): void
    {
        $this->client->request('POST', '/api/sources', ['json' => ['url' => 'not a link']]);
        self::assertResponseStatusCodeSame(422);
        self::assertJsonContains(['violations' => [['propertyPath' => 'url', 'code' => CreateSourceProcessor::INVALID_URL_ERROR]]]);

        $this->client->request('POST', '/api/sources', ['json' => ['url' => '']]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testThePreviewIsFetchedAndTheThumbnailCached(): void
    {
        $id = $this->saveLink('https://recetas.example/lentejas');

        $this->mockHttp([
            'https://recetas.example/lentejas' => new MockResponse(
                '<html><head><meta property="og:title" content="Lentejas de la abuela"><meta property="og:image" content="/lentejas.jpg">'
                .'<script type="application/ld+json">{"@type":"Recipe","name":"Lentejas","author":{"name":"Ana"}}</script></head></html>',
                ['response_headers' => ['content-type' => 'text/html; charset=utf-8']],
            ),
            'https://recetas.example/lentejas.jpg' => new MockResponse('fake-jpeg-bytes', ['response_headers' => ['content-type' => 'image/jpeg']]),
        ]);
        $this->runPreviewJob($id);

        $source = $this->client->request('GET', '/api/sources/'.$id)->toArray();
        self::assertSame('Lentejas de la abuela', $source['title']);
        self::assertSame('Ana', $source['author']);
        self::assertArrayHasKey('metadataFetchedAt', $source);
        self::assertIsString($source['thumbnail']);
        self::assertStringStartsWith('/api/media/'.$this->ana->getHousehold()->getId()->toRfc4122().'/thumbnails/', $source['thumbnail']);

        $entity = $this->findSource($id);
        self::assertEquals(['@type' => 'Recipe', 'name' => 'Lentejas', 'author' => ['name' => 'Ana']], $entity->getEvidence()['json_ld'] ?? null);

        $this->client->request('GET', $source['thumbnail']);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'image/jpeg');

        // Deleting the link deletes the file.
        $path = $entity->getThumbnailPath();
        self::assertNotNull($path);
        $this->client->request('DELETE', '/api/sources/'.$id);
        self::assertResponseStatusCodeSame(204);
        self::assertFalse(self::getContainer()->get(MediaStorage::class)->exists($path));
    }

    public function testMediaIsOnlyServedToItsHousehold(): void
    {
        $household = $this->ana->getHousehold()->getId()->toRfc4122();
        self::getContainer()->get(MediaStorage::class)->write($household.'/thumbnails/x.jpg', 'bytes');

        $this->client->request('GET', '/api/media/'.$household.'/thumbnails/x.jpg');
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/api/media/'.$household.'/thumbnails/missing.jpg');
        self::assertResponseStatusCodeSame(404);

        $other = self::createClient([], ['headers' => ['Accept' => 'application/json']]);
        $this->signIn($other, $this->createUser('bea@example.com', 'Otra casa'));
        $other->request('GET', '/api/media/'.$household.'/thumbnails/x.jpg');
        self::assertResponseStatusCodeSame(404);
    }

    public function testYouTubeUsesOEmbed(): void
    {
        $id = $this->saveLink('https://youtu.be/dQw4w9WgXcQ');

        $this->mockHttp([
            'https://www.youtube.com/oembed?url=https://youtu.be/dQw4w9WgXcQ&format=json' => new MockResponse(
                (string) json_encode(['title' => 'Tortilla de patatas', 'author_name' => 'Chef', 'provider_name' => 'YouTube', 'thumbnail_url' => 'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg']),
                ['response_headers' => ['content-type' => 'application/json']],
            ),
            'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg' => new MockResponse('', ['http_code' => 404]),
        ]);
        $this->runPreviewJob($id);

        // The image couldn't be cached: the original URL is used.
        $this->client->request('GET', '/api/sources/'.$id);
        self::assertJsonContains(['title' => 'Tortilla de patatas', 'author' => 'Chef', 'thumbnail' => 'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg']);
    }

    public function testAFailedFetchStillCompletes(): void
    {
        $id = $this->saveLink('https://instagram.com/p/C1a2B3c4D5e');
        $this->mockHttp(['https://instagram.com/p/C1a2B3c4D5e' => new MockResponse(
            '<title>Instagram</title>',
            ['response_headers' => ['content-type' => 'text/html']],
        )]);
        $this->runPreviewJob($id);

        $source = $this->client->request('GET', '/api/sources/'.$id)->toArray();
        self::assertArrayNotHasKey('title', $source);
        self::assertArrayHasKey('metadataFetchedAt', $source);

        // The user can name it by hand.
        $this->client->request('PATCH', '/api/sources/'.$id, [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['title' => 'Reel de pasta', 'url' => 'https://evil.example'],
        ]);
        self::assertResponseIsSuccessful();
        self::assertJsonContains(['title' => 'Reel de pasta', 'url' => 'https://instagram.com/p/C1a2B3c4D5e']);
    }

    public function testTheInboxListsLinksWithoutARecipe(): void
    {
        $withRecipe = $this->saveLink('https://example.com/one');
        $this->saveLink('https://example.com/two');
        $this->client->request('POST', '/api/recipes', ['json' => ['title' => 'One', 'source' => '/api/sources/'.$withRecipe]]);
        self::assertResponseStatusCodeSame(201);

        $all = $this->client->request('GET', '/api/sources')->toArray();
        self::assertSame(['https://example.com/two', 'https://example.com/one'], array_column($all, 'url'));

        $inbox = $this->client->request('GET', '/api/sources?hasRecipe=false')->toArray();
        self::assertSame(['https://example.com/two'], array_column($inbox, 'url'));

        $done = $this->client->request('GET', '/api/sources?hasRecipe=true')->toArray();
        self::assertSame(['https://example.com/one'], array_column($done, 'url'));
    }

    public function testLinksAreScopedToTheHousehold(): void
    {
        $id = $this->saveLink('https://example.com/mine');

        $other = self::createClient([], ['headers' => ['Accept' => 'application/json']]);
        $this->signIn($other, $this->createUser('bea@example.com', 'Otra casa'));

        self::assertSame([], $other->request('GET', '/api/sources')->toArray());
        $other->request('GET', '/api/sources/'.$id);
        self::assertResponseStatusCodeSame(404);
        $other->request('DELETE', '/api/sources/'.$id);
        self::assertResponseStatusCodeSame(404);

        // The same link can be saved by another household.
        $other->request('POST', '/api/sources', ['json' => ['url' => 'https://example.com/mine']]);
        self::assertResponseStatusCodeSame(201);

        // A second member of the same household sees it.
        $partner = self::createClient([], ['headers' => ['Accept' => 'application/json']]);
        $this->signIn($partner, $this->createUser('carlos@example.com', household: $this->ana->getHousehold()));
        self::assertCount(1, $partner->request('GET', '/api/sources')->toArray());
    }

    private function saveLink(string $url): string
    {
        $id = $this->client->request('POST', '/api/sources', ['json' => ['url' => $url]])->toArray()['id'];
        self::assertIsString($id);

        return $id;
    }

    /** @param array<string, MockResponse> $responses by exact URL */
    private function mockHttp(array $responses): void
    {
        $httpClient = self::getContainer()->get('link_preview.http_client');
        self::assertInstanceOf(MockHttpClient::class, $httpClient);
        $httpClient->setResponseFactory(static fn (string $method, string $url): MockResponse => $responses[$url]
            ?? throw new \LogicException('Unexpected request to '.$url));
    }

    private function runPreviewJob(string $id): void
    {
        self::getContainer()->get(FetchSourceMetadataHandler::class)(new FetchSourceMetadata($id));
        self::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    private function findSource(string $id): Source
    {
        $source = self::getContainer()->get(EntityManagerInterface::class)->find(Source::class, Uuid::fromString($id));
        self::assertNotNull($source);

        return $source;
    }

    private function asyncTransport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }
}
