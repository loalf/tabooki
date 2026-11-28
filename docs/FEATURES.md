# Tabooki — feature inventory

_2026-11-27. Written before the redesign (brand: "la olla, simplificada", `tabooki.pen` node `FSyZV`), so every screen, sheet and state the new design has to cover is in one place, along with ideas worth designing for now._

**Legend**

- ✅ **Built**: in the app today.
- 💡 **Idea, data already there**: the data model has what it needs. Frontend work, sometimes an endpoint or a query.
- 🧱 **Idea, needs data**: needs a new field, table or enum value. The **Needs** line says what.

Part 1 lists what exists, screen by screen. Part 2 lists data we have but don't show. Part 3 has the suggestions. Part 4 lists the screens the redesign has to cover.

---

## Part 1 — What the app does today

### Navigation

- ✅ Four tabs: **Hoy** (Today), **Recetas** (Recipes), **Menú** (Plan), **Compra** (Shopping).
- ✅ Settings opens as a sheet from the avatar on Hoy (`/?account`), and the phone's back button closes it.
- ✅ Bilingual UI (es/en), following the user's setting.
- ✅ Installable PWA. On Android, Tabooki appears in the system Share menu.
- ✅ Toasts, some with **Undo** (removed meal, removed shopping item) or a link ("Recipe ready · Open").
- ✅ Placeholders while loading on every main screen.

### Sign in and account

- ✅ Email + password sign-in.
- ✅ **Continue with Google**: anyone can sign up. Each new user gets a household of their own, and an existing account with the same email is linked. There's a daily limit on sign-ups from one place.
- ✅ Forgot password: an email link that works once and expires in an hour. The reply is the same whether or not the address has an account.
- ✅ Change password (signs out the other devices), or add one if you signed up with Google.
- ✅ Sign out.

### Hoy (Today)

- ✅ Date, and "Today we eat": today's lunch and dinner. After 16:00, dinner comes first and is shown large.
- ✅ Each meal shows its photo, title, minutes and servings, a "leftovers from Tuesday's dinner" line, and a lock badge. Its buttons are **Cook**, **See recipe** and **Change** (opens the meal sheet). A meal can also read "Eating out".
- ✅ **To do** section:
  - Rate meals from the last two days with stars, or **"Didn't make it"** (with undo).
  - "This week / next week doesn't have a menu yet" → plan it.
  - Shopping progress ("Shopping · 9 of 23 left" / "all in the cart").
- ✅ Account sheet: account, household name, language, Household tastes (with its summary or a nudge), Ingredients, AI costs, "Save from your phone" (collapsed), Password (collapsed), Sign out.

### Recetas (Library)

