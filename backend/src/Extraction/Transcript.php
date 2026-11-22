<?php

declare(strict_types=1);

namespace App\Extraction;

/** What is said in a video, as speech recognition heard it: timed segments, in order. */
final readonly class Transcript
{
    private const int MAX_CHARS = 60000;
    /** Below this, Whisper is guessing (its own fallback threshold is -1). */
    private const float MIN_LOGPROB = -1.0;
    private const float MAX_COMPRESSION_RATIO = 2.4;
    private const float PAUSE_SECONDS = 1.5;
    private const float LINE_SECONDS = 12.0;

    /** @param list<array{from: float, to: float, text: string}> $segments seconds from the start */
    public function __construct(
        public array $segments,
        public ?string $language = null,
        /** what transcribing cost, in USD, when it was paid for */
        public ?float $cost = null,
    ) {
    }

    /**
     * An OpenAI-style verbose_json answer (what OpenRouter's /audio/transcriptions returns):
     * {"language": "es", "segments": [{"start": 0, "end": 28.7, "text": "…", "avg_logprob": -0.03}],
     * "words": [{"word": " Este", "start": 0, "end": 0.22}, …]}, times in seconds.
     *
     * Segments can run for half a minute, too long to line up with stills, so lines are rebuilt
     * from the words when there are any: one per sentence, or per pause, or every LINE_SECONDS.
     *
     * Whisper makes up words over music ("Outro Music", "♪", "Thanks for watching"). Those
     * segments come with a low avg_logprob or words that take no time at all: they are left out.
     *
     * @param array<mixed> $data
     */
    public static function fromVerboseJson(array $data, ?float $cost = null): self
    {
        $dropped = [];
        $segments = [];
        foreach (self::items($data['segments'] ?? null) as $segment) {
            $start = self::seconds($segment['start'] ?? null);
            $end = self::seconds($segment['end'] ?? null);
            $text = \is_string($segment['text'] ?? null) ? trim($segment['text']) : '';
            $words = array_filter(self::items($data['words'] ?? null), static fn (array $w): bool => self::seconds($w['start'] ?? null) >= $start - 0.01 && self::seconds($w['start'] ?? null) <= $end);
            $instant = \count(array_filter($words, static fn (array $w): bool => self::seconds($w['end'] ?? null) - self::seconds($w['start'] ?? null) < 0.01));
            $madeUp = 1 !== preg_match('/[\p{L}\p{N}]/u', $text)
                || (is_numeric($segment['avg_logprob'] ?? null) && $segment['avg_logprob'] < self::MIN_LOGPROB)
                || self::repeats($text)
                || ([] !== $words && $instant >= \count($words) / 2);
            if ($madeUp) {
                $dropped[] = [$start, $end];
            } else {
                $segments[] = ['from' => $start, 'to' => $end, 'text' => $text];
            }
        }

        $words = array_values(array_filter(
            self::items($data['words'] ?? null),
            static function (array $w) use ($dropped): bool {
                $at = self::seconds($w['start'] ?? null);
                foreach ($dropped as [$from, $to]) {
                    if ($at >= $from - 0.01 && $at <= $to) {
                        return false;
                    }
                }

                return \is_string($w['word'] ?? null) && '' !== trim($w['word']);
            },
        ));
        if ([] !== $words) {
            $segments = self::lines($words);
        }
        $language = $data['language'] ?? null;

        return new self(
            $segments,
            \is_string($language) && 1 === preg_match('/^[a-z]{2}$/', $language) ? $language : null,
            $cost,
        );
    }

    /**
     * @param list<array<mixed>> $words in order
     *
     * @return list<array{from: float, to: float, text: string}>
     */
    private static function lines(array $words): array
    {
        $lines = [];
        $line = null;
        foreach ($words as $word) {
            $start = self::seconds($word['start'] ?? null);
            $end = max($start, self::seconds($word['end'] ?? null));
            $text = \is_string($word['word'] ?? null) ? $word['word'] : '';
            if (null !== $line && (
                $start - $line['to'] > self::PAUSE_SECONDS
                || $start - $line['from'] > self::LINE_SECONDS
                || (1 === preg_match('/[.!?…]$/u', $line['text']) && $start - $line['from'] > 2)
            )) {
                $lines[] = $line;
                $line = null;
            }
            $line = null === $line
                ? ['from' => $start, 'to' => $end, 'text' => trim($text)]
                : ['from' => $line['from'], 'to' => $end, 'text' => $line['text'].(1 === preg_match('/^\s/u', $text) ? '' : ' ').rtrim($text)];
            $line['text'] = trim((string) preg_replace('/\s+/u', ' ', $line['text']));
        }
        if (null !== $line) {
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * Whisper stuck in a loop ("la noche se me, la noche se me, …"), often on a song: text that
     * compresses unusually well. Whisper's own threshold for its gzip compression ratio is 2.4.
     */
    private static function repeats(string $text): bool
    {
        $compressed = \strlen((string) gzcompress($text));

        return \strlen($text) > 60 && $compressed > 0 && \strlen($text) / $compressed > self::MAX_COMPRESSION_RATIO;
    }

    /** @return list<array<mixed>> */
    private static function items(mixed $value): array
    {
        return \is_array($value) ? array_values(array_filter($value, \is_array(...))) : [];
    }

    private static function seconds(mixed $value): float
    {
        return is_numeric($value) ? max(0.0, (float) $value) : 0.0;
    }

    public function isEmpty(): bool
    {
        return [] === $this->segments;
    }

    public function text(): string
    {
        return mb_substr(implode(' ', array_column($this->segments, 'text')), 0, self::MAX_CHARS);
    }

    /** One line per segment, "[0:12] …", to line up what is said with the video's stills. */
    public function timed(): string
    {
        $lines = array_map(static fn (array $s): string => \sprintf('[%s] %s', self::clock($s['from']), $s['text']), $this->segments);

        return mb_substr(implode("\n", $lines), 0, self::MAX_CHARS);
    }

    public static function clock(float $seconds): string
    {
        $seconds = (int) floor($seconds);

        return \sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }
}
