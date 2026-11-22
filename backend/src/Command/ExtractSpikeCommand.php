<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Household;
use App\Entity\Source;
use App\Extraction\Evidence;
use App\Extraction\EvidenceCollector;
use App\Extraction\ExtractedRecipe;
use App\Extraction\ExtractionException;
use App\Extraction\ExtractionResult;
use App\Extraction\FrameSampler;
use App\Extraction\MediaFetcher;
use App\Extraction\MediaFetchException;
use App\Extraction\RecipeExtractor;
use App\Extraction\Transcriber;
use App\Extraction\Transcript;
use App\Extraction\VideoFrame;
use App\Library\Metadata\MetadataFetcher;
use App\Library\UrlNormalizer;
use App\Llm\LlmException;
use App\MessageHandler\ExtractSourceHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Measures extraction on a list of links without saving anything: the same material the app
 * gathers, read in several ways ("steps") so they can be compared side by side. Writes one JSON
 * line per link and prints a table per step at the end.
 *
 * Steps:
 * - text_thinking, text_no_thinking: the text model on the caption / description / page
 * - whisper: the text model on the caption plus a Whisper transcript of the video (OpenRouter)
 * - frames: the text model (Claude) on the caption, the timed transcript and stills of the video
 * - frames_estimates: the same, allowed to estimate amounts nobody gives (flagged as estimated)
 *
 * The video steps run on every video of reasonable length, whatever the text steps found.
 *
 * With --hide-caption the video steps don't see the caption. On reels whose caption has the
 * whole recipe, text_thinking's answer then serves as the reference: the "vs caption" column
 * counts, per video step, how its amounts compare: same (±10%) / close (within 50%) / off /
 * in another unit / not given.
 */
