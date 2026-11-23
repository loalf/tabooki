<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Test\ApiTestCase;
use ApiPlatform\Test\Client;

final class SimilarRecipesTest extends ApiTestCase
{
    use SignsInUsers;

    private Client $client;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['headers' => ['Accept' => 'application/json']]);
        $this->signIn($this->client, $this->createUser('ana@example.com'));
    }

    public function testRecipesSharingTheRarestIngredientsComeFirst(): void
    {
        $stew = $this->create('Garbanzos con espinacas', ['chickpeas' => 'garbanzos', 'spinach' => 'espinacas', 'tomato' => 'tomate', 'onion' => 'cebolla'], ['chickpeas']);
        $this->create('Hummus', ['chickpeas' => 'garbanzos', 'tahini' => 'tahini', 'garlic' => 'ajo'], ['chickpeas']);
        $this->create('Espinacas a la crema', ['spinach' => 'espinacas', 'cream' => 'nata']);
        $this->create('Ensalada', ['tomato' => 'tomate', 'lettuce' => 'lechuga', 'cucumber' => 'pepino', 'onion' => 'cebolla']);
        $this->create('Macarrones', ['pasta' => 'pasta', 'tomato' => 'tomate', 'cheese' => 'queso']);
        $this->create('Tortilla', ['egg' => 'huevo', 'potato' => 'patata', 'onion' => 'cebolla']);
        // Tomato is in three recipes, chickpeas and spinach in two: sharing those counts for more.

        /** @var list<array{title: string, shared: list<array{es: string, en: string}>, score: float}> $similar */
        $similar = $this->client->request('GET', "/api/recipes/{$stew}/similar")->toArray();
        self::assertResponseIsSuccessful();

        self::assertSame(['Hummus', 'Espinacas a la crema'], array_column($similar, 'title'), 'not just a tomato in common, nor an onion (a staple)');
        self::assertSame([['es' => 'garbanzos', 'en' => 'garbanzos']], $similar[0]['shared']);
        self::assertGreaterThan($similar[1]['score'], $similar[0]['score'], 'chickpeas and a tag beat spinach alone');
    }

    public function testOnlyTheHouseholdsOwnRecipes(): void
    {
        $id = $this->create('Lentejas', ['lentils' => 'lentejas']);
        $other = self::createClient([], ['headers' => ['Accept' => 'application/json']]);
        $this->signIn($other, $this->createUser('bea@example.com', 'Otra casa'));

        $other->request('GET', "/api/recipes/{$id}/similar");
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @param array<string, string> $ingredients name by canonical key
     * @param list<string>          $tags
     */
    private function create(string $title, array $ingredients, array $tags = []): string
    {
        $lines = [];
        foreach ($ingredients as $key => $name) {
            $lines[] = ['rawText' => $name, 'canonicalKey' => $key, 'name' => $name];
        }
        $id = $this->client->request('POST', '/api/recipes', ['json' => ['title' => $title, 'language' => 'es', 'tags' => $tags, 'ingredients' => $lines]])->toArray()['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }
}
