<?php

declare(strict_types=1);

namespace App\Extraction;

/** Reads videos and social posts (implemented with yt-dlp; faked in tests). */
interface MediaFetcher
{
    /** @throws MediaFetchException */
    public function info(string $url): MediaInfo;

    /**
     * Downloads the video to a temporary file the caller must delete; null when there's
     * no format small enough.
     *
     * @throws MediaFetchException
     */
    public function downloadVideo(string $url, int $maxBytes): ?string;
}