#[AsCommand(name: 'app:extract:spike', description: 'Measure extraction on a list of links (saves nothing)')]
final readonly class ExtractSpikeCommand
{
    private const array STEPS = ['text_thinking', 'text_no_thinking', 'whisper', 'frames', 'frames_estimates'];
    private const string DEFAULT_STEPS = 'text_thinking,whisper,frames,frames_estimates';

    /** @param array<string, array{input: float, output: float}> $prices */
    public function __construct(
        private UrlNormalizer $urls,
        private MetadataFetcher $metadata,
        private EvidenceCollector $collector,
        private RecipeExtractor $extractor,
        private MediaFetcher $media,
        private Transcriber $transcriber,
        private FrameSampler $frames,
        private EntityManagerInterface $entityManager,
        #[Autowire('%app.llm_prices%')]
        private array $prices = [],
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'File with one link per line')] string $file,
        #[Option(description: 'Where to write the JSON lines')] string $out = 'var/spike.jsonl',
        #[Option(description: 'Interface language for messages')] string $locale = 'es',
        #[Option(description: 'Comma-separated steps: '.self::DEFAULT_STEPS.', text_no_thinking')] string $steps = self::DEFAULT_STEPS,
        #[Option(description: 'Most stills sent to the model per video')] int $frames = 40,
        #[Option(description: 'Model for the frames steps (default: BEDROCK_TEXT_MODEL)')] ?string $framesModel = null,
        #[Option(description: 'Directory to keep each video\'s transcript and stills in, to look at them')] ?string $keep = null,
        #[Option(description: 'Hide the caption from the video steps, and compare them with text_thinking')] bool $hideCaption = false,
    ): int {
        $lines = file($file, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) ?: [];
        $links = array_values(array_filter(array_map(trim(...), $lines), static fn (string $l): bool => '' !== $l && !str_starts_with($l, '#')));
        $household = $this->entityManager->getRepository(Household::class)->findOneBy([]);
        $selected = array_values(array_intersect(self::STEPS, array_map(trim(...), explode(',', $steps))));
        if (null === $household || [] === $links || [] === $selected) {
            $io->error(match (true) {
                null === $household => 'Create a household (sign up) first.',
                [] === $links => 'No links in '.$file,
                default => 'No known steps in "'.$steps.'". Known: '.implode(', ', self::STEPS),
            });

            return Command::FAILURE;
        }

        $handle = fopen($out, 'w') ?: throw new \RuntimeException('Cannot write '.$out);
        $rows = [];
        foreach ($links as $i => $url) {
            $io->section(\sprintf('[%d/%d] %s', $i + 1, \count($links), $url));
            $row = ['url' => $url];
            try {
                $row += $this->measure($household, $url, $locale, $selected, max(1, $frames), $framesModel, null === $keep ? null : \sprintf('%s/%02d', rtrim($keep, '/'), $i + 1), $hideCaption);
            } catch (\Throwable $e) {
                $row['error'] = $e::class.': '.$e->getMessage();
            }
            fwrite($handle, json_encode($row, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE)."\n");
            $rows[] = $row;
            foreach (['transcript', 'stills'] as $key) {
                if (\is_array($row[$key] ?? null)) {
                    $io->writeln(\sprintf('  %-17s %s', $key, json_encode(array_diff_key($row[$key], ['text' => true]), \JSON_UNESCAPED_UNICODE)));
                }
            }
            foreach (self::STEPS as $step) {
                if (\is_array($row[$step] ?? null)) {
                    $io->writeln(\sprintf('  %-17s %s', $step, json_encode(array_diff_key($row[$step], ['recipes' => true]), \JSON_UNESCAPED_UNICODE)));
                }
            }
            if (isset($row['error'])) {
                $io->writeln('  <error>'.json_encode($row['error']).'</error>');
            }
        }
        fclose($handle);

        $io->section('Summary (averages are per answered link)');
        $io->table(['step', 'links', 'complete', 'partial', 'other', 'failed', 'ingredients', 'measured', 'estimated', 'usd', 'seconds', 'vs caption (same/close/off/unit/missing)'], self::table($rows, $selected));
        $io->success('Wrote '.$out);

        return Command::SUCCESS;
    }

    /**
     * @param list<string> $steps
     *
     * @return array<string, mixed>
     */
    private function measure(Household $household, string $url, string $locale, array $steps, int $maxFrames, ?string $framesModel, ?string $keep, bool $hideCaption): array
    {
        $url = $this->urls->withScheme($url);
        $platform = $this->urls->detectPlatform($url);
        // Never persisted or flushed: nothing reaches the database.
        $source = new Source($household, $url, $this->urls->normalize($url), $platform);
        $source->setThumbnailPath('spike'); // don't download thumbnails

        $started = hrtime(true);
        $metadata = $this->metadata->fetch($url, $platform);
        $source->setTitle($metadata->title);
        $source->setAuthor($metadata->author);
        $source->addEvidence('metadata', $metadata->toEvidence());
        if (null !== $metadata->recipeJsonLd) {
            $source->addEvidence('json_ld', $metadata->recipeJsonLd);
        }
        if (null !== $metadata->pageText) {
            $source->addEvidence('page_text', $metadata->pageText);
        }
        $evidence = $this->collector->collect($source);
        $stored = $source->getEvidence();
        $row = [
            'platform' => $platform->value,
            'evidence' => array_values(array_filter([
                isset($stored['json_ld']) ? 'json_ld' : null,
                isset($stored['page_text']) ? 'page_text' : null,
                isset($stored['media']) ? 'media' : null,
                isset($stored['transcript']) ? 'transcript' : null,
                isset($stored['linked']) ? 'linked' : null,
                isset($stored['media_error']) ? 'media_error' : null,
            ])),
            'media_error' => $stored['media_error'] ?? null,
            'duration' => $evidence->durationSeconds,
            'caption_chars' => mb_strlen($evidence->description ?? ''),
            'gather_ms' => self::ms($started),
        ];

        if ($evidence->hasText()) {
            foreach (['text_thinking' => true, 'text_no_thinking' => false] as $step => $thinking) {
                if (\in_array($step, $steps, true)) {
                    $row[$step] = $this->attempt(fn (): ExtractionResult => $this->extractor->fromText($evidence, $locale, $thinking));
                }
            }
        }

        $videoSteps = array_intersect(['whisper', 'frames', 'frames_estimates'], $steps);
        if ([] === $videoSteps || !ExtractSourceHandler::canWatch($evidence)) {
            return $row;
        }
        $reference = $hideCaption && 'complete' === ($row['text_thinking']['status'] ?? null) ? $row['text_thinking']['recipes'] : null;
        if ($hideCaption) {
            $evidence = $evidence->withoutCaption();
        }

        [$file, $row['download']] = $this->download(fn (): ?string => $this->media->downloadVideo($url, ExtractSourceHandler::MAX_VIDEO_BYTES));
        if (null !== $file) {
            try {
                $row = $this->watch($row, $file, $evidence, $steps, $locale, $maxFrames, $framesModel, $keep);
            } finally {
                self::delete($file);
            }
        }

        if (\is_array($reference)) {
            foreach ($videoSteps as $step) {
                $result = $row[$step] ?? null;
                if (\is_array($result) && \is_array($result['recipes'] ?? null)) {
                    $result['vs_caption'] = self::compare(self::recipes($reference), self::recipes($result['recipes']));
                    $row[$step] = $result;
                }
            }
        }

        return $row;
    }

    /**
     * The whisper and frames steps, on a downloaded video.
     *
     * @param array<string, mixed> $row
     * @param list<string>         $steps
     *
     * @return array<string, mixed>
     */
    private function watch(array $row, string $file, Evidence $evidence, array $steps, string $locale, int $maxFrames, ?string $framesModel, ?string $keep): array
    {
        $started = hrtime(true);
        $transcript = null;
        try {
            $transcript = $this->transcriber->transcribe($file);
            $row['transcript'] = null === $transcript
                ? ['status' => 'no_audio', 'ms' => self::ms($started)]
                : ['status' => $transcript->isEmpty() ? 'no_speech' : 'ok', 'language' => $transcript->language, 'segments' => \count($transcript->segments), 'chars' => mb_strlen($transcript->text()), 'usd' => $transcript->cost, 'ms' => self::ms($started), 'text' => mb_substr($transcript->text(), 0, 3000)];
        } catch (MediaFetchException $e) {
            $row['transcript'] = ['status' => 'failed', 'message' => mb_substr($e->getMessage(), 0, 300), 'ms' => self::ms($started)];
        }
        if (null !== $keep && null !== $transcript) {
            @mkdir($keep, 0775, true);
            file_put_contents($keep.'/transcript.txt', $transcript->timed()."\n");
        }

        if (\in_array('whisper', $steps, true)) {
            $row['whisper'] = null !== $transcript && !$transcript->isEmpty()
                ? $this->attempt(fn (): ExtractionResult => $this->extractor->fromText($evidence->withTranscript($transcript->text()), $locale))
                : ['status' => 'skipped', 'message' => 'no speech'];
        }

        $frameSteps = array_values(array_intersect(['frames', 'frames_estimates'], $steps));
        if ([] === $frameSteps) {
            return $row;
        }
        $started = hrtime(true);
        try {
            $stills = $this->frames->sample($file, $evidence->durationSeconds, $maxFrames);
        } catch (MediaFetchException $e) {
            $row['stills'] = ['status' => 'failed', 'message' => mb_substr($e->getMessage(), 0, 300)];

            return $row;
        }
        $row['stills'] = ['status' => 'ok', 'count' => \count($stills), 'kb' => (int) round(array_sum(array_map(static fn (VideoFrame $f): int => \strlen($f->jpeg), $stills)) / 1024), 'ms' => self::ms($started)];
        if (null !== $keep) {
            self::keepStills($keep, $stills);
        }
        foreach ($frameSteps as $step) {
            $row[$step] = $this->attempt(fn (): ExtractionResult => $this->extractor->fromFrames($evidence, $transcript, $stills, $locale, 'frames_estimates' === $step, $framesModel));
        }

        return $row;
    }

    /**
     * @param callable(): ?string $download
     *
     * @return array{0: ?string, 1: array<string, mixed>} the file (null if none) and what to report
     */
    private function download(callable $download): array
    {
        $started = hrtime(true);
        try {
            $file = $download();
        } catch (MediaFetchException $e) {
            return [null, ['status' => 'failed', 'message' => mb_substr($e->getMessage(), 0, 200), 'ms' => self::ms($started)]];
        }

        return null === $file
            ? [null, ['status' => 'failed', 'message' => 'no format small enough', 'ms' => self::ms($started)]]
            : [$file, ['status' => 'ok', 'bytes' => filesize($file), 'format' => pathinfo($file, \PATHINFO_EXTENSION), 'ms' => self::ms($started)]];
    }

    private static function delete(string $file): void
    {
        @unlink($file);
        @rmdir(\dirname($file));
    }

    /**
     * How a step's amounts compare with the reference's, ingredient by ingredient. Keys match
     * when equal or when one's words are all in the other ("rice" and "round_rice": models name
     * the same thing differently from run to run). Counted items and "piece" are the same unit.
     *
     * @param list<ExtractedRecipe> $reference
     * @param list<ExtractedRecipe> $answer
     *
     * @return array{same: int, close: int, off: int, unit: int, missing: int}
     */
    public static function compare(array $reference, array $answer): array
    {
        $given = self::amounts($answer);
        $counts = ['same' => 0, 'close' => 0, 'off' => 0, 'unit' => 0, 'missing' => 0];
        foreach (self::amounts($reference) as $key => [$quantity, $unit]) {
            if (null === $quantity || $quantity <= 0) {
                continue;
            }
            $match = $given[$key] ?? null;
            if (null === $match) {
                $words = explode('_', $key);
                foreach ($given as $other => $amount) {
                    $otherWords = explode('_', (string) $other);
                    if ([] === array_diff($words, $otherWords) || [] === array_diff($otherWords, $words)) {
                        $match = $amount;
                        break;
                    }
                }
            }
            [$answered, $answeredUnit] = $match ?? [null, null];
            if (null === $answered) {
                ++$counts['missing'];
                continue;
            }
            if ($answeredUnit !== $unit) {
                ++$counts['unit'];
                continue;
            }
            $ratio = $answered / $quantity;
            ++$counts[match (true) {
                $ratio >= 0.9 && $ratio <= 1.1 => 'same',
                $ratio >= 2 / 3 && $ratio <= 1.5 => 'close',
                default => 'off',
            }];
        }

        return $counts;
    }

    /**
     * @param callable(): ExtractionResult $extract
     *
     * @return array<string, mixed>
     */
    private function attempt(callable $extract): array
    {
        $started = hrtime(true);
        $unreadable = [];
        try {
            try {
                return $this->summary($started, $extract());
            } catch (ExtractionException $e) {
                // An answer the app can't read: the app asks once more too (ExtractSourceHandler::run).
                $unreadable[] = self::raw($e);

                return ['retried' => true, 'unreadable' => $unreadable] + $this->summary($started, $extract());
            }
        } catch (LlmException|ExtractionException $e) {
            if ($e instanceof ExtractionException) {
                $unreadable[] = self::raw($e);
            }

            return ['status' => 'llm_error', 'message' => mb_substr($e->getMessage(), 0, 300), 'unreadable' => $unreadable, 'ms' => self::ms($started)];
        }
    }

    /** What the model sent in the answer the app couldn't read, to see why. */
    private static function raw(ExtractionException $e): mixed
    {
        $input = $e->completion?->toolInput;

        return null === $input ? ['stop' => $e->completion?->stopReason, 'text' => mb_substr($e->completion->text ?? '', 0, 2000)] : $input;
    }

    /** @return array<string, mixed> */
    private function summary(int|float $started, ExtractionResult $result): array
    {
        $c = $result->completion;
        $price = $this->prices[$c->model] ?? null;
        $ingredients = $measured = $estimated = $steps = 0;
        foreach ($result->recipes as $recipe) {
            $ingredients += \count($recipe->ingredients);
            $steps += \count($recipe->steps);
            foreach ($recipe->ingredients as $line) {
                $measured += null !== $line['quantity'] ? 1 : 0;
                $estimated += $line['estimated'] ? 1 : 0;
            }
        }

        return [
            'status' => $result->status,
            'model' => $c->model,
            'raw_recipes' => get_debug_type($c->toolInput['recipes'] ?? null),
            'raw_preview' => \is_string($c->toolInput['recipes'] ?? null) ? mb_substr($c->toolInput['recipes'], 0, 400) : null,
            'stop' => $c->stopReason,
            'confidence' => $result->confidence,
            'needs_review' => $result->needsReview(),
            'missing' => $result->missingFields,
            'message' => $result->message,
            'titles' => array_map(static fn ($r): string => $r->title, $result->recipes),
            'servings' => array_map(static fn ($r): ?string => null === $r->servings ? null : $r->servings.($r->servingsEstimated ? '~' : ''), $result->recipes),
            'ingredients' => $ingredients,
            'measured' => $measured,
            'estimated' => $estimated,
            'steps' => $steps,
            'in' => $c->inputTokens,
            'out' => $c->outputTokens,
            'usd' => null === $price ? null : round(($c->inputTokens * $price['input'] + $c->outputTokens * $price['output']) / 1_000_000, 6),
            'ms' => self::ms($started),
            'recipes' => $result->recipes,
        ];
    }

    /**
     * One line per step: how many links got each status, and averages over the answered ones.
     *
     * @param list<array<string, mixed>> $rows
     * @param list<string>               $steps
     *
     * @return list<list<string|int>>
     */
    private static function table(array $rows, array $steps): array
    {
        $table = [];
        foreach ($steps as $step) {
            $results = array_values(array_filter(array_map(static fn (array $row): mixed => $row[$step] ?? null, $rows), \is_array(...)));
            $answered = array_values(array_filter($results, static fn (array $r): bool => isset($r['in'])));
            $count = static fn (string $status): int => \count(array_filter($results, static fn (array $r): bool => $status === ($r['status'] ?? null)));
            $average = static function (string $key, int $decimals = 1) use ($answered): string {
                $values = array_map(static fn (array $r): float => is_numeric($r[$key] ?? null) ? (float) $r[$key] : 0.0, $answered);

                return [] === $values ? '-' : number_format(array_sum($values) / \count($values), $decimals);
            };
            $table[] = [
                $step,
                \count($results),
                $count('complete'),
                $count('partial'),
                $count('no_recipe') + $count('elsewhere'),
                \count($results) - \count($answered),
                $average('ingredients'),
                $average('measured'),
                $average('estimated'),
                number_format(array_sum(array_map(static fn (array $r): float => is_numeric($r['usd'] ?? null) ? (float) $r['usd'] : 0.0, $answered)), 4),
                [] === $answered ? '-' : number_format(array_sum(array_column($answered, 'ms')) / \count($answered) / 1000, 1),
                self::vsCaption($answered),
            ];
        }

        return $table;
    }

    /**
     * Amount and unit by canonical key, first mention wins.
     *
     * @param list<ExtractedRecipe> $recipes
     *
     * @return array<string, array{?float, ?string}>
     */
    private static function amounts(array $recipes): array
    {
        $map = [];
        foreach ($recipes as $recipe) {
            foreach ($recipe->ingredients as $line) {
                if (null !== $line['canonicalKey'] && !isset($map[$line['canonicalKey']])) {
                    $map[$line['canonicalKey']] = [$line['quantity'], 'piece' === $line['unit'] ? null : $line['unit']];
                }
            }
        }

        return $map;
    }

    /**
     * @param array<mixed> $items
     *
     * @return list<ExtractedRecipe>
     */
    private static function recipes(array $items): array
    {
        return array_values(array_filter($items, static fn (mixed $r): bool => $r instanceof ExtractedRecipe));
    }

    /** @param array<array<mixed>> $answered "same/close/off/unit/missing" summed over links, or "-" */
    private static function vsCaption(array $answered): string
    {
        $total = ['same' => 0, 'close' => 0, 'off' => 0, 'unit' => 0, 'missing' => 0];
        $any = false;
        foreach ($answered as $result) {
            if (\is_array($result['vs_caption'] ?? null)) {
                $any = true;
                foreach ($total as $key => $value) {
                    $total[$key] = $value + (\is_int($result['vs_caption'][$key] ?? null) ? $result['vs_caption'][$key] : 0);
                }
            }
        }

        return $any ? implode('/', $total) : '-';
    }

    /** @param list<VideoFrame> $stills */
    private static function keepStills(string $directory, array $stills): void
    {
        @mkdir($directory.'/stills', 0775, true);
        foreach ($stills as $i => $still) {
            file_put_contents(\sprintf('%s/stills/%02d_%s.jpg', $directory, $i + 1, str_replace(':', 'm', Transcript::clock($still->seconds))), $still->jpeg);
        }
    }

    private static function ms(int|float $started): int
    {
        return (int) ((hrtime(true) - $started) / 1_000_000);
    }
}
