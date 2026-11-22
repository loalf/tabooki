<?php

declare(strict_types=1);

namespace App\Taste;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Dto\TasteSuggestionInput;
use App\State\TasteSuggestionProcessor;
use App\State\TasteSuggestionProvider;

/**
 * Something the household's swaps say it could add to its likings (SwapLearning): "you swapped
 * out three dishes with cheese: rather without cheese?". Taking one changes the profile;
 * turning it down hides it for good.
 */
#[ApiResource(
    shortName: 'TasteSuggestion',
    operations: [
        new GetCollection(uriTemplate: '/taste-suggestions', paginationEnabled: false, provider: TasteSuggestionProvider::class),
        new Post(
            uriTemplate: '/taste-suggestions/accept',
            status: 204,
            description: 'Add the suggestion to the household\'s likings.',
            input: TasteSuggestionInput::class,
            output: false,
            read: false,
            processor: TasteSuggestionProcessor::class,
            name: 'taste_suggestion_accept',
        ),
        new Post(
            uriTemplate: '/taste-suggestions/dismiss',
            status: 204,
            description: 'Don\'t offer the suggestion again.',
            input: TasteSuggestionInput::class,
            output: false,
            read: false,
            processor: TasteSuggestionProcessor::class,
            name: 'taste_suggestion_dismiss',
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
final class TasteSuggestion
{
    /** Identifies it, to take it or turn it down: "group:cheese", "ingredient:mushroom", "heaviness:dinner", "long". */
    #[ApiProperty(identifier: true)]
    public string $key = '';

    #[ApiProperty(schema: ['type' => 'string', 'enum' => ['excludeGroup', 'excludeIngredient', 'mealHeaviness', 'note']])]
    public string $kind = '';

    /** The food group, for excludeGroup. */
    public ?string $group = null;

    /** @var array{es: string, en: string}|null the ingredient, for excludeIngredient */
    #[ApiProperty(schema: ['type' => ['object', 'null'], 'required' => ['es', 'en'], 'properties' => ['es' => ['type' => 'string'], 'en' => ['type' => 'string']]])]
    public ?array $name = null;

    /** lunch or dinner, for mealHeaviness. */
    public ?string $slot = null;

    /** light or hearty, for mealHeaviness. */
    public ?string $heaviness = null;

    /** @var array{es: string, en: string}|null the note to add, for note */
    #[ApiProperty(schema: ['type' => ['object', 'null'], 'required' => ['es', 'en'], 'properties' => ['es' => ['type' => 'string'], 'en' => ['type' => 'string']]])]
    public ?array $note = null;

    /** How many swaps (or swapped dishes) say so. */
    public int $count = 0;

    /** @var list<string> some of the dishes swapped out */
    public array $examples = [];

    /** The ingredient's key, for excludeIngredient: kept on the server. */
    #[ApiProperty(readable: false, writable: false)]
    public ?string $ingredientKey = null;
}