- ✅ Search by words in **both languages** (title, ingredients, tags). `-chorizo` leaves out an ingredient.
- ✅ **Search by meaning** (pgvector): "algo ligero con garbanzos" finds the chickpea salad. Shown under "Close to what you're after".
- ✅ Shortcut chips: Favorites, Light, Hearty, Vegetarian, **Fit you** (matches the household's tastes), "N to review".
- ✅ **Filters sheet** with a count badge: kcal per serving (≤400/600/800), protein (≥20/30 g), spice, "without" any of the 14 EU allergens, and tags. It has "Clear" and "Show N recipes". Active filters come first in the chip row, each with ×.
- ✅ Sort: Newest, Quickest, Best rated, A–Z.
- ✅ Recipe cards: photo, title, minutes, ★ rating, platform badge, and "On the menu · thu".
- ✅ **"Haven't made these in a while"** row (Rediscover), with "Last made …".
- ✅ **Inbox / recently saved**: each saved link shows its state (waiting, extracting, needs your help, failed) and its actions (paste the text, retry, write the recipe yourself, delete). A banner shows "N being extracted / N need your help".
- ✅ Empty states: empty library, no matches.
- ✅ **Add a recipe** sheet: paste a link (with a Paste button and duplicate detection), write one yourself, or set up sharing from Instagram/TikTok/YouTube.

### Getting recipes in (import pipeline)

- ✅ Sources: recipe blogs (JSON-LD, then readable text), **YouTube, Instagram, TikTok, Facebook** (caption → carousel pictures → video: Whisper transcript plus stills).
- ✅ Recipes are written in the language of whoever adds them, and **automatically translated** into the other language.
- ✅ Statuses: pending, extracting, ready, **needs review**, **needs input** (e.g. "recipe in the comments"), failed.
- ✅ Android share target. **iPhone Shortcut** with personal keys per device: create, see when each was last used, revoke.
- ✅ Toasts when an import finishes, wherever you are in the app.
- ✅ After extraction, in the background: recipe facts (heaviness, spice, food groups), grams per line, nutrition, allergens, substitutes, embedding for search, and a check against the household's notes.

### Recipe page

- ✅ Photo, title, "author on site", open the original, Favorite, rating, **To menu**, Cook.
- ✅ Facts row: minutes (prep/cook), servings, heaviness (only when light or hearty), spice, diet (vegan/vegetarian/pescatarian).
- ✅ One "contains / allergens" line. Optional groups are shown apart.
- ✅ Taste fit: what clashes with the household's tastes, and what the household notes say about it.
- ✅ "On the menu" badge, which opens that day on Menú. "Waiting for the next menu" when queued.
- ✅ **Servings stepper** that scales the ingredients.
- ✅ Ingredients in groups. ⇄ on a line opens its **substitutes**, with ratio and note.
- ✅ Steps, notes, and the original text (collapsed).
- ✅ **Per serving** nutrition card (kcal, protein, carbs/sugars, fat/saturated, fibre, salt), saying how complete it is.
- ✅ **More like this**: similar recipes, saying what they share.
- ✅ Review banner on auto-extracted recipes: "Looks good" or "Fix it".
- ✅ "Extracted with {model}" or "translated from…". **Extract again** when a newer model exists, after a confirmation.
- ✅ Last made date. Edit, Delete.

### Recipe edit / new

- ✅ Title, language, servings, prep and cook minutes, good for (lunch/dinner), tags.
- ✅ Ingredients as plain text, one per line. A line ending with ":" starts a group.
- ✅ Steps, one per line. Notes.
- ✅ Facts fixed by hand (heaviness, spice, groups) are never overwritten by the model.
- ✅ **Weights** section: grams per line and where each figure came from (by hand, measured, estimated, usual weight). It can be corrected.

### Cook mode

- ✅ Keeps the screen on (Wake Lock), with a status indicator.
- ✅ "Get the ingredients ready" first, scaled to the meal's servings. Lines that couldn't be scaled are marked ×.
- ✅ One step at a time, with "In this step" (the ingredients the step uses), and Previous/Next pinned to the bottom.
- ✅ **Timers** found in the step text, kept in one corner. Several can run at once, and they survive leaving cook mode or a reload. Beep and vibration on "Time's up!".
- ✅ The current step is remembered.
- ✅ End screen: "How did it turn out?" rating. That marks the meal as cooked.

### Menú (weekly plan)

- ✅ Week navigation: previous, next, back to this week, past weeks.
- ✅ **Plan setup**: tap the meals you'll eat out, servings per meal, **Cook once, eat twice** (dinner leftovers become the next day's lunch), and a time limit for Mon–Fri. Then "Create menu (N meals)".
- ✅ Deterministic planner (best of up to 30 tries). It respects tastes, allergies, weekly limits, heaviness per meal, no repeats, shared fresh ingredients, notes fit and queued recipes.
- ✅ Meal rows: recipe, minutes, a short warning ("⚠ A repeat", "Over the time limit"…), lock, leftovers, "Not made".
- ✅ **Meal sheet**: see the recipe, cook, **Keep** (lock), **Eating out** (with undo), servings ±, "Swap for…" (3 suggestions, each with a reason: favorite, ★4, never made, not since March, quick), "Pick from my recipes" (picker with search), **Let Tabooki pick**. It also explains why the meal was chosen.
- ✅ **"Why are you swapping it?"**: not today, don't like it (a food group, an ingredient or the whole dish), too filling, too light, too long. The answers feed suggestions on the Tastes page. "Don't ask again" is per device.
- ✅ **"Anything special this week?"**: a one-week wish in free text ("my mother comes Thursday, no pork"), turned into temporary rules, and the week is redone.
- ✅ Regenerate (locked meals stay), delete the menu, add a meal to an empty slot.
- ✅ **Add to menu** from a recipe: "In the next menu" (queue) or a specific day and meal, this week or next, showing what it would replace. Picking a day only works in a week that **already has a menu**. Otherwise the sheet says "plan the week first" (see 3.0 B).

