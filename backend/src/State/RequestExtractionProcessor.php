<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\SourceTextInput;
use App\Entity\Source;
use App\Enum\SourceStatus;
use App\Message\ExtractSource;
use App\Repository\SourceRepository;
use App\Security\CurrentHousehold;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * POST /api/sources/{id}/extract (try again) and POST /api/sources/{id}/text (the cook pastes
 * the recipe text): both queue a new extraction of a link that has no recipe yet.
 *
 * @implements ProcessorInterface<SourceTextInput|null, Source>
 */
final readonly class RequestExtractionProcessor implements ProcessorInterface
{
    public function __construct(
        private SourceRepository $sources,
        private CurrentHousehold $current,
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $bus,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Source
    {
        $id = $uriVariables['id'] ?? null;
        // API Platform already turns {id} into a Uuid; be lenient with plain strings too.
        $uuid = $id instanceof Uuid ? $id : (\is_string($id) && Uuid::isValid($id) ? Uuid::fromString($id) : null);
        $source = null === $uuid ? null : $this->sources->find($uuid);
        if (null === $source || $source->getHousehold()->getId()->toRfc4122() !== $this->current->household()->getId()->toRfc4122()) {
            throw new NotFoundHttpException('Not Found');
        }
        if (!$source->getRecipes()->isEmpty()) {
            throw new ConflictHttpException('This link already has a recipe.');
        }
        if (SourceStatus::Extracting === $source->getStatus()) {
            return $source;
        }

        if ($data instanceof SourceTextInput) {
            $source->addEvidence('user_text', trim($data->text));
        }
        $source->setStatus(SourceStatus::Pending);
        $this->entityManager->flush();

        $this->bus->dispatch(new ExtractSource($source->getId()->toRfc4122()));

        return $source;
    }
}
