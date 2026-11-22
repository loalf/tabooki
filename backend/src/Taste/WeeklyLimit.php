<?php

declare(strict_types=1);

namespace App\Taste;

use App\Enum\FoodGroup;

/**
 * "Meat or fish twice a week at most", "fish at least twice": meals of the week (a leftover
 * lunch is a meal too) whose dish needs any of these groups.
 */
final readonly class WeeklyLimit
{
    public const int MAX_MEALS = 14;

    /** @param non-empty-list<FoodGroup> $groups */
    public function __construct(public array $groups, public ?int $max, public ?int $min)
    {
    }

    public static function fromArray(mixed $data): ?self
    {
        if (!\is_array($data)) {
            return null;
        }
        $groups = FoodGroup::listFrom(\is_array($data['groups'] ?? null) ? $data['groups'] : []);
        $count = static fn (mixed $value): ?int => \is_int($value) && $value >= 0 && $value <= self::MAX_MEALS ? $value : null;
        $max = $count($data['max'] ?? null);
        $min = $count($data['min'] ?? null);
        if (null !== $max && null !== $min && $min > $max) {
            $min = $max;
        }
        if (0 === $min) {
            $min = null;
        }

        return [] === $groups || (null === $max && null === $min) ? null : new self($groups, $max, $min);
    }

    /** @return array{groups: list<string>, max: ?int, min: ?int} */
    public function toArray(): array
    {
        return ['groups' => array_map(static fn (FoodGroup $g): string => $g->value, $this->groups), 'max' => $this->max, 'min' => $this->min];
    }
}
