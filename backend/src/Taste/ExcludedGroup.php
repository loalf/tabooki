<?php

declare(strict_types=1);

namespace App\Taste;

use App\Enum\FoodGroup;

/** "No fish", "we don't like cheese". */
final readonly class ExcludedGroup
{
    public function __construct(public FoodGroup $group, public Strength $strength)
    {
    }

    public static function fromArray(mixed $data): ?self
    {
        $group = \is_array($data) && \is_string($data['group'] ?? null) ? FoodGroup::tryFrom($data['group']) : null;
        $strength = \is_array($data) ? Strength::read($data['strength'] ?? null) : null;

        return null === $group || null === $strength ? null : new self($group, $strength);
    }

    /** @return array{group: string, strength: string} */
    public function toArray(): array
    {
        return ['group' => $this->group->value, 'strength' => $this->strength->value];
    }
}
