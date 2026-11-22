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
        /** Whether there is a video to watch: photo and carousel posts have none. */
        public bool $hasVideo = false,
        /** @var list<string> The pictures of a photo or carousel post, in order: recipe cards are often in them. */
        public array $images = [],
    ) {
    }

    /** @return array<string, string|int|bool|list<string>> */
    public function toEvidence(): array
    {
        return array_filter([
            'video' => $this->hasVideo,
            'title' => $this->title,
            'description' => $this->description,
            'uploader' => $this->uploader,
            'thumbnail_url' => $this->thumbnailUrl,
            'duration' => $this->durationSeconds,
            'language' => $this->language,
            'images' => $this->images,
        ], static fn (string|int|bool|array|null $value): bool => null !== $value && '' !== $value && false !== $value && [] !== $value);
    }
}
