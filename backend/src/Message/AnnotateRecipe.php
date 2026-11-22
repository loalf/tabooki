<?php

declare(strict_types=1);

namespace App\Message;

/** Read how filling and spicy a recipe is and what it contains (App\Planning\RecipeAnnotator). */
final readonly class AnnotateRecipe
{
    public function __construct(public string $recipeId)
    {
    }
}
