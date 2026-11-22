<?php

declare(strict_types=1);

namespace App\Extraction;

/** One still from a video, as JPEG bytes, and when it shows. */
final readonly class VideoFrame
{
    public function __construct(
        public float $seconds,
        public string $jpeg,
    ) {
    }
}
