<?php

declare(strict_types=1);

namespace App\Taste;

/** See TasteImpact. */
final class TasteImpactView
{
    /** Recipes in the library. */
    public int $library = 0;

    /** Whose facts haven't been read yet: they fit no rule that needs them. */
    public int $unread = 0;

    /** Recipes no rule leaves out. */
    public int $allowed = 0;

    /** Of those, the ones for lunch. */
    public int $lunch = 0;

    /** Of those, the ones for dinner. */
    public int $dinner = 0;

    /** Lunches as filling as the household wants them; null when it didn't say. */
    public ?int $lunchMatching = null;

    /** Dinners as filling as the household wants them; null when it didn't say. */
    public ?int $dinnerMatching = null;

    /** Recipes the diet leaves out; null without one. */
    public ?int $diet = null;

    /** @var list<int> recipes each excludeGroups rule leaves out, in order */
    public array $excludeGroups = [];

    /** @var list<int> recipes each excludeIngredients rule leaves out, in order */
    public array $excludeIngredients = [];
}
