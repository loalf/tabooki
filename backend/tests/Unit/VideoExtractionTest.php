<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Command\ExtractSpikeCommand;
use App\Enum\Language;
use App\Extraction\EstimatedAmount;
use App\Extraction\ExtractionResult;
use App\Extraction\ExtractionSchema;
use App\Extraction\FrameSampler;
use App\Extraction\OpenRouterTranscriber;
use App\Extraction\Transcript;
use App\Llm\Completion;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Process\Process;

final class VideoExtractionTest extends TestCase
{
    public function testVerboseJsonIsCutIntoTimedLinesFromTheWords(): void
    {
        $words = static function (float $from, string ...$words): array {
            $timed = [];
            foreach (array_values($words) as $i => $word) {
                $timed[] = ['word' => ' '.$word, 'start' => $from + $i * 0.4, 'end' => $from + $i * 0.4 + 0.3];
            }

            return $timed;
        };
        $transcript = Transcript::fromVerboseJson([
            'language' => 'es',
            'segments' => [
                ['start' => 0, 'end' => 28.7, 'text' => ' Hoy hacemos lentejas. Ponemos 300 gramos en remojo.', 'avg_logprob' => -0.03],
                ['start' => 29.98, 'end' => 30.98, 'text' => ' I don\'t know.', 'avg_logprob' => -0.84],
                ['start' => 31, 'end' => 32, 'text' => ' ♪', 'avg_logprob' => -1.4],
            ],
            'words' => [
                ...$words(0, 'Hoy', 'hacemos', 'lentejas.'),
                ...$words(3.5, 'Ponemos', '300', 'gramos'),
                ...$words(20, 'en', 'remojo.'),
                ['word' => ' I', 'start' => 29.98, 'end' => 29.98],
                ['word' => ' don\'t', 'start' => 29.98, 'end' => 29.98],
                ['word' => ' know.', 'start' => 29.98, 'end' => 29.98],
                ['word' => ' ♪', 'start' => 31, 'end' => 32],
            ],
        ], cost: 0.0003);

        self::assertSame('es', $transcript->language);
        self::assertSame(0.0003, $transcript->cost);
        self::assertSame("[0:00] Hoy hacemos lentejas.\n[0:03] Ponemos 300 gramos\n[0:20] en remojo.", $transcript->timed(), 'a sentence, a pause, then the rest; made-up words over music left out');
    }

    public function testVerboseJsonWithoutWordsAndWithOnlyMusic(): void
    {
        $segments = Transcript::fromVerboseJson(['language' => 'english', 'segments' => [
            ['start' => 0, 'end' => 4.1, 'text' => ' Hoy hacemos lentejas.', 'avg_logprob' => -0.2],
            ['start' => 4.1, 'end' => 9, 'text' => ' Outro Music', 'avg_logprob' => -1.32],
        ]]);
        self::assertSame('Hoy hacemos lentejas.', $segments->text());
        self::assertNull($segments->language, 'only ISO codes');

        $music = Transcript::fromVerboseJson(['segments' => [['start' => 0, 'end' => 15, 'text' => ' Outro Music', 'avg_logprob' => -0.5]], 'words' => [
            ['word' => ' Outro', 'start' => 14.94, 'end' => 14.94],
            ['word' => ' Music', 'start' => 14.94, 'end' => 14.94],
        ]]);
        self::assertTrue($music->isEmpty(), 'words that take no time are made up');

        $loop = Transcript::fromVerboseJson(['segments' => [
            ['start' => 0, 'end' => 3, 'text' => ' Hoy hacemos lentejas con chorizo.', 'avg_logprob' => -0.2],
            ['start' => 29, 'end' => 31, 'text' => ' Conozco, que se me,'.str_repeat(' la noche se me,', 30), 'avg_logprob' => -0.3],
        ]]);
        self::assertSame('Hoy hacemos lentejas con chorizo.', $loop->text(), 'Whisper stuck in a loop');
    }

