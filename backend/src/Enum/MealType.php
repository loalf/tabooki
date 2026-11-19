<?php

declare(strict_types=1);

namespace App\Enum;

enum MealType: string
{
    case Lunch = 'lunch';
    case Dinner = 'dinner';
}
