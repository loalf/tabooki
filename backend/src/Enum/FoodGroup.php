<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * What a dish contains, for likings, allergies and diets (docs/PLAN.md §6.2). Coarse on
 * purpose: a household says "no fish" or "no cheese", not "no hake".
 */
enum FoodGroup: string
{
    case RedMeat = 'red_meat';
    case Pork = 'pork';
    case Poultry = 'poultry';
    case ProcessedMeat = 'processed_meat';
    case Fish = 'fish';
    case Shellfish = 'shellfish';
    /** Meat or poultry stock, gelatin, lard…: not a meat meal, but not vegetarian either. Fish stock is fish. */
    case AnimalStock = 'animal_stock';
    case Egg = 'egg';
    case Dairy = 'dairy';
    case Cheese = 'cheese';
    case Gluten = 'gluten';
    case TreeNuts = 'tree_nuts';
    case Peanuts = 'peanuts';
    case Soy = 'soy';
    case Sesame = 'sesame';
    case Alcohol = 'alcohol';

    /** @return list<self> meat or fish: what "animal protein twice a week" counts */
    public static function animalProtein(): array
    {
        return [self::RedMeat, self::Pork, self::Poultry, self::ProcessedMeat, self::Fish, self::Shellfish];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $group): string => $group->value, self::cases());
    }

    /**
     * Keeps known groups only, once each, in the enum's order.
     *
     * @param iterable<mixed> $values
     *
     * @return list<self>
     */
    public static function listFrom(iterable $values): array
    {
        $wanted = [];
        foreach ($values as $value) {
            $group = $value instanceof self ? $value : (\is_string($value) ? self::tryFrom($value) : null);
            if (null !== $group) {
                $wanted[$group->value] = true;
            }
        }

        return array_values(array_filter(self::cases(), static fn (self $group): bool => isset($wanted[$group->value])));
    }
}
