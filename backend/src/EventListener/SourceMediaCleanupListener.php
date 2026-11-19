<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Source;
use App\Media\MediaStorage;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;

/** Deletes a link's cached files once the link itself is deleted. */
#[AsEntityListener(event: Events::postRemove, entity: Source::class)]
final readonly class SourceMediaCleanupListener
{
    public function __construct(private MediaStorage $media)
    {
    }

    public function postRemove(Source $source): void
    {
        if (null !== $source->getThumbnailPath()) {
            $this->media->delete($source->getThumbnailPath());
        }
    }
}
