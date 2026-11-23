<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\ExtractionRun;
use App\Entity\Recipe;
use App\Food\FoodCatalog;
use App\Food\RecipeWeigher;
use App\Llm\LlmException;
use App\Llm\Pricing;
use App\Message\AnnotateRecipe;
use App\Planning\AnnotationException;
use App\Planning\RecipeAnnotator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Reads a recipe's facts (heaviness, spice, food groups, each line's weight) after it is added
 * or its title or ingredients change. Each call is logged in extraction_run (step "annotate") for the costs
 * page. A failed call is retried by Messenger; meanwhile the planner treats the facts as unknown.
 */
#[AsMessageHandler]
final readonly class AnnotateRecipeHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private RecipeAnnotator $annotator,
        private FoodCatalog $foods,
        private RecipeWeigher $weigher,
        private LoggerInterface $logger,
        private Pricing $pricing,
        #[Autowire('%env(BEDROCK_TEXT_MODEL)%')]
        private string $model,
    ) {
    }

    public function __invoke(AnnotateRecipe $message): void
    {
        $recipe = $this->entityManager->find(Recipe::class, Uuid::fromString($message->recipeId));
        // Gone, or already read (a repeated message).
        if (null === $recipe || !$recipe->needsFacts()) {
            return;
        }

        $from = $recipe->factsFingerprint();
        $started = hrtime(true);
        try {
            [$facts, $completion] = $this->annotator->annotate($recipe);
        } catch (LlmException|AnnotationException $e) {
            $this->logger->warning('Reading the facts of recipe {id} failed: {error}', ['id' => $message->recipeId, 'error' => $e->getMessage()]);
            $this->entityManager->persist(new ExtractionRun($recipe->getHousehold(), $recipe->getSource(), 'annotate', 'bedrock', $this->model, 0, 0, null, false, $e->getMessage(), self::elapsedMs($started)));
            $this->entityManager->flush();

            throw $e;
        }
        $this->entityManager->persist(new ExtractionRun(
            $recipe->getHousehold(), $recipe->getSource(), 'annotate', 'bedrock', $completion->model, $completion->inputTokens, $completion->outputTokens,
            $this->pricing->cost($completion->model, $completion->inputTokens, $completion->outputTokens), true, null, self::elapsedMs($started),
        ));
        $this->entityManager->flush();

        // Someone may have edited the recipe during the call: read it again, and keep the facts
        // only if they were read from the title and ingredients it has now.
        $this->entityManager->clear();
        $recipe = $this->entityManager->find(Recipe::class, Uuid::fromString($message->recipeId));
        if (null === $recipe || !$recipe->applyFacts($facts, $from)) {
            $this->logger->info('Recipe {id} changed while its facts were being read; its edit queued another reading', ['id' => $message->recipeId]);

            return;
        }
        // Lines typed by hand may have just got a key: their foods, then every line's weight.
        $this->entityManager->flush();
        $this->foods->recipeChanged($recipe);
        $this->weigher->weigh($recipe, $facts->grams());
        $this->entityManager->flush();
    }

    private static function elapsedMs(int|float $started): int
    {
        return (int) ((hrtime(true) - $started) / 1_000_000);
    }
}
