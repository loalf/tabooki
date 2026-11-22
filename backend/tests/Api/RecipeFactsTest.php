<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Test\ApiTestCase;
use ApiPlatform\Test\Client;
use App\Entity\ExtractionRun;
use App\Entity\Recipe;
use App\Entity\User;
use App\Enum\Diet;
use App\Enum\FoodGroup;
use App\Message\AnnotateRecipe;
use App\MessageHandler\AnnotateRecipeHandler;
use App\Planning\AnnotationException;
use App\Planning\RecipeAnnotator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

/** How filling and spicy each recipe is and what it contains: what the household's likings read (docs/PLAN.md §6.2). */
final class RecipeFactsTest extends ApiTestCase
{
    use SignsInUsers;

    protected static ?bool $alwaysBootKernel = false;

    private Client $client;
    private User $ana;

    /** @var list<array<mixed>> */
    private array $bedrockRequests = [];

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['headers' => ['Accept' => 'application/json']]);
        $this->ana = $this->createUser('ana@example.com');
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $this->signIn($this->client, $this->ana);
    }

    public function testANewRecipeHasItsFactsRead(): void
    {
        $id = $this->createRecipe();
        self::assertSame([$id], $this->queuedReadings(), 'a new recipe is queued to have its facts read');

        $before = $this->client->request('GET', '/api/recipes/'.$id)->toArray();
        self::assertArrayNotHasKey('heaviness', $before);
        self::assertArrayNotHasKey('diet', $before, 'unknown until read: an empty list of groups means nothing yet');

        $this->mockBedrock([$this->answer(groups: ['gluten', 'dairy', 'cheese', 'tree_nuts', 'made_up'], optional: ['pork', 'cheese'])]);
        $this->read($id);

        // What the model was asked: the recipe, optional ingredients marked.
        $prompt = $this->bedrockRequests[0];
        self::assertSame(RecipeAnnotator::TOOL, self::at($prompt, 'toolConfig', 'tools', 0, 'toolSpec', 'name'));
        self::assertStringContainsString('- Panceta (optional)', self::at($prompt, 'messages', 0, 'content', 0, 'text'));

        $recipe = $this->client->request('GET', '/api/recipes/'.$id)->toArray();
        self::assertSame('hearty', $recipe['heaviness']);
        self::assertSame(1, $recipe['spice']);
        self::assertSame(['dairy', 'cheese', 'gluten', 'tree_nuts'], $recipe['foodGroups'], 'unknown groups are dropped, the rest in a stable order');
        self::assertSame(['pork'], $recipe['optionalFoodGroups'], 'what the dish needs anyway isn\'t optional');
        self::assertSame('vegetarian', $recipe['diet']);

        /** @var list<array{heaviness?: string, diet?: string}> $list */
        $list = $this->client->request('GET', '/api/recipes')->toArray();
        self::assertSame(['hearty', 'vegetarian'], [$list[0]['heaviness'] ?? null, $list[0]['diet'] ?? null], 'the library can filter by them');

        $runs = self::getContainer()->get(EntityManagerInterface::class)->getRepository(ExtractionRun::class)->findBy(['step' => 'annotate']);
        self::assertCount(1, $runs);
        self::assertTrue($runs[0]->isOk());

        $this->read($id);
        self::assertCount(1, $this->bedrockRequests, 'a repeated message doesn\'t ask again');
    }

    public function testOnlyANewTitleOrIngredientsAreReadAgain(): void
    {
        $id = $this->readRecipe();

        $this->patch($id, ['steps' => ['Cocer la pasta.', 'Mezclar con el pesto y servir.'], 'notes' => 'Mejor recién hecho.']);
        self::assertSame([], $this->queuedReadings(), 'steps and notes hardly change the facts');

        $this->patch($id, ['ingredients' => [
            ['rawText' => '200 g de espaguetis'],
            ['rawText' => '100 g de pesto'],
            ['rawText' => '150 g de langostinos'],
        ]]);
        self::assertSame([$id], $this->queuedReadings());
    }

    public function testAFactFixedByHandIsKept(): void
    {
        $id = $this->readRecipe();
        $shown = $this->client->request('GET', '/api/recipes/'.$id)->toArray();

        // Saving the form with the facts as they were doesn't count as fixing them.
        $this->patch($id, ['heaviness' => $shown['heaviness'], 'foodGroups' => $shown['foodGroups'], 'spice' => 1]);
        self::assertSame([], $this->recipe($id)->getFactsEdited());

        $this->patch($id, ['heaviness' => 'light', 'spice' => 0]);
        self::assertSame(['heaviness', 'spice'], $this->recipe($id)->getFactsEdited());

        $this->patch($id, ['title' => 'Pasta al pesto ligera']);
        self::assertSame([$id], $this->queuedReadings());
        $this->mockBedrock([$this->answer(groups: ['gluten', 'cheese'])]);
        $this->read($id);

        $recipe = $this->client->request('GET', '/api/recipes/'.$id)->toArray();
        self::assertSame(['light', 0], [$recipe['heaviness'], $recipe['spice']], 'the fixes stay');
        self::assertSame(['cheese', 'gluten'], $recipe['foodGroups'], 'what wasn\'t fixed is read again');

        $this->patch($id, ['foodGroups' => [], 'optionalFoodGroups' => ['egg']]);
        $this->patch($id, ['title' => 'Pasta sin nada']);
        self::assertSame([], $this->queuedReadings(), 'every fact fixed by hand: nothing left to read');
        self::assertSame('vegan', $this->client->request('GET', '/api/recipes/'.$id)->toArray()['diet'], 'optional ingredients can be left out');
    }

    public function testFactsReadFromAnOlderTextAreDropped(): void
    {
        $id = $this->createRecipe();
        $this->mockBedrock([$this->answer()]);
        $recipe = $this->recipe($id);
        $from = $recipe->factsFingerprint();
        [$facts] = self::getContainer()->get(RecipeAnnotator::class)->annotate($recipe);

        $this->patch($id, ['title' => 'Otra receta']);
        $recipe = $this->recipe($id);
        self::assertFalse($recipe->applyFacts($facts, $from));
        self::assertNull($recipe->getHeaviness());
        self::assertTrue($recipe->needsFacts());
    }

    public function testAnAnswerThatCannotBeReadIsRetried(): void
    {
        $id = $this->createRecipe();
        $this->mockBedrock([$this->answer(heaviness: 'enormous')]);

        try {
            $this->read($id);
            self::fail('The handler should throw, so Messenger retries it.');
        } catch (AnnotationException) {
        }

        self::assertTrue($this->recipe($id)->needsFacts());
        $runs = self::getContainer()->get(EntityManagerInterface::class)->getRepository(ExtractionRun::class)->findBy(['step' => 'annotate']);
        self::assertCount(1, $runs);
        self::assertFalse($runs[0]->isOk());
    }

    public function testDietsFollowFromTheFoodGroups(): void
    {
        $diet = static fn (string ...$groups): ?Diet => Diet::of(FoodGroup::listFrom($groups));

        self::assertSame(Diet::Vegan, $diet('gluten', 'soy'));
        self::assertSame(Diet::Vegetarian, $diet('egg', 'cheese'));
        self::assertSame(Diet::Pescatarian, $diet('fish', 'dairy'));
        self::assertNull($diet('animal_stock'), 'a vegetable soup made with chicken stock isn\'t vegetarian');
        self::assertNull($diet('processed_meat'));
    }

    private function createRecipe(): string
    {
        $created = $this->client->request('POST', '/api/recipes', ['json' => [
            'title' => 'Pasta al pesto',
            'language' => 'es',
            'servings' => 2,
            'ingredients' => [
                ['rawText' => '200 g de espaguetis', 'canonicalKey' => 'spaghetti'],
                ['rawText' => '100 g de pesto', 'canonicalKey' => 'pesto'],
                ['rawText' => 'Panceta', 'canonicalKey' => 'pancetta', 'optional' => true],
            ],
            'steps' => ['Cocer la pasta.', 'Mezclar con el pesto.'],
        ]]);
        self::assertResponseStatusCodeSame(201);

        $id = $created->toArray()['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    private function readRecipe(): string
    {
        $id = $this->createRecipe();
        $this->mockBedrock([$this->answer()]);
        $this->read($id);
        $this->asyncTransport()->reset();

        return $id;
    }

    /** @param array<string, mixed> $changes */
    private function patch(string $id, array $changes): void
    {
        $this->client->request('PATCH', '/api/recipes/'.$id, [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => $changes,
        ]);
        self::assertResponseIsSuccessful();
    }

    /**
     * @param list<string> $groups
     * @param list<string> $optional
     */
    private function answer(string $heaviness = 'hearty', array $groups = ['gluten', 'cheese', 'tree_nuts'], array $optional = []): JsonMockResponse
    {
        return new JsonMockResponse([
            'output' => ['message' => ['role' => 'assistant', 'content' => [['toolUse' => [
                'toolUseId' => 't1',
                'name' => RecipeAnnotator::TOOL,
                'input' => ['heaviness' => $heaviness, 'spice' => 1, 'food_groups' => $groups, 'optional_food_groups' => $optional],
            ]]]]],
            'stopReason' => 'tool_use',
            'usage' => ['inputTokens' => 400, 'outputTokens' => 40],
        ]);
    }

    private function read(string $id): void
    {
        self::getContainer()->get(AnnotateRecipeHandler::class)(new AnnotateRecipe($id));
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

    /** @return list<string> ids of the recipes queued to have their facts read */
    private function queuedReadings(): array
    {
        $ids = [];
        foreach ($this->asyncTransport()->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof AnnotateRecipe) {
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