### Compra (Shopping)

- ✅ Built from the week's menu: scaled, merged across recipes, with units converted, in each reader's language.
- ✅ Grouped by **aisle** (9 aisles). Ingredients are sorted into aisles in the background.
- ✅ **One line per item**. "…" shows which recipes use it, substitutes ("If they're out: …"), the aisle (change it), and Always at home / Not always at home.
- ✅ **Staples** section: "Before you go: got these?"
- ✅ Typed-in items (with undo on remove). "In the cart" section. "N things left / All in the cart!".
- ✅ **From today only** toggle.
- ✅ **Works offline**: ticks are queued and sent when back online. It refreshes every 5 s to stay in sync with the household.
- ✅ Share the list (native share sheet or the clipboard).

### Household tastes

- ✅ Intro, then a **7-question questionnaire**: diet; what you don't eat (dislike or allergy, plus your own items); meat/fish per week (and whether eggs and dairy count, fish minimum); lunch vs dinner heaviness; more of…; spice; anything else (free text, with **dictation**).
- ✅ Up to 3 multiple-choice follow-up questions from the model.
- ✅ **"This is what we understood"**: one line per rule, each with how many recipes it leaves out, and warnings ("only 9 light dinners left"). Lines can be removed.
- ✅ Saved tastes: summary, rules edited in place (dislike ↔ allergy, numbers, heaviness, diet, spice, more ↔ less), notes, "changed by X on …", and "Redo the menu?" after saving.
- ✅ **Suggestions from swaps** ("You swapped 2 dishes with mushrooms. Add 'Rather without mushrooms'?").
- ✅ Notes judged against the library ("12 recipes fit better, 4 worse").
- ✅ Delete our tastes.

### Ingredients and foods (Settings)

- ✅ List of every ingredient in the household's recipes: how many recipes use it, kcal/100 g, allergens, "no data", "another food". Searchable.
- ✅ Ingredient page: its food, per 100 g figures, allergens, groups, spice, weight of a piece and of a tablespoon, where the figures come from (BEDCA/USDA citations), substitutes, and the recipes that use it.
- ✅ **Is it another food?** (point it at another food), **Correct** the figures by hand, **Back to the estimate**, choose substitutes by hand, or go back to the suggested ones.

### AI costs (Settings)

- ✅ This month, last month, all time. Average per extracted link. Last 6 months. By task, by model. Latest calls with tokens and errors. Note on calls with no price configured.

---

## Part 2 — Data we have but don't show (or barely show)

These cost no backend work, so they're cheap raw material for the redesign:

| Data | Where | Shown today? |
|---|---|---|
| Rating **per meal** (each time it was cooked) and `cooked` | `meal_plan_entry.rating`, `.cooked` | Only as the recipe's single rating and "last made" |
| Full plan history (every past week) | `meal_plan`, `meal_plan_entry` | Only by paging back week by week |
| Why each meal was chosen | `meal_plan_entry.reasons` | Only inside the meal sheet |
| Swap feedback history | `swap_feedback` | Only as aggregated suggestions |
| Nutrition per serving for every recipe | `recipe.nutrition` | Only on the recipe page and as filters |
| Food groups per recipe | `recipe.foodGroups` | One "contains" line. Not counted per week |
| Source author and platform | `source.author`, `source.platform` | A small badge and "author on site" |
| Raw evidence (caption, transcript, JSON-LD) | `source.evidence` | Only the "original text" fold |
| Who added a recipe | `source.addedBy` | No |
| When a recipe was created or last edited | `recipe.createdAt`, `updatedAt` | Only "Newest" sort |
| Food figures' source (BEDCA/USDA/estimated/by hand) | `food.source`, `estimatedFields` | Only on the ingredient page |
| Device keys and when last used | `share_token` | Settings only |

