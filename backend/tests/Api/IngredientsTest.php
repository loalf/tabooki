<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Test\ApiTestCase;
use ApiPlatform\Test\Client;
use App\Entity\User;
use App\Food\FoodCatalog;

/**
 * @phpstan-type IngredientView array{id: string, canonicalKey: string, recipeCount: int, food?: array{id: string, key: string}|null, recipes?: list<array{id: string, title: string}>, keyFoodId?: string|null}
 */
final class IngredientsTest extends ApiTestCase
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

    public function testEveryIngredientOfTheRecipesCanBeSeenAndPointedAtAnotherFood(): void
    {
        $this->recipe('Hummus', [['garbanzos cocidos', 'chickpeas_cooked'], ['ajo', 'garlic']]);
        $this->recipe('Cocido', [['garbanzos', 'chickpeas_cooked'], ['Sal al gusto', null]]);

        /** @var list<IngredientView> $list */
        $list = $this->client->request('GET', '/api/ingredients')->toArray();
        self::assertSame(['chickpeas_cooked' => 2, 'garlic' => 1], array_column($list, 'recipeCount', 'canonicalKey'), 'saved recipes bring them all; a line without a key doesn\'t');
        self::assertSame('chickpeas_cooked', $list[0]['food']['key'] ?? null);
        $chickpeas = $list[0]['id'];
        $ownFood = $list[0]['food']['id'];

        // A food to point it at, found by its name or its key, accents or not.
        self::getContainer()->get(FoodCatalog::class)->ensure($this->ana->getHousehold(), ['chickpeas_dried' => ['name' => 'garbanzos secos', 'language' => 'es']]);
        self::assertSame(['chickpeas_dried'], array_column($this->foods('garbanzos SECOS'), 'key'));
        self::assertSame(['chickpeas_cooked', 'chickpeas_dried'], array_column($this->foods('chickpea'), 'key'), 'the one recipes use most first');
        $dried = $this->foods('chickpeas dried')[0]['id'];

        $this->client->request('PATCH', '/api/ingredients/'.$chickpeas, [
            'json' => ['food' => '/api/foods/'.$dried],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        self::assertResponseIsSuccessful();

        /** @var IngredientView $one */
        $one = $this->client->request('GET', '/api/ingredients/'.$chickpeas)->toArray();
        self::assertSame(['Cocido', 'Hummus'], array_column($one['recipes'] ?? [], 'title'));
        self::assertSame('chickpeas_dried', $one['food']['key'] ?? null);
        self::assertSame($ownFood, $one['keyFoodId'] ?? null, 'its own food, to go back to');

        $other = self::createClient([], ['headers' => ['Accept' => 'application/json']]);
        $this->signIn($other, $this->createUser('bea@example.com', 'Otra casa'));
        self::assertSame([], $other->request('GET', '/api/ingredients')->toArray());
        $other->request('GET', '/api/ingredients/'.$chickpeas);
        self::assertResponseStatusCodeSame(404);
    }

    /** @return list<array{id: string, key: string}> */
    private function foods(string $query): array
    {
        /** @var list<array{id: string, key: string}> $foods */
        $foods = $this->client->request('GET', '/api/foods?q='.rawurlencode($query))->toArray();

        return $foods;
    }

    /** @param list<array{0: string, 1: string|null}> $lines name, key */
    private function recipe(string $title, array $lines): void
    {
        $this->client->request('POST', '/api/recipes', ['json' => [
            'title' => $title, 'language' => 'es',
            'ingredients' => array_map(static fn (array $l): array => ['rawText' => $l[0], 'name' => $l[0], 'canonicalKey' => $l[1]], $lines),
        ]]);
        self::assertResponseStatusCodeSame(201);
    }
}
