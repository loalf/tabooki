<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Enum\FoodGroup;
use App\Enum\Heaviness;
use App\Taste\IngredientMatch;
use App\Taste\TasteRules;
use PHPUnit\Framework\TestCase;

final class TasteRulesTest extends TestCase
{
    public function testIngredientsMatchByWordsPluralOrNot(): void
    {
        self::assertTrue(IngredientMatch::matches('coriander', 'fresh_coriander'));
        self::assertTrue(IngredientMatch::matches('mushroom', 'button_mushrooms'));
        self::assertTrue(IngredientMatch::matches('tomato', 'cherry_tomatoes'));
        self::assertTrue(IngredientMatch::matches('olives', 'black_olive'));
        self::assertFalse(IngredientMatch::matches('egg', 'eggplant'));
        self::assertFalse(IngredientMatch::matches('fresh_coriander', 'coriander'), 'the rule\'s words must all be there');
        self::assertSame(['fresh_coriander', 'cilantro'], IngredientMatch::cleanKeys(['Fresh coriander', 'cilantro', 'cilantro', 42]));
    }

    public function testRulesAreReadLeniently(): void
    {
        $rules = TasteRules::fromArray([
            'diet' => 'carnivore',
            'excludeGroups' => [['group' => 'cheese', 'strength' => 'avoid'], ['group' => 'cheese', 'strength' => 'never'], ['group' => 'cheese', 'strength' => 'avoid'], ['group' => 'nope', 'strength' => 'never']],
            'weekly' => [['groups' => ['fish'], 'max' => null, 'min' => null], ['groups' => ['fish'], 'max' => 1, 'min' => 3]],
            'mealHeaviness' => ['lunch' => 'hearty', 'dinner' => 'huge'],
            'maxSpice' => 2,
        ]);

        self::assertNull($rules->diet);
        self::assertSame([['group' => 'cheese', 'strength' => 'never']], array_map(static fn ($r) => $r->toArray(), $rules->excludeGroups), 'the firmer one wins');
        self::assertSame([['groups' => ['fish'], 'max' => 1, 'min' => 1]], array_map(static fn ($r) => $r->toArray(), $rules->weekly));
        self::assertSame(Heaviness::Hearty, $rules->lunch);
        self::assertNull($rules->dinner);
        self::assertNull($rules->maxSpice, '2 is "any"');
        self::assertSame($rules->toArray(), TasteRules::fromArray($rules->toArray())->toArray(), 'stable round trip');
        self::assertTrue(TasteRules::fromArray([])->isEmpty());
    }

    public function testTappedAnswersMakeADraft(): void
    {
        $rules = TasteRules::fromAnswers([
            'diet' => 'pescatarian',
            'avoid' => [['item' => 'pork', 'strength' => 'never'], ['item' => 'coriander', 'custom' => true, 'strength' => 'avoid']],
            'animalProteinMax' => 2,
            'eggsAndDairyCount' => true,
            'fishMin' => 1,
            'meals' => 'heavyLunch',
            'spice' => 'mild',
        ])->toArray();

        self::assertSame('pescatarian', $rules['diet']);
        self::assertSame([['group' => 'pork', 'strength' => 'never']], $rules['excludeGroups'], 'what isn\'t a group is left to the model');
        self::assertSame([...array_map(static fn (FoodGroup $g): string => $g->value, FoodGroup::animalProtein()), 'egg', 'dairy', 'cheese'], $rules['weekly'][0]['groups']);
        self::assertSame(2, $rules['weekly'][0]['max']);
        self::assertSame(['groups' => ['fish', 'shellfish'], 'max' => null, 'min' => 1], $rules['weekly'][1]);
        self::assertSame(['lunch' => 'hearty', 'dinner' => 'light'], $rules['mealHeaviness']);
        self::assertSame(1, $rules['maxSpice']);
    }

    public function testTheApiSchemaKnowsEveryFoodGroup(): void
    {
        $groups = TasteRules::API_SCHEMA['properties']['excludeGroups']['items']['properties']['group']['enum'];
        self::assertSame(FoodGroup::values(), $groups);
    }
}
