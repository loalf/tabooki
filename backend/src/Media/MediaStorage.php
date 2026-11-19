<?php

declare(strict_types=1);

namespace App\Media;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Files the app keeps (cached thumbnails now; screenshots and videos later), stored on a
 * Docker volume shared by the php and worker containers. Paths are relative to the media
 * directory and always start with the owning household's id, which is how MediaController
 * checks access.
 */
final readonly class MediaStorage
{
    private Filesystem $filesystem;

    public function __construct(
        #[Autowire('%app.media_dir%')]
        private string $mediaDir,
    ) {
        $this->filesystem = new Filesystem();
    }

    public function write(string $path, string $contents): void
    {
        $this->filesystem->dumpFile($this->absolutePath($path), $contents);
    }

    public function delete(string $path): void
    {
        $this->filesystem->remove($this->absolutePath($path));
    }

    public function exists(string $path): bool
    {
        return is_file($this->absolutePath($path));
    }

    public function absolutePath(string $path): string
    {
        if (str_contains($path, '..') || str_contains($path, "\0")) {
            throw new \InvalidArgumentException('Invalid media path.');
        }

        return rtrim($this->mediaDir, '/').'/'.ltrim($path, '/');
    }
}
