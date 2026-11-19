<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Shopping\Quantities;
use PHPUnit\Framework\TestCase;

final class QuantitiesTest extends TestCase
{
    public function testConvertsAndSumsUnitsOfAKind(): void
    {
        self::assertSame([['quantity' => 700.0, 'unit' => 'g']], $this->sum([200, 'g'], [0.5, 'kg']));
        self::assertSame([['quantity' => 1.25, 'unit' => 'kg']], $this->sum([750, 'g'], [0.5, 'kg']));
        self::assertSame([['quantity' => 240.0, 'unit' => 'g']], $this->sum([233.3, 'g']), 'rounded up to 10 g');
        self::assertSame([['quantity' => 130.0, 'unit' => 'ml']], $this->sum([2, 'tbsp'], [100, 'ml']));
        self::assertSame([['quantity' => 1.5, 'unit' => 'l']], $this->sum([1, 'l'], [500, 'ml']));
    }

    public function testSpoonsStaySpoons(): void
    {
        self::assertSame([['quantity' => 3.0, 'unit' => 'tbsp']], $this->sum([2, 'tbsp'], [3, 'tsp']));
        self::assertSame([['quantity' => 1.0, 'unit' => 'tsp']], $this->sum([0.5, 'tsp'], [0.5, 'tsp']));
    }

    public function testCountsAreRoundedUpToWholeItems(): void
    {
        self::assertSame([['quantity' => 2.0, 'unit' => null]], $this->sum([1.5, null]));
        self::assertSame([['quantity' => 1.0, 'unit' => null]], $this->sum([0.25, null]));
        self::assertSame([['quantity' => 3.0, 'unit' => null]], $this->sum([3.000001, null]), 'float noise is not an extra item');
        self::assertSame([['quantity' => 3.0, 'unit' => 'clove']], $this->sum([2, 'clove'], [1, 'clove']));
    }

    public function testDifferentKindsStaySeparate(): void
    {
        self::assertSame(
            [['quantity' => 2.0, 'unit' => 'can'], ['quantity' => 400.0, 'unit' => 'g']],
            $this->sum([1, 'can'], [400, 'g'], [1, 'can']),
        );
    }

    public function testUnmeasuredOnlyWhenNoAmountAtAll(): void
    {
        $salt = new Quantities();
        $salt->add(null, null);
        self::assertTrue($salt->isUnmeasured());
        self::assertSame([], $salt->amounts());

        $oil = new Quantities();
        $oil->add(null, null);
        $oil->add(2, 'tbsp');
        self::assertFalse($oil->isUnmeasured());
        self::assertSame([['quantity' => 2.0, 'unit' => 'tbsp']], $oil->amounts());
    }

    /**
     * @param array{0: float|int, 1: string|null} ...$parts
     *
     * @return list<array{quantity: float, unit: string|null}>
     */
    private function sum(array ...$parts): array
    {
        $quantities = new Quantities();
        foreach ($parts as [$quantity, $unit]) {
            $quantities->add((float) $quantity, $unit);
        }

        return $quantities->amounts();
    }
}
