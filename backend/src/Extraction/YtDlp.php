<?php

declare(strict_types=1);

namespace App\Extraction;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * yt-dlp (installed in the Docker image) for YouTube, Instagram and TikTok.
 *
 * Instagram sometimes answers "login required": yt-dlp can then use a cookies.txt exported
 * from a logged-in browser (YTDLP_COOKIES_FILE). That goes against Instagram's terms, so it's
 * opt-in; without it those posts fall back to "paste the caption".
 */
final readonly class YtDlp implements MediaFetcher
{
    private const int MAX_TRANSCRIPT_CHARS = 60000;

    public function __construct(
        #[Autowire(service: 'link_preview.http_client')]
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        #[Autowire('%env(default::YTDLP_COOKIES_FILE)%')]
        private ?string $cookiesFile = null,
        private string $binary = 'yt-dlp',
    ) {
    }

    public function info(string $url): MediaInfo
    {
        // --ignore-no-formats-error: photo and carousel posts have no video but do have a caption.
        $json = $this->run(['--dump-single-json', '--skip-download', '--no-playlist', '--ignore-no-formats-error', $url], timeout: 90);

        try {
            $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new MediaFetchException('yt-dlp returned invalid JSON.', previous: $e);
        }
        if (!\is_array($data)) {
            throw new MediaFetchException('yt-dlp returned no data.');
        }

        $string = static fn (string $key): ?string => \is_string($data[$key] ?? null) && '' !== trim($data[$key]) ? trim($data[$key]) : null;
        $language = $string('language');

        return new MediaInfo(
            title: $string('title'),
            description: $string('description'),
            uploader: $string('uploader') ?? $string('channel'),
            thumbnailUrl: $string('thumbnail'),
            durationSeconds: is_numeric($data['duration'] ?? null) ? (int) $data['duration'] : null,
            language: $language,
            transcript: $this->transcript($data, $language),
        );
    }

    public function downloadVideo(string $url, int $maxBytes): ?string
    {
        $directory = sys_get_temp_dir().'/tabooki-video-'.bin2hex(random_bytes(6));
        mkdir($directory, 0700, true);
        $megabytes = intdiv($maxBytes, 1024 * 1024);

        // One H.264 mp4 file, no ffmpeg merge: with audio when there is one (Instagram, TikTok), else
        // the picture alone (YouTube mostly serves separate streams now). Nova only reads the
        // picture (what is said comes from the transcript) and rejects AV1/VP9.
        $this->run([
            '--no-playlist',
            '-f', \sprintf(
                'b[ext=mp4][vcodec^=avc1][filesize<%1$dM]/b[ext=mp4][vcodec^=avc1][filesize_approx<%1$dM]/b[ext=mp4][vcodec^=avc1][height<=720]'
                .'/bv[ext=mp4][vcodec^=avc1][height<=720][filesize<%1$dM]/bv[ext=mp4][vcodec^=avc1][height<=720][filesize_approx<%1$dM]'
                .'/bv[ext=mp4][vcodec^=avc1][height<=480]/b[ext=mp4][height<=720]/w[ext=mp4]',
                $megabytes,
            ),
            '--max-filesize', (string) $maxBytes,
            '-o', $directory.'/video.%(ext)s',
            $url,
        ], timeout: 240);

        $files = glob($directory.'/video.*') ?: [];
        $file = $files[0] ?? null;
        if (null === $file || filesize($file) > $maxBytes || 0 === filesize($file)) {
            array_map(unlink(...), $files);
            @rmdir($directory);

            return null;
        }

        return $file;
    }

    public function downloadForAnalysis(string $url, int $maxBytes): ?string
    {
        $directory = sys_get_temp_dir().'/tabooki-video-'.bin2hex(random_bytes(6));
        mkdir($directory, 0700, true);

        // Unlike downloadVideo, the codec doesn't matter (ffmpeg reads them all), so the small VP9
        // streams Instagram offers next to H.264 files of unknown size are fine. Picture and sound
        // come as separate streams more often than not: ffmpeg merges them. 720p keeps on-screen
        // text legible; ~1.2 Mbit/s is plenty for stills without fetching the biggest stream.
        $output = $this->run([
            '--no-playlist',
            '-f', 'bv*+ba/b',
            '-S', 'res:720,vbr~1200',
            '--merge-output-format', 'mkv',
            '--max-filesize', (string) $maxBytes,
            '--print', 'after_move:filepath', '--no-simulate',
            '-o', $directory.'/video.%(ext)s',
            $url,
        ], timeout: 300);

        $file = trim($output);
        if ('' === $file || !str_starts_with($file, $directory.'/') || !is_file($file) || 0 === filesize($file) || filesize($file) > $maxBytes) {
            array_map(unlink(...), glob($directory.'/*') ?: []);
            @rmdir($directory);

            return null;
        }

        return $file;
    }

    /** @param list<string> $arguments */
    private function run(array $arguments, int $timeout): string
    {
        $command = [$this->binary, '--no-warnings', '--no-progress', '--socket-timeout', '20'];
        if (null !== $this->cookiesFile && '' !== $this->cookiesFile && is_readable($this->cookiesFile)) {
            $command = [...$command, '--cookies', $this->cookiesFile];
        }
        $process = new Process([...$command, ...$arguments], timeout: $timeout);

        try {
            $process->run();
        } catch (ProcessTimedOutException $e) {
            throw new MediaFetchException('yt-dlp timed out.', previous: $e);
        }

        if (!$process->isSuccessful()) {
            $error = trim($process->getErrorOutput()) ?: 'exit code '.$process->getExitCode();
            throw new MediaFetchException(mb_substr((string) preg_replace('/^ERROR:\s*/m', '', $error), 0, 500));
        }

        return $process->getOutput();
    }

    /**
     * Subtitles in the video's own language: hand-made ones first, then automatic captions
     * (YouTube marks the untranslated track "-orig").
     *
     * @param array<mixed> $data
     */
    private function transcript(array $data, ?string $language): ?string
    {
        $tracks = [];
        foreach (['subtitles', 'automatic_captions'] as $kind) {
            $byLanguage = \is_array($data[$kind] ?? null) ? $data[$kind] : [];
            $keys = array_map(strval(...), array_keys($byLanguage));
            $preferred = array_merge(
                array_filter($keys, static fn (string $key): bool => str_ends_with($key, '-orig')),
                null !== $language ? array_filter($keys, static fn (string $key): bool => $key === $language || str_starts_with($key, $language.'-')) : [],
                'subtitles' === $kind ? array_filter($keys, static fn (string $key): bool => 'live_chat' !== $key) : [],
            );
            foreach (array_unique($preferred) as $key) {
                if (\is_array($byLanguage[$key])) {
                    $tracks[] = $byLanguage[$key];
                }
            }
        }

        foreach ($tracks as $formats) {
            foreach ($formats as $format) {
                if (\is_array($format) && 'vtt' === ($format['ext'] ?? null) && \is_string($format['url'] ?? null)) {
                    $text = $this->downloadVtt($format['url']);
                    if (null !== $text) {
                        return $text;
                    }
                }
            }
        }

        return null;
    }

    private function downloadVtt(string $url): ?string
    {
        try {
            $vtt = $this->httpClient->request('GET', $url, ['timeout' => 20])->getContent();
        } catch (ExceptionInterface $e) {
            $this->logger->info('Subtitle download failed: {error}', ['error' => $e->getMessage()]);

            return null;
        }

        return self::vttToText($vtt);
    }

    /** WebVTT to plain text: no cue timings or tags, and without the rolling repeats of automatic captions. */
    public static function vttToText(string $vtt): ?string
    {
        $lines = [];
        foreach (preg_split('/\R/u', $vtt) ?: [] as $line) {
            $line = trim(html_entity_decode(strip_tags((string) preg_replace('/<[\d:.]+>/', '', $line))));
            if ('' === $line || str_starts_with($line, 'WEBVTT') || str_contains($line, '-->') || 1 === preg_match('/^(Kind|Language|NOTE|STYLE)\b/', $line) || ctype_digit($line)) {
                continue;
            }
            if ($line !== end($lines)) {
                $lines[] = $line;
            }
        }
        $text = implode(' ', $lines);

        return '' === $text ? null : mb_substr($text, 0, self::MAX_TRANSCRIPT_CHARS);
    }
}
