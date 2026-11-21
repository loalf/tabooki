<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Test\ApiTestCase;
use ApiPlatform\Test\Client;
use App\Entity\ExtractionRun;
use App\Entity\Household;
use App\Entity\Recipe;
use App\Entity\Source;
use App\Entity\User;
use App\Enum\SourcePlatform;
use Doctrine\ORM\EntityManagerInterface;

final class CostsTest extends ApiTestCase
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

    public function testAddsUpTheHouseholdsCalls(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $household = $em->find(Household::class, $this->ana->getHousehold()->getId());
        self::assertNotNull($household);
        $reel = $this->source($household, 'https://instagram.com/reel/abc', withRecipe: true);
        $failed = $this->source($household, 'https://example.com/nada', withRecipe: false);

        $em->persist(new ExtractionRun($household, $reel, 'text', 'bedrock', 'eu.anthropic.claude-haiku-5-5', 10000, 1000, 0.0015, true, null, 900));
        $em->persist(new ExtractionRun($household, $reel, 'video', 'bedrock', 'eu.amazon.nova-pro-v1:0', 20000, 1000, 0.0192, true, null, 4000));
        $em->persist(new ExtractionRun($household, $failed, 'text', 'bedrock', 'eu.anthropic.claude-haiku-5-5', 0, 0, null, false, 'Too many requests', 100));
        $em->persist(new ExtractionRun($household, null, 'classify', 'bedrock', 'eu.anthropic.claude-haiku-5-5', 3000, 600, 0.0006, true, null, 1500));
        $em->persist(new ExtractionRun($household, null, 'classify', 'bedrock', 'some.unpriced-model', 100, 10, null, true, null, 100));
        // Another household's calls don't count.
        $other = $this->createUser('bea@example.com', 'Otra casa')->getHousehold();
        $other = $em->find(Household::class, $other->getId());
        self::assertNotNull($other);
        $em->persist(new ExtractionRun($other, null, 'classify', 'bedrock', 'eu.anthropic.claude-haiku-5-5', 1, 1, 9.99, true, null, 1));
        $em->flush();

        /** @var array{thisMonth: array{usd: float, calls: int}, lastMonth: array{calls: int}, allTime: array{usd: float, calls: int, inputTokens: int}, months: list<array{month: string, usd: float, calls: int}>, byStep: list<array{name: string, usd: float, calls: int, failed: int}>, byModel: list<array{name: string}>, linksExtracted: int, perLink: float, unpricedCalls: int, recent: list<array{step: string, ok: bool, sourceUrl?: string|null, recipeId?: string|null, error?: string|null}>} $costs */
        $costs = $this->client->request('GET', '/api/costs')->toArray();

        self::assertEqualsWithDelta(0.0213, $costs['allTime']['usd'], 1e-9);
        self::assertSame(5, $costs['allTime']['calls']);
        self::assertSame(33100, $costs['allTime']['inputTokens']);
        self::assertSame(5, $costs['thisMonth']['calls']);
        self::assertSame(0, $costs['lastMonth']['calls']);

        self::assertCount(6, $costs['months']);
        self::assertSame(new \DateTimeImmutable('now', new \DateTimeZone('UTC'))->format('Y-m'), $costs['months'][5]['month']);
        self::assertSame(0, $costs['months'][0]['calls']);

        self::assertSame(['video', 'text', 'classify'], array_column($costs['byStep'], 'name'), 'most expensive first');
        self::assertSame(1, $costs['byStep'][1]['failed']);
        self::assertSame('eu.amazon.nova-pro-v1:0', $costs['byModel'][0]['name']);

        // The failed link got no recipe, but what it cost counts towards the average.
        self::assertSame(1, $costs['linksExtracted']);
        self::assertEqualsWithDelta(0.0207, $costs['perLink'], 1e-9);
        self::assertSame(1, $costs['unpricedCalls']);

        self::assertCount(5, $costs['recent']);
        $byStep = array_column($costs['recent'], null, 'step');
        self::assertSame('https://instagram.com/reel/abc', $byStep['video']['sourceUrl'] ?? null);
        self::assertNotNull($byStep['video']['recipeId'] ?? null);
        self::assertNull($byStep['classify']['sourceUrl'] ?? null);
    }

    public function testNeedsSignIn(): void
    {
        self::createClient()->request('GET', '/api/costs', ['headers' => ['Accept' => 'application/json']]);
        self::assertResponseStatusCodeSame(401);
    }

    private function source(Household $household, string $url, bool $withRecipe): Source
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $source = new Source($household, $url, $url, SourcePlatform::Web);
        $em->persist($source);
        if ($withRecipe) {
            $recipe = new Recipe();
            $recipe->assignHousehold($household);
            $recipe->setSource($source);
            $recipe->setTitle('Receta');
            $recipe->markExtracted('eu.amazon.nova-pro-v1:0', 0.9, false);
            $em->persist($recipe);
        }

        return $source;
    }
}
