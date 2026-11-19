<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;

/** One line of a shopping list. */
final class ShoppingListViewItem
{
    /** Identifies the line for checking it off: the ingredient key, or "item:<id>". */
    public string $key = '';

    /** The catalog entry, to fix its name, aisle or staple flag (PATCH /api/ingredients/{id}). */
    #[ApiProperty(schema: ['type' => ['string', 'null'], 'format' => 'uuid'])]
    public ?string $ingredientId = null;

    /** In the viewer's language when known, else as the recipe has it. */
    public string $name = '';

    #[ApiProperty(schema: ['type' => 'string', 'enum' => ['produce', 'meat_fish', 'dairy_eggs', 'bakery', 'pantry', 'spices', 'frozen', 'drinks', 'other']])]
    public string $aisle = 'other';

    /** Usually at home already: shown apart, to check. */
    public bool $staple = false;

    /** Added by hand. */
    public bool $manual = false;

    /**
     * Rounded-up totals, one per kind of unit; empty when no recipe gives an amount.
     *
     * @var list<array{quantity: float, unit: string|null}>
     */
    #[ApiProperty(schema: [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'required' => ['quantity', 'unit'],
            'properties' => [
                'quantity' => ['type' => 'number'],
                'unit' => ['type' => ['string', 'null'], 'enum' => ['g', 'kg', 'ml', 'l', 'tsp', 'tbsp', 'pinch', 'clove', 'can', 'slice', 'bunch', 'sprig', 'piece', 'pack', null]],
            ],
        ],
    ])]
    public array $amounts = [];

    /** @var list<string> titles of the recipes that need it */
    public array $recipes = [];

    public bool $checked = false;
}
