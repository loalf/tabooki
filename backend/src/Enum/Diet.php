<?php

declare(strict_types=1);

namespace App\Enum;

/** The strictest diet a dish suits, from its food groups (optional ingredients can be left out). */
enum Diet: string
{
    case Vegan = 'vegan';
    case Vegetarian = 'vegetarian';
    case Pescatarian = 'pescatarian';

    /** @param list<FoodGroup> $groups what the dish needs; null when it suits none of these */
    public static function of(array $groups): ?self
    {
        $has = static fn (FoodGroup ...$wanted): bool => [] !== array_intersect(
            array_map(static fn (FoodGroup $g): string => $g->value, $groups),
            array_map(static fn (FoodGroup $g): string => $g->value, $wanted),
        );
        if ($has(FoodGroup::RedMeat, FoodGroup::Pork, FoodGroup::Poultry, FoodGroup::ProcessedMeat, FoodGroup::AnimalStock)) {
            return null;
        }
        if ($has(FoodGroup::Fish, FoodGroup::Shellfish)) {
            return self::Pescatarian;
        }

        return $has(FoodGroup::Egg, FoodGroup::Dairy, FoodGroup::Cheese) ? self::Vegetarian : self::Vegan;
    }
}
