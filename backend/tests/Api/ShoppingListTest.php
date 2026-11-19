<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Test\ApiTestCase;
use ApiPlatform\Test\Client;
use App\Entity\Ingredient;
use App\Entity\Recipe;
use App\Entity\RecipeIngredient;
use App\Entity\User;
use App\Enum\Language;
use App\Message\ClassifyIngredients;
use App\MessageHandler\ClassifyIngredientsHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * @phpstan-type Item array{key: string, ingredientId?: string|null, name: string, aisle: string, staple: bool, manual: bool, amounts: list<array{quantity: float|int, unit: string|null}>, recipes: list<string>, checked: bool}
 * @phpstan-type ListView array{week: string, planned: bool, meals: int, classifying: bool, items: list<Item>}
 */
final class ShoppingListTest extends ApiTestCase
{
    use SignsInUsers;

    protected static ?bool $alwaysBootKernel = false;

    private const string WEEK = '2026-11-23';

    private Client $client;
    private User $ana;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['headers' => ['Accept' => 'application/json']]);
        $this->ana = $this->createUser('ana@example.com');
        $this->signIn($this->client, $this->ana);
    }

    public function testMergesScalesAndSortsThePlansIngredients(): void
    {
        $lentils = $this->recipe('Lentejas', Language::Es, servings: 4, ingredients: [
            ['300 g de lentejas', 'lentejas', 'lentils', 300, 'g'],
            ['1 cebolla', 'cebolla', 'onion', 1, null],
            ['sal', 'sal', 'salt', null, null],
        ]);
        $soup = $this->recipe('Onion soup', Language::En, servings: 2, ingredients: [
            ['2 onions', 'onion', 'onion', 2, null],
            ['500 ml stock', 'stock', 'stock', 500, 'ml'],
            ['croutons (optional)', 'croutons', 'croutons', null, null, true],
        ]);
        $plan = $this->plan([['2026-11-23', 'lunch', 8], ['2026-11-24', 'dinner', 2]]);
        $this->choose($plan, 0, $lentils);
        $this->choose($plan, 1, $soup);

        $list = $this->list();

        self::assertTrue($list['planned']);
        self::assertSame(2, $list['meals']);
        $items = $this->byKey($list);
        self::assertSame(['onion', 'lentils', 'salt', 'stock'], array_keys($items), 'by name until sorted into aisles; optional ones left out');
        // Lentils for 8 from a recipe for 4: doubled.
        self::assertEquals([['quantity' => 600, 'unit' => 'g']], $items['lentils']['amounts']);
        // 1 onion ×2 (lentils) + 2 onions ×1 (soup).
        self::assertEquals([['quantity' => 4, 'unit' => null]], $items['onion']['amounts']);
        self::assertSame(['Lentejas', 'Onion soup'], $items['onion']['recipes']);
        self::assertSame([], $items['salt']['amounts']);
        self::assertTrue($items['salt']['staple'], 'a known staple');
        self::assertFalse($items['onion']['staple']);
        // Named as the first recipe had it until classified; then in the viewer's language.
        self::assertSame('cebolla', $items['onion']['name']);
        self::assertTrue($list['classifying']);
        $this->assertClassificationQueued();
    }

    public function testClassificationNamesAndSortsIngredients(): void
    {
        $recipe = $this->recipe('Ensalada', Language::Es, servings: 4, ingredients: [
            ['1 lechuga', 'lechuga', 'lettuce', 1, null],
            ['2 huevos', 'huevo', 'egg', 2, null],
        ]);
        $this->choose($this->plan([['2026-11-23', 'dinner', 4]]), 0, $recipe);
        $this->list();
        $this->mockBedrock(new JsonMockResponse([
            'output' => ['message' => ['content' => [['toolUse' => ['toolUseId' => 't', 'name' => 'save_ingredients', 'input' => ['ingredients' => [
                ['key' => 'lettuce', 'name_es' => 'lechuga', 'name_en' => 'lettuce', 'aisle' => 'produce', 'staple' => false],
                ['key' => 'egg', 'name_es' => 'huevo', 'name_en' => 'egg', 'aisle' => 'dairy_eggs', 'staple' => false],
            ]]]]]]],
            'stopReason' => 'tool_use',
            'usage' => ['inputTokens' => 300, 'outputTokens' => 80],
        ]));

        (self::getContainer()->get(ClassifyIngredientsHandler::class))(new ClassifyIngredients($this->ana->getHousehold()->getId()->toRfc4122()));

        $this->client->request('PATCH', '/api/me', ['json' => ['locale' => 'en'], 'headers' => ['Content-Type' => 'application/merge-patch+json']]);
        $list = $this->list();
        self::assertFalse($list['classifying']);
        self::assertSame(['lettuce', 'egg'], array_column($list['items'], 'key'), 'produce before dairy and eggs');
        self::assertSame(['lettuce', 'egg'], array_column($list['items'], 'name'), "in the viewer's language");
    }

    public function testFixingAnIngredientSticks(): void
    {
        $recipe = $this->recipe('Tostadas', Language::Es, servings: 2, ingredients: [['pan', 'pan', 'bread', 1, null]]);
        $this->choose($this->plan([['2026-11-23', 'dinner', 2]]), 0, $recipe);
        $item = $this->list()['items'][0];
        self::assertSame('other', $item['aisle']);

        self::assertIsString($item['ingredientId'] ?? null);
        $this->client->request('PATCH', '/api/ingredients/'.$item['ingredientId'], [
            'json' => ['aisle' => 'bakery', 'staple' => true],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        self::assertResponseIsSuccessful();

        $ingredient = self::getContainer()->get(EntityManagerInterface::class)->getRepository(Ingredient::class)->findOneBy(['canonicalKey' => 'bread']);
        self::assertNotNull($ingredient);
        $ingredient->classify('pan de molde', 'sliced bread', \App\Enum\Aisle::Pantry, false);
        self::assertSame('bakery', $ingredient->getAisle()->value, 'the model does not undo a fix');
        self::assertTrue($ingredient->isStaple());
    }

    public function testCheckingOffAndItemsAddedByHand(): void
    {
        $recipe = $this->recipe('Tortilla', Language::Es, servings: 4, ingredients: [['4 huevos', 'huevo', 'egg', 4, null]]);
        $this->choose($this->plan([['2026-11-23', 'dinner', 4]]), 0, $recipe);

        $list = $this->post('check', ['key' => 'egg']);
        self::assertTrue($this->byKey($list)['egg']['checked']);
        $list = $this->post('check', ['key' => 'egg']);
        self::assertTrue($this->byKey($list)['egg']['checked'], 'checking twice (a replayed offline tap) is harmless');

        $list = $this->post('items', ['text' => 'Bolsas de basura']);
        $manual = $list['items'][1];
        self::assertTrue($manual['manual']);
        self::assertSame('Bolsas de basura', $manual['name']);
        self::assertStringStartsWith('item:', $manual['key']);

        $list = $this->post('check', ['key' => $manual['key']]);
        self::assertTrue($list['items'][1]['checked']);
        $list = $this->post('check', ['key' => 'egg', 'checked' => false]);
        self::assertFalse($this->byKey($list)['egg']['checked']);

        $list = $this->post('items/remove', ['key' => $manual['key']]);
        self::assertCount(1, $list['items']);
    }

    public function testWhatsLeftOfTheWeek(): void
    {
        $a = $this->recipe('A', Language::Es, servings: 1, ingredients: [['1 a', 'a', 'thing_a', 1, null]]);
        $b = $this->recipe('B', Language::Es, servings: 1, ingredients: [['1 b', 'b', 'thing_b', 1, null]]);
        $plan = $this->plan([['2026-11-23', 'dinner', 1], ['2026-11-26', 'dinner', 1]]);
        $this->choose($plan, 0, $a);
        $this->choose($plan, 1, $b);

        $list = $this->list('?from=2026-11-25');

        self::assertSame(1, $list['meals']);
        self::assertSame(['thing_b'], array_column($list['items'], 'key'));
    }

    public function testAWeekWithoutAPlanIsEmptyAndListsArePrivate(): void
    {
        $list = $this->list();
        self::assertFalse($list['planned']);
        self::assertSame([], $list['items']);

        $this->post('items', ['text' => 'Leche']);
        $other = self::createClient([], ['headers' => ['Accept' => 'application/json']]);
        $this->signIn($other, $this->createUser('eva@example.com', 'Otra casa'));
        /** @var ListView $theirs */
        $theirs = $other->request('GET', '/api/shopping-lists/'.self::WEEK)->toArray();
        self::assertSame([], $theirs['items']);
    }

    // —— Helpers ————————————————————————————————————————————————

    /** @return ListView */
    private function list(string $query = ''): array
    {
        /** @var ListView $list */
        $list = $this->client->request('GET', '/api/shopping-lists/'.self::WEEK.$query)->toArray();

        return $list;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return ListView
     */
    private function post(string $action, array $body): array
    {
        /** @var ListView $list */
        $list = $this->client->request('POST', '/api/shopping-lists/'.self::WEEK.'/'.$action, ['json' => $body])->toArray();

        return $list;
    }

    /**
     * @param ListView $list
     *
     * @return array<string, Item>
     */
    private function byKey(array $list): array
    {
        return array_column($list['items'], null, 'key');
    }

    /**
     * @param list<array{0: string, 1: string, 2: int}> $meals date, slot, servings
     *
     * @return array{id: string, entries: list<array{id: string}>}
     */
    private function plan(array $meals): array
    {
        /** @var array{id: string, entries: list<array{id: string}>} $plan */
        $plan = $this->client->request('POST', '/api/meal-plans', ['json' => [
            'weekStart' => self::WEEK,
            'slots' => array_map(static fn (array $m): array => ['date' => $m[0], 'slot' => $m[1], 'servings' => $m[2]], $meals),
        ]])->toArray();

        return $plan;
    }

    /** @param array{id: string, entries: list<array{id: string}>} $plan */
    private function choose(array $plan, int $entry, Recipe $recipe): void
    {
        $this->client->request('POST', '/api/meal-plans/'.$plan['id'].'/entries/update', ['json' => [
            'entry' => $plan['entries'][$entry]['id'],
            'recipe' => $recipe->getId()->toRfc4122(),
        ]]);
        self::assertResponseIsSuccessful();
    }

    /** @param list<array{0: string, 1: string, 2: string, 3: float|int|null, 4: string|null, 5?: bool}> $ingredients raw, name, key, quantity, unit, optional */
    private function recipe(string $title, Language $language, int $servings, array $ingredients): Recipe
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $owner = $entityManager->find(User::class, $this->ana->getId());
        self::assertNotNull($owner);

        $recipe = new Recipe();
        $recipe->assignHousehold($owner->getHousehold());
        $recipe->setTitle($title);
        $recipe->setLanguage($language);
        $recipe->setServings($servings);
        $recipe->setIngredients(array_map(static function (array $line): RecipeIngredient {
            $ingredient = new RecipeIngredient();
            $ingredient->setRawText($line[0]);
            $ingredient->setName($line[1]);
            $ingredient->setCanonicalKey($line[2]);
            $ingredient->setQuantity(null === $line[3] ? null : (float) $line[3]);
            $ingredient->setUnit($line[4]);
            $ingredient->setOptional($line[5] ?? false);

            return $ingredient;
        }, $ingredients));
        $entityManager->persist($recipe);
        $entityManager->flush();

        return $recipe;
    }

    private function mockBedrock(JsonMockResponse $response): void
    {
        $httpClient = self::getContainer()->get('bedrock.http_client');
        self::assertInstanceOf(MockHttpClient::class, $httpClient);
        $httpClient->setResponseFactory([$response]);
    }

    private function assertClassificationQueued(): void
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $messages = array_map(static fn ($envelope): object => $envelope->getMessage(), $transport->getSent());
        self::assertNotEmpty(array_filter($messages, static fn (object $m): bool => $m instanceof ClassifyIngredients));
    }
}
