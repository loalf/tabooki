# Tabooki — Product & Architecture Plan

_Draft v0.4 — 2026-11-15 (all open questions answered; ready for M0)_

## 1. What we're building

A personal/household meal planner. The household keeps a **library** of recipe links from anywhere (blogs, YouTube, Instagram, TikTok…). Tabooki turns each link into a structured recipe, suggests a **weekly meal plan** from the library, and produces a merged, aisle-grouped **shopping list**.

**Decisions so far**

| Topic | Decision |
|---|---|
| Audience | Just my household (multi-user within one household, no public sign-up) |
| Platform | Mobile-first web app, installable as a PWA |
| Hosting | **Fully self-hosted** (Docker Compose) |
| Backend | **PHP + Symfony** (API) |
| Frontend | **React** SPA (Vite + TypeScript) |
| Extraction | LLM-based, provider-agnostic. **Amazon Bedrock** (API key) is the provider since M2 (2026-11-19): a text model for captions, transcripts and pages, and Amazon Nova for videos. Gemini could be added behind the same interface. The app is self-hosted, the models stay cloud APIs |
| Phones | **iPhone and Android**: Web Share Target on Android, Apple Shortcut on iOS |
| Languages | **Spanish and English**: bilingual UI, every recipe in both languages (each reader sees theirs), one shared ingredient catalog |
| Meals | **Lunch and dinner**, default **4 servings** (adjustable per meal) |
| Units | **Metric everywhere** (g, ml, °C), with spoons, pinches and countable items kept as they are, original text preserved (see 4.3) |
| Environments | **Local development** on macOS (Apple Silicon) with Docker Compose; **production on a Contabo VPS**, reached only through **Tailscale** |

**Non-goals for v1:** public accounts, social features, nutrition tracking (logging what each person eats), grocery delivery integrations, native apps. Per-recipe nutrition and ingredient flags (spicy, allergens…) worked out from the ingredients *are* in scope: see [INGREDIENT-KNOWLEDGE.md](INGREDIENT-KNOWLEDGE.md).

## 2. Core user flows

1. **Save a recipe.** Share a link from the phone (or paste it) → it appears in the library at once with a thumbnail and status "extracting…" → a few seconds to a minute later it becomes a full recipe. If extraction is unsure, it lands in a **Needs review** queue.
2. **Review / edit.** Side-by-side: source (embed/caption/transcript) and extracted recipe. Fix ingredients, servings, tags. Manual recipes are also allowed.
3. **Plan the week.** Choose which lunch and dinner slots to fill (e.g. skip Saturday dinner when eating out) and servings → Tabooki proposes a plan → lock the ones you like, swap or regenerate the rest.
4. **Shop.** One tap builds the shopping list from the plan: scaled, merged, pantry staples left out, grouped by aisle. Both household members can check items off live in the store.
5. **Cook.** Open the recipe from the plan, with a link back to the original video or post.

## 3. Architecture overview

```
 Phone / desktop (PWA: React SPA, served as static files)
   │  share-target / paste / iOS Shortcut           ▲ live updates (SSE)
   ▼                                                 │
 FrankenPHP (Caddy: HTTPS, static SPA, PHP app, built-in Mercure hub)
   │
   ▼
 Symfony API (API Platform, Security, Doctrine)
   │                          │
   │ dispatch message         │ read/write
   ▼                          ▼
 Messenger worker ───────► PostgreSQL          local volume (uploads, thumbnails, videos)
 (same image, `messenger:consume`)
   │
   ├─ fetchers: HttpClient + DomCrawler (JSON-LD), Readability, yt-dlp/ffmpeg via Process
   └─ LLM gateway (own interface → Symfony AI Platform bridges / raw HttpClient)
        ├─ Google AI Studio (Gemini) → primary: text, images, video (YouTube URL or uploaded file)
        └─ Amazon Bedrock            → secondary: text/images fallback or A/B (Claude, Nova, …)
```

Everything runs in one `compose.yaml`: **`app`** (FrankenPHP + Symfony + built SPA), **`worker`** (same image, different command), **`db`** (Postgres) and an optional **`adminer`**. No Redis is needed: Messenger and cache both use Postgres at household scale.

