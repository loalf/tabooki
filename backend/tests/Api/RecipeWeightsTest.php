<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Test\ApiTestCase;
use ApiPlatform\Test\Client;
use App\Entity\Food;
use App\Entity\User;
use App\Food\FoodCatalog;
use App\Message\AnnotateRecipe;
use App\Message\DescribeFoods;
use App\MessageHandler\AnnotateRecipeHandler;
use App\MessageHandler\DescribeFoodsHandler;
use App\Shopping\IngredientCatalog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

/**
 * @phpstan-type View array{nutrition?: array{kcal: int|float, protein: int|float, coverage: int|float, unweighed: int}|null, allergens?: list<string>, optionalAllergens?: list<string>, allergensComplete?: bool}
 * @phpstan-type Line array{rawText: string, canonicalKey?: string|null, name?: string|null, quantity?: float|int|null, unit?: string|null, grams?: float|int|null, gramsSource?: string|null}
 */
final class RecipeWeightsTest extends ApiTestCase
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

    public function testLinesAreWeighedMostTrustedFirst(): void
    {
        $id = $this->create([
            ['rawText' => '200 g de garbanzos', 'canonicalKey' => 'chickpeas_cooked', 'quantity' => 200, 'unit' => 'g'],
            ['rawText' => '2 cucharadas de aceite', 'canonicalKey' => 'olive_oil', 'quantity' => 2, 'unit' => 'tbsp'],
            ['rawText' => '1 cebolla grande', 'canonicalKey' => 'onion', 'quantity' => 1, 'unit' => null],
            ['rawText' => '1 lata de tomate triturado', 'canonicalKey' => 'crushed_tomato', 'quantity' => 1, 'unit' => 'can'],
            ['rawText' => 'Sal al gusto', 'canonicalKey' => 'salt'],
            ['rawText' => 'Un chorrito de vino blanco'],
        ]);

        // Saved: what can be worked out without knowing the foods.
        self::assertSame([
            [200, 'measured'], [30, 'measured'], [null, null], [400, 'converted'], [null, null], [null, null],
        ], $this->weights($id), 'oil as if water, a usual can');

        // The foods described: the oil's density and an onion's weight are known.
        $this->mockBedrock(['foods' => [
            self::food('chickpeas_cooked'), self::food('crushed_tomato'), self::food('salt', density: 1.2),
            self::food('olive_oil', density: 0.92), self::food('onion', piece: 150),
        ]], 'save_foods');
        self::getContainer()->get(DescribeFoodsHandler::class)(new DescribeFoods($this->ana->getHousehold()->getId()->toRfc4122()));
        self::assertSame([
            [200, 'measured'], [27.6, 'measured'], [150, 'converted'], [400, 'converted'], [null, null], [null, null],
        ], $this->weights($id));

        // Read by the model: its estimates beat a piece's usual weight, never a measured amount;
        // the line typed by hand is understood.
        $this->mockBedrock(self::facts([
            ['line' => 1, 'grams' => 999],
            ['line' => 3, 'grams' => 220],
            ['line' => 4, 'grams' => 390],
            ['line' => 5, 'grams' => 4],
            ['line' => 6, 'grams' => 50, 'canonical_key' => 'White Wine', 'name' => 'vino blanco', 'quantity' => 50, 'unit' => 'ml'],
        ]), 'save_recipe_facts');
        $this->read($id);
        self::assertSame([
            [200, 'measured'], [27.6, 'measured'], [220, 'estimated'], [390, 'estimated'], [4, 'estimated'], [50, 'measured'],
        ], $this->weights($id));
        $wine = $this->lines($id)[5];
        self::assertEquals(['white_wine', 'vino blanco', 50, 'ml'], [$wine['canonicalKey'] ?? null, $wine['name'] ?? null, $wine['quantity'] ?? null, $wine['unit'] ?? null]);
        self::assertNotNull(self::getContainer()->get(EntityManagerInterface::class)->getRepository(Food::class)->findOneBy(['key' => 'white_wine']), 'now in the food catalog');
    }

    public function testAWeightTypedInIsKeptAndUnchangedLinesKeepTheirs(): void
    {
        $id = $this->create([
            ['rawText' => '1 cebolla', 'canonicalKey' => 'onion', 'quantity' => 1],
            ['rawText' => 'Sal', 'canonicalKey' => 'salt'],
        ]);
        $this->mockBedrock(self::facts([['line' => 1, 'grams' => 150], ['line' => 2, 'grams' => 2]]), 'save_recipe_facts');
        $this->read($id);

        // The edit form sends the lines back as they were, with a weight typed in for one.
        $lines = $this->lines($id);
        $lines[0]['grams'] = 180;
        $lines[0]['gramsSource'] = 'manual';
        $this->patch($id, ['title' => 'Cebolla con sal', 'ingredients' => $lines]);
        self::assertSame([[180, 'manual'], [2, 'estimated']], $this->weights($id), 'the estimate came back with the line');

        $this->mockBedrock(self::facts([['line' => 1, 'grams' => 150], ['line' => 2, 'grams' => 3]]), 'save_recipe_facts');
        $this->read($id);
        self::assertSame([[180, 'manual'], [3, 'estimated']], $this->weights($id), 'the model does not undo a weight typed in');

        // Typed in through the API without a source: also by hand.
        $lines = $this->lines($id);
        $lines[1]['grams'] = 5;
        unset($lines[1]['gramsSource']);
        $this->patch($id, ['ingredients' => $lines]);
        self::assertSame([[180, 'manual'], [5, 'manual']], $this->weights($id));
    }

    public function testTheRecipeAddsUpItsFoodsAndFollowsAnIngredientPointedElsewhere(): void
    {
        $id = $this->create([
            ['rawText' => '400 g de garbanzos', 'canonicalKey' => 'chickpeas_cooked', 'quantity' => 400, 'unit' => 'g'],
            ['rawText' => '200 g de pasta', 'canonicalKey' => 'pasta', 'quantity' => 200, 'unit' => 'g'],
            ['rawText' => 'Parmesano (opcional)', 'canonicalKey' => 'parmesan', 'quantity' => 20, 'unit' => 'g', 'optional' => true],
        ]);
        self::assertNull($this->get($id)['nutrition'] ?? null, 'nothing known about the foods yet');
        self::assertFalse($this->get($id)['allergensComplete'] ?? null, 'so nothing known to be missing either');

        $this->mockBedrock(['foods' => [
            [...self::food('chickpeas_cooked'), 'kcal' => 140, 'protein' => 7],
            [...self::food('pasta'), 'kcal' => 360, 'protein' => 12, 'allergens' => ['gluten']],
            [...self::food('parmesan'), 'kcal' => 400, 'allergens' => ['milk']],
            [...self::food('chickpeas_dried'), 'kcal' => 364, 'protein' => 19],
        ]], 'save_foods');
        self::getContainer()->get(FoodCatalog::class)->ensure($this->ana->getHousehold(), ['chickpeas_dried' => ['name' => 'garbanzos secos', 'language' => 'es']]);
        self::getContainer()->get(DescribeFoodsHandler::class)(new DescribeFoods($this->ana->getHousehold()->getId()->toRfc4122()));

        $recipe = $this->get($id);
        // (400 × 1.40 + 200 × 3.60) / 4 servings = 320 kcal; (28 + 24) / 4 = 13 g protein.
        self::assertEquals([320, 13], [$recipe['nutrition']['kcal'] ?? null, $recipe['nutrition']['protein'] ?? null]);
        self::assertEquals([1, 0], [$recipe['nutrition']['coverage'] ?? null, $recipe['nutrition']['unweighed'] ?? null]);
        self::assertSame(['gluten'], $recipe['allergens'] ?? null);
        self::assertSame(['milk'], $recipe['optionalAllergens'] ?? null);
        self::assertTrue($recipe['allergensComplete'] ?? null);

        /** @var list<array{title: string, nutrition: array{kcal: int}|null, allergens: list<string>, allergensComplete: bool}> $list */
        $list = $this->client->request('GET', '/api/recipes')->toArray();
        self::assertSame([320, ['gluten'], true], [$list[0]['nutrition']['kcal'] ?? null, $list[0]['allergens'], $list[0]['allergensComplete']], 'the library filters on them');

        // The household says its "chickpeas" are dried ones: the recipe follows.
        $ingredients = self::getContainer()->get(IngredientCatalog::class)->forKeys($this->ana->getHousehold(), ['chickpeas_cooked' => ['name' => 'garbanzos', 'language' => 'es']]);
        $dried = self::getContainer()->get(EntityManagerInterface::class)->getRepository(Food::class)->findOneBy(['key' => 'chickpeas_dried']);
        self::assertNotNull($dried);
        $this->client->request('PATCH', '/api/ingredients/'.$ingredients['chickpeas_cooked']->getId()->toRfc4122(), [
            'json' => ['food' => '/api/foods/'.$dried->getId()->toRfc4122()],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame(544, $this->get($id)['nutrition']['kcal'] ?? null, '(400 × 3.64 + 720) / 4');
    }

    /** @return View */
    private function get(string $id): array
    {
        /** @var View $recipe */
        $recipe = $this->client->request('GET', '/api/recipes/'.$id)->toArray();

        return $recipe;
    }

    /** @param list<Line> $lines */
    private function create(array $lines): string
    {
        $recipe = $this->client->request('POST', '/api/recipes', ['json' => [
            'title' => 'Guiso', 'language' => 'es', 'servings' => 4, 'ingredients' => $lines, 'steps' => ['Guisar.'],
        ]])->toArray();
        self::assertResponseStatusCodeSame(201);
        self::assertIsString($recipe['id'] ?? null);

        return $recipe['id'];
    }

    /** @param array<string, mixed> $body */
    private function patch(string $id, array $body): void
    {
        $this->client->request('PATCH', '/api/recipes/'.$id, ['json' => $body, 'headers' => ['Content-Type' => 'application/merge-patch+json']]);
        self::assertResponseIsSuccessful();
    }

    /** @return list<Line> */
    private function lines(string $id): array
    {
        /** @var list<Line> $lines */
        $lines = $this->client->request('GET', '/api/recipes/'.$id)->toArray()['ingredients'];

        return $lines;
    }

    /** @return list<array{0: float|int|null, 1: string|null}> grams (whole numbers as ints) and source of each line */
    private function weights(string $id): array
    {
        return array_map(static function (array $line): array {
            $grams = $line['grams'] ?? null;

            return [null !== $grams && floor((float) $grams) === (float) $grams ? (int) $grams : $grams, $line['gramsSource'] ?? null];
        }, $this->lines($id));
    }

    private function read(string $id): void
    {
        self::getContainer()->get(AnnotateRecipeHandler::class)(new AnnotateRecipe($id));
        self::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    /**
     * @param list<array<string, mixed>> $lines
     *
     * @return array<string, mixed>
     */
    private static function facts(array $lines): array
    {
        $empty = ['grams' => null, 'canonical_key' => null, 'name' => null, 'quantity' => null, 'unit' => null];

        return [
            'heaviness' => 'medium', 'spice' => 0, 'food_groups' => [], 'optional_food_groups' => [],
            'ingredients' => array_map(static fn (array $line): array => [...$empty, ...$line], $lines),
        ];
    }

    /** @return array<string, mixed> */
    private static function food(string $key, ?float $piece = null, ?float $density = null): array
    {
        return [
            'key' => $key, 'name_es' => $key, 'name_en' => $key, 'kcal' => 100, 'protein' => 1, 'fat' => 1, 'saturated_fat' => 0, 'carbs' => 1,
            'sugar' => 0, 'fiber' => 0, 'salt' => 0, 'food_groups' => [], 'allergens' => [], 'spice' => 0, 'grams_per_piece' => $piece, 'density' => $density,
        ];
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
