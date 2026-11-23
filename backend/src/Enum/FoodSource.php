<?php

declare(strict_types=1);

namespace App\Enum;

/** Where a food's nutrition figures come from, so better sources can replace weaker ones. */
enum FoodSource: string
{
    /** Estimated by the text model: good for planning, not lab-exact. */
    case Llm = 'llm';
    /** Typed in by hand. */
    case Manual = 'manual';
    /** The Spanish food composition database (bedca.net). */
    case Bedca = 'bedca';
    /** USDA FoodData Central. */
    case Usda = 'usda';
}
