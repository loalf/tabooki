<?php

declare(strict_types=1);

namespace App\Library\Metadata;

/**
 * Reads a page's link-preview data: OpenGraph / Twitter card tags, a schema.org Recipe in
 * JSON-LD when there is one (most recipe blogs publish it), and <title> as a last resort.
 */
final class HtmlMetadataParser
{
    public function parse(string $html, string $pageUrl, ?string $charset = null): LinkMetadata
    {
        $document = \Dom\HTMLDocument::createFromString($html, \LIBXML_NOERROR | \Dom\HTML_NO_DEFAULT_NS, $charset);

        $meta = [];
        foreach ($document->querySelectorAll('meta[content]') as $element) {
            $key = strtolower($element->getAttribute('property') ?? $element->getAttribute('name') ?? '');
            $content = self::clean($element->getAttribute('content'));
            if ('' !== $key && null !== $content && !isset($meta[$key])) {
                $meta[$key] = $content;
            }
        }

        $recipe = $this->findRecipeJsonLd($document);

        $title = $meta['og:title'] ?? $meta['twitter:title'] ?? self::clean(self::string($recipe['name'] ?? null))
            ?? self::clean($document->querySelector('title')?->textContent);
        $image = $meta['og:image:secure_url'] ?? $meta['og:image'] ?? $meta['og:image:url'] ?? $meta['twitter:image']
            ?? $meta['twitter:image:src'] ?? self::jsonLdImage($recipe['image'] ?? null);
        $author = self::jsonLdName($recipe['author'] ?? null) ?? $meta['author'] ?? $meta['article:author'] ?? null;
        if (null !== $author && str_starts_with($author, 'http')) {
            $author = null; // article:author is often a profile URL
        }

        return new LinkMetadata(
            title: null === $title ? null : mb_substr($title, 0, 200),
            description: $meta['og:description'] ?? $meta['description'] ?? $meta['twitter:description'] ?? null,
            author: null === $author ? null : mb_substr($author, 0, 200),
            siteName: $meta['og:site_name'] ?? null,
            imageUrl: null === $image ? null : self::absoluteUrl($image, $pageUrl),
            recipeJsonLd: $recipe,
            pageText: HtmlText::fromDocument($document) ?: null,
        );
    }

    /** @return array<string, mixed>|null */
    private function findRecipeJsonLd(\Dom\HTMLDocument $document): ?array
    {
        foreach ($document->querySelectorAll('script[type="application/ld+json"]') as $script) {
            try {
                $data = json_decode((string) $script->textContent, true, 64, \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }
            $recipe = self::findRecipeNode($data);
            if (null !== $recipe) {
                return $recipe;
            }
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    private static function findRecipeNode(mixed $node, int $depth = 0): ?array
    {
        if (!\is_array($node) || $depth > 4) {
            return null;
        }
        $type = $node['@type'] ?? null;
        if ('Recipe' === $type || (\is_array($type) && \in_array('Recipe', $type, true))) {
            /** @var array<string, mixed> $recipe */
            $recipe = $node;

            return $recipe;
        }
        foreach (array_is_list($node) ? $node : (array) ($node['@graph'] ?? []) as $child) {
            $found = self::findRecipeNode($child, $depth + 1);
            if (null !== $found) {
                return $found;
            }
        }

        return null;
    }

    private static function jsonLdImage(mixed $image): ?string
    {
        if (\is_array($image)) {
            $image = array_is_list($image) ? ($image[0] ?? null) : ($image['url'] ?? null);
            if (\is_array($image)) {
                $image = $image['url'] ?? null;
            }
        }

        return self::clean(self::string($image));
    }

    private static function jsonLdName(mixed $author): ?string
    {
        if (\is_array($author)) {
            $author = array_is_list($author) ? ($author[0] ?? null) : $author;
            $author = \is_array($author) ? ($author['name'] ?? null) : $author;
        }

        return self::clean(self::string($author));
    }

    private static function string(mixed $value): ?string
    {
        return \is_string($value) ? $value : null;
    }

    private static function clean(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }
        $value = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($value, \ENT_QUOTES | \ENT_HTML5, 'UTF-8')));

        return '' === $value ? null : $value;
    }

    private static function absoluteUrl(string $url, string $base): ?string
    {
        if (str_starts_with($url, '//')) {
            return 'https:'.$url;
        }
        if (1 === preg_match('#^https?://#i', $url)) {
            return $url;
        }
        $baseParts = parse_url($base);
        if (false === $baseParts || !isset($baseParts['scheme'], $baseParts['host'])) {
            return null;
        }
        $origin = $baseParts['scheme'].'://'.$baseParts['host'].(isset($baseParts['port']) ? ':'.$baseParts['port'] : '');
        if (str_starts_with($url, '/')) {
            return $origin.$url;
        }
        $dir = preg_replace('#/[^/]*$#', '/', $baseParts['path'] ?? '/');

        return $origin.$dir.$url;
    }
}
