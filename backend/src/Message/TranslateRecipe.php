<?php

declare(strict_types=1);

namespace App\Message;

/** Write the recipe in the other language (App\Translation\RecipeTranslator). */
final readonly class TranslateRecipe
{
    public function __construct(public string $recipeId)
    {
    }
}
