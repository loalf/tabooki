<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\ExtractionRun;
use App\Entity\Household;
use App\Llm\LlmException;
use App\Llm\Pricing;
use App\Message\SummarizeTaste;
use App\Repository\TasteProfileRepository;
use App\Taste\TasteCompiler;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Writes the summary of the household's likings for their rules and notes as they are now, when
 * they have none (a rule was edited, a suggestion taken). Logged in extraction_run as step
 * "taste_profile". If they change during the call, the summary is dropped: that change queued
 * another.
 */
#[AsMessageHandler]
final readonly class SummarizeTasteHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TasteProfileRepository $profiles,
        private TasteCompiler $compiler,
        private Pricing $pricing,
        private LoggerInterface $logger,
        #[Autowire('%env(BEDROCK_TEXT_MODEL)%')]
        private string $model,
    ) {
    }

    public function __invoke(SummarizeTaste $message): void
    {
        $household = $this->entityManager->find(Household::class, Uuid::fromString($message->householdId));
        $profile = null === $household ? null : $this->profiles->forHousehold($household);
        if (null === $household || null === $profile || !$profile->needsSummary()) {
            return;
        }

        $from = $profile->likingsHash();
        $started = hrtime(true);
        try {
            [$summary, $completion] = $this->compiler->summarize($profile->getRules(), $profile->getNotes());
        } catch (LlmException $e) {
            $this->logger->warning('Summarizing the household\'s likings failed: {error}', ['error' => $e->getMessage()]);
            $this->entityManager->persist(new ExtractionRun($household, null, 'taste_profile', 'bedrock', $this->model, 0, 0, null, false, $e->getMessage(), self::elapsedMs($started)));
            $this->entityManager->flush();

            throw $e;
        }
        $this->entityManager->persist(new ExtractionRun(
            $household, null, 'taste_profile', 'bedrock', $completion->model, $completion->inputTokens, $completion->outputTokens,
            $this->pricing->cost($completion->model, $completion->inputTokens, $completion->outputTokens), true, null, self::elapsedMs($started),
        ));
        $this->entityManager->flush();

        $this->entityManager->refresh($profile);
        if (null !== $summary) {
            $profile->summarize($summary, $from);
        }
        $this->entityManager->flush();
    }

    private static function elapsedMs(int|float $started): int
    {
        return (int) ((hrtime(true) - $started) / 1_000_000);
    }
}
