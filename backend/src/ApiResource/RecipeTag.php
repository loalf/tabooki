<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use App\State\RecipeTagProvider;

/** A tag used in the household's library and how many recipes carry it: the library's filter chips. */
#[ApiResource(
    shortName: 'RecipeTag',
    operations: [new GetCollection(uriTemplate: '/recipe-tags', paginationEnabled: false)],
    security: "is_granted('ROLE_USER')",
    provider: RecipeTagProvider::class,
)]
final readonly class RecipeTag
{
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $tag,
        public int $count,
    ) {
    }
}
