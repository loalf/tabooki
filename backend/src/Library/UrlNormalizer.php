<?php

declare(strict_types=1);

namespace App\Library;

use App\Enum\SourcePlatform;

/**
 * Turns a pasted or shared link into a canonical form, so the same recipe saved twice
 * (with different tracking parameters, as a Short vs a regular YouTube link, ...) is
 * recognised as a duplicate. The original URL is kept for fetching and opening.
 */
final class UrlNormalizer
{
    /** Query parameters that only track where a link was shared from. */
    private const array TRACKING_PARAMS = [
        'fbclid', 'gclid', 'dclid', 'msclkid', 'mc_cid', 'mc_eid', 'igsh', 'igshid', 'ref', 'ref_src',
        'share', 'si', 'feature', 'is_from_webapp', 'sender_device', 'sender_web_id', '_t', '_r', 'ssr',
        'amp', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id',
    ];

    /** @throws \InvalidArgumentException when the text is not an http(s) URL */
    public function normalize(string $url): string
    {
        $parts = $this->parse($url);
        $host = $parts['host'];
        $path = $parts['path'];
        parse_str($parts['query'], $query);

        switch (self::platformForHost($host)) {
            case SourcePlatform::YouTube:
                $videoId = $this->youTubeVideoId($host, $path, $query);
                if (null !== $videoId) {
                    return 'https://youtube.com/watch?v='.$videoId;
                }
                break;
            case SourcePlatform::Instagram:
                // /p/, /reel/, /reels/ and /tv/ (optionally after a username) share one shortcode space.
                if (1 === preg_match('#^/(?:[^/]+/)?(?:p|reels?|tv)/([A-Za-z0-9_-]+)#', $path, $m)) {
                    return 'https://instagram.com/p/'.$m[1];
                }
                $query = [];
                break;
            case SourcePlatform::TikTok:
                $query = [];
                break;
            case SourcePlatform::Web:
                break;
        }

        $query = array_filter(
            $query,
            static fn (int|string $key): bool => !\in_array(strtolower((string) $key), self::TRACKING_PARAMS, true)
                && !str_starts_with(strtolower((string) $key), 'utm_'),
            \ARRAY_FILTER_USE_KEY,
        );
        ksort($query);

        $path = '/' === $path ? '' : rtrim($path, '/');
        $queryString = [] === $query ? '' : '?'.http_build_query($query, '', '&', \PHP_QUERY_RFC3986);

        return 'https://'.$host.$path.$queryString;
    }

    public function detectPlatform(string $url): SourcePlatform
    {
        return self::platformForHost($this->parse($url)['host']);
    }

    /**
     * Adds a scheme when it's missing ("example.com/recipe") and rejects anything that isn't a web link.
     *
     * @throws \InvalidArgumentException
     */
    public function withScheme(string $url): string
    {
        $url = trim($url);
        if (1 !== preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
            $url = 'https://'.$url;
        }
        $this->parse($url);

        return $url;
    }

    /** @return array{host: string, path: string, query: string} */
    private function parse(string $url): array
    {
        $url = trim($url);
        if (1 !== preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
            $url = 'https://'.$url;
        }
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower(rtrim($parts['host'] ?? '', '.'));

        if (false === $parts || !\in_array($scheme, ['http', 'https'], true) || !str_contains($host, '.')) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a web link.', $url));
        }

        return [
            'host' => (string) preg_replace('/^(?:www|m|mobile)\./', '', $host),
            'path' => '' === ($parts['path'] ?? '') ? '/' : $parts['path'],
            'query' => $parts['query'] ?? '',
        ];
    }

    private static function platformForHost(string $host): SourcePlatform
    {
        $matches = static fn (string $domain): bool => $host === $domain || str_ends_with($host, '.'.$domain);

        return match (true) {
            $matches('youtube.com'), $matches('youtu.be'), $matches('youtube-nocookie.com') => SourcePlatform::YouTube,
            $matches('instagram.com'), $matches('instagr.am') => SourcePlatform::Instagram,
            $matches('tiktok.com') => SourcePlatform::TikTok,
            default => SourcePlatform::Web,
        };
    }

    /** @param array<mixed> $query */
    private function youTubeVideoId(string $host, string $path, array $query): ?string
    {
        $id = null;
        if (str_ends_with($host, 'youtu.be')) {
            $id = explode('/', ltrim($path, '/'))[0];
        } elseif (\is_string($query['v'] ?? null)) {
            $id = $query['v'];
        } elseif (1 === preg_match('#^/(?:shorts|live|embed|v)/([^/?]+)#', $path, $m)) {
            $id = $m[1];
        }

        return null !== $id && 1 === preg_match('/^[A-Za-z0-9_-]{6,20}$/', $id) ? $id : null;
    }
}
