<?php

declare(strict_types=1);

namespace App\Extraction;

use App\Entity\Source;
use App\Enum\SourcePlatform;
use App\Library\Metadata\MetadataFetcher;
use App\Library\UrlNormalizer;
use App\Media\ThumbnailCache;
use Psr\Log\LoggerInterface;

/**
 * Gathers the cheapest good material for a link before any LLM call (docs/PLAN.md §4.1) and
 * stores it on the source, so recipes can be re-extracted later without fetching again.
 */
final readonly class EvidenceCollector
{
    /** Hosts in a video description that never hold the recipe itself. */
    private const string NOT_RECIPE_LINK = '/(youtube\.com|youtu\.be|instagram\.com|tiktok\.com|facebook\.com|twitter\.com|x\.com|pinterest\.|amzn\.|amazon\.|bit\.ly|linktr\.ee|patreon\.com|spotify\.com|threads\.net)/i';

    public function __construct(
        private MediaFetcher $media,
        private MetadataFetcher $metadata,
        private ThumbnailCache $thumbnails,
        private UrlNormalizer $urls,
        private LoggerInterface $logger,
    ) {
    }

    public function collect(Source $source): Evidence
    {
        $evidence = $source->getEvidence();

        if (SourcePlatform::Web === $source->getPlatform()) {
            // Normally stored when the preview was fetched; fetch now if that failed or predates M2.
            if (!isset($evidence['json_ld']) && !isset($evidence['page_text'])) {
                $page = $this->metadata->fetch($source->getUrl(), SourcePlatform::Web);
                if (null !== $page->recipeJsonLd) {
                    $source->addEvidence('json_ld', $page->recipeJsonLd);
                }
                if (null !== $page->pageText) {
                    $source->addEvidence('page_text', $page->pageText);
                }
            }

            return Evidence::fromSource($source);
        }

        // Photo and carousel posts are asked again: their image links expire within days, and
        // ones saved before images were read don't have them.
        if (!isset($evidence['media']) || self::isPhotoPost($evidence['media'])) {
            $this->collectMedia($source);
        }

        return Evidence::fromSource($source);
    }

    private static function isPhotoPost(mixed $media): bool
    {
        return \is_array($media) && true !== ($media['video'] ?? false) && !\is_int($media['duration'] ?? null);
    }

    private function collectMedia(Source $source): void
    {
        try {
            $info = $this->media->info($source->getUrl());
        } catch (MediaFetchException $e) {
            // Typical for Instagram ("login required"): the OpenGraph caption, if any, is all we have.
            $this->logger->info('yt-dlp failed for {url}: {error}', ['url' => $source->getUrl(), 'error' => $e->getMessage()]);
            $source->addEvidence('media_error', $e->getMessage());

            return;
        }

        $source->addEvidence('media', $info->toEvidence());
        if (null !== $info->transcript) {
            $source->addEvidence('transcript', $info->transcript);
        }
        if (null === $source->getTitle() && null !== $info->title && 1 !== preg_match('/^(video|reel|post) by /i', $info->title)) {
            $source->setTitle(mb_substr($info->title, 0, 200));
        }
        if (null === $source->getAuthor() && null !== $info->uploader) {
            $source->setAuthor(mb_substr($info->uploader, 0, 200));
        }
        if (null === $source->getThumbnailPath() && null !== $info->thumbnailUrl) {
            $this->thumbnails->cache($source, $info->thumbnailUrl);
        }

        // "Full recipe on my blog: https://...": follow the first such link for its schema.org Recipe.
        foreach ($this->recipeLinks($info->description ?? '') as $url) {
            $page = $this->metadata->fetch($url, SourcePlatform::Web);
            if (null !== $page->recipeJsonLd) {
                $source->addEvidence('linked', ['url' => $url, 'json_ld' => $page->recipeJsonLd]);
                break;
            }
        }
    }

    /** @return list<string> at most two candidate links from a description */
    private function recipeLinks(string $description): array
    {
        preg_match_all('#https?://[^\s<>"\')\]]+#i', $description, $matches);
        $links = [];
        foreach (array_unique($matches[0]) as $url) {
            $url = rtrim($url, '.,;:!?');
            if (1 === preg_match(self::NOT_RECIPE_LINK, $url)) {
                continue;
            }
            try {
                if (SourcePlatform::Web === $this->urls->detectPlatform($url)) {
                    $links[] = $url;
                }
            } catch (\InvalidArgumentException) {
            }
            if (\count($links) >= 2) {
                break;
            }
        }

        return $links;
    }
}
