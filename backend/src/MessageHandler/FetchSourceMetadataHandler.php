<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Library\Metadata\MetadataFetcher;
use App\Media\ThumbnailCache;
use App\Message\ExtractSource;
use App\Message\FetchSourceMetadata;
use App\Repository\SourceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final readonly class FetchSourceMetadataHandler
{
    public function __construct(
        private SourceRepository $sources,
        private MetadataFetcher $fetcher,
        private ThumbnailCache $thumbnails,
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $bus,
    ) {
    }

    /** Failures are expected (blocked sites, private posts): the link just keeps no preview. */
    public function __invoke(FetchSourceMetadata $message): void
    {
        $source = $this->sources->find(Uuid::fromString($message->sourceId));
        if (null === $source) {
            return; // deleted in the meantime
        }

        $metadata = $this->fetcher->fetch($source->getUrl(), $source->getPlatform());

        if (null === $source->getTitle()) {
            $source->setTitle($metadata->title);
        }
        $source->setAuthor($metadata->author);
        $source->addEvidence('metadata', $metadata->toEvidence());
        if (null !== $metadata->recipeJsonLd) {
            $source->addEvidence('json_ld', $metadata->recipeJsonLd);
        }
        if (null !== $metadata->pageText) {
            $source->addEvidence('page_text', $metadata->pageText);
        }
        if (null !== $metadata->imageUrl) {
            $this->thumbnails->cache($source, $metadata->imageUrl);
        }

        $source->markMetadataFetched();
        $this->entityManager->flush();

        // Next: the recipe itself.
        $this->bus->dispatch(new ExtractSource($message->sourceId));
    }
}
