<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use App\Entity\Recipe;
use App\State\SimilarRecipeProvider;
use App\Taste\Names;

/** A library recipe like another one ("more like this", App\Library\Similarity), and what they share. */
#[ApiResource(
    shortName: 'SimilarRecipe',
    operations: [new GetCollection(
        uriTemplate: '/recipes/{id}/similar',
        uriVariables: ['id' => new Link(fromClass: Recipe::class)],
        paginationEnabled: false,
        description: 'Up to 6 recipes of the library most like this one, by the ingredients they share (rare ones counting more) and their tags',
    )],
    security: "is_granted('ROLE_USER')",
    provider: SimilarRecipeProvider::class,
)]
final readonly class SimilarRecipe
{
    /** @param list<array{es: string, en: string}> $shared the ingredients both need, rarest first */
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $id,
        public string $title,
        public ?string $thumbnail,
        public ?int $totalMinutes,
        public bool $favorite,
        #[ApiProperty(schema: ['type' => 'array', 'items' => Names::API_SCHEMA])]
        public array $shared,
        #[ApiProperty(description: 'How alike, 0 to 1')]
        public float $score,
    ) {
    }
}
