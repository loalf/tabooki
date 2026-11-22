<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\ExtractionRun;
use App\Entity\Recipe;
use App\Entity\RecipeIngredient;
use App\Entity\Source;
use App\Enum\Language;
use App\Enum\MealType;
use App\Enum\SourceStatus;
use App\Extraction\EstimatedAmount;
use App\Extraction\Evidence;
use App\Extraction\EvidenceCollector;
use App\Extraction\ExtractedRecipe;
use App\Extraction\ExtractionException;
use App\Extraction\ExtractionResult;
use App\Extraction\FrameSampler;
use App\Extraction\MediaFetcher;
use App\Extraction\MediaFetchException;
use App\Extraction\PostImage;
use App\Extraction\RecipeExtractor;
use App\Extraction\Transcriber;
use App\Extraction\Transcript;
use App\Library\Metadata\MetadataFetcher;
use App\Llm\LlmException;
use App\Llm\Pricing;
use App\Message\AnnotateRecipe;
use App\Message\ExtractSource;
use App\Message\TranslateRecipe;
use App\Repository\SourceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The extraction pipeline for one link (docs/PLAN.md §4): gather material, read it with the
 * text model, and when that isn't enough on a video platform, watch the video: what is said
 * (Whisper) and stills of it (ffmpeg) go to the same model, which may estimate the amounts nobody
 * gives ("~380 g", see EstimatedAmount). A photo or carousel post has no video: its pictures go
 * to the model instead, as recipe cards are often written on them. Always ends in a status the cook can act on; failures
 * never retry by themselves (the app has a Retry button).
 *
 * Re-extracting a recipe (newer model, better prompt) runs the same pipeline on the material
 * already stored and rewrites that recipe in place, so its rating, favorite and planned meals
 * stay. If it doesn't work, the recipe stays as it was.
 */