### Backend: Symfony

| Concern | Choice | Notes |
|---|---|---|
| Framework | **Symfony 7.4 LTS** (or the current 8.x), PHP 8.4+ | LTS if you'd rather not chase upgrades |
| Web server | **FrankenPHP** (official Docker image) | Caddy gives automatic HTTPS, serves the SPA's static files, has a built-in **Mercure** hub, and offers a worker mode for speed. One container replaces nginx + php-fpm |
| API | **API Platform** for CRUD resources (recipes, plans, lists, ingredients). Plain controllers for workflow endpoints (`POST /api/ingest`, `POST /api/plans/{id}/generate`) | Produces an **OpenAPI spec automatically**, which the frontend turns into TypeScript types (see below) |
| ORM / DB | **Doctrine ORM + Migrations**, **PostgreSQL 17** | JSONB columns for evidence, steps and tags |
| Background jobs | **Symfony Messenger** with the **Doctrine transport** (`ExtractSourceMessage`, `FetchThumbnailMessage`…), retry strategy + failure transport | Run `messenger:consume async` in the worker container |
| Scheduled jobs | **Symfony Scheduler** | e.g. retry `failed` sources nightly, clean old video files |
| Auth | **Symfony Security**, session cookie (the SPA is same-origin, so no JWT/CORS hassle). **Login link** (built-in passwordless email links) or plain passwords. **Personal access tokens** (`access_token` authenticator) for the iOS Shortcut | Household-sized: create users by console command, no sign-up page |
| Realtime | **Mercure** (built into FrankenPHP) + `symfony/mercure-bundle`. API Platform can publish updates automatically (`mercure: true` on a resource) | Live shopping-list check-off, and extraction status changing from "extracting…" to "ready" without polling |
| Files | **Flysystem bundle** → local volume | Screenshots, downloaded reels, cached thumbnails |
| Validation / DTOs | Symfony Validator + Serializer, PHP readonly DTO classes | The LLM output schema is a PHP DTO (see 4.2) |
| HTML parsing | `symfony/dom-crawler`, `fivefilters/readability.php` | JSON-LD + readable article text |
| External tools | `yt-dlp` + `ffmpeg` installed in the image, called via `symfony/process` | Pin the version and update it often (yt-dlp breaks when platforms change) |
| Units | `php-units-of-measure/php-units-of-measure` + our own normalizer | Needed to merge the shopping list |
| i18n | Symfony Translation (emails, error messages), user `locale` field (`es` / `en`) | |
| Quality | PHPStan (max level), PHPUnit, PHP-CS-Fixer | |

### Frontend: React (recommended), with alternatives considered

**Recommendation: React + Vite SPA.** You already lean React, it has the largest ecosystem for what this app needs (PWA, offline data, drag-and-drop planner), and with Symfony doing the backend there's no reason for a meta-framework like Next.js. A plain SPA served as static files by FrankenPHP is the simplest setup to self-host.

| Concern | Choice |
|---|---|
| Build | **Vite + React + TypeScript** |
| Routing | **TanStack Router** (type-safe) or React Router |
| Server state | **TanStack Query**: caching, optimistic updates (checking items off), and offline mutation queueing via its persister |
| API types | **`openapi-typescript` + `openapi-fetch`**, generated from API Platform's OpenAPI spec, so a backend change shows up as a TS compile error |
| UI | **Tailwind CSS + shadcn/ui** (Radix primitives), mobile-first |
| PWA | **`vite-plugin-pwa`** (Workbox): installable, offline shell, cached recipes and shopping list, Web Share Target manifest |
| Realtime | Native `EventSource` subscribed to Mercure topics, which invalidates TanStack Query caches |
| Drag & drop (planner) | `dnd-kit` |
| i18n | **`react-i18next`** with `es` and `en` catalogs. Language follows the user's setting, defaulting to the browser language |

