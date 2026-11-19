<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\QueryParameter;
use App\Dto\ShoppingCheckInput;
use App\Dto\ShoppingItemInput;
use App\State\ShoppingListProcessor;
use App\State\ShoppingListProvider;

/**
 * A week's shopping list (see App\Shopping\ShoppingListBuilder). Every action answers with the
 * whole list. Lines are identified by `key`: the ingredient key, or "item:<id>" for items
 * added by hand.
 */
#[ApiResource(
    shortName: 'ShoppingList',
    operations: [
        new Get(
            uriTemplate: '/shopping-lists/{week}',
            parameters: ['from' => new QueryParameter(schema: ['type' => 'string', 'format' => 'date'], description: 'Only meals from this day on (what\'s left of the week)', castToArray: false)],
            provider: ShoppingListProvider::class,
        ),
        new Post(
            uriTemplate: '/shopping-lists/{week}/check',
            status: 200,
            description: 'Check a line off, or uncheck it.',
            input: ShoppingCheckInput::class,
            read: false,
            processor: ShoppingListProcessor::class,
            name: 'shopping_list_check',
            parameters: ['from' => new QueryParameter(schema: ['type' => 'string', 'format' => 'date'], description: 'Only meals from this day on (what\'s left of the week)', castToArray: false)],
        ),
        new Post(
            uriTemplate: '/shopping-lists/{week}/items',
            status: 200,
            description: 'Add something by hand.',
            input: ShoppingItemInput::class,
            read: false,
            processor: ShoppingListProcessor::class,
            name: 'shopping_list_add_item',
            parameters: ['from' => new QueryParameter(schema: ['type' => 'string', 'format' => 'date'], description: 'Only meals from this day on (what\'s left of the week)', castToArray: false)],
        ),
        new Post(
            uriTemplate: '/shopping-lists/{week}/items/remove',
            status: 200,
            description: 'Remove an item added by hand.',
            input: ShoppingCheckInput::class,
            read: false,
            processor: ShoppingListProcessor::class,
            name: 'shopping_list_remove_item',
            parameters: ['from' => new QueryParameter(schema: ['type' => 'string', 'format' => 'date'], description: 'Only meals from this day on (what\'s left of the week)', castToArray: false)],
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
final class ShoppingListView
{
    /** Monday of the week, YYYY-MM-DD. */
    #[ApiProperty(identifier: true, schema: ['type' => 'string', 'format' => 'date'])]
    public string $week = '';

    /** Whether the week has a meal plan. */
    public bool $planned = false;

    /** Planned meals counted (with a recipe, from `from` on). */
    public int $meals = 0;

    #[ApiProperty(schema: ['type' => ['string', 'null'], 'format' => 'date'])]
    public ?string $from = null;

    /** Some ingredients are still being named and sorted into aisles (refresh in a moment). */
    public bool $classifying = false;

    /** @var list<ShoppingListViewItem> by aisle, then name; items added by hand last */
    public array $items = [];
}
