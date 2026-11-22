<?php

declare(strict_types=1);

namespace App\Taste;

use App\Entity\Household;
use App\Enum\MealType;
use App\Planning\Candidate;
use App\Repository\RecipeRepository;

/**
 * What a set of rules does to the household's library: how many recipes each rule leaves out,
 * and how many are left for lunch and for dinner, so the review screen can warn when the menu
 * would repeat itself.
 */
final readonly class TasteImpact
{
    public function __construct(private RecipeRepository $recipes)
    {
    }

    public function of(Household $household, TasteRules $rules): TasteImpactView
    {
        $library = array_map(Candidate::fromRecipe(...), $this->recipes->libraryOf($household));
        $taste = new Taste($rules);
        $left = array_values(array_filter($library, static fn (Candidate $c): bool => [] === $taste->conflicts($c)));
        $for = static fn (MealType $slot): array => array_values(array_filter($left, static fn (Candidate $c): bool => $c->suits($slot)));
        $matching = static fn (MealType $slot): ?int => null === $rules->heavinessFor($slot)
            ? null
            : \count(array_filter($for($slot), static fn (Candidate $c): bool => 1 === $taste->heavinessFit($c, $slot)));
        $removes = static fn (TasteRules $only): int => \count(array_filter($library, static fn (Candidate $c): bool => [] !== (new Taste($only))->conflicts($c)));

        $view = new TasteImpactView();
        $view->library = \count($library);
        $view->unread = \count(array_filter($library, static fn (Candidate $c): bool => !$c->hasFacts));
        $view->allowed = \count($left);
        $view->lunch = \count($for(MealType::Lunch));
        $view->dinner = \count($for(MealType::Dinner));
        $view->lunchMatching = $matching(MealType::Lunch);
        $view->dinnerMatching = $matching(MealType::Dinner);
        $view->diet = null === $rules->diet ? null : $removes(new TasteRules(diet: $rules->diet));
        $view->excludeGroups = array_map(static fn (ExcludedGroup $rule): int => $removes(new TasteRules(excludeGroups: [$rule])), $rules->excludeGroups);
        $view->excludeIngredients = array_map(static fn (ExcludedIngredient $rule): int => $removes(new TasteRules(excludeIngredients: [$rule])), $rules->excludeIngredients);

        return $view;
    }
}
