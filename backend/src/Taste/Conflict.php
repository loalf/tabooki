<?php

declare(strict_types=1);

namespace App\Taste;

/**
 * Why a recipe doesn't fit a household's likings: its diet, a food group, an ingredient, or
 * that nobody knows yet what it contains (its facts haven't been read) and an allergy or diet
 * needs to know.
 */
final readonly class Conflict
{
    public const string DIET = 'diet';
    public const string GROUP = 'group';
    public const string INGREDIENT = 'ingredient';
    public const string UNKNOWN = 'unknown';

    /** For OpenAPI. */
    public const array API_SCHEMA = [
        'type' => 'object',
        'required' => ['strength', 'kind'],
        'properties' => [
            'strength' => ['type' => 'string', 'enum' => ['never', 'avoid']],
            'kind' => ['type' => 'string', 'enum' => [self::DIET, self::GROUP, self::INGREDIENT, self::UNKNOWN]],
            'value' => ['type' => 'string', 'description' => 'The diet or the food group'],
            'name' => Names::API_SCHEMA,
        ],
    ];

    public function __construct(
        public Strength $strength,
        public string $kind,
        public ?string $value = null,
        public ?Names $name = null,
    ) {
    }

    /** @return array{strength: string, kind: string, value?: string, name?: array{es: string, en: string}} */
    public function toArray(): array
    {
        return ['strength' => $this->strength->value, 'kind' => $this->kind]
            + (null === $this->value ? [] : ['value' => $this->value])
            + (null === $this->name ? [] : ['name' => $this->name->toArray()]);
    }
}
