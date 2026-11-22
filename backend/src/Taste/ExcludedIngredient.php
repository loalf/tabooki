<?php

declare(strict_types=1);

namespace App\Taste;

/** "No coriander", "no mushrooms": canonical ingredient keys (App\Taste\IngredientMatch), and a name to show. */
final readonly class ExcludedIngredient
{
    /** @param non-empty-list<string> $keys */
    public function __construct(public array $keys, public Names $name, public Strength $strength)
    {
    }

    public static function fromArray(mixed $data): ?self
    {
        if (!\is_array($data)) {
            return null;
        }
        $keys = IngredientMatch::cleanKeys($data['keys'] ?? null);
        $name = Names::fromArray($data['name'] ?? null);
        $strength = Strength::read($data['strength'] ?? null);

        return [] === $keys || null === $name || null === $strength ? null : new self($keys, $name, $strength);
    }

    /** @return array{keys: list<string>, name: array{es: string, en: string}, strength: string} */
    public function toArray(): array
    {
        return ['keys' => $this->keys, 'name' => $this->name->toArray(), 'strength' => $this->strength->value];
    }
}
