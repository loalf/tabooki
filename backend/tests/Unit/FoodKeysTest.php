<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Extraction\ExtractionSchema;
use App\Food\KeyReview;
use PHPUnit\Framework\TestCase;

final class FoodKeysTest extends TestCase
{
    public function testProposedMergesAreCleanedUp(): void
    {
        $known = ['ajo', 'garlic', 'garlic_clove', 'tomatoes', 'tomato'];

        self::assertSame([
            ['from' => 'ajo', 'to' => 'garlic'],
            ['from' => 'garlic_clove', 'to' => 'garlic'],
            ['from' => 'tomatoes', 'to' => 'tomato'],
        ], KeyReview::clean([
            ['from' => 'ajo', 'to' => 'garlic_clove'],
            ['from' => 'garlic_clove', 'to' => 'garlic'],
            ['from' => 'ajo', 'to' => 'onion'],
            ['from' => 'tomatoes', 'to' => ' Tomato '],
            ['from' => 'onion', 'to' => 'cebolla'],
            ['from' => 'garlic', 'to' => 'garlic'],
            'nonsense',
        ], $known), 'chains followed, each key once, unknown and no-op ones dropped, keys normalized');
    }

    public function testExtractionKeepsKeysInEnglishAndReusesKnownOnes(): void
    {
        $instructions = ExtractionSchema::instructions('es', false, ['garlic', 'onion']);
        self::assertStringContainsString('canonical_key is always English, whatever the recipe language', $instructions);
        self::assertStringContainsString('to reuse exactly when it is the same ingredient: garlic, onion.', $instructions);
        self::assertStringNotContainsString('to reuse exactly', ExtractionSchema::instructions('es'));
    }
}