---

## Part 3 — Suggestions

### 3.0 Asked for (2026-11-28)

**A. 🧱 Dishes that need a second dish.** Not built. A meal is one recipe (`meal_plan_entry.recipe`), and nothing says whether a recipe is a full meal. So a greek salad can be planned alone as a dinner, and roasted salmon gets no side.

- **Recipe fact, "what it is in a meal":** `complete` (a stew, a paella), `main` (salmon, chicken: wants a side), `side` (greek salad, purée, rice), `starter` (a soup, a salad before a main). The annotate step reads it like heaviness, it can be fixed by hand, and it fills in for existing recipes in the background.
- **More than one dish per meal:** a meal holds a main and up to one side or starter.
- **Planner:** a `main` gets a side from the library. The side should be light when the main is hearty and not repeat its protein, and both dishes together must fit the weekday time limit. A `side` or `starter` is never planned alone. Pairs the household cooked and rated well are proposed again ("salmon + greek salad, ★5"). When someone chooses only a main by hand (3.0 B), Tabooki picks its side when the menu is created and keeps the main (decided 2026-11-28). Until then the meal reads "+ acompañamiento: lo elige Tabooki".
- **Everywhere else:** the meal row and Hoy show "Salmón al horno + Ensalada griega". The meal sheet swaps each dish on its own or the pair. The shopping list adds both. Cook mode lets you switch between the two recipes, and their timers stay together. Each dish is rated on its own. Calories add up both dishes (C).
- **Needs:** `recipe.course` (enum `complete|main|side|starter`, plus `factsEdited`), and a `meal_plan_entry_dish` table (entry, recipe, role, servings, position, rating). It replaces `entry.recipe`, and existing meals move over as one dish each. Planner rules, shopping and cook mode read the dishes. Optionally a `recipe_pairing` table (main, side, times cooked together, rating), or work pairs out from history.

**B. 🧱 Put a recipe on a day of a week that has no menu yet.** Half built. Today you can queue a recipe "for the next menu" (`recipe.planNext`, which has no day), or pick a day in a week that already has a menu. Wanted: "Thursday dinner next week: lasagne" before next week is planned. When the week is then planned, that meal is already there and locked, and the planner fills the rest around it. It counts it for weekly limits, variety and shared ingredients.

- **Proposal:** let a week's menu exist before it's proposed. Picking a day creates the week's `meal_plan` with that one locked meal and marks it as not proposed yet. Menú then shows plan setup with the meal already in place, and "Create menu" fills the other slots without touching it (as regenerate already keeps locked meals). Hoy keeps saying "Next week doesn't have a menu yet · 1 meal chosen".
- **Needs:** `meal_plan.proposedAt` (nullable: null = only meals chosen by hand so far). Every "this week has a menu" check (Hoy, Menú, Compra, add-to-menu) reads it instead of "a plan exists". The "plan the week first" message goes. The queue (`planNext`) stays for "some day next week".

**C. 💡 Calories per meal and per day.** Not built, but the data is there: each recipe has per-serving nutrition (`recipe.nutrition`: kcal, protein, carbs, fat…).

