<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Enum\Language;
use App\Enum\MealType;
use App\Extraction\ExtractionException;
use App\Extraction\ExtractionResult;
use App\Llm\Completion;
use PHPUnit\Framework\TestCase;

final class ExtractionResultTest extends TestCase
{
    public function testParsesAndSanitizesTheModelAnswer(): void
    {
        $result = ExtractionResult::fromCompletion($this->completion([
            'status' => 'complete',
            'confidence' => 1.7,
            'recipes' => [[
                'title' => '  Lentejas estofadas ',
                'language' => 'es',
                'servings' => '4',
                'prep_minutes' => -5,
                'cook_minutes' => 40.4,
                'meal_types' => ['lunch', 'breakfast'],
                'tags' => ['legumbres', '', 42],
                'ingredients' => [
                    ['raw' => '300 g de lentejas', 'quantity' => 300, 'unit' => 'G', 'name' => 'lentejas', 'canonical_key' => 'Lentils (dried)'],
                    ['raw' => 'Sal', 'quantity' => null, 'unit' => 'handful', 'name' => 'sal', 'canonical_key' => 'salt'],
                    ['quantity' => 2],
                ],
                'steps' => ['Remojar.', '  ', 'Cocer 40 minutos.'],
            ], ['language' => 'es']],
        ]));

        self::assertSame('complete', $result->status);
        self::assertSame(1.0, $result->confidence);
        self::assertFalse($result->needsReview());
        self::assertCount(1, $result->recipes);
        $recipe = $result->recipes[0];
        self::assertSame('Lentejas estofadas', $recipe->title);
        self::assertSame(Language::Es, $recipe->language);
        self::assertSame(4, $recipe->servings);
        self::assertNull($recipe->prepMinutes);
        self::assertSame(40, $recipe->cookMinutes);
        self::assertSame([MealType::Lunch], $recipe->mealTypes);
        self::assertSame(['legumbres'], $recipe->tags);
        self::assertSame(['Remojar.', 'Cocer 40 minutos.'], $recipe->steps);
        self::assertCount(2, $recipe->ingredients);
        self::assertSame(['raw' => '300 g de lentejas', 'group' => null, 'quantity' => 300.0, 'unit' => 'g', 'name' => 'lentejas', 'canonicalKey' => 'lentils_dried', 'prep' => null, 'optional' => false], $recipe->ingredients[0]);
        self::assertNull($recipe->ingredients[1]['unit']);
    }

    public function testPartialOrUnsureAnswersNeedReview(): void
    {
        $recipe = ['title' => 'X', 'language' => 'es', 'ingredients' => [], 'steps' => []];

        self::assertTrue(ExtractionResult::fromCompletion($this->completion(['status' => 'partial', 'confidence' => 0.9, 'recipes' => [$recipe]]))->needsReview());
        self::assertTrue(ExtractionResult::fromCompletion($this->completion(['status' => 'complete', 'confidence' => 0.6, 'recipes' => [$recipe]]))->needsReview());
        self::assertTrue(ExtractionResult::fromCompletion($this->completion(['status' => 'complete', 'confidence' => 0.9, 'missing_fields' => ['servings'], 'recipes' => [$recipe]]))->needsReview());
    }

    public function testACompleteAnswerWithoutRecipesMeansNoRecipe(): void
    {
        $result = ExtractionResult::fromCompletion($this->completion(['status' => 'complete', 'confidence' => 0.9, 'recipes' => []]));

        self::assertSame('no_recipe', $result->status);
    }

    public function testAnAnswerWithoutTheToolIsAnError(): void
    {
        $this->expectException(ExtractionException::class);
        ExtractionResult::fromCompletion(new Completion(null, 'Here is the recipe…', 'm', 1, 1, 'end_turn'));
    }

    /** @param array<string, mixed> $input */
    private function completion(array $input): Completion
    {
        return new Completion($input, '', 'test.text-model', 100, 50, 'tool_use');
    }
}
