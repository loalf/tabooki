<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Library\Metadata\HtmlMetadataParser;
use PHPUnit\Framework\TestCase;

final class HtmlMetadataParserTest extends TestCase
{
    public function testOpenGraphTags(): void
    {
        $html = <<<'HTML'
            <!doctype html><html><head>
            <title>Fallback title</title>
            <meta property="og:title" content="Lentejas  con chorizo &amp; verduras">
            <meta property="og:image" content="/img/lentejas.jpg">
            <meta property="og:site_name" content="Recetas de Ana">
            <meta name="description" content="Las lentejas de la abuela.">
            <meta name="author" content="Ana">
            </head><body></body></html>
            HTML;

        $metadata = new HtmlMetadataParser()->parse($html, 'https://recetas.example/legumbres/lentejas');

        self::assertSame('Lentejas con chorizo & verduras', $metadata->title);
        self::assertSame('https://recetas.example/img/lentejas.jpg', $metadata->imageUrl);
        self::assertSame('Recetas de Ana', $metadata->siteName);
        self::assertSame('Las lentejas de la abuela.', $metadata->description);
        self::assertSame('Ana', $metadata->author);
        self::assertNull($metadata->recipeJsonLd);
    }

    public function testRecipeJsonLdInAGraph(): void
    {
        $html = <<<'HTML'
            <html><head><title>Site | Banana bread</title>
            <script type="application/ld+json">{"@context":"https://schema.org","@graph":[
              {"@type":"WebPage","name":"Banana bread"},
              {"@type":["Recipe"],"name":"Best banana bread","image":[{"@type":"ImageObject","url":"https://cdn.example/bb.jpg"}],
               "author":{"@type":"Person","name":"Sam"},"recipeIngredient":["3 ripe bananas"]}
            ]}</script>
            <script type="application/ld+json">{not json</script>
            </head></html>
            HTML;

        $metadata = new HtmlMetadataParser()->parse($html, 'https://blog.example/bb');

        self::assertSame('Best banana bread', $metadata->title);
        self::assertSame('https://cdn.example/bb.jpg', $metadata->imageUrl);
        self::assertSame('Sam', $metadata->author);
        self::assertSame(['3 ripe bananas'], $metadata->recipeJsonLd['recipeIngredient'] ?? null);
    }

    public function testFallsBackToTheTitleElement(): void
    {
        $metadata = new HtmlMetadataParser()->parse('<title> Just a page </title>', 'https://example.com');

        self::assertSame('Just a page', $metadata->title);
        self::assertNull($metadata->imageUrl);
    }
}
