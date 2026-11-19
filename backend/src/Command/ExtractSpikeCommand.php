<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Household;
use App\Entity\Source;
use App\Extraction\EvidenceCollector;
use App\Extraction\ExtractionResult;
use App\Extraction\MediaFetcher;
use App\Extraction\MediaFetchException;
use App\Extraction\RecipeExtractor;
use App\Library\Metadata\MetadataFetcher;
use App\Library\UrlNormalizer;
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
 * gathers, read by the text model with thinking on and off, and the video model when the text
 * isn't enough. Writes one JSON line per link, to compare models, prompts or settings.
 */
#[AsCommand(name: 'app:extract:spike', description: 'Measure extraction on a list of links (saves nothing)')]
final readonly class ExtractSpikeCommand
{
    /** @param array<string, array{input: float, output: float}> $prices */
    public function __construct(
        private UrlNormalizer $urls,
        private MetadataFetcher $metadata,
        private EvidenceCollector $collector,
        private RecipeExtractor $extractor,
        private MediaFetcher $media,
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
    ): int {
        $lines = file($file, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) ?: [];
        $links = array_values(array_filter(array_map(trim(...), $lines), static fn (string $l): bool => '' !== $l && !str_starts_with($l, '#')));
        $household = $this->entityManager->getRepository(Household::class)->findOneBy([]);
        if (null === $household || [] === $links) {
            $io->error(null === $household ? 'Create a household (sign up) first.' : 'No links in '.$file);

            return Command::FAILURE;
        }

        $handle = fopen($out, 'w') ?: throw new \RuntimeException('Cannot write '.$out);
        foreach ($links as $i => $url) {
            $io->section(\sprintf('[%d/%d] %s', $i + 1, \count($links), $url));
            $row = ['url' => $url];
            try {
                $row += $this->measure($household, $url, $locale);
            } catch (\Throwable $e) {
                $row['error'] = $e::class.': '.$e->getMessage();
            }
            fwrite($handle, json_encode($row, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)."\n");
            foreach (['text_thinking', 'text_no_thinking', 'video'] as $step) {
                $result = $row[$step] ?? null;
                if (\is_array($result)) {
                    $io->writeln(\sprintf('  %-17s %s', $step, json_encode(array_diff_key($result, ['recipes' => true]), \JSON_UNESCAPED_UNICODE)));
                }
            }
            if (isset($row['error'])) {
                $io->writeln('  <error>'.json_encode($row['error']).'</error>');
            }
        }
        fclose($handle);
        $io->success('Wrote '.$out);

        return Command::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function measure(Household $household, string $url, string $locale): array
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
            'gather_ms' => self::ms($started),
        ];

        $on = null;
        if ($evidence->hasText()) {
            $row['text_thinking'] = $this->summary(hrtime(true), $on = $this->extractor->fromText($evidence, $locale, true));
            $row['text_no_thinking'] = $this->summary(hrtime(true), $this->extractor->fromText($evidence, $locale, false));
        }

        if ('complete' !== $on?->status && ExtractSourceHandler::canWatch($evidence)) {
            try {
                $file = $this->media->downloadVideo($url, ExtractSourceHandler::MAX_VIDEO_BYTES);
            } catch (MediaFetchException $e) {
                $row['video'] = ['status' => 'download_failed', 'message' => mb_substr($e->getMessage(), 0, 200)];

                return $row;
            }
            if (null === $file) {
                $row['video'] = ['status' => 'download_failed'];
            } else {
                try {
                    $row['video_bytes'] = filesize($file);
                    try {
                        $row['video'] = $this->summary(hrtime(true), $this->extractor->fromVideo($evidence, (string) file_get_contents($file), $locale));
                    } catch (\App\Llm\LlmException|\App\Extraction\ExtractionException $e) {
                        $row['video'] = ['status' => 'llm_error', 'message' => mb_substr($e->getMessage(), 0, 300)];
                    }
                } finally {
                    @unlink($file);
                    @rmdir(\dirname($file));
                }
            }
        }

        return $row;
    }

    /** @return array<string, mixed> */
    private function summary(int|float $started, ExtractionResult $result): array
    {
        $c = $result->completion;
        $price = $this->prices[$c->model] ?? null;
        $ingredients = $measured = $steps = 0;
        foreach ($result->recipes as $recipe) {
            $ingredients += \count($recipe->ingredients);
            $steps += \count($recipe->steps);
            foreach ($recipe->ingredients as $line) {
                $measured += null !== $line['quantity'] ? 1 : 0;
            }
        }

        return [
            'status' => $result->status,
            'raw_recipes' => get_debug_type($c->toolInput['recipes'] ?? null),
            'raw_preview' => \is_string($c->toolInput['recipes'] ?? null) ? mb_substr($c->toolInput['recipes'], 0, 400) : null,
            'stop' => $c->stopReason,
            'confidence' => $result->confidence,
            'needs_review' => $result->needsReview(),
            'missing' => $result->missingFields,
            'message' => $result->message,
            'titles' => array_map(static fn ($r): string => $r->title, $result->recipes),
            'ingredients' => $ingredients,
            'measured' => $measured,
            'steps' => $steps,
            'in' => $c->inputTokens,
            'out' => $c->outputTokens,
            'usd' => null === $price ? null : round(($c->inputTokens * $price['input'] + $c->outputTokens * $price['output']) / 1_000_000, 6),
            'ms' => self::ms($started),
            'recipes' => $result->recipes,
        ];
    }

    private static function ms(int|float $started): int
    {
        return (int) ((hrtime(true) - $started) / 1_000_000);
    }
}
