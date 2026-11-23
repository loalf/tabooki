<?php

declare(strict_types=1);

namespace App\Search;

use App\Entity\Recipe;
use App\Enum\Diet;
use App\Enum\Heaviness;
use App\Enum\Language;
use App\Enum\MealType;
use App\Translation\RecipeText;

/**
 * What a recipe is, in a few lines and in both languages, for its embedding: its title, tags and
 * ingredients (not the steps: how to cook it says little about what it is), and what is known about
 * it said in words, so "algo ligero" or "quick vegetarian dinner" find it.
 */
final class RecipeDigest
{
    public static function of(Recipe $recipe): string
    {
        $es = $recipe->textIn(Language::Es);
        $en = $recipe->textIn(Language::En);
        $names = static function (RecipeText $text): string {
            $names = [];
            foreach ($text->ingredients as $line) {
                $names[$line['name'] ?? $line['rawText']] = true;
            }

            return implode(', ', array_keys($names));
        };
        $both = static fn (string $a, string $b): string => $a === $b ? $a : $a.' / '.$b;

        $lines = [
            $both($es->title, $en->title),
            $both(implode(', ', $es->tags), implode(', ', $en->tags)),
            $both($names($es), $names($en)),
        ];

        $facts = [];
        $facts[] = match ($recipe->getHeaviness()) {
            Heaviness::Light => 'Ligera / light',
            Heaviness::Medium => null,
            Heaviness::Hearty => 'Contundente, llena / hearty, filling',
            null => null,
        };
        $facts[] = match ($recipe->getDiet()) {
            Diet::Vegan => 'Vegana / vegan',
            Diet::Vegetarian => 'Vegetariana / vegetarian',
            Diet::Pescatarian => 'Con pescado, sin carne / fish, no meat',
            null => null,
        };
        $facts[] = match ($recipe->getSpice()) {
            0 => 'Nada picante / not spicy',
            1 => 'Algo picante / a little spicy',
            2 => 'Picante / spicy, hot',
            default => null,
        };
        $minutes = $recipe->getTotalMinutes();
        if (null !== $minutes) {
            $facts[] = $minutes <= 30 ? \sprintf('Rápida, %d min / quick, %d min', $minutes, $minutes) : \sprintf('%d min', $minutes);
        }
        $meals = array_map(static fn (MealType $m): string => MealType::Lunch === $m ? 'comida / lunch' : 'cena / dinner', $recipe->getMealTypes());
        if ([] !== $meals) {
            $facts[] = implode(', ', $meals);
        }
        $kcal = $recipe->getNutrition()['kcal'] ?? null;
        if (\is_int($kcal) || \is_float($kcal)) {
            $facts[] = \sprintf('%d kcal por ración / per serving', (int) $kcal);
        }

        return implode("\n", array_filter([...$lines, implode('. ', array_filter($facts))], static fn (string $l): bool => '' !== trim($l, ' /')));
    }
}
