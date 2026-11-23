<?php

declare(strict_types=1);

namespace App\Food;

use App\Entity\Food;
use App\Entity\Ingredient;
use App\Entity\Recipe;
use App\Entity\RecipeIngredient;
use App\Enum\GramsSource;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Works out what each ingredient line of a recipe weighs. Most trusted first: a weight typed in
 * by hand, a measured amount (g, ml, spoons), the text model's estimate for the line, then a
 * count times the food's weight per piece. Run when a recipe is saved or read, and when its
 * foods are described or merged. Doesn't flush.
 */
final readonly class RecipeWeigher
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /** @param array<int, float|null> $estimates the model's grams by line position, when it has just read the recipe */
    public function weigh(Recipe $recipe, array $estimates = []): void
    {
        $foods = $this->foodsFor($recipe);
        foreach ($recipe->getIngredients() as $line) {
            [$grams, $source] = self::weightOf($line, $foods[$line->getCanonicalKey() ?? ''] ?? null, $estimates[$line->getPosition()] ?? null);
            $line->weigh($grams, $source);
        }
    }

    /**
     * After foods were described or merged: the recipes using them weighed again.
     *
     * @param list<string> $keys
     */
    public function weighRecipesWith(array $keys): int
    {
        if ([] === $keys) {
            return 0;
        }
        /** @var list<Recipe> $recipes */
        $recipes = $this->entityManager->createQueryBuilder()
            ->select('r', 'i')->from(Recipe::class, 'r')->join('r.ingredients', 'i')
            ->where('r.id IN (SELECT IDENTITY(ri.recipe) FROM '.RecipeIngredient::class.' ri WHERE ri.canonicalKey IN (:keys))')
            ->setParameter('keys', $keys)
            ->getQuery()->getResult();
        foreach ($recipes as $recipe) {
            $this->weigh($recipe);
        }

        return \count($recipes);
    }

    /** @return array{float|null, GramsSource|null} */
    private static function weightOf(RecipeIngredient $line, ?Food $food, ?float $estimate): array
    {
        $kept = $line->getGrams();
        $source = $line->getGramsSource();
        // Typed in: the app sends no source, or "manual", with it.
        if (null !== $kept && (null === $source || GramsSource::Manual === $source)) {
            return [$kept, GramsSource::Manual];
        }
        if (null !== $grams = Grams::measured($line->getQuantity(), $line->getUnit(), $food)) {
            return [$grams, GramsSource::Measured];
        }
        if (null !== $estimate && $estimate >= 0) {
            return [$estimate, GramsSource::Estimated];
        }
        if (null !== $kept && GramsSource::Estimated === $source) {
            return [$kept, GramsSource::Estimated];
        }
        if (null !== $grams = Grams::converted($line->getQuantity(), $line->getUnit(), $food)) {
            return [$grams, GramsSource::Converted];
        }

        return [null, null];
    }

    /**
     * The food of each line: the one the household pointed the ingredient at, else the shared one with its key.
     *
     * @return array<string, Food>
     */
    private function foodsFor(Recipe $recipe): array
    {
        $keys = array_values(array_unique(array_filter(array_map(static fn (RecipeIngredient $i): ?string => $i->getCanonicalKey(), $recipe->getIngredients()))));
        if ([] === $keys) {
            return [];
        }
        $foods = [];
        foreach ($this->entityManager->getRepository(Food::class)->findBy(['key' => $keys]) as $food) {
            $foods[$food->getKey()] = $food;
        }
        /** @var list<Ingredient> $ingredients */
        $ingredients = $this->entityManager->getRepository(Ingredient::class)->findBy(['household' => $recipe->getHousehold(), 'canonicalKey' => $keys]);
        foreach ($ingredients as $ingredient) {
            if (null !== $ingredient->getFood()) {
                $foods[$ingredient->getCanonicalKey()] = $ingredient->getFood();
            }
        }

        return $foods;
    }
}
