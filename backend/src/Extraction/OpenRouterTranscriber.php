<?php

declare(strict_types=1);

namespace App\Extraction;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Whisper through OpenRouter's /audio/transcriptions: a few seconds per reel on the providers'
 * GPUs, against half a minute or more on our CPU, for a fraction of a cent.
 *
 * ffmpeg (needed for the stills anyway) first turns the sound into a small mono MP3, ~0.25 MB a
 * minute: well under the upload limits, and quick to send. Word timestamps let Transcript cut
 * the answer into short timed lines.
 */
final readonly class OpenRouterTranscriber implements Transcriber
{
    private const string URL = 'https://openrouter.ai/api/v1/audio/transcriptions';
    /** Rate limits and provider hiccups: worth one more try. */
    private const array RETRY_STATUSES = [408, 429, 500, 502, 503, 504, 524, 529];

    public function __construct(
        #[Autowire(service: 'openrouter.http_client')]
        private HttpClientInterface $httpClient,
        #[Autowire('%env(OPENROUTER_API_KEY)%')]
        private string $apiKey,
        #[Autowire('%env(OPENROUTER_TRANSCRIPTION_MODEL)%')]
        private string $model = 'openai/whisper-large-v3',
        private int $retryDelayMs = 2000,
    ) {
    }

    public function transcribe(string $videoFile, ?string $language = null): ?Transcript
    {
        if ('' === trim($this->apiKey)) {
            throw new MediaFetchException('OPENROUTER_API_KEY is not set.');
        }
        if (!self::hasAudio($videoFile)) {
            return null;
        }

        $audio = self::mp3($videoFile);
        $body = [
            'model' => $this->model,
            'input_audio' => ['data' => base64_encode($audio), 'format' => 'mp3'],
            'response_format' => 'verbose_json',
            'timestamp_granularities' => ['segment', 'word'],
        ] + (null !== $language ? ['language' => $language] : []);

        for ($attempt = 1;; ++$attempt) {
            try {
                $response = $this->httpClient->request('POST', self::URL, [
                    'auth_bearer' => $this->apiKey,
                    'json' => $body,
                    'timeout' => 90,
                ]);
                $status = $response->getStatusCode();
                $data = $response->toArray(false);
            } catch (ExceptionInterface $e) {
                if (1 === $attempt) {
                    usleep($this->retryDelayMs * 1000);
                    continue;
                }
                throw new MediaFetchException('Transcription request failed: '.$e->getMessage(), previous: $e);
            }

            if (200 === $status) {
                $usage = \is_array($data['usage'] ?? null) ? $data['usage'] : [];

                return Transcript::fromVerboseJson($data, is_numeric($usage['cost'] ?? null) ? (float) $usage['cost'] : null);
            }
            if (1 === $attempt && \in_array($status, self::RETRY_STATUSES, true)) {
                usleep($this->retryDelayMs * 1000);
                continue;
            }
            $error = \is_array($data['error'] ?? null) && \is_string($data['error']['message'] ?? null) ? $data['error']['message'] : 'no message';
            throw new MediaFetchException(\sprintf('Transcription failed (HTTP %d): %s', $status, mb_substr($error, 0, 300)));
        }
    }

    /** @throws MediaFetchException */
    private static function mp3(string $videoFile): string
    {
        $file = sys_get_temp_dir().'/tabooki-audio-'.bin2hex(random_bytes(6)).'.mp3';
        try {
            self::run(['ffmpeg', '-nostdin', '-hide_banner', '-loglevel', 'error', '-i', $videoFile, '-vn', '-ac', '1', '-ar', '16000', '-c:a', 'libmp3lame', '-b:a', '32k', $file]);

            return (string) file_get_contents($file);
        } finally {
            @unlink($file);
        }
    }

    /** @throws MediaFetchException */
    private static function hasAudio(string $videoFile): bool
    {
        return '' !== trim(self::run(['ffprobe', '-v', 'error', '-select_streams', 'a', '-show_entries', 'stream=index', '-of', 'csv=p=0', $videoFile]));
    }

    /**
     * @param list<string> $command
     *
     * @throws MediaFetchException
     */
    private static function run(array $command): string
    {
        $process = new Process($command, timeout: 120);
        try {
            $process->run();
        } catch (ProcessTimedOutException $e) {
            throw new MediaFetchException($command[0].' timed out.', previous: $e);
        }
        if (!$process->isSuccessful()) {
            throw new MediaFetchException($command[0].' failed: '.mb_substr(trim($process->getErrorOutput()), -500));
        }

        return $process->getOutput();
    }
}
