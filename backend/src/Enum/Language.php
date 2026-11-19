<?php

declare(strict_types=1);

namespace App\Enum;

/** Language a recipe is written in. Recipes are kept in their original language. */
enum Language: string
{
    case Es = 'es';
    case En = 'en';
}
