<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Enum\SourcePlatform;
use App\Library\UrlNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UrlNormalizerTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function urls(): iterable
    {
        yield 'tracking params stripped, rest sorted' => ['https://www.example.com/receta/?utm_source=ig&b=2&a=1#comments', 'https://example.com/receta?a=1&b=2'];
        yield 'scheme added, host lowercased' => ['Example.COM/Pasta', 'https://example.com/Pasta'];
        yield 'mobile host' => ['https://m.example.com/x', 'https://example.com/x'];
        yield 'root path' => ['https://example.com/', 'https://example.com'];
        yield 'youtube watch' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=42s&list=PL1', 'https://youtube.com/watch?v=dQw4w9WgXcQ'];
        yield 'youtube short link' => ['https://youtu.be/dQw4w9WgXcQ?si=abcdef', 'https://youtube.com/watch?v=dQw4w9WgXcQ'];
        yield 'youtube shorts' => ['https://youtube.com/shorts/dQw4w9WgXcQ?feature=share', 'https://youtube.com/watch?v=dQw4w9WgXcQ'];
        yield 'youtube mobile' => ['https://m.youtube.com/watch?v=dQw4w9WgXcQ', 'https://youtube.com/watch?v=dQw4w9WgXcQ'];
        yield 'instagram reel' => ['https://www.instagram.com/reel/C1a2B3c4D5e/?igsh=MWQ1ZGUxMzBkMA==', 'https://instagram.com/p/C1a2B3c4D5e'];
        yield 'instagram post with username' => ['https://www.instagram.com/cookingwithana/p/C1a2B3c4D5e/', 'https://instagram.com/p/C1a2B3c4D5e'];
        yield 'tiktok' => ['https://www.tiktok.com/@chef/video/7301234567890123456?is_from_webapp=1&sender_device=pc', 'https://tiktok.com/@chef/video/7301234567890123456'];
    }

    #[DataProvider('urls')]
    public function testNormalize(string $url, string $expected): void
    {
        self::assertSame($expected, new UrlNormalizer()->normalize($url));
    }

    public function testDetectPlatform(): void
    {
        $normalizer = new UrlNormalizer();

        self::assertSame(SourcePlatform::YouTube, $normalizer->detectPlatform('https://youtu.be/dQw4w9WgXcQ'));
        self::assertSame(SourcePlatform::Instagram, $normalizer->detectPlatform('instagram.com/p/abc'));
        self::assertSame(SourcePlatform::TikTok, $normalizer->detectPlatform('https://vm.tiktok.com/ZMabc/'));
        self::assertSame(SourcePlatform::Web, $normalizer->detectPlatform('https://notyoutube.com/watch?v=1'));
    }

    public function testWithSchemeKeepsTheOriginalLink(): void
    {
        self::assertSame('https://example.com/a?utm_source=x', new UrlNormalizer()->withScheme(' example.com/a?utm_source=x '));
    }

    /** @return iterable<array{string}> */
    public static function invalidUrls(): iterable
    {
        yield ['not a link'];
        yield ['ftp://example.com/file'];
        yield ['javascript:alert(1)'];
        yield ['https://localhost/'];
    }

    #[DataProvider('invalidUrls')]
    public function testRejectsNonWebLinks(string $url): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new UrlNormalizer()->normalize($url);
    }
}
