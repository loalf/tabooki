<?php

declare(strict_types=1);

namespace App\Extraction;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Stills from a video for models that read images but not video (Claude), with ffmpeg.
 *
 * Reels cut fast and show ingredient lists for a second or two, so a still every few seconds
 * misses them: take up to 2 per second, drop the ones that look like the previous one
 * (mpdecimate), then thin what's left evenly. Shots where a lot happens keep more stills.
 * The long side is scaled down to 768 px (never up): on-screen text stays legible at ~450 tokens a still.
 */
final readonly class FrameSampler
{
    public const int MAX_SIDE = 768;

    /**
     * @param int|null $durationSeconds to pick the sampling rate; null if unknown
     *
     * @return list<VideoFrame> in order
     *
     * @throws MediaFetchException
     */
    public function sample(string $videoFile, ?int $durationSeconds, int $maxFrames = 40): array
    {
        $directory = sys_get_temp_dir().'/tabooki-frames-'.bin2hex(random_bytes(6));
        mkdir($directory, 0700, true);
        try {
            $process = new Process([
                'ffmpeg', '-nostdin', '-hide_banner', '-i', $videoFile, '-an',
                '-vf', \sprintf('fps=%s,mpdecimate,scale=w=min(%2$d\\,iw):h=min(%2$d\\,ih):force_original_aspect_ratio=decrease,showinfo', self::rate($durationSeconds, $maxFrames), self::MAX_SIDE),
                '-fps_mode', 'vfr', '-q:v', '4',
                $directory.'/%05d.jpg',
            ], timeout: 180);
            try {
                $process->run();
            } catch (ProcessTimedOutException $e) {
                throw new MediaFetchException('ffmpeg timed out.', previous: $e);
            }
            if (!$process->isSuccessful()) {
                throw new MediaFetchException('ffmpeg failed: '.mb_substr(trim($process->getErrorOutput()), -500));
            }

            $files = glob($directory.'/*.jpg') ?: [];
            sort($files);
            $times = self::showinfoTimes($process->getErrorOutput());
            $frames = [];
            foreach (self::thin(array_keys($files), $maxFrames) as $i) {
                $frames[] = new VideoFrame($times[$i] ?? (float) $i, (string) file_get_contents($files[$i]));
            }

            return $frames;
        } finally {
            array_map(unlink(...), glob($directory.'/*') ?: []);
            @rmdir($directory);
        }
    }

    /** Stills per second to decode: enough candidates for ~3 per kept still, between 0.5 and 2. */
    public static function rate(?int $durationSeconds, int $maxFrames): string
    {
        if (null === $durationSeconds || $durationSeconds <= 0) {
            return '1';
        }

        return rtrim(rtrim(\sprintf('%.2F', max(0.5, min(2.0, 3 * $maxFrames / $durationSeconds))), '0'), '.');
    }

    /**
     * Timestamps (seconds) of the stills ffmpeg wrote, in order, from showinfo's log lines:
     * "[Parsed_showinfo_3 @ 0x…] n:   4 pts:      9 pts_time:4.5     duration: …".
     *
     * @return list<float>
     */
    public static function showinfoTimes(string $log): array
    {
        preg_match_all('/Parsed_showinfo.*?\bn:\s*\d+\s+pts:\s*-?\d+\s+pts_time:(-?[\d.]+)/', $log, $matches);

        return array_map(floatval(...), $matches[1]);
    }

    /**
     * At most $max items, spread evenly, always keeping the first and the last.
     *
     * @template T
     *
     * @param list<T> $items
     *
     * @return list<T>
     */
    public static function thin(array $items, int $max): array
    {
        $count = \count($items);
        if ($count <= $max) {
            return $items;
        }
        if ($max <= 1) {
            return \array_slice($items, 0, max(0, $max));
        }
        $kept = [];
        for ($i = 0; $i < $max; ++$i) {
            $kept[] = $items[(int) round($i * ($count - 1) / ($max - 1))];
        }

        return $kept;
    }
}
