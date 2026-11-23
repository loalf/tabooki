<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\QueryParameter;
use App\State\RecipeSearchProvider;

/** A library recipe found by meaning (App\Search\RecipeSearch), and how close it is. */
#[ApiResource(
    shortName: 'RecipeSearchResult',
    operations: [new GetCollection(
        uriTemplate: '/recipe-search',
        paginationEnabled: false,
        description: 'Up to 12 of the household\'s recipes closest in meaning to the words ("algo ligero con garbanzos"); words after a dash leave recipes out',
        parameters: ['q' => new QueryParameter(schema: ['type' => 'string'], required: true, castToArray: false)],
    )],
    security: "is_granted('ROLE_USER')",
    provider: RecipeSearchProvider::class,
)]
final readonly class RecipeSearchResult
{
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $id,
        #[ApiProperty(description: 'Cosine similarity, -1 to 1; above about 0.3 it is usually about the same thing')]
        public float $score,
    ) {
    }
}
