<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Food\Usda\UsdaFigures;
use App\Food\Usda\UsdaFood;
use PHPUnit\Framework\TestCase;

final class UsdaFiguresTest extends TestCase
{
    public function testCarbohydrateWithoutItsFibreAndSaltFromSodium(): void
    {
        // A search result: SR Legacy chickpeas, canned, drained.
        $food = UsdaFood::fromArray(['fdcId' => 173757, 'description' => 'Chickpeas, canned, drained solids', 'dataType' => 'SR Legacy', 'foodNutrients' => [
            ['nutrientNumber' => '208', 'value' => 139, 'unitName' => 'KCAL'],
            ['nutrientNumber' => '203', 'value' => 7.05, 'unitName' => 'G'],
            ['nutrientNumber' => '204', 'value' => 2.77, 'unitName' => 'G'],
            ['nutrientNumber' => '606', 'value' => 0.29, 'unitName' => 'G'],
            ['nutrientNumber' => '205', 'value' => 22.5, 'unitName' => 'G'],
            ['nutrientNumber' => '291', 'value' => 7.0, 'unitName' => 'G'],
            ['nutrientNumber' => '307', 'value' => 246, 'unitName' => 'MG'],
        ]]);
        self::assertNotNull($food);
        $entry = UsdaFigures::entry($food);

        self::assertSame('173757 Chickpeas, canned, drained solids (SR Legacy)', $entry->ref);
        self::assertEquals(['kcal' => 139, 'protein' => 7.05, 'fat' => 2.77, 'saturatedFat' => 0.29, 'carbs' => 15.5, 'sugar' => null, 'fiber' => 7.0, 'salt' => 0.62], $entry->values);
        self::assertNull($entry->problem);
        self::assertSame(['sugar'], $entry->missing());
    }

    public function testEnergyFromKilojoulesAndAnAbridgedFood(): void
    {
        // An abridged food (GET /food/{id}?format=abridged) with only kJ.
        $food = UsdaFood::fromArray(['fdcId' => 1, 'description' => 'Broth', 'dataType' => 'Foundation', 'foodNutrients' => [
            ['number' => '268', 'amount' => 41.84, 'unitName' => 'kJ'],
            ['number' => '203', 'amount' => 1.5, 'unitName' => 'g'],
            ['number' => '205', 'amount' => 1.0, 'unitName' => 'g'],
        ]]);
        self::assertNotNull($food);
        self::assertSame(10.0, UsdaFigures::entry($food)->values['kcal']);
        self::assertSame($food->toArray(), UsdaFood::fromArray($food->toArray())?->toArray(), 'kept and read back');
    }
}
