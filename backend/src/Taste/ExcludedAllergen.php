<?php

declare(strict_types=1);

namespace App\Taste;

use App\Enum\Allergen;

/**
 * "Allergic to mustard": an allergen no food group says (FoodGroup::allergens() covers the rest),
 * so only the recipe's foods can tell (App\Food\Nutrition::allergens()).
 */
final readonly class ExcludedAllergen
{
    /** The allergens a household can only rule out this way. */
    public const array ALLERGENS = [Allergen::Celery, Allergen::Mustard, Allergen::Sulphites, Allergen::Lupin, Allergen::Molluscs];

    public function __construct(public Allergen $allergen, public Strength $strength)
    {
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (Allergen $a): string => $a->value, self::ALLERGENS);
    }

    public static function fromArray(mixed $data): ?self
    {
        $allergen = \is_array($data) && \is_string($data['allergen'] ?? null) ? Allergen::tryFrom($data['allergen']) : null;
        $strength = \is_array($data) ? Strength::read($data['strength'] ?? null) : null;

        return null === $allergen || null === $strength || !\in_array($allergen, self::ALLERGENS, true) ? null : new self($allergen, $strength);
    }

    /** @return array{allergen: string, strength: string} */
    public function toArray(): array
    {
        return ['allergen' => $this->allergen->value, 'strength' => $this->strength->value];
    }
}
