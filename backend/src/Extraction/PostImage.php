<?php

declare(strict_types=1);

namespace App\Extraction;

/** One picture of a photo or carousel post, as the model receives it. */
final readonly class PostImage
{
    /** Formats the models read; the others (AVIF) are skipped. */
    public const array FORMATS = ['jpg' => 'jpeg', 'jpeg' => 'jpeg', 'png' => 'png', 'webp' => 'webp', 'gif' => 'gif'];

    /** @param 'jpeg'|'png'|'webp'|'gif' $format */
    public function __construct(
        public string $bytes,
        public string $format,
    ) {
    }

    /** From a downloaded file's extension; null when the model can't read that format. */
    public static function fromDownload(string $bytes, string $extension): ?self
    {
        $format = self::FORMATS[strtolower($extension)] ?? null;

        return null === $format ? null : new self($bytes, $format);
    }
}