- **Per meal:** kcal per person, the dish's serving (or both dishes' together, with A), on the meal row and in the meal sheet.
- **Per day:** lunch + dinner per person, on each day on Menú and on Hoy ("Today ≈ 1,350 kcal"). A small protein/carbs/fat bar could sit under it.
- **Per week:** the daily average in the week balance panel (💡 13).
- **Honest gaps:** a meal "eating out" counts as unknown, not zero, and so does a recipe with no figures (under 50 % of its weight is a known food). A day with an unknown meal shows "≈ 650 kcal + lunch out", never a lower total that looks complete. Leftovers count again on the day they're eaten.
- **Optional, 🧱:** a daily target to compare against ("about 1,800 kcal"), as `user.dailyKcalTarget`. It's per person, since needs differ. This stays planning, not tracking what each person ate (PLAN.md's non-goal).

### 3.1 💡 With the data we already have

**Hoy**

1. **Week at a glance strip**: seven small days with dots for lunch and dinner (planned, out, cooked, not made). One tap opens the day on Menú. Uses `meal_plan_entry`.
2. **"Coming up tomorrow"**: tomorrow's meals under today's, so you can thaw or soak what's needed (legumes, frozen fish). Spotting "needs soaking or thawing" in the steps could be a cheap text check, or a fact the annotate step already reads (see 🧱 12).
3. **Leftovers nudge**: "Tonight cook double: tomorrow's lunch is the leftovers". Data is in `leftoverOf`.
4. **Rediscover on Hoy**: one "Haven't made this in a while" card when next week has no menu. It pushes you to plan with an idea already there.

**Recetas**

5. **Platform and creator browsing**: filter by Instagram, TikTok, YouTube or Web, and "from @creator". `source.platform` and `source.author` exist.
6. **Smart shelves** instead of a flat list: "Never made", "Quick (≤30 min)", "Your top rated", "Light dinners", "Vegetarian", "Rediscover". All computed from the fields we have. These suit a more editorial look (horizontal rows of cards) better than chips do.
7. **"What can I make with…"**: type or dictate 2–3 ingredients and rank recipes by how many of their non-staple ingredients you have. Uses the canonical keys and the similarity weights.
8. **Cooked history on the recipe**: "Made 5 times · ★4.2 on average · last on 12 Nov", from the per-meal ratings, with a small timeline.
9. **Who saved it**: "Saved by Belén" (`source.addedBy`). It becomes useful once households have several members (see 🧱 1).

**Recipe page and cook mode**

10. **Side-by-side review** for "needs review": the original caption or transcript (`source.evidence`) next to the extracted ingredients. This was the original M2 idea and is still not built.
11. **Ingredient checklist in "Get ready"**: tick ingredients off as you lay them out. State is local and needs no backend.
12. **Step ingredients with amounts**: cook mode already knows which ingredients a step uses. Show their scaled amounts inline too.

**Menú**

13. **Week balance panel**: a small summary at the foot of the week. Meat or fish ×3 of your 4, fish ×1, legumes ×2, vegetarian ×4, light dinners 5/5, and the average kcal per meal. Uses `recipe.foodGroups`, `heaviness`, `nutrition` and the taste rules. It makes the planner's work visible.
14. **Swap for something similar**: in "Swap for…", a "like this one" option from the similarity endpoint, next to the existing reasons.
15. **Search by meaning in the picker**: the recipe picker uses word search only, and `/api/recipe-search` already exists.
16. **Repeat a past week**: "Plan like the week of 3 Nov" copies its recipes, then you swap what you want. Past plans are stored.
17. **Plan history / calendar view**: a month view of what you ate, with ratings. It's a nice home for "cooked", "not made" and "eating out".
18. **Export to calendar (ICS)**: the week's meals as calendar events, so they show on the phone's calendar. A download needs only one read-only endpoint. A calendar the phone keeps up to date needs a URL with a key in it: a new kind of personal key, since today's keys can only save links.

**Compra**

19. **Group by recipe** as an alternative to aisle ("everything for Thursday's curry"), for people who shop for a few days at a time. The item → recipes link exists.
20. **Shop up to a day**: generalise "From today only" into "From today until Wed". The API takes `from`; it would also take `to`. No new data.
21. **Use-it-up hints**: "You'll have half a bunch of coriander left after Tuesday. 3 recipes also use it." Data is in the shared-ingredient reasons and the canonical keys.

