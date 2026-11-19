<?php

declare(strict_types=1);

namespace App\Media;

use App\Entity\Source;
use App\Library\Metadata\MetadataFetcher;

/** Keeps our own copy of a link's thumbnail (remote ones expire or refuse hotlinking). */
final readonly class ThumbnailCache
{
    public function __construct(
        private MetadataFetcher $fetcher,
        private MediaStorage $media,
    ) {
    }

    public function cache(Source $source, string $imageUrl): void
    {
        $source->setThumbnailUrl($imageUrl);
        $image = $this->fetcher->downloadImage($imageUrl);
        if (null === $image) {
            return;
        }

        // The content hash in the name lets the browser cache the file forever.
        $path = \sprintf(
            '%s/thumbnails/%s-%s.%s',
            $source->getHousehold()->getId()->toRfc4122(),
            $source->getId()->toRfc4122(),
            substr(hash('xxh3', $image['contents']), 0, 12),
            $image['extension'],
        );
        $previous = $source->getThumbnailPath();

        $this->media->write($path, $image['contents']);
        $source->setThumbnailPath($path);

        if (null !== $previous && $previous !== $path) {
            $this->media->delete($previous);
        }
    }
}
