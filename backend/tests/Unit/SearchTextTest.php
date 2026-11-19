<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Library\SearchText;
use PHPUnit\Framework\TestCase;

final class SearchTextTest extends TestCase
{
    public function testFoldsCaseAndAccents(): void
    {
        self::assertSame('pure de patatas con nino y pinon', SearchText::normalize("  Puré de PATATAS\n con niño y piñón "));
    }

    public function testWords(): void
    {
        self::assertSame(['pure', 'patata'], SearchText::words('Puré  patata puré'));
        self::assertSame([], SearchText::words('   '));
    }
}