Alternatives considered:
- **Symfony UX (Twig + Turbo + Stimulus/Live Components):** a single PHP codebase with no separate frontend build. A great fit for CRUD, but weaker for an **offline-capable shopping list** and a PWA that feels like an app on the phone. Not recommended here.
- **Vue 3 / Svelte 5:** both fine and a bit lighter, and Vue is popular with Symfony developers. Pick one only if you prefer it; there's no technical win over React for this app.

## 4. The extraction pipeline (the hard part)

Each saved URL becomes a **source**, and a job takes it through these steps:

```
normalize URL → detect platform → fetch metadata → gather evidence → LLM extract → validate → store (ready | needs_review | failed)
```

### 4.1 Evidence gathering per platform

Rule: **collect the cheapest good evidence first and only escalate when needed.** Always store the raw evidence (HTML snippet, caption, transcript) so we can re-extract later with better prompts or models, without fetching again.

| Platform | Step 1 (cheap) | Step 2 | Step 3 (expensive) |
|---|---|---|---|
| **Recipe blogs** | Parse schema.org `Recipe` JSON-LD (most recipe sites have it). Map it directly | No JSON-LD → Readability-cleaned text → LLM | Page blocked → headless fetch, or ask the user to paste |
| **YouTube** (incl. Shorts) | Title + description via YouTube Data API or `yt-dlp --dump-json`. Descriptions often contain the full recipe **or a link to a blog: follow it** | Captions/transcript → LLM | **Gemini with the YouTube URL** (native video understanding, public videos only) |
| **Instagram** (posts/reels) | Caption via `yt-dlp` metadata or OpenGraph tags | Carousel images → vision LLM (recipe cards are often in the images) | Download the reel video → Gemini (File API or inline) |
| **TikTok** | Same as Instagram (`yt-dlp` usually works better there) | — | Video → Gemini |
| **Fallbacks (any)** | User pastes caption/text | User uploads screenshot(s) → vision LLM | User uploads a screen recording → Gemini |

**Instagram warning:** Meta actively blocks scraping. `yt-dlp` works for many public reels but breaks from time to time and may need a logged-in cookie. That's acceptable at household volume, but it's brittle, and logged-in scraping goes against Instagram's ToS. Design so that **failure is a normal state**: the item stays in the library with its thumbnail and link, and one tap opens "paste caption / add screenshot". Also common: "recipe in comments" or "comment RECIPE and I'll DM you". The LLM should detect these and set the status to `needs_input` with a helpful message instead of guessing.

### 4.2 LLM extraction

- **LLM gateway in PHP:** define our own small interface (`RecipeExtractor::extract(Evidence): ExtractionResult`) and put the provider code behind it. **Symfony AI Platform** (`symfony/ai-platform`) has bridges for Gemini and Bedrock and supports structured output mapped to PHP classes. However, it is still **experimental (pre-1.0, no BC promise)**, so it stays behind our interface. If a bridge can't do something we need (e.g. Gemini YouTube-URL video input), we call the provider's API directly: Gemini's REST API is plain JSON over `HttpClient`, and Bedrock goes through `aws/aws-sdk-php` (Converse API).
- **One extraction schema** (a PHP DTO turned into JSON Schema), used for every source: title, servings, times, cuisine, meal types, tags, diet flags, ingredient groups, steps, plus `confidence` and `missing_fields`.
- Each ingredient comes out **already normalized**: `{ raw, quantity, unit, name, canonical_key, name_es, name_en, prep, optional }`, with units normalized as described in 4.3. `raw` and `name` are in the cook's language (see below), `raw` with the stored metric amount so cook mode can scale it. `canonical_key` is a stable English slug (`onion`, `chickpeas_canned`) used for matching, so *cebolla* and *onion* merge on the shopping list.
- Recipes are **written in the language of whoever adds them** (their `locale`), translated in the same call when the material is in another language (changed 2026-11-22; until then they kept their original language). The recipe's **`language`** (`es` / `en`) is stored.
- **Every recipe in both languages** (added 2026-11-22): after a recipe is added, extracted again or edited, a background text-only call (`TranslateRecipe`, ~$0.001) writes its title, tags, ingredient lines (name, prep, group), steps and notes in the other language, line for line. Amounts, units, servings and times are shared. The API serves each reader their locale's version (`language` says which, `translatedFrom` marks a translation); search matches both. Editing the translation makes it the original and the other language is translated again; saving it unchanged keeps the original. `app:translate` translates recipes saved before this.
- **Model routing (config, not code):**
  - Text/JSON-LD normalization → **Gemini Flash-class** model (cheap, fast, good at JSON).
  - Images (screenshots, Instagram carousels) → Gemini (natively multimodal).
  - Video → **Gemini via AI Studio**: YouTube URLs are passed directly (public videos only). Instagram/TikTok reels are downloaded with yt-dlp and uploaded through the File API.
  - **Bedrock** (e.g. Claude or Nova) is configured as the **fallback for text/image steps** when Gemini errors or is rate-limited, and is handy for comparing quality on the extraction spike. Bedrock doesn't host Gemini and has no YouTube-URL equivalent, so **video stays Gemini-only**.
