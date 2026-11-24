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
- [x] See a food and point an ingredient at another: Settings → Ingredients lists every ingredient the household's recipes use (`GET /api/ingredients`, with how many recipes use each, its food, kcal and allergens, "no data" while undescribed, "another food" when repointed); one opens its food (per 100 g, allergens, groups, spice, weight of a piece and a tablespoon, substitutes, where the figures come from) and the recipes that use it, and "Is it another food?" searches the shared foods (`GET /api/foods?q=`, accent-insensitive, most used first) to point it elsewhere, or back to its own. The shopping list's fix menu links to it. Household entries are now made when a recipe is saved (`IngredientCatalog::recipeChanged`), not only when a shopping list needs them; `app:foods` adds the missing ones. Correcting a food by hand: "Correct" on its card edits the figures per 100 g, allergens, spice and the weight of a piece and of a tablespoon (`POST /api/foods/{id}/correction`, all at once; sugar can't exceed carbohydrates nor saturated fat the fat). Foods are shared and Tabooki has no public sign-up, so anyone signed in may (decided 2026-11-23); the fix says who made it and when, the model never overwrites it, and every recipe with the food (by key, or pointed at it) is worked out again. "Back to the estimate" (`POST /api/foods/{id}/estimate`) has the model describe it again
- [x] Seed from the library: `app:foods` creates the foods, links existing household ingredients, and queues `DescribeFoods`, which has the text model fill in each food in batches of 40 (step "foods" on the costs page)
- [x] Import BEDCA's figures over the model's estimates: `app:foods:bedca` (licence: see "Data licences"). The text model pairs each food with the BEDCA food in the same state, answering with BEDCA's exact Spanish name (looked up here: a copied-wrong id would be another food), 30 foods a call (step "bedca"). Pairs are saved under `var/tables/bedca/pairs.json` with BEDCA's answers (never in git; `var/tables` is the `food_tables` volume, so they outlive deploys), so what a dry run showed is what the import uses; `--pair key=id` sets one by hand (0: none), `--rematch` asks again, `--skip` leaves foods out. The table shows each pair and its kcal now and in BEDCA; the import happens once confirmed (or `--apply`). Left out: foods fixed by hand; BEDCA entries whose energy contradicts their own nutrients ("Garbanzo, hervido": 359 kcal from nutrients worth 160), since BEDCA's data can't be altered; and pairs far from the estimate (over 1.5× and 40 kcal apart), which are imported only when named. Energy comes from kJ, salt from sodium; a nutrient BEDCA doesn't give (often sugars) stays estimated, and the food's card says which, with the citation. Allergens, groups, spice and weights stay the model's. On the local catalog (2026-11-23): 65 of 104 foods from BEDCA; "cooking cream" went from an estimated 70 to 201 kcal
- [x] USDA FoodData Central for what BEDCA lacks: `app:foods:usda`, for foods still estimated (not BEDCA's, not fixed by hand). Each is searched by its key in words (by POST: in a URL the HTTP client sends the parentheses of "Survey (FNDDS)" unencoded and USDA refuses them), raw ingredients (Foundation, SR Legacy, 8 results) and prepared foods (FNDDS, 4) as separate lists so dishes don't crowd out ingredients, and products' labels (Branded: gochujang, halloumi) only when both have nothing; a refused search stops the run rather than counting as "nothing found". The text model picks from each food's own results by letter (step "usda"): copying descriptions out failed on a whole batch Same review, hold-backs, `--pair key=fdcId`, `--skip`, saved pairs (`var/tables/usda`) as BEDCA, through the shared `App\Food\TableImport`. USDA's carbohydrate "by difference" counts the fibre, so it's taken off (Tabooki's carbs are without it); the card says so, cites the dataset, and lists what's still estimated. Needs `USDA_API_KEY`, a free data.gov key (https://api.data.gov/signup/): `DEMO_KEY` allows only about 10 requests an hour

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
- [x] Substitutions: `food_substitution`, shared like the foods, one way (what replaces butter isn't what butter replaces): up to 3 per food, best first, with a ratio (grams of it per gram of the food) and a short note ("más ácido"). The substitute is a key plus names, so it needn't be in the catalog. The text model fills it in (`SuggestSubstitutes`, step "substitutes", 40 foods a call, not for staples) once the foods are described, and `app:foods` queues it for the rest; `food.substitutes_at` says it was asked. Merges rename substitute keys. A household's ingredient pointed at another food brings that food's substitutes. Shown on the recipe page (a ⇄ on a line opens them, with the ratio when it isn't 1:1) and on the shopping list ("If they're out: …"). Chosen by hand on the food's card (Settings → Ingredients): up to 3, best first, from the shared foods or typed (a key made from the name), each with its ratio and a note (`POST /api/foods/{id}/substitutes`, all at once; none is an answer too). Like a fix of the figures, anyone signed in may, it says who and when, and the model isn't asked again; "Back to the suggested ones" (`POST /api/foods/{id}/substitutes/suggest`) has it suggest them again. A note changed by hand is shown in both languages. Not yet: the planner keeping a disliked-ingredient recipe because a substitute exists
- [x] "More like this": `GET /api/recipes/{id}/similar`, a "Similar" row at the end of the recipe page with what each shares. `App\Library\Similarity` compares the non-staple ingredients by cosine, each weighted by its share of the dish's grams and its rarity in the library (two chickpea stews are alike, two dishes with a pinch of paprika aren't), plus a quarter for shared main tags; under 0.15 it's left out. Variants of one ingredient count as one ("chickpeas_canned", "chickpeas_cooked"). In PHP over the loaded library rather than SQL, like the planner: household scale, and the weights are easier to tune
- [x] Search by meaning with `pgvector`: "algo ligero con garbanzos" finds the chickpea salad. Postgres is now built from `deploy/postgres/` (17 alpine plus pgvector, so the C library and text sorting stay those of the existing data). Each recipe is embedded from a short bilingual digest (`App\Search\RecipeDigest`: title, tags, ingredient names, and its facts in words: light, vegetarian, not spicy, quick, kcal) in `recipe_embedding`, in the background whenever the recipe changes and only when the digest does (`EmbedRecipe`, step "embed"); `app:embed` queues them all. `GET /api/recipe-search?q=` embeds the words (cached a day, step "search") and compares them with the household's recipes, exactly, no index; dashed words leave recipes out as in the library search. Only matches above 0.35 and within 0.12 of the best come back. The library shows them under "Close to what you're after", below the word matches or instead of "no matches", with the other filters applied. Model: Cohere Embed v4 through Bedrock's EU profile (`BEDROCK_EMBEDDING_MODEL`), which tells queries and documents apart; Titan Text Embeddings V2 was tried first and found less (seafood, melted cheese, "arroz"). The thresholds are that model's own

## Open questions

- ~~Should per-serving figures use the recipe's servings or the planned servings?~~ The recipe's: a serving's nutrition doesn't change with how many are planned, only the shopping amounts do (2026-11-23)
- ~~Should cooking losses be handled?~~ Ignored for v1: the weight estimates already count only the oil a fried food soaks up, the biggest one (2026-11-23)
- ~~Should the nutrition source be licence-checked before importing BEDCA in bulk?~~ Checked (2026-11-23), see "Data licences" below.

## Data licences

Checked 2026-11-23. Not legal advice; re-check if Tabooki's use changes.

**BEDCA** ([Condiciones de uso](https://www.bedca.net/bdpub/UsoBD.pdf), AESAN/BEDCA v1.0, 2010). No open licence (AESAN's open-data page lists it as a database it doesn't manage; a [datos.gob.es request](https://datos.gob.es/es/solicitud-de-datos/base-de-datos-bedca) complains its reuse terms are unclear). What the conditions say:

- Personal, educational or non-commercial use is free; any other use of the data in electronic form ("reproducción, traducción o cualquier uso que no sea personal, educacional o no comercial") needs AESAN/BEDCA's express authorization.
- Cite the source: "AESAN/BEDCA Base de Datos Española de Composición de Alimentos v1.0 (2010)".
- Don't modify the data or alter its original meaning.
- AESAN/BEDCA take no responsibility for the data or its use, software included.

Tabooki is self-hosted for one household and non-commercial, so an import fits, on these terms:

1. Show the citation wherever a food's figures come from BEDCA (`source = bedca`).
2. Store BEDCA's values as given (rounding aside). Salt is worked out from BEDCA's sodium (× 2.5), and says so.
3. Don't commit BEDCA's data to git: the import fetches it.
4. If Tabooki ever goes beyond personal, non-commercial use (paid, public sign-up), ask AESAN/BEDCA first (bedca.adm@gmail.com).

BEDCA has no bulk download or official API, only its web query interface; the conditions say nothing about automated queries, so the import asks politely (one food at a time, cached).

**USDA FoodData Central**: public domain, [CC0 1.0](https://fdc.nal.usda.gov/index.html). Credit is requested ("FoodData Central" as the source), not required. Proper [API](https://fdc.nal.usda.gov/api-guide.html) (free data.gov key) and downloads. Fills what BEDCA lacks.

