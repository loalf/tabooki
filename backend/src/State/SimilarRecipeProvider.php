<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\SimilarRecipe;
use App\Library\Similarity;
use App\Planning\Candidate;
use App\Repository\RecipeRepository;
use App\Security\CurrentHousehold;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** @implements ProviderInterface<SimilarRecipe> */
final readonly class SimilarRecipeProvider implements ProviderInterface
{
    public function __construct(
        private CurrentHousehold $current,
        private RecipeRepository $recipes,
    ) {
    }

    /** @return list<SimilarRecipe> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $library = array_map(Candidate::fromRecipe(...), $this->recipes->libraryOf($this->current->household()));
        $id = $uriVariables['id'] ?? null;
        $id = $id instanceof \Stringable ? (string) $id : $id;
        $recipe = array_values(array_filter($library, static fn (Candidate $c): bool => $c->id === $id))[0] ?? null;
        if (null === $recipe) {
            throw new NotFoundHttpException();
        }

        return array_map(static function (array $found) use ($recipe): SimilarRecipe {
            $other = $found['recipe'];
            $names = array_map(
                static fn (string $key): array => $recipe->freshNames[$key] ?? $other->freshNames[$key] ?? ['es' => str_replace('_', ' ', $key), 'en' => str_replace('_', ' ', $key)],
                $found['shared'],
            );

            return new SimilarRecipe(
                $other->id,
                $other->recipe->getTitle(),
                $other->recipe->getSource()?->getThumbnail(),
                $other->minutes,
                $other->favorite,
                $names,
                $found['score'],
            );
        }, Similarity::to($recipe, $library));
    }
}
