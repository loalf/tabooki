<?php

declare(strict_types=1);

namespace App\Planning;

use App\Enum\FoodGroup;
use App\Enum\Heaviness;

/** What the text model read from a recipe for the planner (docs/PLAN.md §6.2). */
final readonly class RecipeFacts
{
    /**
     * @param list<FoodGroup> $foodGroups         of the ingredients the dish needs
     * @param list<FoodGroup> $optionalFoodGroups of its optional ingredients
     */
    public function __construct(
        public Heaviness $heaviness,
        public int $spice,
        public array $foodGroups,
        public array $optionalFoodGroups,
    ) {
    }

    /**
     * Reads the model's answer (RecipeAnnotator::schema()); null when it isn't that shape.
     * Unknown food groups are dropped rather than failing the whole answer.
     *
     * @param array<mixed> $input
     */
    public static function fromToolInput(array $input): ?self
    {
        $heaviness = Heaviness::tryFrom(\is_string($input['heaviness'] ?? null) ? $input['heaviness'] : '');
        $spice = $input['spice'] ?? null;
        if (null === $heaviness || !\is_int($spice) || $spice < 0 || $spice > 2) {
            return null;
        }
        $groups = static fn (mixed $list): array => FoodGroup::listFrom(\is_array($list) ? $list : []);

        return new self($heaviness, $spice, $groups($input['food_groups'] ?? null), $groups($input['optional_food_groups'] ?? null));
    }
}
