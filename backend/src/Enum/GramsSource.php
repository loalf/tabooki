<?php

declare(strict_types=1);

namespace App\Enum;

/** Where an ingredient line's weight comes from, most trusted first (App\Food\RecipeWeigher). */
enum GramsSource: string
{
    /** Typed in by hand: never recomputed. */
    case Manual = 'manual';
    /** From an amount in g, kg, ml, l or spoons (volumes through the food's density). */
    case Measured = 'measured';
    /** The text model's estimate for the line as written ("1 cebolla grande", "sal al gusto"). */
    case Estimated = 'estimated';
    /** From the food's weight per piece, or a usual weight (a can, a clove, a pinch). */
    case Converted = 'converted';
}
