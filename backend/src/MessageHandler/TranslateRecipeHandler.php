<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\ExtractionRun;
use App\Entity\Recipe;
use App\Llm\LlmException;
use App\Llm\Pricing;
use App\Message\TranslateRecipe;
use App\Translation\RecipeText;
use App\Translation\RecipeTranslator;
use App\Translation\TranslationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Writes a recipe in the other language after it is added, extracted again or edited. Each call
 * is logged in extraction_run (step "translate") for the costs page. A failed call is retried by
 * Messenger; meanwhile everyone reads the recipe in the language it was written in.
 */
#[AsMessageHandler]
final readonly class TranslateRecipeHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private RecipeTranslator $translator,
        private LoggerInterface $logger,
        private Pricing $pricing,
        #[Autowire('%env(BEDROCK_TEXT_MODEL)%')]
        private string $model,
    ) {
    }

    public function __invoke(TranslateRecipe $message): void
    {
        $recipe = $this->entityManager->find(Recipe::class, Uuid::fromString($message->recipeId));
        // Gone, or already translated (a repeated message).
        if (null === $recipe || !$recipe->needsTranslation()) {
            return;
        }

        $text = RecipeText::of($recipe);
        $started = hrtime(true);
        try {
            $translated = $this->translator->translate($text);
        } catch (LlmException|TranslationException $e) {
            $this->logger->warning('Translating recipe {id} failed: {error}', ['id' => $message->recipeId, 'error' => $e->getMessage()]);
            $this->entityManager->persist(new ExtractionRun($recipe->getHousehold(), $recipe->getSource(), 'translate', 'bedrock', $this->model, 0, 0, null, false, $e->getMessage(), self::elapsedMs($started)));
            $this->entityManager->flush();

            throw $e;
        }
        $completion = $translated->completion;
        $this->entityManager->persist(new ExtractionRun(
            $recipe->getHousehold(), $recipe->getSource(), 'translate', 'bedrock', $completion->model, $completion->inputTokens, $completion->outputTokens,
            $this->pricing->cost($completion->model, $completion->inputTokens, $completion->outputTokens), true, null, self::elapsedMs($started),
        ));
        $this->entityManager->flush();

        // Someone may have edited the recipe during the call: read it again, and keep the
        // translation only if it was made from the text the recipe has now.
        $this->entityManager->clear();
        $recipe = $this->entityManager->find(Recipe::class, Uuid::fromString($message->recipeId));
        if (null === $recipe || !$recipe->translate($translated->text, $text->hash())) {
            $this->logger->info('Recipe {id} changed while it was being translated; its edit queued another translation', ['id' => $message->recipeId]);

            return;
        }
        $this->entityManager->flush();
    }

    private static function elapsedMs(int|float $started): int
    {
        return (int) ((hrtime(true) - $started) / 1_000_000);
    }
}