**Tastes and insights**

22. **"Your kitchen" stats page**: most cooked recipes, best rated, new this month, share by platform, how often the menu was followed (cooked vs not made), extraction success rate. All from existing tables. It also makes a good, shareable end-of-year "Tabooki year".
23. **Swap feedback log**: a readable list of past swaps and reasons behind the suggestions, so people see why Tabooki suggests something.

**App-wide**

24. **Onboarding checklist** for new Google sign-ups (they start with an empty household now): ① save 3 recipes ② set up sharing on your phone ③ tell us your tastes ④ plan your first week. Each step can be read from counts we already have.
25. **Live sync with Mercure**: the shopping list refreshes every 5 s, while the plan (§3) wanted Mercure. This is infrastructure, not data, and it would make checking items off together feel instant.

### 3.2 🧱 Needs changes to the data model

Ordered by value, highest first. Breakfast, snacks and merienda are left out: Tabooki plans lunch and dinner (decided 2026-11-28).

1. **Household members and invites.** Since open Google sign-up, everyone lands alone in their own household, and there's no way to join someone's. This is the most important gap now: shared menu and shared shopping are the app's point.
   **Needs:** a `household_invite` table (household, token hash, invited email or open link, expiry, created by, accepted by), a way to move a user with an empty household, roles (owner/member), and a members list in Settings.
2. **Pantry ("at home").** Today only "staple" (always at home) exists. A real pantry would take what you have out of the list and feed "What can I make" (💡 7).
   **Needs:** a `pantry_item` table (household, ingredient, quantity/unit or just "have", updated at), or a simpler `ingredient.inPantry` + date.
3. **Push notifications.** Needed for timers on a locked screen (UI-IMPROVEMENTS B2), "Next week has no menu" on Sunday, "Recipe ready" when the app is closed, and "Rate yesterday's dinner".
   **Needs:** a `push_subscription` table (user, endpoint, keys, created at), VAPID keys, a scheduled job, and notification preferences per user.
4. **Meal times per slot.** This makes "Start cooking at 19:20 for dinner at 20:30" possible on Hoy, and properly timed push reminders.
   **Needs:** `household.lunchAt`, `household.dinnerAt` (or one per slot), and optionally per day.
5. **Your own photos.** For recipes written by hand (no photo today) and "how ours turned out" pictures.
   **Needs:** recipe image upload (`recipe.photoPath`, or a `recipe_photo` table with meal entry, user and date). Flysystem storage already exists.
6. **Step photos from the video.** Extraction samples video stills but doesn't keep them. Keeping one per step would make cook mode much more visual.
   **Needs:** store the selected frames (`recipe.steps[].image`, or a `step_media` table) plus the files on the volume.
7. **Cooking notes per meal.** "Too salty, use half the stock next time", attached to that cooking and shown the next time it's planned.
   **Needs:** `meal_plan_entry.note`, or a `cook_note` table (recipe, entry, user, text, date).
8. **Cuisine as a fact.** The PLAN listed `recipes.cuisine`, which was never built: cuisine lives in free tags. A real field would allow cuisine filters, "variety across the week" and nicer shelves. (Course, meaning main, side and so on, is part of 3.0 A.)
   **Needs:** `recipe.cuisine`, read by the annotate step.
9. **Equipment.** Oven, air fryer, Thermomix, slow cooker, pressure cooker. Useful for "no oven this week" (summer) and filters.
    **Needs:** `recipe.equipment[]` (annotate step) and a taste rule or tweak that understands it.
10. **Active vs hands-off time and difficulty.** "Weekday" should mean little *hands-on* time, not little total time: a 3-hour stew that needs 15 minutes of work is fine. Prep and cook minutes don't say this.
    **Needs:** `recipe.activeMinutes`, `recipe.difficulty` (annotate step), with the planner's weekday limit reading `activeMinutes`.
