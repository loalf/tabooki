<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Recipe;
use App\Enum\Allergen;
use App\Enum\FoodGroup;
use App\Planning\Candidate;
use App\Taste\Conflict;
use App\Taste\Taste;
use App\Taste\TasteRules;
use PHPUnit\Framework\TestCase;

final class TasteAllergensTest extends TestCase
{
    public function testAnAllergyToAGroupAlsoGoesByTheFoods(): void
    {
        $taste = new Taste(TasteRules::fromArray(['excludeGroups' => [['group' => 'gluten', 'strength' => 'never'], ['group' => 'dairy', 'strength' => 'avoid']]]));

        // Read as gluten-free, but its soy sauce has wheat.
        self::assertSame([['strength' => 'never', 'kind' => 'group', 'value' => 'gluten']], self::conflicts($taste, self::recipe(allergens: [Allergen::Gluten, Allergen::Soy])));
        self::assertTrue($taste->forbids(self::recipe(optionalAllergens: [Allergen::Gluten])), 'optional bread too: an allergy is firm');
        self::assertSame([], self::conflicts($taste, self::recipe(allergens: [Allergen::Milk])), 'a dislike only goes by the dish as read');
        self::assertSame('avoid', self::conflicts($taste, self::recipe(groups: [FoodGroup::Dairy]))[0]['strength'] ?? null);
    }

    public function testAnAllergyNoGroupSaysGoesByTheFoodsAndWantsThemKnown(): void
    {
        $taste = new Taste(TasteRules::fromArray(['excludeAllergens' => [['allergen' => 'mustard', 'strength' => 'never'], ['allergen' => 'celery', 'strength' => 'avoid'], ['allergen' => 'gluten', 'strength' => 'never']]]));
        self::assertCount(2, $taste->rules->excludeAllergens, 'gluten is a food group: said that way');

        self::assertSame([['strength' => 'never', 'kind' => 'allergen', 'value' => 'mustard']], self::conflicts($taste, self::recipe(optionalAllergens: [Allergen::Mustard])));
        self::assertSame([['strength' => 'avoid', 'kind' => 'allergen', 'value' => 'celery']], self::conflicts($taste, self::recipe(allergens: [Allergen::Celery])));
        self::assertSame([], self::conflicts($taste, self::recipe(optionalAllergens: [Allergen::Celery])), 'leave the optional celery out');
        self::assertSame([['strength' => 'never', 'kind' => 'unknown']], self::conflicts($taste, self::recipe(complete: false)), 'a food not described yet might have mustard');
        self::assertSame([], self::conflicts($taste, self::recipe()));
    }

    public function testTheQuestionnaireTakesThemAndAWeekAddsThem(): void
    {
        $rules = TasteRules::fromAnswers(['avoid' => [['item' => 'mustard', 'strength' => 'never'], ['item' => 'sesame', 'strength' => 'never']]])->toArray();
        self::assertSame([['allergen' => 'mustard', 'strength' => 'never']], $rules['excludeAllergens']);
        self::assertSame([['group' => 'sesame', 'strength' => 'never']], $rules['excludeGroups']);

        $week = TasteRules::fromArray(['excludeAllergens' => [['allergen' => 'mustard', 'strength' => 'avoid'], ['allergen' => 'lupin', 'strength' => 'never']]]);
        self::assertSame(
            [['allergen' => 'mustard', 'strength' => 'never'], ['allergen' => 'lupin', 'strength' => 'never']],
            TasteRules::fromArray($rules)->with($week)->toArray()['excludeAllergens'],
            'the firmer one stays',
        );
        self::assertSame(['celery', 'mustard', 'sulphites', 'lupin', 'molluscs'], TasteRules::API_SCHEMA['properties']['excludeAllergens']['items']['properties']['allergen']['enum']);
    }

    /** @return list<array<string, mixed>> */
    private static function conflicts(Taste $taste, Candidate $recipe): array
    {
        return array_map(static fn (Conflict $c): array => $c->toArray(), $taste->conflicts($recipe));
    }

    /**
     * @param list<FoodGroup> $groups
     * @param list<Allergen>  $allergens
     * @param list<Allergen>  $optionalAllergens
     */
    private static function recipe(array $groups = [], array $allergens = [], array $optionalAllergens = [], bool $complete = true): Candidate
    {
        return new Candidate(
            recipe: new Recipe(), id: 'r', mealTypes: [], minutes: null, tags: [], fresh: [], favorite: false, rating: null, needsReview: false,
            foodGroups: $groups, hasFacts: true, allergens: $allergens, optionalAllergens: $optionalAllergens, allergensComplete: $complete,
        );
    }
}
