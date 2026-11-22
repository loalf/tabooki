<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Test\ApiTestCase;
use ApiPlatform\Test\Client;
use App\Entity\ExtractionRun;
use App\Entity\Recipe;
use App\Entity\User;
use App\Extraction\ExtractionSchema;
use App\Message\TranslateRecipe;
use App\MessageHandler\ClassifyIngredientsHandler;
use App\MessageHandler\TranslateRecipeHandler;
use App\Planning\RecipeAnnotator;
use App\Taste\NotesJudge;
use App\Taste\TasteCompiler;
use App\Translation\RecipeTranslator;
use App\Translation\TranslationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

/** Every recipe in both languages: each member of the household reads it in theirs. */
final class TranslationTest extends ApiTestCase
{
    use SignsInUsers;

    protected static ?bool $alwaysBootKernel = false;

    private Client $client;
    private User $ana;
    private User $ben;

    /** @var list<array<mixed>> */
    private array $bedrockRequests = [];

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['headers' => ['Accept' => 'application/json']]);
        $this->ana = $this->createUser('ana@example.com');
        $this->ben = $this->createUser('ben@example.com', household: $this->ana->getHousehold());
        $this->ben->setLocale('en');
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $this->signIn($this->client, $this->ana);
    }

    public function testEachReaderSeesTheRecipeInTheirLanguage(): void
    {
        $id = $this->createRecipe();
        self::assertSame([$id], $this->queuedTranslations(), 'a new recipe is queued for translation');

        $this->mockBedrock([$this->answer()]);
        $this->translate($id);

        // What the model was asked: Spanish into English, line for line.
        $prompt = $this->bedrockRequests[0];
        self::assertStringContainsString('from Spanish into English', $this->system($prompt));
        self::assertStringContainsString('"line": "~200 g de garbanzos cocidos"', $this->userText($prompt));
        self::assertSame(RecipeTranslator::TOOL, $this->toolName($prompt));

        // Ana wrote it in Spanish and reads it in Spanish.
        $mine = $this->client->request('GET', '/api/recipes/'.$id)->toArray();
        self::assertSame('Hummus casero', $mine['title']);
        self::assertSame('es', $mine['language']);
        self::assertArrayNotHasKey('translatedFrom', $mine);

        // Ben reads English, with the same amounts.
        $this->signIn($this->client, $this->ben);
        /** @var array{title: string, language: string, translatedFrom: string, tags: list<string>, ingredients: list<array{rawText: string, quantity?: float, name?: string, prep?: string, groupName?: string}>, steps: list<string>, notes: string} $theirs */
        $theirs = $this->client->request('GET', '/api/recipes/'.$id)->toArray();
        self::assertSame('Homemade hummus', $theirs['title']);
        self::assertSame('en', $theirs['language']);
        self::assertSame('es', $theirs['translatedFrom']);
        self::assertSame(['dip', 'vegetarian'], $theirs['tags']);
        self::assertSame(['~200 g cooked chickpeas', '1 tbsp tahini', 'Salt'], array_column($theirs['ingredients'], 'rawText'));
        self::assertSame([200.0, 1.0], [$theirs['ingredients'][0]['quantity'] ?? null, $theirs['ingredients'][1]['quantity'] ?? null]);
        self::assertSame('cooked chickpeas', $theirs['ingredients'][0]['name'] ?? null);
        self::assertSame('For the dip', $theirs['ingredients'][0]['groupName'] ?? null);
        self::assertSame('drained', $theirs['ingredients'][0]['prep'] ?? null);
        self::assertArrayNotHasKey('prep', $theirs['ingredients'][1]);
        self::assertSame(['Blend everything.', 'Season with salt.'], $theirs['steps']);
        self::assertSame("Grandma's.", $theirs['notes']);

        // The library, its tags and its search, in English too.
        $list = $this->client->request('GET', '/api/recipes')->toArray();
        self::assertSame(['Homemade hummus'], array_column($list, 'title'));
        self::assertSame(['dip', 'vegetarian'], array_column($this->client->request('GET', '/api/recipe-tags')->toArray(), 'tag'));
        self::assertCount(1, $this->client->request('GET', '/api/recipes?tags[]=vegetarian')->toArray());
        self::assertCount(1, $this->client->request('GET', '/api/recipes?q=chickpeas')->toArray());
        self::assertCount(1, $this->client->request('GET', '/api/recipes?q=garbanzos')->toArray(), 'search covers both languages');

        // The translation call is on the costs page.
        $runs = self::getContainer()->get(EntityManagerInterface::class)->getRepository(ExtractionRun::class)->findBy(['step' => 'translate']);
        self::assertCount(1, $runs);
        self::assertTrue($runs[0]->isOk());
    }

    public function testSavingTheTranslationUnchangedKeepsTheOriginal(): void
    {
        $id = $this->translatedRecipe();
        $this->signIn($this->client, $this->ben);
        $shown = $this->client->request('GET', '/api/recipes/'.$id)->toArray();

        // The editor sends back everything it showed (in English), plus what Ben changed.
        $this->client->request('PATCH', '/api/recipes/'.$id, [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['servings' => 6, 'needsReview' => false] + array_intersect_key($shown, array_flip(['title', 'language', 'tags', 'ingredients', 'steps', 'notes'])),
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->queuedTranslations(), 'nothing to translate again');

        $recipe = $this->recipe($id);
        self::assertSame('es', $recipe->getLanguage()->value);
        self::assertSame('Hummus casero', $recipe->getTitle());
        self::assertSame(6, $recipe->getServings());
        self::assertSame('Homemade hummus', $recipe->getTranslation()?->title);
    }

    public function testEditingTheTranslationMakesItTheOriginal(): void
    {
        $id = $this->translatedRecipe();
        $this->signIn($this->client, $this->ben);
        $shown = $this->client->request('GET', '/api/recipes/'.$id)->toArray();

        $this->client->request('PATCH', '/api/recipes/'.$id, [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['title' => 'Smoky hummus'] + array_intersect_key($shown, array_flip(['language', 'tags', 'ingredients', 'steps', 'notes'])),
        ]);
        self::assertResponseIsSuccessful();

        $recipe = $this->recipe($id);
        self::assertSame('en', $recipe->getLanguage()->value);
        self::assertSame('Smoky hummus', $recipe->getTitle());
        self::assertSame('~200 g cooked chickpeas', $recipe->getIngredients()[0]->getRawText());
        self::assertNull($recipe->getTranslation(), 'the Spanish text is out of date');
        self::assertSame([$id], $this->queuedTranslations(), 'and is translated again, from English');

        // Meanwhile Ana reads Ben's English.
        $this->signIn($this->client, $this->ana);
        self::assertSame('Smoky hummus', $this->client->request('GET', '/api/recipes/'.$id)->toArray()['title']);
    }

    public function testEditingTheOriginalTranslatesItAgain(): void
    {
        $id = $this->translatedRecipe();

        // Favorites and ratings don't touch the text.
        $this->client->request('PATCH', '/api/recipes/'.$id, ['headers' => ['Content-Type' => 'application/merge-patch+json'], 'json' => ['favorite' => true]]);
        self::assertSame([], $this->queuedTranslations());
        self::assertNotNull($this->recipe($id)->getTranslation());

        $this->client->request('PATCH', '/api/recipes/'.$id, ['headers' => ['Content-Type' => 'application/merge-patch+json'], 'json' => ['steps' => ['Triturar todo.', 'Salar.', 'Servir con pan.']]]);
        self::assertNull($this->recipe($id)->getTranslation());
        self::assertSame([$id], $this->queuedTranslations());

        // Until then Ben reads the Spanish.
        $this->signIn($this->client, $this->ben);
        $theirs = $this->client->request('GET', '/api/recipes/'.$id)->toArray();
        self::assertSame('Hummus casero', $theirs['title']);
        self::assertSame('es', $theirs['language']);
    }

    public function testATranslationThatDoesNotLineUpIsNotKept(): void
    {
        $id = $this->createRecipe();
        $answer = $this->answer();
        $this->mockBedrock([$this->answer(steps: ['Blend everything and season.'])]);

        try {
            $this->translate($id);
            self::fail('Expected the translation to be refused');
        } catch (TranslationException) {
        }
        self::assertNull($this->recipe($id)->getTranslation());
        $runs = self::getContainer()->get(EntityManagerInterface::class)->getRepository(ExtractionRun::class)->findBy(['step' => 'translate']);
        self::assertCount(1, $runs);
        self::assertFalse($runs[0]->isOk(), 'the failed call is logged, and Messenger tries again');

        // A repeated message for a recipe already translated costs nothing.
        $this->mockBedrock([$answer]);
        $this->translate($id);
        $this->translate($id);
        self::assertCount(1, $this->bedrockRequests);
        self::assertSame('Homemade hummus', $this->recipe($id)->getTranslation()?->title);
    }

    public function testATranslationOfAnOlderTextIsDropped(): void
    {
        $id = $this->createRecipe();
        $recipe = $this->recipe($id);
        $stale = \App\Translation\RecipeText::of($recipe)->hash();
        $recipe->setTitle('Hummus de remolacha');
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $translation = new \App\Translation\RecipeText(\App\Enum\Language::En, 'Homemade hummus', [], ['a', 'b'], null, [
            ['rawText' => 'a', 'name' => null, 'prep' => null, 'groupName' => null],
            ['rawText' => 'b', 'name' => null, 'prep' => null, 'groupName' => null],
            ['rawText' => 'c', 'name' => null, 'prep' => null, 'groupName' => null],
        ]);
        self::assertFalse($recipe->translate($translation, $stale));
        self::assertTrue($recipe->needsTranslation());
    }

    public function testTheShoppingListNamesRecipesInTheReadersLanguage(): void
    {
        $id = $this->translatedRecipe();
        /** @var array{id: string, entries: list<array{id: string}>} $plan */
        $plan = $this->client->request('POST', '/api/meal-plans', ['json' => [
            'weekStart' => '2026-11-23',
            'slots' => [['date' => '2026-11-23', 'slot' => 'lunch', 'servings' => 4]],
        ]])->toArray();
        $this->client->request('POST', '/api/meal-plans/'.$plan['id'].'/entries/update', ['json' => ['entry' => $plan['entries'][0]['id'], 'recipe' => $id]]);
        self::assertResponseIsSuccessful();

        $this->signIn($this->client, $this->ben);
        /** @var array{items: list<array{recipes?: list<string>}>} $list */
        $list = $this->client->request('GET', '/api/shopping-lists/2026-11-23')->toArray();
        $recipes = array_unique(array_merge(...array_map(static fn (array $item): array => $item['recipes'] ?? [], $list['items'])));
        self::assertSame(['Homemade hummus'], array_values($recipes));

        // The menu, which embeds its recipes, too.
        /** @var array{entries: list<array{recipe?: array{title: string}}>} $week */
        $week = $this->client->request('GET', '/api/meal-plans/'.$plan['id'])->toArray();
        self::assertSame('Homemade hummus', $week['entries'][0]['recipe']['title'] ?? null);
    }

    public function testToolSchemasSuitStrictToolUse(): void
    {
        foreach ([ExtractionSchema::schema(), ExtractionSchema::schema(estimates: true), ClassifyIngredientsHandler::schema(), RecipeTranslator::schema(), RecipeAnnotator::schema(), TasteCompiler::schema(), NotesJudge::schema()] as $schema) {
            $this->assertStrict($schema);
        }
    }

    /** @param array<mixed> $schema */
    private function assertStrict(array $schema, string $path = '$'): void
    {
        self::assertArrayNotHasKey('minimum', $schema, $path);
        self::assertArrayNotHasKey('maximum', $schema, $path);
        if ('object' === ($schema['type'] ?? null)) {
            self::assertFalse($schema['additionalProperties'] ?? true, $path.' must be closed');
            $properties = $schema['properties'] ?? null;
            self::assertIsArray($properties);
            foreach ($properties as $name => $property) {
                self::assertIsArray($property);
                $this->assertStrict($property, $path.'.'.$name);
            }
        }
        if (\is_array($schema['items'] ?? null)) {
            $this->assertStrict($schema['items'], $path.'[]');
        }
        foreach (\is_array($schema['anyOf'] ?? null) ? $schema['anyOf'] : [] as $i => $option) {
            self::assertIsArray($option);
            $this->assertStrict($option, $path.'|'.$i);
        }
    }

    private function createRecipe(): string
    {
        $created = $this->client->request('POST', '/api/recipes', ['json' => [
            'title' => 'Hummus casero',
            'language' => 'es',
            'servings' => 4,
            'tags' => ['aperitivo', 'vegetariano'],
            'ingredients' => [
                ['rawText' => '~200 g de garbanzos cocidos', 'quantity' => 200, 'unit' => 'g', 'name' => 'garbanzos cocidos', 'prep' => 'escurridos', 'groupName' => 'Para el hummus', 'canonicalKey' => 'cooked_chickpeas'],
                ['rawText' => '1 cucharada de tahini', 'quantity' => 1, 'unit' => 'tbsp', 'name' => 'tahini', 'groupName' => 'Para el hummus', 'canonicalKey' => 'tahini'],
                ['rawText' => 'Sal', 'name' => 'sal', 'canonicalKey' => 'salt'],
            ],
            'steps' => ['Triturar todo.', 'Salar.'],
            'notes' => 'De la abuela.',
        ]]);
        self::assertResponseStatusCodeSame(201);

        $id = $created->toArray()['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    private function translatedRecipe(): string
    {
        $id = $this->createRecipe();
        $this->mockBedrock([$this->answer()]);
        $this->translate($id);
        $this->asyncTransport()->reset();

        return $id;
    }

    /** @param list<string>|null $steps */
    private function answer(?array $steps = null): JsonMockResponse
    {
        return new JsonMockResponse([
            'output' => ['message' => ['role' => 'assistant', 'content' => [['toolUse' => [
                'toolUseId' => 't1',
                'name' => RecipeTranslator::TOOL,
                'input' => [
                    'title' => 'Homemade hummus',
                    'tags' => ['dip', 'Vegetarian'],
                    'ingredients' => [
                        ['line' => '~200 g cooked chickpeas', 'name' => 'cooked chickpeas', 'prep' => 'drained', 'group' => 'For the dip'],
                        ['line' => '1 tbsp tahini', 'name' => 'tahini', 'prep' => null, 'group' => 'For the dip'],
                        ['line' => 'Salt', 'name' => 'salt', 'prep' => null, 'group' => null],
                    ],
                    'steps' => $steps ?? ['Blend everything.', 'Season with salt.'],
                    'notes' => "Grandma's.",
                ],
            ]]]]],
            'stopReason' => 'tool_use',
            'usage' => ['inputTokens' => 900, 'outputTokens' => 300],
        ]);
    }

    private function translate(string $id): void
    {
        self::getContainer()->get(TranslateRecipeHandler::class)(new TranslateRecipe($id));
        self::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    private function recipe(string $id): Recipe
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $recipe = $entityManager->find(Recipe::class, Uuid::fromString($id));
        self::assertNotNull($recipe);

        return $recipe;
    }

    /** @return list<string> ids of the recipes queued for translation */
    private function queuedTranslations(): array
    {
        $ids = [];
        foreach ($this->asyncTransport()->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof TranslateRecipe) {
                $ids[] = $message->recipeId;
            }
        }
        $this->asyncTransport()->reset();

        return array_values(array_unique($ids));
    }

    private function asyncTransport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    /** @param list<MockResponse> $responses */
    private function mockBedrock(array $responses): void
    {
        $this->bedrockRequests = [];
        $httpClient = self::getContainer()->get('bedrock.http_client');
        self::assertInstanceOf(MockHttpClient::class, $httpClient);
        $httpClient->setResponseFactory(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $body = json_decode(\is_string($options['body'] ?? null) ? $options['body'] : '{}', true);
            $this->bedrockRequests[] = \is_array($body) ? $body : [];

            return array_shift($responses) ?? throw new \LogicException('Unexpected Bedrock call to '.$url);
        });
    }

    /** @param array<mixed> $request */
    private function system(array $request): string
    {
        return self::at($request, 'system', 0, 'text');
    }

    /** @param array<mixed> $request */
    private function userText(array $request): string
    {
        return self::at($request, 'messages', 0, 'content', 0, 'text');
    }

    /** @param array<mixed> $request */
    private function toolName(array $request): string
    {
        return self::at($request, 'toolConfig', 'tools', 0, 'toolSpec', 'name');
    }

    /** @param array<mixed> $data */
    private static function at(array $data, string|int ...$path): string
    {
        $value = $data;
        foreach ($path as $key) {
            $value = \is_array($value) ? ($value[$key] ?? null) : null;
        }

        return \is_string($value) ? $value : '';
    }
}
