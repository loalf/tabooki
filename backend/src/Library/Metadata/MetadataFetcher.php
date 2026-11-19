<?php

declare(strict_types=1);

namespace App\Library\Metadata;

use App\Enum\SourcePlatform;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Fetches a link's preview: oEmbed where the platform offers it without an API key
 * (YouTube, TikTok), the page's own meta tags otherwise.
 */
final readonly class MetadataFetcher
{
    private const int MAX_HTML_BYTES = 3 * 1024 * 1024;
    private const int MAX_IMAGE_BYTES = 8 * 1024 * 1024;
    private const array IMAGE_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', 'image/avif' => 'avif'];
    // A browser-like user agent: plenty of recipe sites refuse obvious bots.
    /** Titles of login walls and error pages: worse than no title at all. */
    private const string GENERIC_TITLE = '/^(?:instagram|login\s*•\s*instagram|tiktok(?:\s*-.*)?|youtube|facebook|log in|sign in|just a moment\.*|access denied|attention required.*)$/i';
    private const string USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36 Tabooki/1.0';

    public function __construct(
        #[Autowire(service: 'link_preview.http_client')]
        private HttpClientInterface $httpClient,
        private HtmlMetadataParser $parser,
        private LoggerInterface $logger,
    ) {
    }

    public function fetch(string $url, SourcePlatform $platform): LinkMetadata
    {
        $metadata = $this->fetchFromPlatform($url, $platform);
        if (null !== $metadata->title && 1 === preg_match(self::GENERIC_TITLE, $metadata->title)) {
            return new LinkMetadata(null, $metadata->description, $metadata->author, $metadata->siteName, $metadata->imageUrl, $metadata->recipeJsonLd, $metadata->fetchedVia, $metadata->pageText);
        }

        return $metadata;
    }

    private function fetchFromPlatform(string $url, SourcePlatform $platform): LinkMetadata
    {
        $oEmbedEndpoint = match ($platform) {
            SourcePlatform::YouTube => 'https://www.youtube.com/oembed',
            SourcePlatform::TikTok => 'https://www.tiktok.com/oembed',
            default => null,
        };

        if (null !== $oEmbedEndpoint) {
            $metadata = $this->fetchOEmbed($oEmbedEndpoint, $url);
            if (null !== $metadata) {
                return $metadata;
            }
        }

        return $this->fetchHtml($url);
    }

    /** @return array{contents: string, extension: string}|null */
    public function downloadImage(string $url): ?array
    {
        try {
            $response = $this->request($url, ['Accept' => 'image/avif,image/webp,image/*;q=0.8']);
            $type = strtolower(trim(explode(';', $response->getHeaders()['content-type'][0] ?? '')[0]));
            $extension = self::IMAGE_TYPES[$type] ?? null;
            if (null === $extension) {
                return null;
            }
            $contents = $this->readBody($response, self::MAX_IMAGE_BYTES);

            return null === $contents ? null : ['contents' => $contents, 'extension' => $extension];
        } catch (ExceptionInterface $e) {
            $this->logger->info('Thumbnail download failed for {url}: {error}', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }
    }

    private function fetchOEmbed(string $endpoint, string $url): ?LinkMetadata
    {
        try {
            $data = $this->httpClient->request('GET', $endpoint, [
                'query' => ['url' => $url, 'format' => 'json'],
                'headers' => ['User-Agent' => self::USER_AGENT, 'Accept' => 'application/json'],
                'timeout' => 10,
            ])->toArray();
        } catch (ExceptionInterface $e) {
            $this->logger->info('oEmbed failed for {url}: {error}', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }

        $string = static fn (string $key): ?string => \is_string($data[$key] ?? null) && '' !== trim($data[$key]) ? trim($data[$key]) : null;
        $title = $string('title');

        return new LinkMetadata(
            // TikTok's title is the whole caption: keep it as the description too.
            title: null === $title ? null : mb_substr($title, 0, 200),
            description: mb_strlen($title ?? '') > 200 ? $title : null,
            author: $string('author_name'),
            siteName: $string('provider_name'),
            imageUrl: $string('thumbnail_url'),
            fetchedVia: 'oembed',
        );
    }

    private function fetchHtml(string $url): LinkMetadata
    {
        try {
            $response = $this->request($url, [
                'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.5',
                'Accept-Language' => 'es,en;q=0.8',
            ]);
            $contentType = $response->getHeaders()['content-type'][0] ?? '';
            if (!str_contains($contentType, 'html')) {
                return new LinkMetadata(fetchedVia: 'none');
            }
            $html = $this->readBody($response, self::MAX_HTML_BYTES, truncate: true) ?? '';
            preg_match('/charset=([\w-]+)/i', $contentType, $charset);
            $finalUrl = $response->getInfo('url');

            return $this->parser->parse($html, \is_string($finalUrl) ? $finalUrl : $url, $charset[1] ?? null);
        } catch (ExceptionInterface $e) {
            $this->logger->info('Fetching {url} failed: {error}', ['url' => $url, 'error' => $e->getMessage()]);

            return new LinkMetadata(fetchedVia: 'none');
        }
    }

    /** @param array<string, string> $headers */
    private function request(string $url, array $headers): ResponseInterface
    {
        $response = $this->httpClient->request('GET', $url, [
            'headers' => ['User-Agent' => self::USER_AGENT, ...$headers],
            'timeout' => 10,
            'max_duration' => 20,
            'max_redirects' => 5,
        ]);
        $response->getHeaders(); // throws on 4xx/5xx

        return $response;
    }

    /** Reads at most $maxBytes; past that, returns what was read ($truncate) or gives up. */
    private function readBody(ResponseInterface $response, int $maxBytes, bool $truncate = false): ?string
    {
        $body = '';
        foreach ($this->httpClient->stream($response) as $chunk) {
            $body .= $chunk->getContent();
            if (\strlen($body) > $maxBytes) {
                $response->cancel();

                return $truncate ? substr($body, 0, $maxBytes) : null;
            }
        }

        return $body;
    }
}
