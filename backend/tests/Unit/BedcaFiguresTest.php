<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Food\Bedca\BedcaFigures;
use App\Food\Bedca\BedcaFood;
use PHPUnit\Framework\TestCase;

final class BedcaFiguresTest extends TestCase
{
    public function testKilojoulesBecomeKcalAndSodiumBecomesSalt(): void
    {
        // BEDCA's "Nata, líquida, para cocinar, 18% de grasa".
        $figures = BedcaFigures::entry(new BedcaFood(923, 'Nata, líquida, para cocinar, 18% de grasa', 'Cream'), [
            'ENERC' => ['value' => 840.078, 'unit' => 'kJ'],
            'PROT' => ['value' => 2.51, 'unit' => 'g'],
            'FAT' => ['value' => 19.9894, 'unit' => 'g'],
            'FASAT' => ['value' => 12.7, 'unit' => 'g'],
            'CHO' => ['value' => 3.4, 'unit' => 'g'],
            'SUGAR' => ['value' => 3.4, 'unit' => 'g'],
            'FIBT' => ['value' => 0.0, 'unit' => 'g'],
            'NA' => ['value' => 45.0, 'unit' => 'mg'],
        ]);

        self::assertNull($figures->problem);
        self::assertSame(['kcal' => 200.8, 'protein' => 2.51, 'fat' => 19.9894, 'saturatedFat' => 12.7, 'carbs' => 3.4, 'sugar' => 3.4, 'fiber' => 0.0, 'salt' => 0.11], $figures->values);
    }

    public function testWhatIsntGivenStaysUnknownAndContradictionsAreCaught(): void
    {
        // BEDCA's "Garbanzo, hervido": energy of the dried ones, nutrients of the boiled ones.
        $boiled = BedcaFigures::entry(new BedcaFood(2192, 'Garbanzo, hervido', 'Chickpea, boiled'), [
            'ENERC' => ['value' => 1500.6, 'unit' => 'kJ'],
            'PROT' => ['value' => 8.9, 'unit' => 'g'],
            'FAT' => ['value' => 2.5, 'unit' => 'g'],
            'CHO' => ['value' => 18.7, 'unit' => 'g'],
            'FIBT' => ['value' => 13.6, 'unit' => 'g'],
            'VITC' => ['value' => null, 'unit' => 'mg'],
        ]);
        self::assertSame('energy 359 kcal, but its nutrients add up to 160', $boiled->problem);
        self::assertNull($boiled->values['sugar'], 'not given');
        self::assertNull($boiled->values['salt']);

        self::assertSame('no energy', BedcaFigures::entry(new BedcaFood(1, 'X', 'X'), ['PROT' => ['value' => 1.0, 'unit' => 'g']])->problem);
        self::assertSame('2192 Garbanzo, hervido', $boiled->ref);
    }
}