- **Never invent:** the prompt tells the model to leave fields empty instead of guessing. If the evidence doesn't include quantities (common in reels), mark the recipe `needs_review` instead of making up amounts.
- **One source can yield several recipes** (e.g. a "3 weeknight dinners" video).
- Log model, tokens and cost per job so you can see what extraction actually costs. Text extraction costs a fraction of a cent. Video is billed roughly per second of footage, which for short reels is still cheap on Flash-class models. Check current pricing.

### 4.3 Units

English recipes, especially American ones, use **cups, ounces, pounds and °F**. Spanish recipes use **grams, millilitres and °C**. Mixing them breaks shopping-list merging ("1 cup flour" + "200 g harina" can't be summed).

Decision: **store and display everything in metric**. Weights in g/kg, volumes in ml/l, temperatures in °C (also rewritten inside the steps). Some units are practical and stay as they are: spoons (tbsp/tsp ↔ *cucharada/cucharadita*), pinches, and countable items ("2 eggs", "1 can"). The original text (`raw`) is always kept and shown on tap, so nothing is lost. Cups of dry ingredients are converted to grams only when the ingredient's density is known (flour, sugar, rice). Otherwise they become ml.

## 5. Data model (first cut)

```
households        id, name, default_servings, planned_slots (e.g. Mon–Sun × lunch/dinner)
users             id, household_id, email, display_name, locale (es|en)

sources           id, household_id, url, normalized_url (unique per household), platform,
                  title, author, thumbnail_url, status (pending|extracting|ready|needs_review|needs_input|failed),
                  evidence jsonb (caption, transcript, json_ld, …), error, added_by, created_at

recipes           id, household_id, source_id (nullable for manual), language (es|en), title, servings,
                  prep_min, cook_min, total_min, cuisine, meal_types[], tags[], diet_flags[],
                  steps jsonb, notes, rating, is_favorite, confidence, extracted_by_model, reviewed_at,
                  translation jsonb (the text in the other language + hash of the text it came from),
                  heaviness (light|medium|hearty), spice (0-2), food_groups[], optional_food_groups[],
                  facts_from (hash of the text they were read from), facts_edited[] (fields fixed by hand)

recipe_ingredients id, recipe_id, position, group_name, raw_text, quantity, unit,
                  ingredient_id, prep, optional

ingredients       id, household_id, canonical_key, name_es, name_en, aisle, is_staple,
                  density_g_per_ml (nullable)   -- grows as recipes arrive

extraction_runs   id, source_id, step, provider, model, input_tokens, output_tokens, cost_usd, ok, error, created_at

taste_profiles    id, household_id (unique), answers jsonb, rules jsonb, notes, summary jsonb {es, en},
                  updated_by, updated_at

meal_plans        id, household_id, week_start, created_at
meal_plan_entries id, plan_id, date, slot (lunch|dinner), recipe_id, servings,
                  is_leftover_of (entry id), locked

shopping_lists    id, plan_id (nullable), household_id, created_at
shopping_items    id, list_id, ingredient_id (nullable), display_text, quantity, unit, aisle,
                  checked, is_manual, from_recipe_ids[]
```

Every table carries `household_id` so it's easy to open up to multiple households later. A **Doctrine SQL filter** plus an API Platform extension scope every query to the current user's household automatically.

## 6. Meal planning

Start **deterministic, not LLM**: it's faster, free, predictable and easy to tweak.

- **Inputs:** which of the 14 lunch/dinner slots to fill this week, servings per slot (household default, overridable), max cook time on weeknights, last N weeks of plans.
- **Lunch vs dinner fit:** recipes carry `meal_types`. The extractor suggests them and you can edit them. Some dishes are lunch-only (e.g. a heavy *cocido* or a big pasta), some dinner-only (a light soup or salad), most fit both. The planner respects this.
- **Scoring each candidate recipe:** favorites and rating ↑, recently cooked ↓ (no repeats within ~3 weeks), variety across the week (protein, cuisine) ↑, **overlap in fresh ingredients with already chosen recipes ↑** (less waste: half a bunch of cilantro gets used twice), fits the slot's time limit.
- Weighted random pick, slot by slot, so "regenerate" gives a different plan each time.
- **Leftovers / cook once, eat twice:** with both lunch and dinner planned, this matters a lot. Tonight's dinner cooked in double quantity can fill tomorrow's lunch (a takeaway container for work). The planner can propose these pairs, and the shopping list counts the ingredients once at the doubled amount.
- **Later (optional):** a natural-language tweak box ("more vegetarian this week, nothing spicy on Thursday") where an LLM turns the request into constraints and weights. The LLM sets the parameters and the planner still does the picking. See §6.3.

### 6.1 Taste profile: the household's likings

Each household tells Tabooki what it likes ("no fish", "we don't like cheese", "heavy lunches and light dinners", "animal protein twice a week at most"). Nobody writes a prompt: a short questionnaire collects the answers, and an LLM turns them into **typed rules** the planner applies by itself. The LLM never picks the week: hard rules (an allergy) must hold every time, regenerate and swap stay instant and free, and locks, leftovers, history and shared ingredients keep working.

The three kinds of rule, and the data each needs:

| Rule | Example | Needs |
|---|---|---|
| Exclude a food group or ingredient | "no fish", "no cheese", "no coriander" | what each recipe contains |
| Match a dish to the meal | "heavy lunches, light dinners" | how heavy each recipe is |
| Weekly count | "meat or fish twice a week at most", "fish at least twice" | food groups, counted over the week |

Wishes no rule can express ("nothing fiddly on weekdays", "the kids hate saucy dishes") are kept as free-text **notes**, judged once per recipe by the LLM and cached (§6.3).

**Questionnaire** (Settings → "Gustos de la casa", plus a nudge in the plan setup until it exists). One tappable question per screen, all skippable, prefilled when retaken:

1. Diet: omnivore · flexitarian · pescatarian · vegetarian · vegan.
2. Never eaten: chips (fish, shellfish, pork, red meat, cheese, dairy, egg, gluten, nuts, mushrooms, coriander, spicy… + add your own), each marked **"we don't like it"** (only counts when the recipe really uses it: an optional garnish is fine) or **"allergy / can't"** (counts even when optional; never relaxed).
3. Meat or fish per week: no limit · 1–2 · 3–4 · 5+, then "do eggs and dairy count?" (default no), and an optional minimum ("fish at least twice").
4. Lunch vs dinner: heavy lunch and light dinner · the same · heavier dinner.
5. More of: legumes, vegetables, rice, pasta, Asian…
6. Spice: none · mild · we love it.
7. Anything else? Free text, with dictation (Whisper is already wired).

Then the LLM may ask **up to 3 multiple-choice follow-ups** where something is ambiguous, grounded in the library ("14 of your recipes have cheese: is melted cheese on a pizza fine?"). One round, no more.

**"This is what we understood"**: one bullet per rule, each with its **effect on the library** ("No fish or shellfish: leaves out 12 of 140 recipes") and warnings when too little is left ("only 9 light weekday dinners: the menu will repeat often"). Bullets can be removed or edited; the readable summary (the "prompt") sits on top. Saving offers to redo this week's menu.

**Decisions:** a leftover lunch counts as a meal for weekly limits (it is eaten twice); eggs and dairy aren't animal protein unless the household says so; one profile per household (no per-member profiles: "someone at home doesn't eat X" plans the same); a recipe chosen by hand or asked for "en el próximo menú" can break a dislike (with a warning), never an allergy without confirming.

### 6.2 Recipe facts

What the rules read, per recipe, from one small call to the text model (`AnnotateRecipe`, no thinking, the recipe's title, ingredients and steps). It runs after extraction and when the title or ingredients change, plus `app:annotate` for the existing library; each call is logged in `extraction_run` as step `annotate`.

- **heaviness:** light (salads, soups, steamed fish and vegetables), medium, hearty (stews, legumes with meat, baked pasta, fried food).
- **spice:** 0 none, 1 mild, 2 hot.
- **food groups** of the ingredients the dish needs, and separately of its optional ones: `red_meat, pork, poultry, processed_meat, fish, shellfish, animal_stock, egg, dairy, cheese, gluten, tree_nuts, peanuts, soy, sesame, alcohol`. Composite ingredients count (pesto → cheese, tree nuts); `animal_stock` (stock, gelatin, lard) keeps a vegetable soup made with chicken stock out of a vegetarian week without counting it as a meat meal. Diets follow from the groups: vegan, vegetarian, pescatarian.

They live on the recipe rather than on the ingredient catalog, which is only filled when a shopping list is built. They can be fixed by hand on the recipe form; a field fixed by hand is never overwritten by the model. The library can filter by them (light, hearty, vegetarian).

### 6.3 How the planner uses the profile

`rules` is a small typed, versioned vocabulary (the LLM answers through a tool whose schema has only these, with enums, so it can't invent others):

```json
{
  "version": 1,
  "diet": "omnivore",
  "exclude": [
    {"group": "fish", "strength": "never"},
    {"group": "cheese", "strength": "avoid"},
    {"ingredients": ["coriander", "fresh_coriander"], "strength": "avoid"}
  ],
  "weekly": [{"groups": ["red_meat", "pork", "poultry", "processed_meat", "fish", "shellfish"], "max": 2}],
  "slotHeaviness": {"lunch": "hearty", "dinner": "light"},
  "prefer": [{"tag": "legumes", "weight": "more"}],
  "spice": "mild"
}
```

**Compiling** (`POST /api/taste-profile/draft`, synchronous, nothing saved): answers + free text + the rule schema + the household's ingredient keys (so "cilantro" maps to keys that exist) → `rules`, `notes`, `summary {es, en}`, `interpretations[]` (the bullets) and up to 3 `questions[]`. The server validates it, drops unknown keys and computes the effect on the library itself. `PUT /api/taste-profile` saves what the user confirmed. Logged as step `taste_profile`.

**Planning:**

| Rule | Enforced as | Relaxes? |
|---|---|---|
| Allergy (`never`), diet | filtered out first | **never**: the meal stays empty, with the reason |
| Dislike (`avoid`) | first filter tier | last, after the no-repeat tiers |
| Weekly `max` | running count over the week (locked and leftover meals included); the group drops out once reached | yes, and the explanation says so |
| Weekly `min` | weight grows as the remaining meals run out | — |
| Meal heaviness, `prefer`, spice, notes fit | score multipliers (e.g. right heaviness ×1.5, wrong ×0.4, `fit: poor` ×0.2) | soft by design |

The greedy slot-by-slot pick can't plan ahead for minimums, so it becomes the sampler: build ~100 weeks with it, score each whole week (rules broken, heaviness matches, variety, shared ingredients), keep the best. Milliseconds at household scale, and regenerate still varies. Each entry records why it was chosen (`meal_plan_entries.reasons`), shown in the meal sheet ("light dinner · uses Tuesday's coriander", or "no other light dinner under 30 min").

**Notes fit:** when the profile has notes, one batched call (title, minutes, number of steps, main ingredients, tags; up to 100 recipes) returns `good|neutral|poor` per recipe, **for which meals** (`always`, `weekdays`, `weekends`, `lunch`, `dinner`: "nothing fiddly on weekdays" makes a three-hour stew poor on weekdays only) and a reason of a few words in both languages. Stored on the recipe with the notes' hash and the recipe's fingerprint, so new notes or a changed title or ingredients make it stale; saving the notes, adding a recipe or changing one queues the judging (`JudgeNotes`, step `notes_fit`; `app:judge-notes` to retry). The planner reads the stored answers only: good ×1.4, poor ×0.25 for the meals it applies to, and a poor pick costs 2 in best-of-K and says so on the meal.

**Phases:** (1) recipe facts, backfill, editing, library filters — done; (2) questionnaire, compile, review screen, profile settings, planner rules, best-of-K, explanations — done, with dictation for the free-text answers (`POST /api/dictation`: the browser records, Whisper transcribes in the speaker's language, step `dictation`), rules edited in place on the review screen and on the saved likings (dislike ↔ allergy, weekly numbers, meal heaviness, diet, spice, more ↔ less, the notes; saved at once on the saved likings), and the summary written again in the background whenever the likings change without one (`SummarizeTaste`; dropped if they change during the call); (3) notes fit — done (see below), a per-week tweak box — done (temporary rules on the plan, same compiler: `meal_plans.tweak`, `tweak_rules`, `tweak_label`, `tweak_avoids`; on top of the household's rules with `TasteRules::with()`: the stricter diet, every exclusion and limit, the week's heaviness, the lower spice; logged as step `tweak`; when the model can't be reached the wish is kept without rules and the week offers to try again), and "why are you swapping it?" feeding profile suggestions — done (after a swap the meal sheet asks, skippable, "don't ask again" per device: not today, don't like it — a food group, an ingredient or the whole dish —, too filling or too light for that meal, too long; stored in `swap_feedback`. By counting, no model: the same group or ingredient in two dishes in four months suggests "rather without it", three swaps for heaviness at one meal in two months suggest that meal's heaviness, three weekday swaps for time suggest a note "nothing over N minutes on weekdays" (which the notes judging then reads). Taken or turned down, a suggestion isn't offered again (`taste_profiles.dismissed_suggestions`); a whole dish nobody likes comes up five times less for six months).

**As built (phase 2):** the tappable answers become rules without the model (`TasteRules::fromAnswers()`); the model refines them, and can't drop an allergy or the diet the household tapped. When the model can't be reached, the draft is the tapped answers alone and the free text is kept word for word as notes. Recipes whose facts haven't been read yet are left out by an allergy or a diet (we can't know), never by a dislike. The planner tries up to 30 weeks and keeps the one that gives up least (relaxed dislike 100, relaxed weekly limit 30, a weekly minimum short 20 per meal, a repeat 4 per tier, the opposite heaviness 2), stopping at the first that gives up nothing. Each recipe in the library and the picker says what it has against the profile (`tasteConflicts`).

## 7. Shopping list

1. Take all ingredients of planned (non-leftover) entries, scaled by `entry.servings / recipe.servings`.
2. Group by `ingredient_id`. Convert compatible units and sum (200 g + 0.5 kg → 700 g). Keep incompatible units as separate lines under the same item ("2 cans + 400 g chickpeas").
3. Leave out **staples** (salt, oil, common spices) by default, shown in a collapsible "check you have" section.
4. Shown in **each viewer's language** (`name_es` / `name_en`), whatever language the recipes are in.
5. Group by aisle (produce, dairy, meat/fish, pantry, frozen, …). The aisle is stored on `ingredients`, so fixing it once fixes it for good.
6. Manual items can be added. Check-off syncs live between household members (Mercure) and works offline (PWA cache plus TanStack Query's queued mutations, replayed on reconnect).

## 8. Getting links in from the phone

This matters, because most saves happen inside the Instagram and YouTube apps. The household has **both iPhone and Android**, so we build both paths. They share the same backend endpoint, `POST /api/ingest`.

- **Android:** the installed PWA registers as a **Web Share Target** (in the manifest), so "Share → Tabooki" appears in the system share sheet. It uses the normal session cookie.
- **iOS:** Safari does **not** support Web Share Target, so we ship an **Apple Shortcut** ("Save to Tabooki") that shows up in the share sheet and POSTs the shared URL to `/api/ingest` with a **personal access token**. The app gets a settings page that generates the token and links to the Shortcut, so setup takes about two minutes. Fallback: paste the link in the app.
- Desktop: paste box, and optionally a bookmarklet.

### Environments & access

PWAs (service worker, install, share target) **require HTTPS**, and phones need to reach the server from anywhere (the supermarket, saving a reel on the bus).

**Local development (Mac, Apple Silicon)**
- `docker compose up` runs the same `app` / `worker` / `db` services as production, with source code mounted and Vite's dev server for hot reload.
- FrankenPHP/Caddy serves `https://localhost` with its own local CA (trusted once), so service worker and PWA features work locally.
- To try it on a real phone during development, use the staging copy on the VPS, or join the Mac to the tailnet too.

**Production: Contabo VPS + Tailscale**
- The VPS and every household phone join one **tailnet** (the Tailscale app on iPhone and Android, free for personal use).
- The app is served at `https://tabooki.<tailnet>.ts.net`. Caddy (inside FrankenPHP) gets a **real certificate for `*.ts.net` automatically** from the local `tailscaled`, so no domain or Let's Encrypt setup is needed.
- **Firewall:** nothing public except SSH, and even SSH can move to Tailscale SSH and close port 22. Postgres is never exposed.
- The iOS Shortcut and Android share target post to the `ts.net` URL. This works whenever Tailscale is connected on the phone (enable "VPN On Demand" on iOS so it connects automatically).
- **Architecture:** the Mac is ARM (arm64), while Contabo VPSs are usually x86_64. Images are built multi-arch with `docker buildx`, or built directly on the VPS.
- **Deploy:** start simple. `git pull && docker compose up -d --build` on the VPS (a `make deploy` over SSH). Move to CI-built images later if needed.

**Backups:** a nightly `pg_dump` + uploads volume → **off the VPS** with restic (e.g. to Contabo Object Storage, Backblaze B2 or a home NAS over Tailscale). Don't rely on VPS snapshots alone. The library is personal data you'd hate to lose.

## 9. Milestones

| # | Milestone | Done when… |
|---|---|---|
| M0 | **Scaffold** | Monorepo (`backend/` Symfony + API Platform, `frontend/` Vite React, `compose.yaml`). FrankenPHP serving API + SPA, Postgres, Messenger worker, login, generated TS API client. Reachable over HTTPS from the phone, installable |
| M1 | **Library** | Paste a URL → card with title/thumbnail (OpenGraph/oEmbed). Manual recipe create/edit. Search and filter by tags |
| M2 | **Extraction v1** | Job queue + statuses. Blogs (JSON-LD + LLM fallback). YouTube (description → transcript → Gemini video). Review screen |
| M3 | **Social + inputs** | Instagram/TikTok via yt-dlp + Gemini. Paste-caption / screenshot / video-upload fallbacks. Share target + iOS Shortcut endpoint |
| M4 | **Planner** | Weekly plan generation, lock/swap/regenerate, leftovers, history |
| M5 | **Shopping list** | Merge/scale/units, staples, aisles, live check-off, offline |
| M6 | **Polish** | Ratings after cooking, cook mode (screen stays on, step by step), re-extract with a newer model, cost dashboard |

M2 is where the real risk is. It's worth a quick **extraction spike** (option 2 from earlier) before or during M0 with ~20 real links from your library, to measure success rate per platform.

## 10. Risks & open questions

**Risks**
- Instagram extraction reliability and ToS (see 4.1). Mitigation: fallbacks are first-class.
- LLM-hallucinated quantities. Mitigation: the "never invent" prompt, confidence plus review queue, raw evidence shown side by side.
- Ingredient normalization quality ("spring onion" vs "scallion" vs "green onion") decides whether merging works. Mitigation: LLM canonical names + an editable `ingredients` table that learns.

**Open questions**
None blocking. Minor ones can be settled when we reach them: Symfony 7.4 LTS vs 8.x (default: 7.4 LTS), login links vs passwords (default: passwords for M0, login links later, since they need outgoing email from the VPS).
