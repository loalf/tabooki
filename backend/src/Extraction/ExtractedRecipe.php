<?php

declare(strict_types=1);

namespace App\Extraction;

use App\Enum\Language;
use App\Enum\MealType;

final readonly class ExtractedRecipe
{
    /**
     * @param list<MealType>                                                                                                                                 $mealTypes
     * @param list<string>                                                                                                                                   $tags
     * @param list<array{raw: string, group: ?string, quantity: ?float, unit: ?string, name: ?string, canonicalKey: ?string, prep: ?string, optional: bool}> $ingredients
     * @param list<string>                                                                                                                                   $steps
     */
    public function __construct(
        public string $title,
        public Language $language,
        public ?int $servings,
        public ?int $prepMinutes,
        public ?int $cookMinutes,
        public array $mealTypes,
        public array $tags,
        public array $ingredients,
        public array $steps,
        public ?string $notes,
    ) {
    }
}
