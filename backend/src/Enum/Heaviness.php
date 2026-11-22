<?php

declare(strict_types=1);

namespace App\Enum;

/** How filling a dish is: what "heavy lunches, light dinners" reads (docs/PLAN.md §6.2). */
enum Heaviness: string
{
    case Light = 'light';
    case Medium = 'medium';
    case Hearty = 'hearty';
}
