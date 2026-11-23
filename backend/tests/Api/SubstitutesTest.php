<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Test\ApiTestCase;
use ApiPlatform\Test\Client;
use App\Entity\Food;
use App\Entity\FoodSubstitution;
use App\Entity\User;
use App\Food\KeyMerger;
use App\Message\DescribeFoods;
use App\Message\SuggestSubstitutes;
use App\MessageHandler\DescribeFoodsHandler;
use App\MessageHandler\SuggestSubstitutesHandler;
use App\Shopping\IngredientCatalog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * @phpstan-type Substitute array{key: string, name: array{es: string, en: string}, ratio: float|int, note: array{es: string, en: string}|null}
 */
final class SubstitutesTest extends ApiTestCase
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

    public function testOnceTheFoodsAreDescribedTheModelSaysWhatCanStandInForThem(): void
    {
        $id = $this->client->request('POST', '/api/recipes', ['json' => [
            'title' => 'Crema de calabaza', 'language' => 'es', 'servings' => 4, 'steps' => ['Triturar.'],
            'ingredients' => [
                ['rawText' => '200 ml de nata', 'canonicalKey' => 'heavy_cream', 'name' => 'nata', 'quantity' => 200, 'unit' => 'ml'],
                ['rawText' => 'Cilantro fresco', 'canonicalKey' => 'fresh_coriander', 'name' => 'cilantro'],
                ['rawText' => 'Sal', 'canonicalKey' => 'salt', 'name' => 'sal'],
            ],
        ]])->toArray()['id'] ?? null;
        self::assertIsString($id);

        $food = static fn (string $key): array => [
            'key' => $key, 'name_es' => $key, 'name_en' => $key, 'kcal' => 100, 'protein' => 1, 'fat' => 1, 'saturated_fat' => 0, 'carbs' => 1,
            'sugar' => 0, 'fiber' => 0, 'salt' => 0, 'food_groups' => [], 'allergens' => [], 'spice' => 0, 'grams_per_piece' => null, 'density' => null,
        ];
        $this->mockBedrock(['foods' => [$food('heavy_cream'), $food('fresh_coriander'), $food('salt')]], 'save_foods');
        $householdId = $this->ana->getHousehold()->getId()->toRfc4122();
        self::getContainer()->get(DescribeFoodsHandler::class)(new DescribeFoods($householdId));
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        self::assertNotEmpty(array_filter($transport->getSent(), static fn ($e): bool => $e->getMessage() instanceof SuggestSubstitutes), 'all described: substitutes next');

        $substitute = static fn (string $key, float $ratio = 1, ?string $note = null): array => [
            'key' => $key, 'name_es' => str_replace('_', ' ', $key), 'name_en' => $key, 'ratio' => $ratio, 'note_es' => $note, 'note_en' => $note,
        ];
        $this->mockBedrock(['foods' => [
            ['key' => 'heavy_cream', 'substitutes' => [
                $substitute('Greek yogurt', 1, 'más ácido'), $substitute('heavy_cream'), $substitute('cooking_cream'), $substitute('greek_yogurt'),
                $substitute('smoked_paprika', 0.1), $substitute('evaporated_milk', 1.5), $substitute('milk'),
            ]],
            ['key' => 'fresh_coriander', 'substitutes' => [$substitute('parsley')]],
            ['key' => 'salt', 'substitutes' => [$substitute('soy_sauce')]],
        ]], 'save_substitutes');
        self::getContainer()->get(SuggestSubstitutesHandler::class)(new SuggestSubstitutes($householdId));

        $substitutes = $this->substitutes($id);
        self::assertSame(['heavy_cream', 'fresh_coriander'], array_keys($substitutes), 'not for salt: a staple, never asked');
        self::assertSame(['greek_yogurt', 'cooking_cream', 'evaporated_milk'], array_column($substitutes['heavy_cream'], 'key'), 'clean keys, not itself, once each, no pinch of something, three at most');
        self::assertSame(['es' => 'más ácido', 'en' => 'más ácido'], $substitutes['heavy_cream'][0]['note']);
        self::assertEquals(1.5, $substitutes['heavy_cream'][2]['ratio']);

        // A merge renames the substitute, and drops it where the food already had the new one.
        self::getContainer()->get(KeyMerger::class)->merge('cooking_cream', 'greek_yogurt');
        self::getContainer()->get(KeyMerger::class)->merge('parsley', 'flat_leaf_parsley');
        $substitutes = $this->substitutes($id);
        self::assertSame(['greek_yogurt', 'evaporated_milk'], array_column($substitutes['heavy_cream'], 'key'));
        self::assertSame(['flat_leaf_parsley'], array_column($substitutes['fresh_coriander'], 'key'));

        // Asked once: nothing pending, so nothing more is asked.
        $remaining = self::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchOne('SELECT count(*) FROM food WHERE described_at IS NOT NULL AND substitutes_at IS NULL AND key <> ?', ['salt']);
        self::assertSame(0, $remaining);
    }

    public function testAHouseholdsOwnFoodBringsItsSubstitutes(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $household = $entityManager->getRepository(User::class)->find($this->ana->getId())?->getHousehold();
        self::assertNotNull($household);
        $dried = new Food('chickpeas_dried');
        $entityManager->persist($dried);
        $entityManager->persist(new FoodSubstitution($dried, 'white_beans_dried', 'alubias blancas secas', 'dried white beans', 1, null, null, 0));
        $entityManager->flush();

        $id = $this->client->request('POST', '/api/recipes', ['json' => [
            'title' => 'Cocido', 'language' => 'es', 'servings' => 4, 'steps' => ['Cocer.'],
            'ingredients' => [['rawText' => '300 g de garbanzos', 'canonicalKey' => 'chickpeas', 'name' => 'garbanzos', 'quantity' => 300, 'unit' => 'g']],
        ]])->toArray()['id'] ?? null;
        self::assertIsString($id);
        self::assertNull($this->client->request('GET', '/api/recipes/'.$id)->toArray()['substitutes'], 'the catalog\'s chickpeas: nothing asked yet');

        $ingredient = self::getContainer()->get(IngredientCatalog::class)->forKeys($household, ['chickpeas' => ['name' => 'garbanzos', 'language' => 'es']])['chickpeas'];
        $this->client->request('PATCH', '/api/ingredients/'.$ingredient->getId()->toRfc4122(), [
            'json' => ['food' => '/api/foods/'.$dried->getId()->toRfc4122()],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame(['white_beans_dried'], array_column($this->substitutes($id)['chickpeas'] ?? [], 'key'));
    }

    /** @return array<string, list<Substitute>> */
    private function substitutes(string $id): array
    {
        /** @var array{substitutes: array<string, list<Substitute>>|null} $recipe */
        $recipe = $this->client->request('GET', '/api/recipes/'.$id)->toArray();

        return $recipe['substitutes'] ?? [];
    }

    /** @param array<string, mixed> $input */
    private function mockBedrock(array $input, string $tool): void
    {
        $httpClient = self::getContainer()->get('bedrock.http_client');
        self::assertInstanceOf(MockHttpClient::class, $httpClient);
        $httpClient->setResponseFactory([new JsonMockResponse([
            'output' => ['message' => ['content' => [['toolUse' => ['toolUseId' => 't', 'name' => $tool, 'input' => $input]]]]],
            'stopReason' => 'tool_use',
            'usage' => ['inputTokens' => 100, 'outputTokens' => 50],
        ])]);
    }
}
