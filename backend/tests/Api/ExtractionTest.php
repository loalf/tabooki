<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Test\ApiTestCase;
use ApiPlatform\Test\Client;
use App\Entity\ExtractionRun;
use App\Entity\Source;
use App\Entity\User;
use App\Extraction\MediaFetchException;
use App\Extraction\MediaInfo;
use App\Message\ExtractSource;
use App\Message\FetchSourceMetadata;
use App\MessageHandler\ExtractSourceHandler;
use App\MessageHandler\FetchSourceMetadataHandler;
use App\Tests\FakeMediaFetcher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

final class ExtractionTest extends ApiTestCase
{
    use SignsInUsers;

    protected static ?bool $alwaysBootKernel = false;

    private Client $client;
    private User $ana;

    /** @var list<array{model: string, body: array<mixed>}> */
    private array $bedrockRequests = [];

    private static ?string $video = null;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['headers' => ['Accept' => 'application/json']]);
        $this->ana = $this->createUser('ana@example.com');
        $this->signIn($this->client, $this->ana);
    }

    protected function tearDown(): void
    {
        new \Symfony\Component\Filesystem\Filesystem()->remove(self::getContainer()->getParameter('app.media_dir'));
        parent::tearDown();
    }

    public function testABlogWithStructuredDataBecomesARecipe(): void
    {
        $id = $this->saveLink('https://recetas.example/lentejas');
        $this->mockPage('https://recetas.example/lentejas', '<script type="application/ld+json">{"@type":"Recipe","name":"Lentejas","recipeIngredient":["300 g lentejas","1 chorizo"]}</script>');
        $this->mockBedrock([$this->answer('complete', 0.95, [$this->recipe('Lentejas con chorizo')])]);

        $this->runPreview($id);
        $this->runExtraction($id);

        // The recipe is then written in the other language too.
        $queued = array_filter(array_map(static fn ($envelope): object => $envelope->getMessage(), $this->asyncTransport()->getSent()), static fn (object $m): bool => $m instanceof \App\Message\TranslateRecipe);
        self::assertCount(1, $queued);

        // The text model read the page's structured data.
        self::assertCount(1, $this->bedrockRequests);
        self::assertSame('test.text-model', $this->bedrockRequests[0]['model']);
        self::assertStringContainsString('"recipeIngredient":["300 g lentejas","1 chorizo"]', $this->promptText(0));

        $source = $this->client->request('GET', '/api/sources/'.$id)->toArray();
        self::assertSame('ready', $source['status']);
        self::assertSame([], $this->client->request('GET', '/api/sources?hasRecipe=false')->toArray());

        /** @var list<array{id: string}> $recipes */
        $recipes = $this->client->request('GET', '/api/recipes')->toArray();
        self::assertCount(1, $recipes);
        /** @var array{title: string, needsReview: bool, extractedBy: string, servings: int, tags: list<string>, mealTypes: list<string>, ingredients: list<array{rawText: string, quantity?: float, unit?: string, name: string, canonicalKey: string}>} $recipe */
        $recipe = $this->client->request('GET', '/api/recipes/'.$recipes[0]['id'])->toArray();
        self::assertSame('Lentejas con chorizo', $recipe['title']);
        self::assertFalse($recipe['needsReview']);
        self::assertSame('test.text-model', $recipe['extractedBy']);
        self::assertSame(2, $recipe['servings']);
        self::assertSame(['legumbres'], $recipe['tags']);
        self::assertSame(['lunch'], $recipe['mealTypes']);
        self::assertSame(
            [['300 g de lentejas', 300.0, 'g', 'lentejas', 'lentils'], ['Sal', null, null, 'sal', 'salt']],
            array_map(static fn (array $i): array => [$i['rawText'], $i['quantity'] ?? null, $i['unit'] ?? null, $i['name'], $i['canonicalKey']], $recipe['ingredients']),
        );

        $runs = $this->runs($id);
        self::assertCount(1, $runs);
        self::assertTrue($runs[0]->isOk());
        self::assertSame('text', $runs[0]->getStep());
        self::assertSame(1200, $runs[0]->getInputTokens());
    }

    public function testAReelWhoseCaptionHasNoRecipeIsWatched(): void
    {
        $url = 'https://instagram.com/reel/C1a2B3c4D5e';
        $id = $this->saveLink($url);
        // No duration, as anonymous Instagram answers have it: the video formats are enough.
        $this->media()->infos[$url] = new MediaInfo(title: 'Video by chef', description: 'Pasta en 10 minutos 🍝 #pasta', uploader: 'chef', hasVideo: true);
        $this->media()->videos[$url] = self::video();
        $this->mockOpenRouter([new JsonMockResponse([
            'text' => 'Hoy hacemos pasta al limón.', 'language' => 'es',
            'segments' => [['start' => 0, 'end' => 2, 'text' => ' Hoy hacemos pasta al limón.', 'avg_logprob' => -0.1]],
            'usage' => ['seconds' => 3, 'cost' => 0.00002],
        ])]);
        $pasta = $this->recipe('Pasta al limón');
        $pasta['ingredients'] = [
            ['raw' => 'pasta', 'quantity' => 200, 'unit' => 'g', 'name' => 'pasta', 'canonical_key' => 'pasta', 'estimated' => true],
            ['raw' => '1 limón', 'quantity' => 1, 'unit' => null, 'name' => 'limón', 'canonical_key' => 'lemon', 'estimated' => false],
        ];
        $this->mockBedrock([
            $this->answer('elsewhere', 0.9, [], message: 'La receta solo aparece en el vídeo.'),
            $this->answer('complete', 0.9, [$pasta]),
        ]);

        $this->runExtraction($id);

        self::assertSame(['test.text-model', 'test.text-model'], array_column($this->bedrockRequests, 'model'), 'the video goes to the text model as stills');
        $images = array_filter($this->contentBlocks(1), static fn (mixed $block): bool => \is_array($block) && isset($block['image']));
        self::assertNotEmpty($images, 'stills of the video');
        self::assertStringContainsString('Pasta en 10 minutos', $this->promptText(1));
        self::assertStringContainsString('[0:00] Hoy hacemos pasta al limón.', $this->promptText(1));
        self::assertStringContainsString('Estimate the amounts nobody gives', $this->promptText(1));

        $source = $this->findSource($id);
        self::assertSame('ready', $source->getStatus()->value);
        self::assertSame('chef', $source->getAuthor());
        $recipe = $source->getRecipes()->first();
        self::assertNotFalse($recipe);
        self::assertSame('Pasta al limón', $recipe->getTitle());
        self::assertSame('test.text-model', $recipe->getExtractedBy());
        self::assertSame(['~200 g de pasta', '1 limón'], array_map(static fn (\App\Entity\RecipeIngredient $i): string => $i->getRawText(), $recipe->getIngredients()), 'estimated amounts are marked');
        $runs = $this->runs($id);
        self::assertSame(['text', 'transcript', 'video'], array_map(static fn (ExtractionRun $r): string => $r->getStep(), $runs));
        self::assertSame(['bedrock', 'openrouter', 'bedrock'], array_map(static fn (ExtractionRun $r): string => $r->getProvider(), $runs));
        self::assertSame(0.00002, $runs[1]->getCostUsd());
        self::assertSame([], glob(sys_get_temp_dir().'/tabooki-test-video-*/video.mp4'), 'the downloaded video is deleted');
    }

    public function testAFailedVideoStepKeepsWhatTheTextFound(): void
    {
        $url = 'https://instagram.com/reel/V1d3oF4il';
        $id = $this->saveLink($url);
        $this->media()->infos[$url] = new MediaInfo(title: 'Video by chef', description: 'Pasta: 200 g de pasta, 1 limón. Cocer y mezclar.', uploader: 'chef', durationSeconds: 3);
        $this->media()->videos[$url] = self::video();
        // Transcription fails: the stills still go to the model, which fails too.
        $this->mockOpenRouter([
            new JsonMockResponse(['error' => ['code' => 401, 'message' => 'No auth credentials found']], ['http_code' => 401]),
        ]);
        $this->mockBedrock([
            $this->answer('partial', 0.8, [$this->recipe('Pasta al limón')]),
            new JsonMockResponse(['message' => 'Invalid Input: The input does not adhere to the expected standards.'], ['http_code' => 400]),
        ]);

        $this->runExtraction($id);

        $source = $this->findSource($id);
        self::assertSame('needs_review', $source->getStatus()->value);
        self::assertSame(['Pasta al limón'], array_map(static fn ($r) => $r->getTitle(), $source->getRecipes()->toArray()));
        $runs = $this->runs($id);
        self::assertSame(['text', 'transcript', 'video'], array_map(static fn (ExtractionRun $r): string => $r->getStep(), $runs));
        self::assertSame([true, false, false], array_map(static fn (ExtractionRun $r): bool => $r->isOk(), $runs));
        self::assertStringContainsString('No auth credentials found', (string) $runs[1]->getError());
    }

    public function testAnUnreadableAnswerIsAskedForAgain(): void
    {
        $url = 'https://example.com/gazpacho';
        $id = $this->saveLink($url);
        $this->mockPage($url, '<html><head><title>Gazpacho</title></head><body><article><p>Gazpacho: 500 g de melón. Triturar.</p></article></body></html>');
        $this->runPreview($id);
        $this->mockBedrock([
            new JsonMockResponse([
                'output' => ['message' => ['content' => [['toolUse' => ['toolUseId' => 't', 'name' => 'save_recipes', 'input' => ['status' => 'complete', 'confidence' => 0.9, 'recipes' => 'Gazpacho: melón…']]]]]],
                'stopReason' => 'tool_use',
                'usage' => ['inputTokens' => 900, 'outputTokens' => 400],
            ]),
            $this->answer('complete', 0.9, [$this->recipe('Gazpacho de melón')]),
        ]);

        $this->runExtraction($id);

        self::assertSame('ready', $this->findSource($id)->getStatus()->value);
        $runs = $this->runs($id);
        self::assertSame([false, true], array_map(static fn (ExtractionRun $r): bool => $r->isOk(), $runs));
        self::assertSame(400, $runs[0]->getOutputTokens(), 'the unreadable answer is still counted');
    }

    public function testAPhotoPostIsNotWatched(): void
    {
        $url = 'https://instagram.com/p/Ph0toP0st';
        $id = $this->saveLink($url);
        $this->media()->infos[$url] = new MediaInfo(title: 'Post by chef', description: 'Guiso: la receta en los comentarios', uploader: 'chef');
        $this->mockBedrock([$this->answer('elsewhere', 0.9, [], message: 'La receta está en los comentarios.')]);

        $this->runExtraction($id);

        self::assertSame(['test.text-model'], array_column($this->bedrockRequests, 'model'));
        self::assertSame('needs_input', $this->findSource($id)->getStatus()->value);
    }

    public function testACarouselWhoseCaptionHasNoRecipeIsRead(): void
    {
        $url = 'https://instagram.com/p/C4rouSel01';
        $id = $this->saveLink($url);
        $slides = ['https://cdn.example/slide1.jpg', 'https://cdn.example/slide2.webp', 'https://cdn.example/slide3.avif', 'https://cdn.example/gone.jpg'];
        $this->media()->infos[$url] = new MediaInfo(title: 'Post by chef', description: 'Comenta "Guiso" si quieres más recetas así', uploader: 'chef', images: $slides);
        $this->mockImages([
            'https://cdn.example/slide1.jpg' => ['image/jpeg', 'jpeg-bytes'],
            'https://cdn.example/slide2.webp' => ['image/webp', 'webp-bytes'],
            'https://cdn.example/slide3.avif' => ['image/avif', 'avif-bytes'],
        ]);
        $this->mockBedrock([
            $this->answer('no_recipe', 0.8, []),
            $this->answer('complete', 0.9, [$this->recipe('Garbanzos con langostinos')]),
        ]);

        $this->runExtraction($id);

        self::assertSame(['test.text-model', 'test.text-model'], array_column($this->bedrockRequests, 'model'));
        $images = array_values(array_filter($this->contentBlocks(1), static fn (mixed $block): bool => \is_array($block) && isset($block['image'])));
        self::assertSame(['jpeg', 'webp'], array_column(array_column($images, 'image'), 'format'), 'the slides the model reads, in order; unreadable formats and dead links are skipped');
        self::assertStringContainsString('Comenta "Guiso"', $this->promptText(1));
        self::assertStringContainsString('2 pictures in the order the post shows them', $this->promptText(1));

        $source = $this->findSource($id);
        self::assertSame('ready', $source->getStatus()->value);
        self::assertSame(['Garbanzos con langostinos'], array_map(static fn ($r) => $r->getTitle(), $source->getRecipes()->toArray()));
        self::assertSame(['text', 'images'], array_map(static fn (ExtractionRun $r): string => $r->getStep(), $this->runs($id)));
    }

    public function testACarouselWithoutReadablePicturesKeepsWhatTheTextSaid(): void
    {
        $url = 'https://instagram.com/p/C4rouSel02';
        $id = $this->saveLink($url);
        $this->media()->infos[$url] = new MediaInfo(title: 'Post by chef', description: 'Receta en los comentarios', uploader: 'chef', images: ['https://cdn.example/gone.jpg']);
        $this->mockImages([]);
        $this->mockBedrock([$this->answer('elsewhere', 0.9, [], message: 'La receta está en los comentarios.')]);

        $this->runExtraction($id);

        self::assertCount(1, $this->bedrockRequests, 'no pictures, no second call');
        self::assertSame('needs_input', $this->findSource($id)->getStatus()->value);
    }

    public function testAnUnreadablePostAsksForTheText(): void
    {
        $url = 'https://instagram.com/p/PRIVATE1234';
        $id = $this->saveLink($url);
        $this->media()->infos[$url] = new MediaFetchException('Instagram: login required');
        $this->mockBedrock([]);

        $this->runExtraction($id);

        self::assertSame([], $this->bedrockRequests, 'nothing to read, nothing sent');
        $source = $this->client->request('GET', '/api/sources/'.$id)->toArray();
        self::assertSame('needs_input', $source['status']);
        self::assertIsString($source['statusMessage'] ?? null);
        self::assertStringContainsString('Pega el texto', $source['statusMessage']);
        self::assertCount(1, $this->client->request('GET', '/api/sources?hasRecipe=false')->toArray(), 'stays in the inbox');

        // The cook pastes the caption: extraction runs again from it.
        $this->client->request('POST', '/api/sources/'.$id.'/text', ['json' => ['text' => 'Croquetas: 1 l de leche, 100 g de harina…']]);
        self::assertResponseStatusCodeSame(202);
        self::assertJsonContains(['status' => 'pending']);
        self::assertInstanceOf(ExtractSource::class, array_last($this->asyncTransport()->getSent())?->getMessage());

        $this->mockBedrock([$this->answer('partial', 0.7, [$this->recipe('Croquetas')], message: 'Faltan los pasos.')]);
        $this->runExtraction($id);

        self::assertStringContainsString("Text the cook pasted for this link (most reliable)\nCroquetas: 1 l de leche", $this->promptText(0));
        $source = $this->client->request('GET', '/api/sources/'.$id)->toArray();
        self::assertSame('needs_review', $source['status']);

        // Review: the recipe is flagged, and marking it reviewed makes the link ready.
        /** @var list<array{id: string, title: string}> $toReview */
        $toReview = $this->client->request('GET', '/api/recipes?needsReview=true')->toArray();
        self::assertSame(['Croquetas'], array_column($toReview, 'title'));
        /** @var array{source: array{originalText: string}} $recipe */
        $recipe = $this->client->request('GET', '/api/recipes/'.$toReview[0]['id'])->toArray();
        self::assertSame('Croquetas: 1 l de leche, 100 g de harina…', $recipe['source']['originalText']);

        $this->client->request('PATCH', '/api/recipes/'.$toReview[0]['id'], [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['needsReview' => false],
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame('ready', $this->client->request('GET', '/api/sources/'.$id)->toArray()['status']);
        self::assertSame([], $this->client->request('GET', '/api/recipes?needsReview=true')->toArray());
    }

    public function testNoRecipeAndProviderErrorsEndInFailed(): void
    {
        $id = $this->saveLink('https://example.com/about');
        $this->mockPage('https://example.com/about', '<article><p>Sobre nosotros</p></article>');
        $this->runPreview($id);

        $this->mockBedrock([$this->answer('no_recipe', 0.95, [])]);
        $this->runExtraction($id);
        self::assertSame('failed', $this->findSource($id)->getStatus()->value);

        $this->client->request('POST', '/api/sources/'.$id.'/extract');
        self::assertResponseStatusCodeSame(202);
        $this->mockBedrock([new JsonMockResponse(['message' => 'Too many requests'], ['http_code' => 429])]);
        $this->runExtraction($id);

        $source = $this->findSource($id);
        self::assertSame('failed', $source->getStatus()->value);
        self::assertStringContainsString('Too many requests', (string) $source->getStatusMessage());
        self::assertSame([true, false], array_map(static fn (ExtractionRun $r): bool => $r->isOk(), $this->runs($id)));
    }

    public function testTheSavedPreviewQueuesExtraction(): void
    {
        $id = $this->saveLink('https://recetas.example/gazpacho');
        $this->mockPage('https://recetas.example/gazpacho', '<p>Gazpacho</p>');
        $this->runPreview($id);

        $messages = array_map(static fn ($envelope) => $envelope->getMessage(), $this->asyncTransport()->getSent());
        self::assertEquals([new FetchSourceMetadata($id), new ExtractSource($id)], $messages);
    }

    public function testExtractionEndpointsGuardTheirLinks(): void
    {
        $id = $this->saveLink('https://example.com/done');
        $this->client->request('POST', '/api/recipes', ['json' => ['title' => 'Done', 'source' => '/api/sources/'.$id]]);

        $this->client->request('POST', '/api/sources/'.$id.'/extract');
        self::assertResponseStatusCodeSame(409);

        $this->client->request('POST', '/api/sources/'.$id.'/text', ['json' => ['text' => '']]);
        self::assertResponseStatusCodeSame(422);

        $other = self::createClient([], ['headers' => ['Accept' => 'application/json']]);
        $this->signIn($other, $this->createUser('bea@example.com', 'Otra casa'));
        $other->request('POST', '/api/sources/'.$id.'/extract');
        self::assertResponseStatusCodeSame(404);
    }

    public function testARecipeIsExtractedAgainInPlace(): void
    {
        $id = $this->saveLink('https://recetas.example/lentejas');
        $this->mockPage('https://recetas.example/lentejas', '<script type="application/ld+json">{"@type":"Recipe","name":"Lentejas"}</script>');
        $this->mockBedrock([$this->answer('partial', 0.6, [$this->recipe('Lentejas')])]);
        $this->runPreview($id);
        $this->runExtraction($id);

        /** @var list<array{id: string}> $recipes */
        $recipes = $this->client->request('GET', '/api/recipes')->toArray();
        $recipeId = $recipes[0]['id'];
        $this->client->request('PATCH', '/api/recipes/'.$recipeId, [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['rating' => 5, 'favorite' => true, 'tags' => ['legumbres', 'de la abuela']],
        ]);

        $this->client->request('POST', '/api/recipes/'.$recipeId.'/extract');
        self::assertResponseStatusCodeSame(202);
        self::assertJsonContains(['id' => $recipeId, 'source' => ['status' => 'pending']]);
        self::assertEquals(new ExtractSource($id, $recipeId), array_last($this->asyncTransport()->getSent())?->getMessage());

        $better = ['ingredients' => [
            ['raw' => '300 g de lentejas', 'quantity' => 300, 'unit' => 'g', 'name' => 'lentejas', 'canonical_key' => 'lentils'],
            ['raw' => 'Sal', 'quantity' => null, 'unit' => null, 'name' => 'sal', 'canonical_key' => 'salt'],
            ['raw' => '1 zanahoria', 'quantity' => 1, 'unit' => null, 'name' => 'zanahoria', 'canonical_key' => 'carrot'],
        ]] + $this->recipe('Lentejas estofadas');
        $this->mockBedrock([$this->answer('complete', 0.95, [$this->recipe('Postre'), $better])]);
        self::getContainer()->get(ExtractSourceHandler::class)(new ExtractSource($id, $recipeId));
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        self::assertStringContainsString('"name":"Lentejas"', $this->promptText(0), 'from the stored page, not fetched again');
        /** @var array{id: string, title: string, rating: int, favorite: bool, needsReview: bool, tags: list<string>, ingredients: list<array<mixed>>, source: array{status: string}} $after */
        $after = $this->client->request('GET', '/api/recipes/'.$recipeId)->toArray();
        self::assertSame('Lentejas estofadas', $after['title'], 'the closest recipe by title');
        self::assertCount(3, $after['ingredients']);
        self::assertSame([5, true, false], [$after['rating'], $after['favorite'], $after['needsReview']]);
        self::assertSame(['legumbres', 'de la abuela'], $after['tags'], "the cook's tags stay");
        self::assertSame('ready', $after['source']['status']);
        self::assertCount(1, $this->client->request('GET', '/api/recipes')->toArray(), 'no new recipe');
        self::assertCount(2, $this->runs($id));
    }

    public function testAFailedReextractionLeavesTheRecipeAsItWas(): void
    {
        $id = $this->saveLink('https://recetas.example/sopa');
        $this->mockPage('https://recetas.example/sopa', '<script type="application/ld+json">{"@type":"Recipe","name":"Sopa"}</script>');
        $this->mockBedrock([$this->answer('complete', 0.9, [$this->recipe('Sopa')])]);
        $this->runPreview($id);
        $this->runExtraction($id);
        /** @var list<array{id: string}> $recipes */
        $recipes = $this->client->request('GET', '/api/recipes')->toArray();
        $recipeId = $recipes[0]['id'];

        $this->client->request('POST', '/api/recipes/'.$recipeId.'/extract');
        $this->mockBedrock([new JsonMockResponse(['message' => 'Too many requests'], ['http_code' => 429])]);
        self::getContainer()->get(ExtractSourceHandler::class)(new ExtractSource($id, $recipeId));
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        /** @var array{title: string, ingredients: list<array<mixed>>, source: array{status: string, statusMessage: string}} $after */
        $after = $this->client->request('GET', '/api/recipes/'.$recipeId)->toArray();
        self::assertSame(['Sopa', 2, 'ready'], [$after['title'], \count($after['ingredients']), $after['source']['status']]);
        self::assertStringContainsString('se queda como estaba', $after['source']['statusMessage']);
    }

    public function testReextractionNeedsALinkAndIsPrivate(): void
    {
        /** @var string $typed */
        $typed = $this->client->request('POST', '/api/recipes', ['json' => ['title' => 'A mano']])->toArray()['id'];
        $this->client->request('POST', '/api/recipes/'.$typed.'/extract');
        self::assertResponseStatusCodeSame(409);

        $other = self::createClient([], ['headers' => ['Accept' => 'application/json']]);
        $this->signIn($other, $this->createUser('bea@example.com', 'Otra casa'));
        $other->request('POST', '/api/recipes/'.$typed.'/extract');
        self::assertResponseStatusCodeSame(404);
    }

    private function saveLink(string $url): string
    {
        $id = $this->client->request('POST', '/api/sources', ['json' => ['url' => $url]])->toArray()['id'];
        self::assertIsString($id);

        return $id;
    }

    private function mockPage(string $url, string $html): void
    {
        $httpClient = self::getContainer()->get('link_preview.http_client');
        self::assertInstanceOf(MockHttpClient::class, $httpClient);
        $httpClient->setResponseFactory(static fn (string $method, string $requested): MockResponse => $requested === $url
            ? new MockResponse('<html><head><title>Page</title></head><body>'.$html.'</body></html>', ['response_headers' => ['content-type' => 'text/html']])
            : throw new \LogicException('Unexpected request to '.$requested));
    }

    /** @param array<string, array{string, string}> $images content type and bytes by URL; other URLs are gone (404) */
    private function mockImages(array $images): void
    {
        $httpClient = self::getContainer()->get('link_preview.http_client');
        self::assertInstanceOf(MockHttpClient::class, $httpClient);
        $httpClient->setResponseFactory(static fn (string $method, string $url): MockResponse => isset($images[$url])
            ? new MockResponse($images[$url][1], ['response_headers' => ['content-type' => $images[$url][0]]])
            : new MockResponse('', ['http_code' => 404]));
    }

    /** @param list<MockResponse> $responses in call order */
    private function mockBedrock(array $responses): void
    {
        $this->bedrockRequests = [];
        $httpClient = self::getContainer()->get('bedrock.http_client');
        self::assertInstanceOf(MockHttpClient::class, $httpClient);
        $httpClient->setResponseFactory(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            preg_match('#/model/([^/]+)/converse$#', $url, $m);
            $body = json_decode(\is_string($options['body'] ?? null) ? $options['body'] : '{}', true);
            $this->bedrockRequests[] = ['model' => rawurldecode($m[1] ?? ''), 'body' => \is_array($body) ? $body : []];

            return array_shift($responses) ?? throw new \LogicException('Unexpected Bedrock call to '.$url);
        });
    }

    /** @param list<MockResponse> $responses */
    private function mockOpenRouter(array $responses): void
    {
        $httpClient = self::getContainer()->get('openrouter.http_client');
        self::assertInstanceOf(MockHttpClient::class, $httpClient);
        $httpClient->setResponseFactory(static fn (string $method, string $url): MockResponse => array_shift($responses) ?? throw new \LogicException('Unexpected OpenRouter call to '.$url));
    }

    /** A real 3-second video with sound (ffmpeg reads it), made once. */
    private static function video(): string
    {
        if (null === self::$video) {
            $file = sys_get_temp_dir().'/tabooki-test-'.bin2hex(random_bytes(4)).'.mp4';
            $make = new \Symfony\Component\Process\Process(['ffmpeg', '-nostdin', '-loglevel', 'error', '-f', 'lavfi', '-i', 'testsrc=duration=3:size=640x360:rate=10', '-f', 'lavfi', '-i', 'sine=duration=3', '-shortest', '-pix_fmt', 'yuv420p', $file]);
            $make->run();
            if (!$make->isSuccessful()) {
                self::markTestSkipped('ffmpeg is not installed.');
            }
            self::$video = (string) file_get_contents($file);
            unlink($file);
        }

        return self::$video;
    }

    /**
     * @param list<array<string, mixed>> $recipes
     */
    private function answer(string $status, float $confidence, array $recipes, ?string $message = null): JsonMockResponse
    {
        return new JsonMockResponse([
            'output' => ['message' => ['role' => 'assistant', 'content' => [['toolUse' => [
                'toolUseId' => 't1',
                'name' => 'save_recipes',
                'input' => ['status' => $status, 'confidence' => $confidence, 'message' => $message, 'missing_fields' => [], 'recipes' => $recipes],
            ]]]]],
            'stopReason' => 'tool_use',
            'usage' => ['inputTokens' => 1200, 'outputTokens' => 300],
        ]);
    }

    /** @return array<string, mixed> */
    private function recipe(string $title): array
    {
        return [
            'title' => $title,
            'language' => 'es',
            'servings' => 2,
            'meal_types' => ['lunch'],
            'tags' => ['legumbres'],
            'ingredients' => [
                ['raw' => '300 g de lentejas', 'quantity' => 300, 'unit' => 'g', 'name' => 'lentejas', 'canonical_key' => 'lentils'],
                ['raw' => 'Sal', 'quantity' => null, 'unit' => null, 'name' => 'sal', 'canonical_key' => 'salt'],
            ],
            'steps' => ['Cocer.'],
        ];
    }

    /** The user-message text of the nth Bedrock call. */
    private function promptText(int $call): string
    {
        $texts = array_filter(array_map(
            static fn (mixed $block): ?string => \is_array($block) && \is_string($block['text'] ?? null) ? $block['text'] : null,
            $this->contentBlocks($call),
        ));

        return implode("\n", $texts);
    }

    /** @return list<mixed> the content blocks of the user message of the nth Bedrock call */
    private function contentBlocks(int $call): array
    {
        $messages = $this->bedrockRequests[$call]['body']['messages'] ?? null;
        $first = \is_array($messages) ? ($messages[0] ?? null) : null;
        $content = \is_array($first) ? ($first['content'] ?? null) : null;

        return \is_array($content) ? array_values($content) : [];
    }

    private function runPreview(string $id): void
    {
        self::getContainer()->get(FetchSourceMetadataHandler::class)(new FetchSourceMetadata($id));
        self::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    private function runExtraction(string $id): void
    {
        self::getContainer()->get(ExtractSourceHandler::class)(new ExtractSource($id));
        self::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    private function media(): FakeMediaFetcher
    {
        $media = self::getContainer()->get(\App\Extraction\MediaFetcher::class);
        self::assertInstanceOf(FakeMediaFetcher::class, $media);

        return $media;
    }

    private function findSource(string $id): Source
    {
        $source = self::getContainer()->get(EntityManagerInterface::class)->find(Source::class, Uuid::fromString($id));
        self::assertNotNull($source);

        return $source;
    }

    /** @return list<ExtractionRun> oldest first */
    private function runs(string $id): array
    {
        return self::getContainer()->get(EntityManagerInterface::class)->getRepository(ExtractionRun::class)
            ->findBy(['source' => Uuid::fromString($id)], ['createdAt' => 'ASC', 'id' => 'ASC']);
    }

    private function asyncTransport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }
}
