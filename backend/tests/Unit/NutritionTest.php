<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Food;
use App\Entity\RecipeIngredient;
use App\Enum\Allergen;
use App\Enum\GramsSource;
use App\Food\FoodFacts;
use App\Food\Nutrition;
use PHPUnit\Framework\TestCase;

final class NutritionTest extends TestCase
{
    public function testAddsUpPerServingLeavingOutOptionalLines(): void
    {
        $foods = [
            'lentils' => self::food(kcal: 350, protein: 24, fat: 1.5, carbs: 50, salt: 0.1),
            'olive_oil' => self::food(kcal: 884, fat: 100),
            'chorizo' => self::food(kcal: 455, protein: 24, fat: 38, salt: 4, allergens: [Allergen::Milk, Allergen::Sulphites]),
        ];
        $lines = [
            self::line('lentils', 400),
            self::line('olive_oil', 30),
            self::line('chorizo', 100, optional: true),
            self::line('salt', null),
        ];

        $nutrition = Nutrition::perServing($lines, $foods, 4);

        // Lentils: 1400 kcal, 96 g protein, 6 g fat; oil: 265.2 kcal, 30 g fat; over 4 servings.
        self::assertNotNull($nutrition);
        self::assertSame(416, $nutrition['kcal']);
        self::assertSame(24.0, $nutrition['protein']);
        self::assertSame(9.0, $nutrition['fat']);
        self::assertSame(50.0, $nutrition['carbs']);
        self::assertSame(0.1, $nutrition['salt']);
        self::assertSame([1.0, 1], [$nutrition['coverage'], $nutrition['unweighed']], 'all weighed grams known; salt not weighed');
    }

    public function testPartlyKnownOrTooLittleKnown(): void
    {
        $foods = ['lentils' => self::food(kcal: 350), 'mystery' => new Food('mystery')];

        $partly = Nutrition::perServing([self::line('lentils', 300), self::line('mystery', 100), self::line('unknown_key', 0)], $foods, 2);
        self::assertSame([525, 0.75], [$partly['kcal'] ?? null, $partly['coverage'] ?? null], 'undescribed foods left out, and said so');

        self::assertNull(Nutrition::perServing([self::line('lentils', 100), self::line('mystery', 300)], $foods, 2), 'under half known');
        self::assertNull(Nutrition::perServing([self::line('lentils', null)], $foods, 2), 'nothing weighed');
    }

    public function testAllergensOfWhatIsNeededAndOfWhatIsOptional(): void
    {
        $foods = [
            'pasta' => self::food(kcal: 360, allergens: [Allergen::Gluten, Allergen::Eggs]),
            'parmesan' => self::food(kcal: 400, allergens: [Allergen::Milk]),
            'egg' => self::food(kcal: 143, allergens: [Allergen::Eggs]),
        ];

        [$needed, $optional, $complete] = Nutrition::allergens([
            self::line('pasta', 200), self::line('egg', 55, optional: true), self::line('parmesan', 20, optional: true), self::line('mystery', 5, optional: true),
        ], $foods);

        self::assertSame([Allergen::Gluten, Allergen::Eggs], $needed);
        self::assertSame([Allergen::Milk], $optional, 'eggs are needed anyway');
        self::assertTrue($complete, 'an unknown optional line can be left out');

        self::assertFalse(Nutrition::allergens([self::line('pasta', 200), self::line('mystery', 5)], $foods)[2], 'a needed line whose food is unknown');
        self::assertFalse(Nutrition::allergens([], $foods)[2], 'no lines, nothing known');
    }

    private static function line(string $key, ?float $grams, bool $optional = false): RecipeIngredient
    {
        $line = new RecipeIngredient();
        $line->setCanonicalKey($key);
        $line->setOptional($optional);
        $line->weigh($grams, GramsSource::Estimated);

        return $line;
    }

    /** @param list<Allergen> $allergens */
    private static function food(float $kcal, float $protein = 0, float $fat = 0, float $carbs = 0, float $salt = 0, array $allergens = []): Food
    {
        $food = new Food('x');
        $food->describe(new FoodFacts(null, null, $kcal, $protein, $fat, 0, $carbs, 0, 0, $salt, [], $allergens, 0, null, null));

        return $food;
    }
}
