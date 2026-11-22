<?php

declare(strict_types=1);

namespace App\Llm;

/** One piece of a user message: text or an image (raw bytes, not base64). */
final readonly class Content
{
    private function __construct(
        public string $type,
        public string $data,
        public ?string $format = null,
    ) {
    }

    public static function text(string $text): self
    {
        return new self('text', $text);
    }

    /** @param 'jpeg'|'png'|'webp'|'gif' $format */
    public static function image(string $bytes, string $format): self
    {
        return new self('image', $bytes, $format);
    }
}
