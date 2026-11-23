<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\ExtractionRun;
use App\Entity\Recipe;
use App\Entity\RecipeEmbedding;
use App\Llm\EmbeddingClient;
use App\Llm\LlmException;
use App\Llm\Pricing;
use App\Message\EmbedRecipe;
use App\Search\RecipeDigest;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Embeds a recipe's digest (App\Search\RecipeDigest) when it changed, or the model did: most
 * saves (a rating, a favorite) change nothing it says, and cost no call. Logged in extraction_run
 * as step "embed".
 */
#[AsMessageHandler]
final readonly class EmbedRecipeHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private EmbeddingClient $embeddings,
        private LoggerInterface $logger,
        private Pricing $pricing,
        #[Autowire('%env(BEDROCK_EMBEDDING_MODEL)%')]
        private string $model,
    ) {
    }

    public function __invoke(EmbedRecipe $message): void
    {
        $recipe = $this->entityManager->find(Recipe::class, Uuid::fromString($message->recipeId));
        if (null === $recipe) {
            return;
        }
        $digest = RecipeDigest::of($recipe);
        $hash = hash('xxh128', $this->model."\n".$digest);
        $stored = $this->entityManager->find(RecipeEmbedding::class, $recipe->getId());
        if ($stored?->getDigestHash() === $hash) {
            return;
        }

        $started = hrtime(true);
        try {
            $embedding = $this->embeddings->embed($this->model, $digest, EmbeddingClient::DOCUMENT);
        } catch (LlmException $e) {
            $this->logger->warning('Embedding recipe {id} failed: {error}', ['id' => $message->recipeId, 'error' => $e->getMessage()]);
            $this->entityManager->persist(new ExtractionRun($recipe->getHousehold(), $recipe->getSource(), 'embed', 'bedrock', $this->model, 0, 0, null, false, $e->getMessage(), self::elapsedMs($started)));
            $this->entityManager->flush();

            if ($e->retryable) {
                throw $e;
            }

            return;
        }
        $this->entityManager->persist(new ExtractionRun(
            $recipe->getHousehold(), $recipe->getSource(), 'embed', 'bedrock', $embedding->model, $embedding->tokens, 0,
            $this->pricing->cost($embedding->model, $embedding->tokens, 0), true, null, self::elapsedMs($started),
        ));
        if (null === $stored) {
            $this->entityManager->persist(new RecipeEmbedding($recipe, $embedding->vector, $embedding->model, $hash));
        } else {
            $stored->update($embedding->vector, $embedding->model, $hash);
        }
        $this->entityManager->flush();
    }

    private static function elapsedMs(int|float $started): int
    {
        return (int) ((hrtime(true) - $started) / 1_000_000);
    }
}
