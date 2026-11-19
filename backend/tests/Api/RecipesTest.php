<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Test\ApiTestCase;
use ApiPlatform\Test\Client;
use App\Entity\User;

final class RecipesTest extends ApiTestCase
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

    public function testCreateAndReadARecipe(): void
    {
        $sourceId = $this->saveLink('https://recetas.example/pure');

        $created = $this->client->request('POST', '/api/recipes', ['json' => [
            'title' => '  Puré de patatas ',
            'language' => 'es',
            'servings' => 2,
            'prepMinutes' => 10,
            'cookMinutes' => 25,
            'mealTypes' => ['dinner', 'lunch'],
            'tags' => ['Vegetariano', ' rápido ', 'vegetariano', ''],
            'ingredients' => [
                ['rawText' => '1 kg de patatas'],
                ['rawText' => '200 ml de leche', 'groupName' => 'Para la crema'],
                ['rawText' => 'Nuez moscada', 'optional' => true],
            ],
            'steps' => ['Cocer las patatas.', '  ', 'Triturar con la leche.'],
            'notes' => 'De la abuela.',
            'source' => '/api/sources/'.$sourceId,
        ]]);

        self::assertResponseStatusCodeSame(201);
        /** @var array{title: string, totalMinutes: int, mealTypes: list<string>, tags: list<string>, steps: list<string>, ingredients: list<array{rawText: string, groupName?: string, optional: bool}>, favorite: bool, source: array{id: string, url: string}} $recipe */
        $recipe = $created->toArray();
        self::assertSame('Puré de patatas', $recipe['title']);
        self::assertSame(35, $recipe['totalMinutes']);
        self::assertSame(['lunch', 'dinner'], $recipe['mealTypes']);
        self::assertSame(['vegetariano', 'rápido'], $recipe['tags']);
        self::assertSame(['Cocer las patatas.', 'Triturar con la leche.'], $recipe['steps']);
        self::assertSame(['1 kg de patatas', '200 ml de leche', 'Nuez moscada'], array_column($recipe['ingredients'], 'rawText'));
        self::assertSame('Para la crema', $recipe['ingredients'][1]['groupName'] ?? null);
        self::assertTrue($recipe['ingredients'][2]['optional']);
        self::assertFalse($recipe['favorite']);
        self::assertSame($sourceId, $recipe['source']['id']);
        self::assertSame('https://recetas.example/pure', $recipe['source']['url']);

        // The list has a lighter shape.
        /** @var list<array{title: string, source: array{platform: string}}> $list */
        $list = $this->client->request('GET', '/api/recipes')->toArray();
        self::assertCount(1, $list);
        self::assertSame('Puré de patatas', $list[0]['title']);
        self::assertArrayNotHasKey('ingredients', $list[0]);
        self::assertArrayNotHasKey('steps', $list[0]);
        self::assertSame('web', $list[0]['source']['platform']);
    }

    public function testDefaults(): void
    {
        $recipe = $this->client->request('POST', '/api/recipes', ['json' => ['title' => 'Ensalada']])->toArray();

        self::assertResponseStatusCodeSame(201);
        self::assertSame(4, $recipe['servings']);
        self::assertSame('es', $recipe['language']);
        self::assertSame(['lunch', 'dinner'], $recipe['mealTypes']);
        self::assertSame([], $recipe['tags']);
        self::assertSame([], $recipe['ingredients']);
        self::assertArrayNotHasKey('totalMinutes', $recipe);
        self::assertArrayNotHasKey('source', $recipe);
    }

    public function testValidation(): void
    {
        $this->client->request('POST', '/api/recipes', ['json' => [
            'title' => ' ',
            'servings' => 0,
            'rating' => 6,
            'ingredients' => [['rawText' => '']],
        ]]);

        self::assertResponseStatusCodeSame(422);
        /** @var array{violations: list<array{propertyPath: string}>} $error */
        $error = $this->client->getResponse()?->toArray(false) ?? [];
        $paths = array_column($error['violations'], 'propertyPath');
        sort($paths);
        self::assertSame(['ingredients[0].rawText', 'rating', 'servings', 'title'], $paths);

        $this->client->request('POST', '/api/recipes', ['json' => ['title' => 'X', 'mealTypes' => ['breakfast']]]);
        self::assertResponseStatusCodeSame(400);
    }

    public function testUpdateReplacesIngredients(): void
    {
        $id = $this->createRecipe('Gazpacho', ingredients: ['1 kg tomates', '1 pepino']);

        $this->client->request('PATCH', '/api/recipes/'.$id, [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => [
                'favorite' => true,
                'rating' => 5,
                'ingredients' => [['rawText' => '1 pimiento verde'], ['rawText' => '1 kg tomates maduros']],
            ],
        ]);
        self::assertResponseIsSuccessful();

        /** @var array{title: string, favorite: bool, rating: int, ingredients: list<array{rawText: string}>} $recipe */
        $recipe = $this->client->request('GET', '/api/recipes/'.$id)->toArray();
        self::assertSame('Gazpacho', $recipe['title']);
        self::assertTrue($recipe['favorite']);
        self::assertSame(5, $recipe['rating']);
        self::assertSame(['1 pimiento verde', '1 kg tomates maduros'], array_column($recipe['ingredients'], 'rawText'));

        // The search index follows ingredient-only changes.
        self::assertSame(['Gazpacho'], $this->titles('/api/recipes?q=pimiento'));
        self::assertSame([], $this->titles('/api/recipes?q=pepino'));
    }

    public function testSearchAndFilters(): void
    {
        $this->createRecipe('Puré de calabaza', tags: ['vegetariano', 'otoño'], ingredients: ['1 calabaza', 'Nuez moscada']);
        $this->createRecipe('Lentejas con chorizo', tags: ['legumbres'], ingredients: ['300 g lentejas', '1 chorizo']);
        $this->createRecipe('Lentejas veganas', tags: ['legumbres', 'vegetariano'], ingredients: ['300 g lentejas'], favorite: true);

        self::assertSame(['Puré de calabaza'], $this->titles('/api/recipes?q=PURE'));
        self::assertSame(['Puré de calabaza'], $this->titles('/api/recipes?q=moscada'));
        self::assertSame(['Lentejas veganas', 'Lentejas con chorizo'], $this->titles('/api/recipes?q=lentejas'));
        self::assertSame(['Lentejas con chorizo'], $this->titles('/api/recipes?q=lentejas%20chorizo'));
        self::assertSame([], $this->titles('/api/recipes?q=100%25'));

        self::assertSame(['Lentejas veganas', 'Puré de calabaza'], $this->titles('/api/recipes?tags[]=vegetariano'));
        self::assertSame(['Lentejas veganas'], $this->titles('/api/recipes?tags[]=vegetariano&tags[]=Legumbres'));
        self::assertSame(['Lentejas veganas'], $this->titles('/api/recipes?favorite=true'));
        self::assertSame(['Lentejas veganas'], $this->titles('/api/recipes?q=lentejas&tags[]=vegetariano'));

        $tags = $this->client->request('GET', '/api/recipe-tags')->toArray();
        self::assertSame([
            ['tag' => 'legumbres', 'count' => 2],
            ['tag' => 'vegetariano', 'count' => 2],
            ['tag' => 'otoño', 'count' => 1],
        ], $tags);
    }

    public function testDeletingARecipeSendsItsLinkBackToTheInbox(): void
    {
        $sourceId = $this->saveLink('https://example.com/r');
        $id = $this->createRecipe('R', source: $sourceId);
        self::assertSame([], $this->client->request('GET', '/api/sources?hasRecipe=false')->toArray());

        $this->client->request('DELETE', '/api/recipes/'.$id);
        self::assertResponseStatusCodeSame(204);

        self::assertCount(1, $this->client->request('GET', '/api/sources?hasRecipe=false')->toArray());
    }

    public function testRecipesAreScopedToTheHousehold(): void
    {
        $id = $this->createRecipe('Secreto de la casa', tags: ['familia']);
        $sourceId = $this->saveLink('https://example.com/ours');

        $other = self::createClient([], ['headers' => ['Accept' => 'application/json']]);
        $this->signIn($other, $this->createUser('bea@example.com', 'Otra casa'));

        self::assertSame([], $other->request('GET', '/api/recipes')->toArray());
        self::assertSame([], $other->request('GET', '/api/recipe-tags')->toArray());
        $other->request('GET', '/api/recipes/'.$id);
        self::assertResponseStatusCodeSame(404);
        $other->request('PATCH', '/api/recipes/'.$id, ['headers' => ['Content-Type' => 'application/merge-patch+json'], 'json' => ['title' => 'Mine now']]);
        self::assertResponseStatusCodeSame(404);

        // Can't attach another household's link to a recipe.
        $other->request('POST', '/api/recipes', ['json' => ['title' => 'Copy', 'source' => '/api/sources/'.$sourceId]]);
        self::assertResponseStatusCodeSame(400);
    }

    /**
     * @param list<string> $tags
     * @param list<string> $ingredients
     */
    private function createRecipe(string $title, array $tags = [], array $ingredients = [], bool $favorite = false, ?string $source = null): string
    {
        $id = $this->client->request('POST', '/api/recipes', ['json' => [
            'title' => $title,
            'tags' => $tags,
            'ingredients' => array_map(static fn (string $line): array => ['rawText' => $line], $ingredients),
            'favorite' => $favorite,
            'source' => null === $source ? null : '/api/sources/'.$source,
        ]])->toArray()['id'];
        self::assertIsString($id);

        return $id;
    }

    private function saveLink(string $url): string
    {
        $id = $this->client->request('POST', '/api/sources', ['json' => ['url' => $url]])->toArray()['id'];
        self::assertIsString($id);

        return $id;
    }

    /** @return list<string> */
    private function titles(string $url): array
    {
        /** @var list<array{title: string}> $rows */
        $rows = $this->client->request('GET', $url)->toArray();

        return array_column($rows, 'title');
    }
}
