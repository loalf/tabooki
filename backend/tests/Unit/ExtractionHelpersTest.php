<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Enum\SourcePlatform;
use App\Extraction\Evidence;
use App\Extraction\YtDlp;
use App\Library\Metadata\HtmlText;
use PHPUnit\Framework\TestCase;

final class ExtractionHelpersTest extends TestCase
{
    public function testVttToText(): void
    {
        $vtt = <<<'VTT'
            WEBVTT
            Kind: captions
            Language: es

            00:00:00.000 --> 00:00:02.000 align:start position:0%
            hoy<00:00:00.500><c> hacemos</c> lentejas

            00:00:02.000 --> 00:00:04.000
            hoy hacemos lentejas

            00:00:04.000 --> 00:00:06.000
            con 300 gramos &amp; chorizo
            VTT;

        self::assertSame('hoy hacemos lentejas con 300 gramos & chorizo', YtDlp::vttToText($vtt));
        self::assertNull(YtDlp::vttToText("WEBVTT\n\n"));
    }

    public function testPageTextKeepsTheArticleAndDropsTheChrome(): void
    {
        $document = \Dom\HTMLDocument::createFromString(<<<'HTML'
            <html><body>
            <nav>Inicio · Recetas · Contacto</nav>
            <article><h1>Tortilla</h1><p>Ingredientes:</p><ul><li>6 huevos</li><li>4 patatas</li></ul>
            <script>track()</script><p>Batir   y cuajar.</p></article>
            <footer>© 2026</footer>
            </body></html>
            HTML, \LIBXML_NOERROR);

        self::assertSame("Tortilla\nIngredientes:\n6 huevos\n4 patatas\nBatir y cuajar.", HtmlText::fromDocument($document));
    }

    public function testEvidencePromptPrefersStructuredData(): void
    {
        $evidence = new Evidence(
            SourcePlatform::Web,
            'https://blog.example/tortilla',
            title: 'Tortilla',
            pageText: str_repeat('texto ', 2000),
            jsonLd: ['@context' => 'https://schema.org', '@type' => 'Recipe', 'name' => 'Tortilla', 'recipeIngredient' => ['6 huevos'], 'review' => [['a' => 'b']]],
        );

        $prompt = $evidence->toPrompt('es');

        self::assertStringContainsString('"recipeIngredient":["6 huevos"]', $prompt);
        self::assertStringNotContainsString('review', $prompt);
        self::assertLessThan(7000, mb_strlen($prompt), 'page text is cut when structured data is present');
        self::assertFalse($evidence->isVideo());
        self::assertTrue($evidence->hasText());
    }
}
