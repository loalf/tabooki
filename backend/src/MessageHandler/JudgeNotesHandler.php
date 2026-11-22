<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\ExtractionRun;
use App\Entity\Household;
use App\Entity\Recipe;
use App\Llm\LlmException;
use App\Llm\Pricing;
use App\Message\JudgeNotes;
use App\Repository\RecipeRepository;
use App\Repository\TasteProfileRepository;
use App\Taste\NotesJudge;
use App\Taste\Taste;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Judges every recipe of the household not yet read against its current notes, in batches.
 * Runs after the notes are saved and after a recipe is added or its title or ingredients
 * change; a repeated message finds nothing left to do. Each call is logged in extraction_run
 * (step "notes_fit"). A failed call is retried by Messenger; meanwhile those recipes count as
 * neutral.
 */
#[AsMessageHandler]
final readonly class JudgeNotesHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private RecipeRepository $recipes,
        private TasteProfileRepository $profiles,
        private NotesJudge $judge,
        private Pricing $pricing,
        private LoggerInterface $logger,
        #[Autowire('%env(BEDROCK_TEXT_MODEL)%')]
        private string $model,
    ) {
    }

    public function __invoke(JudgeNotes $message): void
    {
        $household = $this->entityManager->find(Household::class, Uuid::fromString($message->householdId));
        $notes = null === $household ? null : $this->profiles->forHousehold($household)?->getNotes();
        $hash = Taste::notesHash($notes);
        if (null === $household || null === $notes || null === $hash) {
            return;
        }

        $pending = array_values(array_filter($this->recipes->libraryOf($household), static fn (Recipe $r): bool => $r->needsNotesJudgement($hash)));
        foreach (array_chunk($pending, NotesJudge::BATCH) as $batch) {
            $read = array_map(static fn (Recipe $r): string => $r->factsFingerprint(), $batch);
            $started = hrtime(true);
            try {
                [$judgements, $completion] = $this->judge->judge($notes, $batch);
            } catch (LlmException $e) {
                $this->logger->warning('Judging recipes against the notes failed: {error}', ['error' => $e->getMessage()]);
                $this->entityManager->persist(new ExtractionRun($household, null, 'notes_fit', 'bedrock', $this->model, 0, 0, null, false, $e->getMessage(), self::elapsedMs($started)));
                $this->entityManager->flush();

                throw $e;
            }
            $this->entityManager->persist(new ExtractionRun(
                $household, null, 'notes_fit', 'bedrock', $completion->model, $completion->inputTokens, $completion->outputTokens,
                $this->pricing->cost($completion->model, $completion->inputTokens, $completion->outputTokens), true, null, self::elapsedMs($started),
            ));
            // A recipe edited during the call keeps no judgement: its edit queued another.
            foreach ($batch as $i => $recipe) {
                $recipe->judgeNotes($hash, $read[$i], $judgements[$i]['fit'], $judgements[$i]['when'], $judgements[$i]['reason']);
            }
            $this->entityManager->flush();
        }
    }

    private static function elapsedMs(int|float $started): int
    {
        return (int) ((hrtime(true) - $started) / 1_000_000);
    }
}