    public function testTranscribesThroughOpenRouter(): void
    {
        $video = sys_get_temp_dir().'/tabooki-test-'.bin2hex(random_bytes(4)).'.mp4';
        $make = new Process(['ffmpeg', '-nostdin', '-loglevel', 'error', '-f', 'lavfi', '-i', 'sine=frequency=440:duration=2', '-f', 'lavfi', '-i', 'color=size=64x64:duration=2', '-shortest', '-pix_fmt', 'yuv420p', $video]);
        try {
            $make->run();
        } catch (\Throwable) {
        }
        if (!$make->isSuccessful()) {
            self::markTestSkipped('ffmpeg is not installed.');
        }

        $requests = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = [$url, $options['headers'] ?? [], json_decode(\is_string($options['body'] ?? null) ? $options['body'] : '', true)];

            return 1 === \count($requests)
                ? new MockResponse('{"error": {"code": 529, "message": "overloaded"}}', ['http_code' => 529])
                : new MockResponse((string) json_encode(['text' => 'Hola', 'language' => 'es', 'segments' => [['start' => 0, 'end' => 1, 'text' => ' Hola']], 'words' => [['word' => ' Hola', 'start' => 0.1, 'end' => 0.6]], 'usage' => ['seconds' => 2, 'cost' => 0.00002]]));
        });

        try {
            $transcript = (new OpenRouterTranscriber($http, 'key', 'openai/whisper-large-v3', retryDelayMs: 0))->transcribe($video);
        } finally {
            @unlink($video);
        }

        self::assertNotNull($transcript);
        self::assertSame('[0:00] Hola', $transcript->timed());
        self::assertSame(0.00002, $transcript->cost);
        self::assertCount(2, $requests, 'retried once after the provider was overloaded');
        [$url, $headers, $body] = $requests[1];
        self::assertSame('https://openrouter.ai/api/v1/audio/transcriptions', $url);
        self::assertIsArray($headers);
        self::assertContains('Authorization: Bearer key', $headers);
        self::assertIsArray($body);
        $audio = $body['input_audio'] ?? null;
        self::assertIsArray($audio);
        self::assertIsString($audio['data'] ?? null);
        self::assertSame(['model' => 'openai/whisper-large-v3', 'format' => 'mp3', 'response_format' => 'verbose_json', 'timestamp_granularities' => ['segment', 'word']], [
            'model' => $body['model'] ?? null, 'format' => $audio['format'] ?? null, 'response_format' => $body['response_format'] ?? null, 'timestamp_granularities' => $body['timestamp_granularities'] ?? null,
        ]);
        self::assertStringStartsWith('ID3', (string) base64_decode($audio['data'], true), 'an MP3');
    }

    public function testFrameRateThinningAndShowinfo(): void
    {
        self::assertSame('2', FrameSampler::rate(30, 40));
        self::assertSame('0.67', FrameSampler::rate(180, 40));
        self::assertSame('0.5', FrameSampler::rate(600, 40));
        self::assertSame('1', FrameSampler::rate(null, 40));

        self::assertSame([0, 1, 2], FrameSampler::thin([0, 1, 2], 5));
        self::assertSame([0, 5, 9], FrameSampler::thin(range(0, 9), 3));
        self::assertSame([0], FrameSampler::thin(range(0, 9), 1));

        $log = <<<'LOG'
            [Parsed_showinfo_3 @ 0xffff84011060] config in time_base: 1/2, frame_rate: 2/1
            [Parsed_showinfo_3 @ 0xffff84011060] n:   0 pts:      0 pts_time:0       duration:      1 duration_time:0.5     fmt:yuv420p
            [Parsed_showinfo_3 @ 0xffff84011060] n:   1 pts:      7 pts_time:3.5     duration:      1 duration_time:0.5     fmt:yuv420p
            LOG;
        self::assertSame([0.0, 3.5], FrameSampler::showinfoTimes($log));
    }

    public function testSamplesStillsWithFfmpeg(): void
    {
        $video = sys_get_temp_dir().'/tabooki-test-'.bin2hex(random_bytes(4)).'.mp4';
        $make = new Process(['ffmpeg', '-nostdin', '-loglevel', 'error', '-f', 'lavfi', '-i', 'testsrc=duration=10:size=1280x720:rate=25', '-pix_fmt', 'yuv420p', $video]);
        try {
            $make->run();
        } catch (\Throwable) {
        }
        if (!$make->isSuccessful()) {
            self::markTestSkipped('ffmpeg is not installed.');
        }

        try {
            $frames = (new FrameSampler())->sample($video, 10, maxFrames: 8);
        } finally {
            @unlink($video);
        }

        self::assertCount(8, $frames);
        self::assertSame(0.0, $frames[0]->seconds);
        self::assertGreaterThan(9.0, $frames[7]->seconds);
        $size = getimagesizefromstring($frames[0]->jpeg);
        self::assertIsArray($size);
        self::assertSame([768, 432], [$size[0], $size[1]]);
    }

    public function testEstimatesAreFlaggedAndComparedWithTheCaption(): void
    {
        $parse = static fn (array $ingredients, array $recipe = []): ExtractionResult => ExtractionResult::fromCompletion(new Completion(
            toolInput: ['status' => 'complete', 'confidence' => 0.9, 'recipes' => [$recipe + ['title' => 'Curry', 'language' => 'es', 'ingredients' => $ingredients, 'steps' => ['Cocinar.']]]],
            text: '',
            model: 'test',
            inputTokens: 0,
            outputTokens: 0,
            stopReason: 'tool_use',
        ));

        $answer = $parse([
            ['raw' => 'garbanzos', 'name' => 'garbanzos', 'canonical_key' => 'chickpeas', 'quantity' => 380, 'unit' => 'g', 'estimated' => true],
            ['raw' => '2 berenjenas', 'name' => 'berenjena', 'canonical_key' => 'aubergine', 'quantity' => 2, 'unit' => 'piece'],
            ['raw' => 'ajo', 'name' => 'ajo', 'canonical_key' => 'garlic', 'quantity' => 10, 'unit' => 'clove'],
            ['raw' => 'sal', 'name' => 'sal', 'canonical_key' => 'salt', 'quantity' => null, 'unit' => null, 'estimated' => true],
            ['raw' => 'cebolla', 'name' => 'cebolla', 'canonical_key' => 'onion', 'quantity' => 150, 'unit' => 'g', 'estimated' => true],
        ], ['servings' => 2, 'servings_estimated' => true]);
        $recipe = $answer->recipes[0];
        self::assertTrue($recipe->servingsEstimated);
        self::assertTrue($recipe->ingredients[0]['estimated']);
        self::assertFalse($recipe->ingredients[1]['estimated']);
        self::assertFalse($recipe->ingredients[3]['estimated'], 'no amount, nothing estimated');

        $reference = $parse([
            ['raw' => '400 g garbanzos', 'name' => 'garbanzos', 'canonical_key' => 'chickpeas', 'quantity' => 400, 'unit' => 'g'],
            ['raw' => '1 berenjena', 'name' => 'berenjena', 'canonical_key' => 'aubergine', 'quantity' => 1, 'unit' => null],
            ['raw' => '3 dientes de ajo', 'name' => 'ajo', 'canonical_key' => 'garlic', 'quantity' => 3, 'unit' => 'clove'],
            ['raw' => '400 ml leche de coco', 'name' => 'leche de coco', 'canonical_key' => 'coconut_milk', 'quantity' => 400, 'unit' => 'ml'],
            ['raw' => '1 cebolla morada', 'name' => 'cebolla morada', 'canonical_key' => 'red_onion', 'quantity' => 1, 'unit' => null],
            ['raw' => 'sal', 'name' => 'sal', 'canonical_key' => 'salt', 'quantity' => null, 'unit' => null],
        ]);
        // chickpeas same; aubergine 2 vs 1 off; garlic 10 vs 3 off; coconut milk missing; onion (red_onion) in grams vs a count
        self::assertSame(['same' => 1, 'close' => 0, 'off' => 2, 'unit' => 1, 'missing' => 1], ExtractSpikeCommand::compare($reference->recipes, $answer->recipes));
    }

    public function testSchemaOnlyAsksForEstimatesWhenAllowed(): void
    {
        $ingredient = static fn (bool $estimates): string => (string) json_encode(ExtractionSchema::schema($estimates));

        self::assertStringNotContainsString('"estimated"', $ingredient(false));
        self::assertStringContainsString('"estimated"', $ingredient(true));
        self::assertStringContainsString('"servings_estimated"', $ingredient(true));
        self::assertStringContainsString('Never invent. Use only', ExtractionSchema::instructions('es'));
        self::assertStringContainsString('set estimated: true', ExtractionSchema::instructions('es', true));
    }

    public function testEstimatedAmountsAreMarkedInTheLine(): void
    {
        self::assertSame('~380 g de garbanzos', EstimatedAmount::line('garbanzos', 380, 'g', Language::Es));
        self::assertSame('~0,5 l de caldo', EstimatedAmount::line('caldo', 0.5, 'l', Language::Es));
        self::assertSame('~2 cucharadas de aceite de oliva', EstimatedAmount::line('aceite de oliva', 2, 'tbsp', Language::Es));
        self::assertSame('~1 cucharada de miel', EstimatedAmount::line('miel', 1, 'tbsp', Language::Es));
        self::assertSame('~2 berenjenas', EstimatedAmount::line('berenjenas', 2, null, Language::Es));
        self::assertSame('~3 eggs', EstimatedAmount::line('eggs', 3, 'piece', Language::En));
        self::assertSame('~1.5 tbsp olive oil', EstimatedAmount::line('olive oil', 1.5, 'tbsp', Language::En));
        self::assertSame('~200 g pasta', EstimatedAmount::line('pasta', 200, 'g', Language::En));
        self::assertSame('~1 lata de tomate', EstimatedAmount::line('1 lata de tomate', 1, 'can', Language::Es), 'a number already in the line is only marked');
    }

    public function testSchemaSuitsStrictToolUse(): void
    {
        foreach ([false, true] as $estimates) {
            $check = static function (mixed $node, string $path) use (&$check): void {
                if (!\is_array($node)) {
                    return;
                }
                if ('object' === ($node['type'] ?? null)) {
                    self::assertFalse($node['additionalProperties'] ?? null, $path.': closed object');
                }
                self::assertArrayNotHasKey('minimum', $node, $path);
                self::assertArrayNotHasKey('maximum', $node, $path);
                if (isset($node['enum'])) {
                    self::assertIsString($node['type'] ?? null, $path.': an enum has a single type');
                }
                foreach ($node as $key => $child) {
                    $check($child, $path.'.'.$key);
                }
            };
            $check(ExtractionSchema::schema($estimates), 'schema');
        }
    }
}
