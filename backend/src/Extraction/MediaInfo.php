<?php

declare(strict_types=1);

namespace App\Extraction;

/** What yt-dlp knows about a video or post, without downloading it. */
final readonly class MediaInfo
{
    public function __construct(
        public ?string $title = null,
        /** The caption (Instagram, TikTok) or description (YouTube): often the whole recipe. */
        public ?string $description = null,
        public ?string $uploader = null,
        public ?string $thumbnailUrl = null,
        public ?int $durationSeconds = null,
        public ?string $language = null,
        /** Spoken words, from subtitles or automatic captions (YouTube mostly). */
        public ?string $transcript = null,
    ) {
    }

    /** @return array<string, string|int> */
    public function toEvidence(): array
    {
        return array_filter([
            'title' => $this->title,
            'description' => $this->description,
            'uploader' => $this->uploader,
            'thumbnail_url' => $this->thumbnailUrl,
            'duration' => $this->durationSeconds,
            'language' => $this->language,
        ], static fn (string|int|null $value): bool => null !== $value && '' !== $value);
    }
}