11. **Seasonality.** An "in season" badge, and a planner preference for seasonal produce (Spanish calendar).
    **Needs:** `food.seasonMonths[]` (shared catalog, filled by the model like the other food facts).
12. **Plan-ahead steps.** "Soak the chickpeas tonight", "thaw the fish": reminders the day before.
    **Needs:** `recipe.aheadSteps` (e.g. `{what, hoursBefore}`), read by the annotate step. It pairs with 💡 2 and 🧱 3.
13. **Prices and the week's cost.** An estimated cost per meal and for the shopping list, and "cheap week" as a tweak.
    **Needs:** `food.pricePerKg` (or per unit), and optionally per household or store. Price data is the hard part: start with model estimates, editable by hand, like the nutrition.
14. **Store order.** The shopping list ordered the way *your* supermarket is laid out.
    **Needs:** `household.aisleOrder[]` (or per store: a `store` table with its own order).
15. **Freezer and batch cooking.** Generalise "cook once, eat twice" from "dinner → next lunch" to "cook 8 servings Sunday, freeze 4, plan them in 2 weeks".
    **Needs:** leftovers linkable to any later meal, in this week or a later one (today `leftoverOf` is only the next day's lunch), and a `freezer_item` table (recipe, servings, frozen on).
16. **Per-member tastes and portions.** "Leo is vegetarian" or "kids' portions", which reverses the PLAN's "one profile per household" decision. Only worth it if real households ask for it.
    **Needs:** `taste_profile.user_id` (or `member` rows that aren't users, for children), and portion weights per member.
17. **Public recipe sharing.** Send a recipe to a friend who doesn't use Tabooki, or let them "save to my Tabooki".
    **Needs:** `recipe_share` (recipe, token, created by, expires), and a public read-only page. It needs care about creators' content: link back to the original prominently.
18. **User collections.** "Navidad", "Para invitados", "Tupper". Tags may already cover this; add collections only if tags feel wrong in the new design.
    **Needs:** `collection` + `collection_recipe` (household, name, emoji or cover, position).

---

## Part 4 — Screens and states the redesign has to cover

This is a checklist for the new design system: each one exists today.

- **Auth:** Sign in (Google + email), forgot password, reset password (valid link, used or expired link).
- **Hoy:** with menu (lunch first or dinner first), no menu this week, no menu next week, eating out, leftovers, locked, "To do" (ratings, not made, shopping progress), account sheet.
- **Recetas:** list (cards, rediscover row, inbox banner, inbox open), search with results, search by meaning, no matches, empty library, filters sheet, sort, add-recipe sheet, import toasts.
- **Recipe:** full recipe, needs review, translated, being re-extracted, without photo, substitutes sheet, nutrition card (complete or partial), similar row, add-to-menu sheet, edit and new form, weights section.
- **Cook mode:** get ready (scaled, with × lines), step, step with timers (one, several, time's up), end with rating, screen-wake indicator.
- **Menú:** setup (slots, servings, leftovers, time limit, and, with 3.0 B, meals already chosen), week (rows, warnings, locked, not made, eating out, empty slot), past week, meal sheet, swap suggestions, picker, "why are you swapping it?", "anything special this week?" (open, applying, applied, couldn't apply), regenerate and delete, empty library.
- **Compra:** aisles, item "…" panel, staples, typed items, in the cart, all done, offline and sending, sorting into aisles, no menu this week, "From today only", share.
- **Tastes:** intro, 7 questions, dictation, thinking, follow-ups, "what we understood" with warnings, saved view with in-place edits, suggestions, redo menu prompt, delete.
- **Ingredients:** list, ingredient page, repoint search, correct figures form, substitutes editor.
- **AI costs:** summary, months, by task or model, latest calls.
- **Settings:** account, language, save from your phone (Android text, iPhone steps, keys), password (change or add).
- **Shared bits:** bottom tab bar, page header with back, sheet, toast (with undo or a link), star rating, tag input, dictate button, week navigation, skeletons, splash, platform badge, thumbnail fallback.
