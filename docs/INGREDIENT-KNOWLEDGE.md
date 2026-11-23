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

- [x] `RecipeIngredient.grams` (whole line, nullable) plus `gramsSource`: `manual` > `measured` > `estimated` > `converted`, most trusted first (`RecipeWeigher`)
- [x] Work it out: `measured` from g/kg/ml/l/spoons (volumes through the food's density, water's when unknown); `estimated` by the text model in the existing annotate step, which reads every line in context ("1 cebolla grande", "sal al gusto", "aceite para freír"); `converted` from the food's weight per piece, or a usual can (400 g), clove (5 g) or pinch (0.4 g) when there is no estimate yet. Weighed again when a recipe is saved or read, and when its foods are described or merged
- [x] Lines typed by hand: the same annotate call gives them a key, name, quantity and unit, so they now reach the food catalog and merge on the shopping list. Extraction's values are never overwritten
- [x] Existing recipes: `FACTS_VERSION` in the facts fingerprint was raised, so `app:annotate` re-reads them all once; `app:foods` weighs every recipe
- [x] Show it and allow edits: a "Weights" section on the recipe edit page lists each line's grams and where they come from; typing a number fixes it by hand, clearing it goes back to the worked-out one. Unchanged lines keep their weight through edits
- [x] Repointing a household ingredient at another food (`PATCH /api/ingredients/{id}`) weighs that household's recipes with it again (`IngredientProcessor`, phase 3)

### 3. Derived recipe properties

- [x] `Recipe.nutrition` (JSON): kcal, protein, fat, saturated fat, carbs, sugar, fiber and salt **per serving of the recipe's own servings**, plus `coverage` (share of the weighed grams whose food is described) and `unweighed` (lines with no weight). Optional lines are left out. Null under 50 % coverage, so a guess isn't shown as a figure (`App\Food\Nutrition`)
- [x] `Recipe.allergens` and `optionalAllergens`, from the lines' foods. Spice and diet stay as the annotate step reads them: it sees the dish in context with amounts ("a pinch of chili", "bacon, optional"), which a per-food flag can't. Food-derived allergens lean cautious ("may contain" in spice mixes and stock cubes counts)
- [x] Recompute: everything is added up whenever the lines are weighed (recipe saved or read, foods described or merged, an ingredient repointed, `servings` changed)
- [x] Backfill: `app:foods` weighs and adds up every recipe
- [x] UI: an allergen line under "contains" and a "Per serving" card under the ingredients on the recipe page, with how complete it is

### 4. Search and planning

- [x] Filters: "More filters" on the library: kcal per serving (up to 400/600/800), protein (20/30 g or more), spice (not spicy/spicy), and "without" any of the 14 allergens. Filtered in the browser like the other chips (the list carries `nutrition`, `allergens`, `allergensComplete`). Unknown never passes: no figures, no spice reading, or a needed line whose food isn't described (`Recipe.allergensComplete`) leaves the recipe out; "without" also checks the annotate step's food groups. Excluding an ingredient is a search word with a dash (`-chorizo`; it matches the start of words and drops a final "s"). Including one is the search as before
- [x] The planner and taste profile use the allergens as hard rules (`Taste::conflicts`, so the plan, swaps, the library's "fit you" and the taste page's counts all follow). An allergy to a food group ("never" gluten, dairy, egg, fish, shellfish, nuts, peanuts, soy, sesame) also goes by the foods' allergens, optional lines included (`FoodGroup::allergens()`): wheat in soy sauce, milk in cheese. A dislike ("avoid") still goes only by the dish as read. Allergies no food group says (celery, mustard, sulphites, lupin, molluscs) are a new rule, `excludeAllergens`, offered in the questionnaire and read from the free text; while a needed line's food isn't described the recipe counts as unknown, like an unread one. "No spicy on weekdays" needed nothing new: the notes already say when ("weekdays") and `maxSpice` covers the rest
- [ ] Substitutions table (food ↔ food) used by swaps and the shopping list
- [ ] "More like this": ingredient overlap in SQL first
- [ ] `pgvector` embeddings for free-text search ("something light with chickpeas"); needs a Postgres image with the extension

## Open questions

- ~~Should per-serving figures use the recipe's servings or the planned servings?~~ The recipe's: a serving's nutrition doesn't change with how many are planned, only the shopping amounts do (2026-11-23)
- ~~Should cooking losses be handled?~~ Ignored for v1: the weight estimates already count only the oil a fried food soaks up, the biggest one (2026-11-23)
- Should the nutrition source be licence-checked before importing BEDCA in bulk?
