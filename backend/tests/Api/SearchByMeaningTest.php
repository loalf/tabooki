<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Test\ApiTestCase;
use ApiPlatform\Test\Client;
use App\Llm\Embedding;
use App\Message\EmbedRecipe;
use App\MessageHandler\EmbedRecipeHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class SearchByMeaningTest extends ApiTestCase
{
    use SignsInUsers;

    protected static ?bool $alwaysBootKernel = false;

    private Client $client;

    /** @var list<array{input_type: string, text: string}> what Bedrock was asked */
    private array $calls = [];

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['headers' => ['Accept' => 'application/json']]);
        $this->signIn($this->client, $this->createUser('ana@example.com'));
        // One kernel throughout: the mocked Bedrock stays.
        $this->client->disableReboot();
    }

    public function testRecipesAreFoundByWhatTheyAreAbout(): void
    {
        $this->mockEmbeddings();
        $chickpeas = $this->create('Ensalada de garbanzos', ['garbanzos', 'pepino']);
        $chicken = $this->create('Pollo al horno', ['pollo', 'patata']);
        $stew = $this->create('Garbanzos con chorizo', ['garbanzos', 'chorizo']);

        // Saving queued its embedding (the transport is emptied after each request: the last save's is there).
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $queued = [];
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof EmbedRecipe) {
                $queued[] = $message->recipeId;
            }
        }
        self::assertSame([$stew], $queued);
        $handler = self::getContainer()->get(EmbedRecipeHandler::class);
        foreach ([$chickpeas, $chicken, $stew] as $id) {
            $handler(new EmbedRecipe($id));
        }
        self::assertSame(['document', 'document', 'document'], array_column($this->calls, 'input_type'));
        self::assertStringContainsString('garbanzos, pepino', $this->calls[0]['text'], 'its ingredients are part of what is embedded');

        // Nothing it says changed: no new call.
        $handler(new EmbedRecipe($chickpeas));
        self::assertCount(3, $this->calls);

        $query = 'algo ligero con garbanzos '.bin2hex(random_bytes(4)); // not cached by an earlier run
        self::assertSame(['Ensalada de garbanzos', 'Garbanzos con chorizo'], $this->titles($query));
        self::assertSame('query', $this->calls[3]['input_type']);
        self::assertSame(['Ensalada de garbanzos'], $this->titles($query.' -chorizo'), 'a dash leaves out, and isn\'t searched for');
        self::assertCount(4, $this->calls, 'the same words, from the cache');
        self::assertSame([], $this->titles('postre de chocolate '.bin2hex(random_bytes(4))), 'nothing close enough');

        $runs = self::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchFirstColumn("SELECT step FROM extraction_run WHERE step IN ('embed', 'search') ORDER BY created_at");
        self::assertSame(['embed', 'embed', 'embed', 'search', 'search'], $runs, 'on the costs page');

        // Another household finds nothing of this one's.
        $other = self::createClient([], ['headers' => ['Accept' => 'application/json']]);
        $this->signIn($other, $this->createUser('bea@example.com', 'Otra casa'));
        self::assertSame([], $other->request('GET', '/api/recipe-search?q='.rawurlencode($query))->toArray());
    }

    /** @return list<string> */
    private function titles(string $query): array
    {
        /** @var list<array{id: string, score: float}> $found */
        $found = $this->client->request('GET', '/api/recipe-search?q='.rawurlencode($query))->toArray();
        self::assertResponseIsSuccessful();
        $titles = [];
        foreach ($found as $result) {
            /** @var array{title: string} $recipe */
            $recipe = $this->client->request('GET', '/api/recipes/'.$result['id'])->toArray();
            $titles[] = $recipe['title'];
        }

        return $titles;
    }

    /** @param list<string> $ingredients */
    private function create(string $title, array $ingredients): string
    {
        $id = $this->client->request('POST', '/api/recipes', ['json' => [
            'title' => $title, 'language' => 'es',
            'ingredients' => array_map(static fn (string $i): array => ['rawText' => $i, 'name' => $i], $ingredients),
        ]])->toArray()['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    /**
     * A tiny world of meaning: chickpeas one way, chicken another, sweets a third; a dish with both
     * chickpeas and chorizo leans a little towards meat.
     */
    private function mockEmbeddings(): void
    {
        $httpClient = self::getContainer()->get('bedrock.http_client');
        self::assertInstanceOf(MockHttpClient::class, $httpClient);
        $httpClient->setResponseFactory(function (string $method, string $url, array $options): JsonMockResponse {
            /** @var array{texts: list<string>, input_type: string} $body */
            $body = json_decode(\is_string($options['body'] ?? null) ? $options['body'] : '{}', true);
            $text = mb_strtolower($body['texts'][0]);
            $this->calls[] = ['input_type' => str_replace('search_', '', $body['input_type']), 'text' => $text];
            $vector = array_fill(0, Embedding::DIMENSIONS, 0.0);
            if (str_contains($text, 'chocolate')) {
                $vector[2] = 1.0;
            } elseif (str_contains($text, 'garbanzos')) {
                $vector[0] = 1.0;
                $vector[1] = str_contains($text, 'chorizo') ? 0.3 : 0.0;
            } else {
                $vector[1] = 1.0;
            }

            return new JsonMockResponse(['embeddings' => ['float' => [$vector]]], ['response_headers' => ['x-amzn-bedrock-input-token-count' => '12']]);
        });
    }
}
