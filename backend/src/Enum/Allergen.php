<?php

declare(strict_types=1);

namespace App\Enum;

/** The 14 allergens EU law (Regulation 1169/2011, Annex II) says must be declared. */
enum Allergen: string
{
    case Gluten = 'gluten';
    case Crustaceans = 'crustaceans';
    case Eggs = 'eggs';
    case Fish = 'fish';
    case Peanuts = 'peanuts';
    case Soy = 'soy';
    case Milk = 'milk';
    case TreeNuts = 'tree_nuts';
    case Celery = 'celery';
    case Mustard = 'mustard';
    case Sesame = 'sesame';
    case Sulphites = 'sulphites';
    case Lupin = 'lupin';
    case Molluscs = 'molluscs';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $allergen): string => $allergen->value, self::cases());
    }

    /**
     * Keeps known allergens only, once each, in the enum's order.
     *
     * @param iterable<mixed> $values
     *
     * @return list<self>
     */
    public static function listFrom(iterable $values): array
    {
        $wanted = [];
        foreach ($values as $value) {
            $allergen = $value instanceof self ? $value : (\is_string($value) ? self::tryFrom($value) : null);
            if (null !== $allergen) {
                $wanted[$allergen->value] = true;
            }
        }

        return array_values(array_filter(self::cases(), static fn (self $allergen): bool => isset($wanted[$allergen->value])));
    }
}
