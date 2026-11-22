<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ExtractionRun;
use App\Extraction\MediaFetchException;
use App\Extraction\Transcriber;
use App\Security\CurrentHousehold;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Speech to text for the app's free-text answers (the tastes questionnaire, the week's wish):
 * the browser records, Whisper transcribes (the same as for videos), in the speaker's language.
 * Logged in extraction_run as step "dictation".
 */
#[AsController]
final readonly class DictationController
{
    /** About three minutes of browser audio. */
    private const int MAX_BYTES = 8 * 1024 * 1024;

    public function __construct(
        private CurrentHousehold $current,
        private Transcriber $transcriber,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    #[Route('/api/dictation', name: 'api_dictation', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->current->user();
        $audio = $request->files->get('audio');
        if (!$audio instanceof UploadedFile || !$audio->isValid()) {
            return new JsonResponse(['error' => 'Send the recording as the "audio" file.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($audio->getSize() > self::MAX_BYTES) {
            return new JsonResponse(['error' => 'The recording is too long.'], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        $started = hrtime(true);
        try {
            $transcript = $this->transcriber->transcribe($audio->getPathname(), $user->getLocale());
        } catch (MediaFetchException $e) {
            $this->logger->warning('Dictation failed: {error}', ['error' => $e->getMessage()]);
            $this->log(false, null, $e->getMessage(), $started);

            return new JsonResponse(['error' => 'The recording couldn\'t be transcribed.'], Response::HTTP_BAD_GATEWAY);
        }
        $this->log(true, $transcript?->cost, null, $started);

        return new JsonResponse(['text' => null === $transcript ? '' : trim($transcript->text())]);
    }

    private function log(bool $ok, ?float $cost, ?string $error, int|float $started): void
    {
        $this->entityManager->persist(new ExtractionRun(
            $this->current->household(), null, 'dictation', 'openrouter', $this->transcriber->model(), 0, 0, $cost, $ok, $error, (int) ((hrtime(true) - $started) / 1_000_000),
        ));
        $this->entityManager->flush();
    }
}
