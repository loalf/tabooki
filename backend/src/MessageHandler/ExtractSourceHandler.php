<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\ExtractionRun;
use App\Entity\Recipe;
use App\Entity\RecipeIngredient;
use App\Entity\Source;
use App\Enum\MealType;
use App\Enum\SourceStatus;
use App\Extraction\Evidence;
use App\Extraction\EvidenceCollector;
use App\Extraction\ExtractionException;
use App\Extraction\ExtractionResult;
use App\Extraction\MediaFetcher;
use App\Extraction\MediaFetchException;
use App\Extraction\RecipeExtractor;
use App\Llm\LlmException;
use App\Message\ExtractSource;
use App\Repository\SourceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * The extraction pipeline for one link (docs/PLAN.md §4): gather material, read it with the
 * text model, and when that isn't enough on a video platform, watch the video. Always ends in
 * a status the cook can act on; failures never retry by themselves (the app has a Retry button).
 */
#[AsMessageHandler]
final readonly class ExtractSourceHandler
{
    /** Bedrock takes inline videos up to 25 MB once base64-encoded. */
    public const int MAX_VIDEO_BYTES = 18 * 1024 * 1024;
    private const int MAX_VIDEO_SECONDS = 600;

    private const array RANK = ['complete' => 3, 'partial' => 2, 'elsewhere' => 1, 'no_recipe' => 0];

    private const array MESSAGES = [
        'es' => [
            'unreadable' => 'No hemos podido leer esta publicación. Pega el texto de la receta para extraerla.',
            'elsewhere' => 'La receta no está en la publicación. Pega el texto de la receta para extraerla.',
            'no_recipe' => 'No hemos encontrado ninguna receta en este enlace.',
            'llm' => 'No se ha podido extraer la receta (%s). Inténtalo de nuevo más tarde.',
            'unexpected' => 'Algo ha fallado al extraer la receta. Inténtalo de nuevo.',
        ],
        'en' => [
            'unreadable' => "We couldn't read this post. Paste the recipe text to extract it.",
            'elsewhere' => "The recipe isn't in the post. Paste the recipe text to extract it.",
            'no_recipe' => "We didn't find a recipe at this link.",
            'llm' => "Couldn't extract the recipe (%s). Please try again later.",
            'unexpected' => 'Something went wrong extracting the recipe. Please try again.',
        ],
    ];

    /** @param array<string, array{input: float, output: float}> $prices USD per million tokens, by model */
    public function __construct(
        private SourceRepository $sources,
        private EvidenceCollector $collector,
        private RecipeExtractor $extractor,
        private MediaFetcher $media,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        #[Autowire('%app.llm_prices%')]
        private array $prices = [],
    ) {
    }

    public function __invoke(ExtractSource $message): void
    {
        $source = $this->sources->find(Uuid::fromString($message->sourceId));
        // Deleted, or already has a recipe (typed by hand meanwhile, or a duplicate message).
        if (null === $source || !$source->getRecipes()->isEmpty()) {
            return;
        }

        $locale = 'en' === $source->getAddedBy()?->getLocale() ? 'en' : 'es';
        $source->setStatus(SourceStatus::Extracting);
        $this->entityManager->flush();

        try {
            $evidence = $this->collector->collect($source);
            $this->entityManager->flush();

            $result = $evidence->hasText() ? $this->run($source, 'text', fn (): ExtractionResult => $this->extractor->fromText($evidence, $locale)) : null;
            if ('complete' !== $result?->status && $this->canWatch($evidence)) {
                $result = $this->better($result, $this->watch($source, $evidence, $locale));
            }

            $this->apply($source, $result, $locale);
        } catch (LlmException|ExtractionException $e) {
            $this->logger->warning('Extraction failed for {url}: {error}', ['url' => $source->getUrl(), 'error' => $e->getMessage()]);
            $source->setStatus(SourceStatus::Failed, \sprintf(self::MESSAGES[$locale]['llm'], mb_substr($e->getMessage(), 0, 200)));
        } catch (\Throwable $e) {
            $this->logger->error('Extraction crashed for {url}: {error}', ['url' => $source->getUrl(), 'error' => $e->getMessage(), 'exception' => $e]);
            $source->setStatus(SourceStatus::Failed, self::MESSAGES[$locale]['unexpected']);
        }

        $this->entityManager->flush();
    }

    private function canWatch(Evidence $evidence): bool
    {
        return $evidence->isVideo() && ($evidence->durationSeconds ?? 0) <= self::MAX_VIDEO_SECONDS;
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
            $bytes = (string) file_get_contents($file);

            return $this->run($source, 'video', fn (): ExtractionResult => $this->extractor->fromVideo($evidence, $bytes, $locale));
        } finally {
            @unlink($file);
            @rmdir(\dirname($file));
        }
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

    /** @param callable(): ExtractionResult $extract */
    private function run(Source $source, string $step, callable $extract): ExtractionResult
    {
        $model = 'video' === $step ? $this->extractor->videoModel() : $this->extractor->textModel();
        $started = hrtime(true);
        try {
            $result = $extract();
        } catch (LlmException|ExtractionException $e) {
            $this->entityManager->persist(new ExtractionRun($source, $step, 'bedrock', $model, 0, 0, null, false, $e->getMessage(), self::elapsedMs($started)));
            throw $e;
        }

        $completion = $result->completion;
        $this->entityManager->persist(new ExtractionRun(
            $source, $step, 'bedrock', $completion->model, $completion->inputTokens, $completion->outputTokens,
            $this->cost($completion->model, $completion->inputTokens, $completion->outputTokens), true, null, self::elapsedMs($started),
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
            $recipe->setTitle($extracted->title);
            $recipe->setLanguage($extracted->language);
            $recipe->setServings($extracted->servings ?? $source->getHousehold()->getDefaultServings());
            $recipe->setPrepMinutes($extracted->prepMinutes);
            $recipe->setCookMinutes($extracted->cookMinutes);
            $recipe->setMealTypes([] !== $extracted->mealTypes ? $extracted->mealTypes : MealType::cases());
            $recipe->setTags($extracted->tags);
            $recipe->setSteps($extracted->steps);
            $recipe->setNotes($extracted->notes);

            $ingredients = [];
            foreach ($extracted->ingredients as $line) {
                $ingredient = new RecipeIngredient();
                $ingredient->setRawText($line['raw']);
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

            $source->getRecipes()->add($recipe);
            $this->entityManager->persist($recipe);
        }

        $result->needsReview()
            ? $source->setStatus(SourceStatus::NeedsReview, $result->message)
            : $source->setStatus(SourceStatus::Ready);
    }

    private function cost(string $model, int $inputTokens, int $outputTokens): ?float
    {
        $price = $this->prices[$model] ?? null;

        return null === $price ? null : round(($inputTokens * $price['input'] + $outputTokens * $price['output']) / 1_000_000, 6);
    }

    private static function elapsedMs(int|float $started): int
    {
        return (int) ((hrtime(true) - $started) / 1_000_000);
    }
}
