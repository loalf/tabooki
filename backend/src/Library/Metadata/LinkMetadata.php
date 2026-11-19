<?php

declare(strict_types=1);

namespace App\Library\Metadata;

/** What a link preview needs, plus raw material worth keeping for extraction. */
final readonly class LinkMetadata
{
    /** @param array<string, mixed>|null $recipeJsonLd schema.org Recipe node found on the page */
    public function __construct(
        public ?string $title = null,
        public ?string $description = null,
        public ?string $author = null,
        public ?string $siteName = null,
        public ?string $imageUrl = null,
        public ?array $recipeJsonLd = null,
        public string $fetchedVia = 'html',
        /** Readable text of the page (HTML pages only). */
        public ?string $pageText = null,
    ) {
    }

    public function isEmpty(): bool
    {
        return null === $this->title && null === $this->imageUrl;
    }

    /** @return array<string, string> */
    public function toEvidence(): array
    {
        return array_filter([
            'title' => $this->title,
            'description' => $this->description,
            'author' => $this->author,
            'site_name' => $this->siteName,
            'image_url' => $this->imageUrl,
            'fetched_via' => $this->fetchedVia,
        ], static fn (?string $value): bool => null !== $value);
    }
}
