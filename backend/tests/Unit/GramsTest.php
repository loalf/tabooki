<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Food;
use App\Food\FoodFacts;
use App\Food\Grams;
use PHPUnit\Framework\TestCase;

final class GramsTest extends TestCase
{
    public function testMeasuredAmounts(): void
    {
        $oil = self::food(density: 0.92);

        self::assertSame(200.0, Grams::measured(200, 'g', null));
        self::assertSame(1500.0, Grams::measured(1.5, 'kg', null));
        self::assertSame(250.0, Grams::measured(250, 'ml', null), 'water when the food is unknown');
        self::assertEqualsWithDelta(27.6, Grams::measured(2, 'tbsp', $oil), 0.001);
        self::assertEqualsWithDelta(920.0, Grams::measured(1, 'l', $oil), 0.001);
        self::assertNull(Grams::measured(2, null, $oil), 'counted, not measured');
        self::assertNull(Grams::measured(null, 'g', null), 'to taste');
        self::assertNull(Grams::measured(1, 'pinch', null));
    }

    public function testCountedAmounts(): void
    {
        $onion = self::food(piece: 150);
        $chickpeas = self::food(piece: 250);
        $garlic = self::food(piece: 5);
        $garlicHead = self::food(piece: 50);

        self::assertSame(300.0, Grams::converted(2, null, $onion));
        self::assertSame(150.0, Grams::converted(1, 'piece', $onion));
        self::assertNull(Grams::converted(2, null, null), 'weight of one unknown');
        self::assertSame(250.0, Grams::converted(1, 'can', $chickpeas));
        self::assertSame(400.0, Grams::converted(1, 'can', null), 'a usual can');
        self::assertSame(10.0, Grams::converted(2, 'clove', $garlic));
        self::assertSame(10.0, Grams::converted(2, 'clove', $garlicHead), 'a clove, not a head');
        self::assertEqualsWithDelta(0.8, Grams::converted(2, 'pinch', null), 0.001);
        self::assertNull(Grams::converted(1, 'bunch', $onion));
    }

    private static function food(?float $piece = null, ?float $density = null): Food
    {
        $food = new Food('x');
        $food->describe(new FoodFacts(null, null, 100, 1, 1, 0, 1, 0, 0, 0, [], [], 0, $piece, $density));

        return $food;
    }
}
