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
2. **Nutrition source.** BEDCA (the Spanish food composition database) fits the mostly Spanish recipes; USDA FoodData Central covers the gaps. For now the text model estimates the figures (`source = llm`), asked to follow BEDCA/USDA values: good enough for planning, and the import can replace them later.
3. **Amounts matter.** A pinch of pimentón doesn't make a stew spicy, one habanero does. Optional ingredients are left out of the totals or shown separately.

## Roadmap

### 1. Shared food catalog

- [x] `Food` entity, **not** household-scoped, one per canonical key: names (es/en), nutrition per 100g (kcal, protein, fat, saturated fat, carbs, sugar, fiber, salt), `source` (llm/manual/bedca/usda), `edited` so the model never overwrites a fix
- [x] Flags on `Food`: `spice` 0–2 (the same scale as `Recipe.spice`), `allergens` (the 14 EU ones, `Allergen` enum), `foodGroups` (the existing `FoodGroup` enum). Vegan/vegetarian/pescatarian come from the food groups through `Diet::of()`; gluten-free and milk-free come from the allergens, so they aren't stored separately
- [x] Unit conversions on `Food`: `gramsPerPiece` (1 onion), `density` (g/ml for spoons and cups). Defaults for a pinch or a splash move to phase 2, where grams are worked out
- [ ] Optional `parent_id` for an ingredient hierarchy (chipotle → chili). Deferred: each food carries its own flags, so nothing needs inheriting yet
- [x] `Ingredient.food` (nullable): set to the food with the same key when the household ingredient is created; `PATCH /api/ingredients/{id}` with `food` points it at another one
- [x] Linking: extraction already gives normalized English keys, so the link is 1:1 by key (`FoodCatalog`, on every extracted or edited recipe). Lines typed by hand (no key, `x_…` fallback keys) are left out until phase 2 parses them
- [x] Keys stay English and consistent: extraction is told `canonical_key` is English whatever the recipe language, and is given the catalog's keys (most used first, up to 400) to reuse. `app:foods:merge` has the text model review the catalog for Spanish, plural or reworded keys ("ajo" → "garlic", "cherry_tomatoes" → "cherry_tomato"), shows the merges and makes them once confirmed. `app:foods:merge <from> <to>` merges one pair by hand. A merge rewrites recipe lines, household ingredients, checked shopping lines, taste rules and foods
- [ ] UI to see a food and relink an ingredient (the API is there)
- [x] Seed from the library: `app:foods` creates the foods, links existing household ingredients, and queues `DescribeFoods`, which has the text model fill in each food in batches of 40 (step "foods" on the costs page)
- [ ] Import BEDCA/USDA figures over the model's estimates (`source` tells them apart; check BEDCA's licence first)

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
