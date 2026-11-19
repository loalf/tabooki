<?php

declare(strict_types=1);

namespace App\Enum;

/** Where an ingredient is in the shop, in the order a shopping list shows them. */
enum Aisle: string
{
    case Produce = 'produce';
    case MeatFish = 'meat_fish';
    case DairyEggs = 'dairy_eggs';
    case Bakery = 'bakery';
    case Pantry = 'pantry';
    case Spices = 'spices';
    case Frozen = 'frozen';
    case Drinks = 'drinks';
    case Other = 'other';

    public function order(): int
    {
        return (int) array_search($this, self::cases(), true);
    }
}
