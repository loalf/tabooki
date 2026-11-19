<?php

declare(strict_types=1);

namespace App\Controller;

use App\Media\MediaStorage;
use App\Security\CurrentHousehold;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/** Serves stored media (cached thumbnails, ...) to members of the household that owns it. */
#[AsController]
final readonly class MediaController
{
    private const array CONTENT_TYPES = [
        'jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif', 'avif' => 'image/avif',
    ];

    public function __construct(
        private MediaStorage $media,
        private CurrentHousehold $current,
    ) {
    }

    #[Route('/api/media/{path}', name: 'api_media', requirements: ['path' => '[0-9a-f-]{36}/[A-Za-z0-9/_.-]+'], methods: ['GET'])]
    public function __invoke(string $path): BinaryFileResponse
    {
        $householdId = explode('/', $path, 2)[0];
        if ($householdId !== $this->current->household()->getId()->toRfc4122() || !$this->media->exists($path)) {
            throw new NotFoundHttpException();
        }

        $response = new BinaryFileResponse($this->media->absolutePath($path));
        $response->headers->set('Content-Type', self::CONTENT_TYPES[pathinfo($path, \PATHINFO_EXTENSION)] ?? 'application/octet-stream');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        // File names carry a content hash, so they never change: cache them for good (privately).
        $response->setPrivate();
        $response->setMaxAge(31536000);
        $response->setImmutable();

        return $response;
    }
}
