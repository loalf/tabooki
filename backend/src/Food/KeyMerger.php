<?php

declare(strict_types=1);

namespace App\Food;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Turns one ingredient key into another everywhere keys are kept ("ajo" → "garlic",
 * "cherry_tomatoes" → "cherry_tomato"): recipe ingredients, the households' catalogs, checked
 * shopping lines, taste rules and the shared foods. Then "cebolla" and "onion" merge on the
 * shopping list and share one food.
 */
final readonly class KeyMerger
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private RecipeWeigher $weigher,
    ) {
    }

    /** @return array{recipeLines: int, ingredients: int, lists: int, tastes: int} what changed */
    public function merge(string $from, string $to): array
    {
        if ($from === $to || '' === $from || '' === $to) {
            return ['recipeLines' => 0, 'ingredients' => 0, 'lists' => 0, 'tastes' => 0];
        }
        $connection = $this->entityManager->getConnection();

        $changed = $connection->transactional(function (Connection $db) use ($from, $to): array {
            // The food: the target's when there is one, else this one renamed (its description holds).
            $old = $db->fetchOne('SELECT id FROM food WHERE key = ?', [$from]);
            $old = \is_string($old) ? $old : null;
            $target = $db->fetchOne('SELECT id FROM food WHERE key = ?', [$to]);
            $target = \is_string($target) ? $target : null;
            if (null === $target && null !== $old) {
                $db->executeStatement('UPDATE food SET key = ? WHERE id = ?', [$to, $old]);
                $target = $old;
            }

            $recipeLines = (int) $db->executeStatement('UPDATE recipe_ingredient SET canonical_key = ? WHERE canonical_key = ?', [$to, $from]);

            // A household that has both keeps the target's entry (and its fixes); one with only the old gets it renamed.
            $ingredients = (int) $db->executeStatement(
                'DELETE FROM ingredient old WHERE old.canonical_key = ? AND EXISTS (SELECT 1 FROM ingredient i WHERE i.household_id = old.household_id AND i.canonical_key = ?)',
                [$from, $to],
            );
            $ingredients += (int) $db->executeStatement('UPDATE ingredient SET canonical_key = ? WHERE canonical_key = ?', [$to, $from]);
            if (null !== $target) {
                // Pointed at the old food (by key or by hand), or at none: now at the target.
                $db->executeStatement(
                    'UPDATE ingredient SET food_id = ? WHERE food_id = ? OR (food_id IS NULL AND canonical_key = ?)',
                    [$target, $old ?? $target, $to],
                );
            }
            if (null !== $old && $old !== $target) {
                $db->executeStatement('DELETE FROM food WHERE id = ?', [$old]);
            }

            // Substitutes named by the old key: by the new one, unless the food has it already or is it.
            $db->executeStatement(
                'DELETE FROM food_substitution s USING food f WHERE f.id = s.food_id AND s.substitute_key = ? AND (f.key = ? OR EXISTS (SELECT 1 FROM food_substitution o WHERE o.food_id = s.food_id AND o.substitute_key = ?))',
                [$from, $to, $to],
            );
            $db->executeStatement('UPDATE food_substitution SET substitute_key = ? WHERE substitute_key = ?', [$to, $from]);

            $lists = (int) $db->executeStatement(
                'UPDATE shopping_list SET checked = (SELECT COALESCE(jsonb_agg(DISTINCT CASE WHEN v = ? THEN ? ELSE v END), \'[]\') FROM jsonb_array_elements_text(checked) v) WHERE jsonb_exists(checked, ?)',
                [$from, $to, $from],
            );

            return ['recipeLines' => $recipeLines, 'ingredients' => $ingredients, 'lists' => $lists, 'tastes' => $this->mergeInTastes($db, $from, $to)];
        });
        $this->entityManager->clear();
        // Another food, maybe another weight per piece.
        $this->weigher->weighRecipesWith([$to]);
        $this->entityManager->flush();

        return $changed;
    }

    /** Taste rules name ingredients by key: the old key becomes the new one, once. */
    private function mergeInTastes(Connection $db, string $from, string $to): int
    {
        $changed = 0;
        foreach ($db->fetchAllAssociative('SELECT id, rules FROM taste_profile WHERE rules::text LIKE ?', ['%"'.addcslashes($from, '%_\\').'"%']) as $row) {
            $rules = \is_string($row['rules']) ? json_decode($row['rules'], true) : null;
            if (!\is_array($rules) || !\is_array($rules['excludeIngredients'] ?? null)) {
                continue;
            }
            $touched = false;
            foreach ($rules['excludeIngredients'] as $i => $rule) {
                $keys = \is_array($rule) && \is_array($rule['keys'] ?? null) ? array_filter($rule['keys'], \is_string(...)) : [];
                if (\is_array($rule) && \in_array($from, $keys, true)) {
                    $rule['keys'] = array_values(array_unique(array_map(static fn (string $k): string => $k === $from ? $to : $k, $keys)));
                    $rules['excludeIngredients'][$i] = $rule;
                    $touched = true;
                }
            }
            if ($touched) {
                $db->executeStatement('UPDATE taste_profile SET rules = ? WHERE id = ?', [json_encode($rules, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE), $row['id']]);
                ++$changed;
            }
        }

        return $changed;
    }
}
