<?php

declare(strict_types=1);

namespace App\Extraction;

use App\Entity\Source;
use App\Enum\SourcePlatform;

/** Everything gathered about a link, as the LLM reads it. Built from Source::getEvidence(). */
final readonly class Evidence
{
    private const int MAX_PAGE_TEXT_WITH_JSON_LD = 6000;

    /**
     * @param array<string, mixed>|null $jsonLd
     * @param array<string, mixed>|null $linkedJsonLd
     */
    public function __construct(
        public SourcePlatform $platform,
        public string $url,
        public ?string $title = null,
        public ?string $author = null,
        public ?string $description = null,
        public ?string $transcript = null,
        public ?string $pageText = null,
        public ?array $jsonLd = null,
        public ?array $linkedJsonLd = null,
        public ?string $linkedUrl = null,
        public ?string $userText = null,
        public ?int $durationSeconds = null,
        /** A video to watch (yt-dlp saw video formats, or a duration). */
        public bool $hasVideo = false,
    ) {
    }

    public static function fromSource(Source $source): self
    {
        $evidence = $source->getEvidence();
        $get = static function (string ...$path) use ($evidence): mixed {
            $value = $evidence;
            foreach ($path as $key) {
                if (!\is_array($value) || !\array_key_exists($key, $value)) {
                    return null;
                }
                $value = $value[$key];
            }

            return $value;
        };
        $string = static fn (mixed $value): ?string => \is_string($value) && '' !== trim($value) ? trim($value) : null;
        /** @var array<string, mixed>|null $jsonLd */
        $jsonLd = \is_array($get('json_ld')) ? $get('json_ld') : null;
        /** @var array<string, mixed>|null $linked */
        $linked = \is_array($get('linked', 'json_ld')) ? $get('linked', 'json_ld') : null;
        $duration = $get('media', 'duration');

        return new self(
            platform: $source->getPlatform(),
            url: $source->getUrl(),
            title: $string($get('media', 'title')) ?? $string($get('metadata', 'title')) ?? $source->getTitle(),
            author: $string($get('media', 'uploader')) ?? $source->getAuthor(),
            // yt-dlp's caption is the full one; OpenGraph descriptions are often cut.
            description: $string($get('media', 'description')) ?? $string($get('metadata', 'description')),
            transcript: $string($get('transcript')),
            pageText: $string($get('page_text')),
            jsonLd: $jsonLd,
            linkedJsonLd: $linked,
            linkedUrl: $string($get('linked', 'url')),
            userText: $string($get('user_text')),
            durationSeconds: \is_int($duration) ? $duration : null,
            hasVideo: true === $get('media', 'video') || (\is_int($duration) && $duration > 0),
        );
    }

    /** The same material with a transcript made here (speech recognition) instead of the platform's. */
    public function withTranscript(?string $transcript): self
    {
        return clone ($this, ['transcript' => $transcript]);
    }

    /** The same material without the caption and what it links to: to test what a video alone gives. */
    public function withoutCaption(): self
    {
        return clone ($this, ['description' => null, 'linkedJsonLd' => null, 'linkedUrl' => null]);
    }

    public function hasText(): bool
    {
        return null !== $this->jsonLd || null !== $this->linkedJsonLd || null !== $this->description
            || null !== $this->transcript || null !== $this->pageText || null !== $this->userText;
    }

    /** Video-first platforms, where the recipe may only be shown or said in the video. */
    public function isVideo(): bool
    {
        return SourcePlatform::Web !== $this->platform;
    }

    /** The material as the user message of the extraction prompt. */
    public function toPrompt(string $interfaceLanguage): string
    {
        $sections = [\sprintf("Interface language for messages: %s\nLink: %s (%s)", $interfaceLanguage, $this->url, $this->platform->value)];
        if (null !== $this->title) {
            $sections[] = 'Title: '.$this->title;
        }
        if (null !== $this->author) {
            $sections[] = 'Author: '.$this->author;
        }
        if (null !== $this->userText) {
            $sections[] = "## Text the cook pasted for this link (most reliable)\n".$this->userText;
        }
        if (null !== $this->jsonLd) {
            $sections[] = "## Recipe data published by the page (schema.org)\n".self::json($this->jsonLd);
        }
        if (null !== $this->linkedJsonLd) {
            $sections[] = \sprintf("## Recipe data from the page linked in the description (%s)\n%s", $this->linkedUrl ?? 'link', self::json($this->linkedJsonLd));
        }
        if (null !== $this->description) {
            $sections[] = ($this->isVideo() ? '## Caption / description' : '## Page description')."\n".$this->description;
        }
        if (null !== $this->transcript) {
            $sections[] = "## Transcript of the video (automatic captions, may have errors)\n".$this->transcript;
        }
        if (null !== $this->pageText) {
            // With structured data, the page text is only backup: keep it short.
            $text = null !== $this->jsonLd ? mb_substr($this->pageText, 0, self::MAX_PAGE_TEXT_WITH_JSON_LD) : $this->pageText;
            $sections[] = "## Page text\n".$text;
        }

        return implode("\n\n", $sections);
    }

    /** @param array<string, mixed> $data */
    private static function json(array $data): string
    {
        // Drop the bulky parts that never help: reviews, ratings, nested images.
        unset($data['review'], $data['aggregateRating'], $data['video'], $data['image'], $data['@context']);

        return (string) json_encode($data, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
    }
}
