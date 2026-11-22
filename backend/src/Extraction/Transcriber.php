<?php

declare(strict_types=1);

namespace App\Extraction;

/** Speech to text for downloaded videos (Whisper, through OpenRouter). */
interface Transcriber
{
    /**
     * null when the video has no sound track; an empty transcript when nothing is said (music only).
     *
     * @param string|null $language "es", "en"; null to detect it
     *
     * @throws MediaFetchException
     */
    public function transcribe(string $videoFile, ?string $language = null): ?Transcript;
}
