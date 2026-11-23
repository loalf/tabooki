# Ingredient knowledge: nutrition, flags and smarter search

_Started 2026-11-23. Tick items off as they land._

## Goal

Work out recipe properties from the ingredients instead of guessing them per recipe:

- **Flags**: a recipe is spicy, vegan, gluten-free and so on because of what's in it.
- **Nutrition**: calories and the protein/fat/carbs/sugar split per serving, summed from each ingredient's grams × its value per 100g.
- **Search**: richer filters ("chickpeas, not spicy, under 600 kcal") and later "more like this".

Note: [PLAN.md](PLAN.md) lists *nutrition tracking* as a v1 non-goal. This is per-recipe information that helps with planning and search, not tracking what anyone eats.

## Decision: Postgres only, no graph database

Considered Neo4j next to Postgres and rejected it (2026-11-23):

- Every question so far only goes two steps (recipe → recipe ingredient → ingredient), so a join plus `GROUP BY` covers it in milliseconds at household scale.
- The few graph-shaped needs fit in Postgres: an ingredient hierarchy (`parent_id` + recursive CTE, or `ltree`), substitutions (a table of pairs), similarity (ingredient overlap or `pgvector`).
- A second database brings dual-write drift, about 1GB+ of RAM on the VPS, separate backups, no Doctrine support, and household scoping to rebuild.
- **Revisit if** a concrete question turns out to be painful in SQL. If Cypher (Neo4j's query language) is ever wanted, try the Apache AGE extension before a separate database.

## The hard part is the data

1. **Grams.** `RecipeIngredient.quantity`/`unit` are nullable and can be spoons, pieces or pinches. Nutrition needs grams, so each line gets an estimated weight.
2. **Nutrition source.** BEDCA (the Spanish food composition database) fits the mostly Spanish recipes; USDA FoodData Central covers the gaps.
3. **Amounts matter.** A pinch of pimentón doesn't make a stew spicy, one habanero does. Optional ingredients are left out of the totals or shown separately.

## Roadmap

### 1. Shared food catalog

- [ ] `Food` entity, **not** household-scoped: names (es/en), nutrition per 100g (kcal, protein, fat, saturated fat, carbs, sugar, fibre, salt), source and source id (BEDCA/USDA/LLM/manual)
- [ ] Flags on `Food`: spicy level, allergens (the 14 EU ones), vegan, vegetarian, gluten-free, lactose-free
- [ ] Unit conversions on `Food`: grams per piece (1 onion), density (g/ml for spoons and cups), with sensible defaults for a pinch or a splash
- [ ] Optional `parent_id` for an ingredient hierarchy (chipotle → chili) so flags are inherited
- [ ] `Ingredient.food` (nullable): links each household ingredient to a shared food
- [ ] Linking step: match `canonicalKey` → `Food` (exact or alias first, LLM suggestion second), with a way to fix wrong links by hand
- [ ] Seed or import: start with the ingredients already in the library, filled from BEDCA/USDA

### 2. Grams per recipe line

- [ ] `RecipeIngredient.grams` (nullable float) plus `gramsEstimated` (bool)
- [ ] Work it out from quantity + unit + the food's conversions; ask the LLM in the existing extraction pipeline for vague lines ("al gusto", "un chorrito")
- [ ] Show it and allow edits on the recipe review screen

### 3. Derived recipe properties

- [ ] Recipe-level cached columns: kcal and macros **per serving**, plus coverage (share of ingredient weight with known nutrition) so partial numbers aren't shown as exact
- [ ] Derived flags on `Recipe` (spicy, vegan, allergens…), with amount thresholds where they matter (spicy)
- [ ] Recompute whenever ingredients, servings or food links change (same pattern as `heaviness` / `food_groups`)
- [ ] Backfill command for existing recipes
- [ ] Show it in the UI: a nutrition summary on the recipe page, flag badges

### 4. Search and planning

- [ ] Filters: has/excludes ingredient, flags, kcal range, protein minimum
- [ ] The planner and taste profile use the flags ("no spicy on weekdays", allergens as hard rules)
- [ ] Substitutions table (food ↔ food) used by swaps and the shopping list
- [ ] "More like this": ingredient overlap in SQL first
- [ ] `pgvector` embeddings for free-text search ("something light with chickpeas"); needs a Postgres image with the extension

## Open questions

- Should per-serving figures use the recipe's servings or the planned servings (default 4)?
- Should cooking losses (oil absorbed when frying, water lost when roasting) be handled, or ignored for v1?
- Should the nutrition source be licence-checked before importing BEDCA in bulk?
