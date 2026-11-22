<?php

declare(strict_types=1);

namespace App\Tests;

use App\Extraction\MediaFetcher;
use App\Extraction\MediaFetchException;
use App\Extraction\MediaInfo;

/** Stands in for yt-dlp in tests: set what each URL answers. */
final class FakeMediaFetcher implements MediaFetcher
{
    /** @var array<string, MediaInfo|MediaFetchException> */
    public array $infos = [];

    /** @var array<string, string> video bytes by URL */
    public array $videos = [];

    /** @var list<string> */
    public array $downloaded = [];

    public function info(string $url): MediaInfo
    {
        $info = $this->infos[$url] ?? new MediaFetchException('No canned info for '.$url);
        if ($info instanceof MediaFetchException) {
            throw $info;
        }

        return $info;
    }

    public function downloadVideo(string $url, int $maxBytes): ?string
    {
        if (!isset($this->videos[$url])) {
            return null;
        }
        $this->downloaded[] = $url;
        $directory = sys_get_temp_dir().'/tabooki-test-video-'.bin2hex(random_bytes(4));
        mkdir($directory);
        file_put_contents($directory.'/video.mp4', $this->videos[$url]);

        return $directory.'/video.mp4';
    }
}
