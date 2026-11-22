<?php

declare(strict_types=1);

namespace App\Taste;

/** How firmly a household keeps something out of its menu. */
enum Strength: string
{
    /** An allergy, or something nobody at home can eat: counts even when optional, never relaxed. */
    case Never = 'never';
    /** Not liked: only when the dish really needs it, and the planner may give in when nothing else is left. */
    case Avoid = 'avoid';

    public static function read(mixed $value): ?self
    {
        return \is_string($value) ? self::tryFrom($value) : null;
    }
}