#[AsMessageHandler]
final readonly class ExtractSourceHandler
{
    /** Videos are worked on here (their sound and stills go out, never the file): the cap only guards disk and time. */
    public const int MAX_VIDEO_BYTES = 300 * 1024 * 1024;
    private const int MAX_VIDEO_SECONDS = 600;
    /** Instagram carousels hold up to 20 slides, which is also all the images Bedrock takes in one call. */
    public const int MAX_IMAGES = 20;
    /** Bedrock refuses larger images. */
    private const int MAX_IMAGE_BYTES = 3_750_000;

    private const array RANK = ['complete' => 3, 'partial' => 2, 'elsewhere' => 1, 'no_recipe' => 0];

    private const array MESSAGES = [
        'es' => [
            'unreadable' => 'No hemos podido leer esta publicación. Pega el texto de la receta para extraerla.',
            'elsewhere' => 'La receta no está en la publicación. Pega el texto de la receta para extraerla.',
            'no_recipe' => 'No hemos encontrado ninguna receta en este enlace.',
            'llm' => 'No se ha podido extraer la receta (%s). Inténtalo de nuevo más tarde.',
            'unexpected' => 'Algo ha fallado al extraer la receta. Inténtalo de nuevo.',
            'kept' => 'No se ha podido volver a extraer la receta, así que se queda como estaba.',
        ],
        'en' => [
            'unreadable' => "We couldn't read this post. Paste the recipe text to extract it.",
            'elsewhere' => "The recipe isn't in the post. Paste the recipe text to extract it.",
            'no_recipe' => "We didn't find a recipe at this link.",
            'llm' => "Couldn't extract the recipe (%s). Please try again later.",
            'unexpected' => 'Something went wrong extracting the recipe. Please try again.',
            'kept' => "Couldn't extract the recipe again, so it's unchanged.",
        ],
    ];

    public function __construct(
        private SourceRepository $sources,
        private EvidenceCollector $collector,
        private RecipeExtractor $extractor,
        private MediaFetcher $media,
        private MetadataFetcher $images,
        private Transcriber $transcriber,
        private FrameSampler $frames,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        private Pricing $pricing,
        private MessageBusInterface $bus,
    ) {
    }

    public function __invoke(ExtractSource $message): void
    {
        $source = $this->sources->find(Uuid::fromString($message->sourceId));
        if (null === $source) {
            return;
        }
        $target = null;
        if (null !== $message->recipeId) {
            $target = $source->getRecipes()->findFirst(static fn (int $i, Recipe $r): bool => $r->getId()->toRfc4122() === $message->recipeId);
            if (null === $target) {
                // Deleted meanwhile.
                $this->settle($source);
                $this->entityManager->flush();

                return;
            }
        } elseif (!$source->getRecipes()->isEmpty()) {
            // Already has a recipe (typed by hand meanwhile, or a duplicate message).
            return;
        }

        $locale = 'en' === $source->getAddedBy()?->getLocale() ? 'en' : 'es';
        $source->setStatus(SourceStatus::Extracting);
        $this->entityManager->flush();

        try {
            $evidence = $this->collector->collect($source);
            $this->entityManager->flush();

            $result = $evidence->hasText() ? $this->run($source, 'text', fn (): ExtractionResult => $this->extractor->fromText($evidence, $locale)) : null;
            if ('complete' !== $result?->status && self::canWatch($evidence)) {
                $result = $this->better($result, $this->watch($source, $evidence, $locale));
            } elseif ('complete' !== $result?->status && self::canLook($evidence)) {
                $result = $this->better($result, $this->look($source, $evidence, $locale));
            }

            null === $target ? $this->apply($source, $result, $locale) : $this->reapply($source, $target, $result, $locale);
        } catch (LlmException|ExtractionException $e) {
            $this->logger->warning('Extraction failed for {url}: {error}', ['url' => $source->getUrl(), 'error' => $e->getMessage()]);
            null === $target
                ? $source->setStatus(SourceStatus::Failed, \sprintf(self::MESSAGES[$locale]['llm'], mb_substr($e->getMessage(), 0, 200)))
                : $this->settle($source, self::MESSAGES[$locale]['kept']);
        } catch (\Throwable $e) {
            $this->logger->error('Extraction crashed for {url}: {error}', ['url' => $source->getUrl(), 'error' => $e->getMessage(), 'exception' => $e]);
            null === $target
                ? $source->setStatus(SourceStatus::Failed, self::MESSAGES[$locale]['unexpected'])
                : $this->settle($source, self::MESSAGES[$locale]['kept']);
        }

        $this->entityManager->flush();

        // New or rewritten recipes: write them in the other language too, and read their facts.
        // (Asked to the repository: the link's own list doesn't know the recipes this run created.)
        foreach ($this->entityManager->getRepository(Recipe::class)->findBy(['source' => $source]) as $recipe) {
            if ($recipe->needsTranslation()) {
                $this->bus->dispatch(new TranslateRecipe($recipe->getId()->toRfc4122()));
            }
            if ($recipe->needsFacts()) {
                $this->bus->dispatch(new AnnotateRecipe($recipe->getId()->toRfc4122()));
            }
        }
    }

    /**
     * A video (not a photo or carousel post) of reasonable length. Instagram often leaves the
     * length out: then the download cap and the stills limit keep it in check.
     */
    public static function canWatch(Evidence $evidence): bool
    {
        return $evidence->isVideo() && $evidence->hasVideo && ($evidence->durationSeconds ?? 0) <= self::MAX_VIDEO_SECONDS;
    }

    /** A photo or carousel post (no video) whose pictures we know. */
    public static function canLook(Evidence $evidence): bool
    {
        return $evidence->isVideo() && !$evidence->hasVideo && [] !== $evidence->images;
    }

    /** The post's pictures, read by the text model with the caption; null if none could be fetched or read. */
    private function look(Source $source, Evidence $evidence, string $locale): ?ExtractionResult
    {
        $images = [];
        foreach (\array_slice($evidence->images, 0, self::MAX_IMAGES) as $url) {
            $download = $this->images->downloadImage($url);
            $image = null === $download ? null : PostImage::fromDownload($download['contents'], $download['extension']);
            if (null !== $image && \strlen($image->bytes) <= self::MAX_IMAGE_BYTES) {
                $images[] = $image;
            }
        }
        if ([] === $images) {
            $this->logger->info('No picture could be fetched for {url}', ['url' => $source->getUrl()]);

            return null;
        }

        try {
            return $this->run($source, 'images', fn (): ExtractionResult => $this->extractor->fromImages($evidence, $images, $locale));
        } catch (LlmException|ExtractionException $e) {
            // Logged as a failed run; whatever the text step found still counts.
            $this->logger->warning('Reading the pictures failed for {url}: {error}', ['url' => $source->getUrl(), 'error' => $e->getMessage()]);

            return null;
        }
    }

    private function watch(Source $source, Evidence $evidence, string $locale): ?ExtractionResult
    {
        try {
            $file = $this->media->downloadVideo($source->getUrl(), self::MAX_VIDEO_BYTES);
        } catch (MediaFetchException $e) {
            $this->logger->info('Video download failed for {url}: {error}', ['url' => $source->getUrl(), 'error' => $e->getMessage()]);

            return null;
        }
        if (null === $file) {
            return null;
        }

        try {
            // Platform subtitles (YouTube) say it already: no need to transcribe.
            $transcript = null === $evidence->transcript ? $this->transcribe($source, $file) : null;
            $frames = $this->frames->sample($file, $evidence->durationSeconds);

            return $this->run($source, 'video', fn (): ExtractionResult => $this->extractor->fromFrames($evidence, $transcript, $frames, $locale, estimates: true));
        } catch (MediaFetchException|LlmException|ExtractionException $e) {
            // Logged as a failed run when the model was asked; whatever the text step found still counts.
            $this->logger->warning('Video extraction failed for {url}: {error}', ['url' => $source->getUrl(), 'error' => $e->getMessage()]);

            return null;
        } finally {
            @unlink($file);
            @rmdir(\dirname($file));
        }
    }

    /** What is said in the video, logged in extraction_run; null if it couldn't be transcribed (the stills still help). */
    private function transcribe(Source $source, string $file): ?Transcript
    {
        $started = hrtime(true);
        try {
            $transcript = $this->transcriber->transcribe($file);
        } catch (MediaFetchException $e) {
            $this->entityManager->persist(new ExtractionRun(
                $source->getHousehold(), $source, 'transcript', 'openrouter', $this->transcriber->model(), 0, 0, null, false, $e->getMessage(), self::elapsedMs($started),
            ));
            $this->logger->warning('Transcription failed for {url}: {error}', ['url' => $source->getUrl(), 'error' => $e->getMessage()]);

            return null;
        }
        if (null !== $transcript) {
            $this->entityManager->persist(new ExtractionRun(
                $source->getHousehold(), $source, 'transcript', 'openrouter', $this->transcriber->model(), 0, 0, $transcript->cost, true, null, self::elapsedMs($started),
            ));
        }

        return $transcript;
    }

    /** The more useful of two answers: a fuller status wins; among equals, the one with more measured ingredients. */
    private function better(?ExtractionResult $text, ?ExtractionResult $video): ?ExtractionResult
    {
        if (null === $text || null === $video) {
            return $video ?? $text;
        }
        $rank = self::RANK[$video->status] <=> self::RANK[$text->status];
        if (0 !== $rank) {
            return $rank > 0 ? $video : $text;
        }

        return self::measured($video) > self::measured($text) ? $video : $text;
    }

    private static function measured(ExtractionResult $result): int
    {
        $count = 0;
        foreach ($result->recipes as $recipe) {
            foreach ($recipe->ingredients as $ingredient) {
                $count += null !== $ingredient['quantity'] ? 1 : 0;
            }
        }

        return $count;
    }

    /**
     * One LLM call, logged in extraction_run. An answer the app can't read (no tool call, recipes
     * as loose text) is asked for once more: it's rare and doesn't repeat.
     *
     * @param callable(): ExtractionResult $extract
     */
    private function run(Source $source, string $step, callable $extract): ExtractionResult
    {
        $model = $this->extractor->textModel();
        for ($attempt = 1;; ++$attempt) {
            $started = hrtime(true);
            try {
                $result = $extract();
                break;
            } catch (LlmException|ExtractionException $e) {
                // An unreadable answer was still paid for.
                $paid = $e instanceof ExtractionException ? $e->completion : null;
                $this->entityManager->persist(new ExtractionRun(
                    $source->getHousehold(), $source, $step, 'bedrock', $model, $paid->inputTokens ?? 0, $paid->outputTokens ?? 0,
                    null === $paid ? null : $this->pricing->cost($model, $paid->inputTokens, $paid->outputTokens), false, $e->getMessage(), self::elapsedMs($started),
                ));
                if (!$e instanceof ExtractionException || $attempt >= 2) {
                    throw $e;
                }
                $this->logger->info('Unreadable answer for {url}, asking again: {error}', ['url' => $source->getUrl(), 'error' => $e->getMessage()]);
            }
        }

        $completion = $result->completion;
        $this->entityManager->persist(new ExtractionRun(
            $source->getHousehold(), $source, $step, 'bedrock', $completion->model, $completion->inputTokens, $completion->outputTokens,
            $this->pricing->cost($completion->model, $completion->inputTokens, $completion->outputTokens), true, null, self::elapsedMs($started),
        ));

        return $result;
    }

    private function apply(Source $source, ?ExtractionResult $result, string $locale): void
    {
        if (null === $result) {
            $source->setStatus(SourceStatus::NeedsInput, self::MESSAGES[$locale]['unreadable']);

            return;
        }

        if ([] === $result->recipes) {
            'elsewhere' === $result->status
                ? $source->setStatus(SourceStatus::NeedsInput, $result->message ?? self::MESSAGES[$locale]['elsewhere'])
                : $source->setStatus(SourceStatus::Failed, $result->message ?? self::MESSAGES[$locale]['no_recipe']);

            return;
        }

        foreach ($result->recipes as $extracted) {
            $recipe = new Recipe();
            $recipe->assignHousehold($source->getHousehold());
            $recipe->setSource($source);
            $recipe->setServings($source->getHousehold()->getDefaultServings());
            $recipe->setMealTypes(MealType::cases());
            $this->fill($recipe, $extracted, $result, $locale);

            $source->getRecipes()->add($recipe);
            $this->entityManager->persist($recipe);
        }

        $result->needsReview()
            ? $source->setStatus(SourceStatus::NeedsReview, $result->message)
            : $source->setStatus(SourceStatus::Ready);
    }

    /** A new extraction of one recipe of the link: the closest by title replaces its contents. */
    private function reapply(Source $source, Recipe $recipe, ?ExtractionResult $result, string $locale): void
    {
        if (null === $result || [] === $result->recipes) {
            $this->settle($source, self::MESSAGES[$locale]['kept']);

            return;
        }

        $extracted = self::closest($recipe->getTitle(), $result->recipes);
        $userTags = $recipe->getTags();
        $this->fill($recipe, $extracted, $result, $locale);
        // Tags the cook added are theirs: keep them after the new ones.
        $recipe->setTags(\array_slice([...$recipe->getTags(), ...$userTags], 0, Recipe::MAX_TAGS));

        $this->settle($source, $result->needsReview() ? $result->message : null);
    }

    /** Back to ready (or "needs review" while a recipe of the link is unchecked) after re-extracting. */
    private function settle(Source $source, ?string $message = null): void
    {
        $pending = $source->getRecipes()->exists(static fn (int $i, Recipe $r): bool => $r->getNeedsReview());
        $source->setStatus($pending ? SourceStatus::NeedsReview : SourceStatus::Ready, $message);
    }

    /**
     * What the model found, written onto a recipe. Servings and meal types are only replaced when
     * the evidence says. The model writes in the cook's language, so that's the recipe's language
     * (whatever it answers in `language`: the translation goes from it to the other one).
     */
    private function fill(Recipe $recipe, ExtractedRecipe $extracted, ExtractionResult $result, string $locale): void
    {
        $language = Language::ofLocale($locale);
        $recipe->setTitle($extracted->title);
        $recipe->setLanguage($language);
        if (null !== $extracted->servings) {
            $recipe->setServings($extracted->servings);
        }
        $recipe->setPrepMinutes($extracted->prepMinutes);
        $recipe->setCookMinutes($extracted->cookMinutes);
        if ([] !== $extracted->mealTypes) {
            $recipe->setMealTypes($extracted->mealTypes);
        }
        $recipe->setTags($extracted->tags);
        $recipe->setSteps($extracted->steps);
        $recipe->setNotes($extracted->notes);

        $ingredients = [];
        foreach ($extracted->ingredients as $line) {
            $ingredient = new RecipeIngredient();
            $ingredient->setRawText($line['estimated'] && null !== $line['quantity'] ? EstimatedAmount::line($line['raw'], $line['quantity'], $line['unit'], $language) : $line['raw']);
            $ingredient->setGroupName($line['group']);
            $ingredient->setQuantity($line['quantity']);
            $ingredient->setUnit($line['unit']);
            $ingredient->setName($line['name']);
            $ingredient->setCanonicalKey($line['canonicalKey']);
            $ingredient->setPrep($line['prep']);
            $ingredient->setOptional($line['optional']);
            $ingredients[] = $ingredient;
        }
        $recipe->setIngredients($ingredients);
        $recipe->markExtracted($result->completion->model, $result->confidence, $result->needsReview());
    }

    /** @param non-empty-list<ExtractedRecipe> $recipes */
    private static function closest(string $title, array $recipes): ExtractedRecipe
    {
        $best = $recipes[0];
        $bestScore = -1.0;
        foreach ($recipes as $candidate) {
            similar_text(mb_strtolower($title), mb_strtolower($candidate->title), $score);
            if ($score > $bestScore) {
                [$best, $bestScore] = [$candidate, $score];
            }
        }

        return $best;
    }

    private static function elapsedMs(int|float $started): int
    {
        return (int) ((hrtime(true) - $started) / 1_000_000);
    }
}
