<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Test\ApiTestCase;
use ApiPlatform\Test\Client;
use App\Entity\User;
use App\Message\DescribeFoods;
use App\MessageHandler\DescribeFoodsHandler;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

/**
 * @phpstan-type FoodView array{id: string, kcal: float|int|null, allergens: list<string>, edited: bool, described: bool, correctedByName?: string|null, gramsPerPiece: float|int|null}
 */
final class FoodCorrectionTest extends ApiTestCase
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

    public function testAFixByHandSticksAndCanBeUndone(): void
    {
        $recipe = $this->client->request('POST', '/api/recipes', ['json' => [
            'title' => 'Garbanzos', 'language' => 'es', 'servings' => 4,
            'ingredients' => [['rawText' => '400 g de garbanzos', 'canonicalKey' => 'chickpeas_cooked', 'quantity' => 400, 'unit' => 'g']],
        ]])->toArray()['id'] ?? null;
        self::assertIsString($recipe);
        $this->describe(140);
        self::assertSame(140, $this->kcal($recipe), '400 g × 1.40 / 4');

        $food = $this->food();
        $this->correct($food['id'], ['kcal' => 164, 'protein' => 8.9, 'fat' => 2.6, 'saturatedFat' => 0.3, 'carbs' => 18, 'sugar' => 4.8, 'fiber' => 7.6, 'salt' => 0, 'allergens' => ['sesame'], 'spice' => 0, 'gramsPerPiece' => 250, 'density' => null]);
        self::assertResponseIsSuccessful();
        $food = $this->food();
        self::assertEquals([164, ['sesame'], true, 'Ana', 250], [$food['kcal'], $food['allergens'], $food['edited'], $food['correctedByName'] ?? null, $food['gramsPerPiece']]);
        self::assertSame(164, $this->kcal($recipe), 'the recipe follows');

        $this->correct($food['id'], ['kcal' => 100, 'protein' => 1, 'fat' => 1, 'saturatedFat' => 2, 'carbs' => 1, 'sugar' => 3, 'fiber' => 1, 'salt' => 0, 'allergens' => ['nope'], 'spice' => 5, 'gramsPerPiece' => null, 'density' => null]);
        self::assertResponseStatusCodeSame(422);
        /** @var array{violations: list<array{propertyPath: string}>} $error */
        $error = $this->client->getResponse()?->toArray(false) ?? [];
        self::assertSame(['saturatedFat', 'sugar', 'allergens[0]', 'spice'], array_column($error['violations'], 'propertyPath'));

        // Back to the model's figures.
        $this->client->request('POST', '/api/foods/'.$food['id'].'/estimate');
        self::assertResponseStatusCodeSame(202);
        self::assertEquals([false, false], [$this->food()['edited'], $this->food()['described']]);
        $this->describe(150);
        self::assertEquals([150, false], [$this->food()['kcal'], $this->food()['edited']]);
        self::assertSame(150, $this->kcal($recipe));
    }

    /** @param array<string, mixed> $figures */
    private function correct(string $food, array $figures): void
    {
        $this->client->request('POST', '/api/foods/'.$food.'/correction', ['json' => $figures]);
    }

    /** @return FoodView */
    private function food(): array
    {
        /** @var list<FoodView> $foods */
        $foods = $this->client->request('GET', '/api/foods?q=chickpeas')->toArray();
        self::assertCount(1, $foods);

        return $foods[0];
    }

    private function kcal(string $recipe): mixed
    {
        /** @var array{nutrition?: array{kcal: int}|null} $view */
        $view = $this->client->request('GET', '/api/recipes/'.$recipe)->toArray();

        return $view['nutrition']['kcal'] ?? null;
    }

    private function describe(float $kcal): void
    {
        $httpClient = self::getContainer()->get('bedrock.http_client');
        self::assertInstanceOf(MockHttpClient::class, $httpClient);
        $httpClient->setResponseFactory([new JsonMockResponse([
            'output' => ['message' => ['content' => [['toolUse' => ['toolUseId' => 't', 'name' => 'save_foods', 'input' => ['foods' => [[
                'key' => 'chickpeas_cooked', 'name_es' => 'garbanzos cocidos', 'name_en' => 'cooked chickpeas', 'kcal' => $kcal, 'protein' => 7, 'fat' => 2, 'saturated_fat' => 0.3,
                'carbs' => 17, 'sugar' => 1, 'fiber' => 6, 'salt' => 0, 'food_groups' => [], 'allergens' => [], 'spice' => 0, 'grams_per_piece' => null, 'density' => null,
            ]]]]]]]],
            'stopReason' => 'tool_use',
            'usage' => ['inputTokens' => 100, 'outputTokens' => 50],
        ])]);
        self::getContainer()->get(DescribeFoodsHandler::class)(new DescribeFoods($this->ana->getHousehold()->getId()->toRfc4122()));
    }
}
