<?php

declare(strict_types=1);

namespace App\Taste;

use App\Entity\Household;
use App\Enum\Language;
use App\Repository\RecipeRepository;

/**
 * The words the household's recipes use, so the text model maps "cilantro" or "more legumes"
 * onto ingredient keys and tags that exist (TasteCompiler).
 */
final readonly class TasteVocabulary
{
    public function __construct(private RecipeRepository $recipes)
    {
    }

    /** @return array{array<string, string>, array<string, int>} ingredient keys with a name, English tags with counts */
    public function of(Household $household): array
    {
        $ingredients = [];
        $tags = [];
        foreach ($this->recipes->libraryOf($household) as $recipe) {
            foreach ($recipe->getIngredients() as $ingredient) {
                $key = $ingredient->getCanonicalKey();
                if (null !== $key && '' !== $key && !isset($ingredients[$key])) {
                    $ingredients[$key] = $ingredient->getName() ?? $ingredient->getRawText();
                }
            }
            foreach ($recipe->tagsIn(Language::En) as $tag) {
                $tags[$tag] = ($tags[$tag] ?? 0) + 1;
            }
        }
        ksort($ingredients);
        arsort($tags);

        return [\array_slice($ingredients, 0, 600, true), \array_slice($tags, 0, 150, true)];
    }
}
