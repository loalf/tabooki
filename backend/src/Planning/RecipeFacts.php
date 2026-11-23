<?php

declare(strict_types=1);

namespace App\Planning;

use App\Enum\FoodGroup;
use App\Enum\Heaviness;
use App\Extraction\ExtractionSchema;

/** What the text model read from a recipe: for the planner (docs/PLAN.md §6.2), and each line's weight (docs/INGREDIENT-KNOWLEDGE.md). */
final readonly class RecipeFacts
{
    /**
     * @param list<FoodGroup>                                                                                $foodGroups         of the ingredients the dish needs
     * @param list<FoodGroup>                                                                                $optionalFoodGroups of its optional ingredients
     * @param array<int, array{grams: ?float, key: ?string, name: ?string, quantity: ?float, unit: ?string}> $lines              by ingredient position: its weight, and for lines typed by hand what it is
     */
    public function __construct(
        public Heaviness $heaviness,
        public int $spice,
        public array $foodGroups,
        public array $optionalFoodGroups,
        public array $lines = [],
    ) {
    }

    /** @return array<int, float|null> the estimated grams by ingredient position */
    public function grams(): array
    {
        return array_map(static fn (array $line): ?float => $line['grams'], $this->lines);
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

        $lines = [];
        foreach (\is_array($input['ingredients'] ?? null) ? $input['ingredients'] : [] as $line) {
            $n = \is_array($line) ? ($line['line'] ?? null) : null;
            if (!\is_array($line) || !\is_int($n) || $n < 1) {
                continue;
            }
            $number = static fn (string $field): ?float => is_numeric($line[$field] ?? null) && (float) $line[$field] >= 0 ? (float) $line[$field] : null;
            $text = static fn (string $field, int $max): ?string => \is_string($line[$field] ?? null) && '' !== trim($line[$field]) ? mb_substr(trim($line[$field]), 0, $max) : null;
            $key = $text('canonical_key', 80);
            $unit = $text('unit', 20);
            $lines[$n - 1] = [
                'grams' => $number('grams'),
                'key' => null === $key ? null : (trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($key)), '_') ?: null),
                'name' => $text('name', 150),
                'quantity' => $number('quantity'),
                'unit' => null !== $unit && \in_array($unit, ExtractionSchema::UNITS, true) ? $unit : null,
            ];
        }

        return new self($heaviness, $spice, $groups($input['food_groups'] ?? null), $groups($input['optional_food_groups'] ?? null), $lines);
    }
}
