# UI improvements

_Review of 2026-11-24: each screen used at phone width with a throwaway account, plus a read of the code. The `.pen` design wasn't used (it may be out of date)._

Every item below works with what the API already returns, so it only needs frontend changes. Work that needs the backend is listed separately at the end. They're ordered by need, and each one stands alone.

**Status:** ☐ to do · ◐ proposed (PNGs shown, waiting for approval) · ☑ done. The workflow is the `ui-improvement` skill in `.claude/skills/`.

| # | Screen | Improvement | Status |
|---|---|---|---|
| 1 | Compra | [One line per item](#1-shopping-one-line-per-item) | ☑ |
| 2 | Menú | [Say what the ⚠ means on the row](#2-menu-say-what-the--means) | ☑ |
| 3 | Menú, Compra | [Undo after "Fuera"](#3-undo-after-fuera) | ☑ |
| 4 | Menú, Compra | [Placeholders while loading; don't wait for last week](#4-menu-and-shopping-placeholders-while-loading) | ☑ |
| 5 | Hoy | ["No lo hicimos" on pending ratings](#5-today-no-lo-hicimos-on-pending-ratings) | ☑ |
| 6 | Cocinar | [Timers survive leaving cook mode](#6-cook-mode-timers-survive-leaving-the-screen) | ☑ |
| 7 | Ajustes | [Back goes back; everyday settings first](#7-settings-back-goes-back-everyday-settings-first) | ☑ |
| 8 | Recetas | [Show "on the menu" and rating on cards](#8-library-show-on-the-menu-and-rating-on-cards) | ☑ |
| 9 | Receta | [Tidy the facts above the ingredients](#9-recipe-tidy-the-facts-above-the-ingredients) | ☑ |
| 10 | Recetas | [Rethink filtering](#10-library-rethink-filtering) | ☑ |

---

## 1. Shopping: one line per item

**Problem.** Each item takes 2–3 lines: name and amount, every recipe that uses it, and "Si no hay: …". A 23-item week fills about three phone screens, which is slow to scan in the shop.

**Change.** The row shows only name and amount. The recipes and the substitutes move into the panel the "…" button already opens.

**Where.** [ItemRow.tsx](../frontend/src/components/shopping/ItemRow.tsx) (the `item.recipes` and `item.substitutes` lines, and the `open` panel).

**Data.** Already on every item. Typed-in items have no "…" button (no `ingredientId`), but they have no recipes or substitutes either.

**Done when.** A planned week's list shows one line per item, and "…" reveals the recipes, the substitutes, the aisle and the staple toggle.

**Mockup:** [before](ui-proposals/01-shopping-one-line/before.png) · [after](ui-proposals/01-shopping-one-line/after.png). **Done 2026-11-24:** items without an ingredient but with recipes or substitutes also get "…" (panel without the aisle controls). The "…" label now reads "Más sobre {name}: recetas, sustitutos y sección".

---

## 2. Menu: say what the ⚠ means

**Problem.** Some meal rows show a red triangle with no explanation. You only learn what it means ("Se repite: no había otras recetas para esta cena") by opening the meal.

**Change.** Show the first warning as a short line on the row, in place of the minutes line, keeping the icon.

**Where.** [MealRow.tsx](../frontend/src/components/plan/MealRow.tsx) only calls `hasWarning(entry)`. `mealReasons(entry, t, language).warnings` in [reasons.ts](../frontend/src/components/plan/reasons.ts) already builds the text that [MealSheet.tsx](../frontend/src/components/plan/MealSheet.tsx) shows.

**Data.** Already on the entry.

**Done when.** A meal with a warning shows its reason on the menu row without being opened.

**Mockup:** [before](ui-proposals/02-menu-warning-text/before.png) · [after](ui-proposals/02-menu-warning-text/after.png). **Done 2026-11-24:** a first mockup with the full sentence on the row was rejected as too much text. Built instead: a short label per warning (`plan.why.short.*`, "⚠ Se repite") on the minutes line, replacing the triangle on the right; the full sentence stays in the meal sheet.

---

## 3. Undo after "Fuera"

**Problem.** In the meal sheet, "Fuera" removes the meal and closes the sheet at once: no confirmation, no way back. The × on a typed-in shopping item works the same way.

**Change.** After removing, show a toast with "Deshacer".

**Where.**
- [toast.ts](../frontend/src/lib/toast.ts) and [Toaster.tsx](../frontend/src/components/Toaster.tsx): `action` can only open a page (`{ label, to }`). Add a callback form (`{ label, onClick }`).
- [MealSheet.tsx](../frontend/src/components/plan/MealSheet.tsx) (`remove.mutate`): before removing, remember the meal's date, slot, recipe, servings and lock. Undo calls `useAddMeal` (date, slot, servings), then `useUpdateMeal` with `recipe` and `locked`.
- [ItemRow.tsx](../frontend/src/components/shopping/ItemRow.tsx) (manual item ×): undo adds the same text again with `useAddItem`.

**Known limit.** If the removed meal was cooked with leftovers planned on later days, removing it unlinks them (`MealPlan::removeEntry`), and undo can't link them again. See [Backend 1](#b1-restore-leftover-links).

**Done when.** Removing a meal or a typed-in item shows "Deshacer" for a few seconds, and tapping it brings the meal or item back.

**Mockup:** [before](ui-proposals/03-undo-fuera/before.png) · [after](ui-proposals/03-undo-fuera/after.png). **Done 2026-11-24:** toasts take an `undo` action and an icon of their own. A meal comes back with its day, meal, servings, recipe and lock. A meal that *was* leftovers comes back as a normal meal of the same recipe, and leftovers of a removed meal stay unlinked ([Backend 1](#b1-restore-leftover-links) covers both). Typed shopping items come back unticked.

---

## 4. Menu and Shopping: placeholders while loading

**Problem.** While loading, both pages show the week bar and nothing else (Hoy and Recetas show placeholders). On Menú, the planned week also waits for the *previous* week's menu, which only the "plan a new week" form uses.

**Change.**
- [PlanPage.tsx](../frontend/src/pages/PlanPage.tsx): `plan.isPending || previous.isPending ? null`. Show a placeholder while `plan` loads, render `PlanWeek` as soon as the plan arrives, and wait for `previous` only before showing `PlanSetup`.
- [ShoppingPage.tsx](../frontend/src/pages/ShoppingPage.tsx): `isPending ? null`. Show a placeholder there too.

**Data.** None needed.

**Done when.** On a slow connection (DevTools throttling), both pages show placeholders, and an existing menu appears without waiting for the previous week.

**Mockup:** [before](ui-proposals/04-loading-placeholders/before.png) · [after](ui-proposals/04-loading-placeholders/after.png). **Done 2026-11-24:** `PlanSkeleton` and `ListSkeleton`. A week with no menu still waits for last week's (behind the same placeholder) before showing the planning form, which starts from it.

---

## 5. Today: "No lo hicimos" on pending ratings

**Problem.** Under "Pendiente", meals from the last two days ask to be rated, and rating is the only option. If you didn't cook it, the card stays for two days. Worse, a recipe's "last cooked" date is simply the latest menu day it appeared on (`MealPlanRepository::lastCooked`), so meals that weren't cooked still count as cooked. That makes "Aún no la habéis hecho", the "última vez" date and "Redescubre" wrong.

**Change.** Add a small "No lo hicimos" button to each pending rating card. It removes that past meal from the menu, exactly what "Fuera" does in the meal sheet, so the card goes away and "last cooked" stays honest. The undo toast from #3 applies here too.

**Where.** `RateMeal` in [TodayPage.tsx](../frontend/src/pages/TodayPage.tsx), using `useRemoveMeal` from [plan.ts](../frontend/src/api/plan.ts).

**Data.** Already there: past meals can be removed (the meal sheet allows it on past days).

**Done when.** "No lo hicimos" removes the meal and its card, and that recipe's "última vez" no longer counts that day.

**Mockup:** [before](ui-proposals/05-not-cooked/before.png) · [after](ui-proposals/05-not-cooked/after.png). **Done 2026-11-24:** a first mockup with plain text beside the stars was turned down as not looking tappable. Built as an outlined tile at the card's right edge (crossed cutlery over "No lo hicimos"). Taking a meal off the menu with its undo is now one hook, `useTakeOffMenu`, shared with "Fuera" in the meal sheet. When a card goes, the next un-rated meal of the last two days can take its place.

---

## 6. Cook mode: timers survive leaving the screen

**Problem.** The current step is remembered across a reload (`useStoredPage`), but timers are not. Closing cook mode to look at the recipe, or iOS reloading the app, silently loses a running timer.

**Change.** Timers live in one corner, bottom right, just above Anterior/Siguiente (now pinned to the bottom), the same on every step. A step's timer is a pill there, and tapping it turns it into its countdown in the same place: only the clock, with the label only while several are going. The step's own button and the bar at the top are gone. Save the running timers (`label`, `seconds`, `endsAt`) in `sessionStorage` under the recipe's id, and restore them when cook mode opens. A timer that ended while you were away shows "¡Tiempo!" straight away.

**Where.** [useTimers.ts](../frontend/src/components/cook/useTimers.ts) (take the recipe id), [CookPage.tsx](../frontend/src/pages/CookPage.tsx). Copy the storage approach of `useStoredPage`, including the private-mode `try/catch`.

**Note.** The beep needs an `AudioContext` that was started by a tap. After a reload, a restored timer can only vibrate, or show the pulse, until the next tap. That's acceptable; say so in a comment.

**Done when.** Start a timer, close cook mode, reopen it: the timer is still counting down.

**Mockup:** before [1](ui-proposals/06-cook-timers-survive/before-1.png) · [2](ui-proposals/06-cook-timers-survive/before-2.png) — after [1](ui-proposals/06-cook-timers-survive/after-1.png) · [2](ui-proposals/06-cook-timers-survive/after-2.png) · [3](ui-proposals/06-cook-timers-survive/after-3.png) · [4](ui-proposals/06-cook-timers-survive/after-4.png)

**Done 2026-11-24.** Timers are saved per recipe in `sessionStorage` and keep counting through closing cook mode and a reload. One that ended meanwhile shows "¡Tiempo!" without ringing late. A timer belongs to the step it was started from, so the same "15 minutos" on two steps are two timers. On a short phone a long step, or the end screen, can sit under the pills until you scroll (the space under the content grows with them).

---

## 7. Settings: back goes back; everyday settings first

**Problem.**
- Day to day, settings opens as a sheet from the avatar on Hoy. But "← Ajustes" on Gustos de la casa, Ingredientes and Costes de IA always goes to `/settings`, a full page you never otherwise see, not back to where you were.
- In the sheet, one-time setup ("Guardar desde el móvil" with its long explanation and key form, Contraseña) takes up most of the space, ahead of Ingredientes and Costes.

**Change.**
- Give [useGoBack.ts](../frontend/src/lib/useGoBack.ts) a fallback parameter (it's hardcoded to `/recipes` today) and use it with `/settings` in [TastePage.tsx](../frontend/src/pages/TastePage.tsx), [IngredientsPage.tsx](../frontend/src/pages/IngredientsPage.tsx) and [CostsPage.tsx](../frontend/src/pages/CostsPage.tsx).
- In `SettingsContent` ([SettingsPage.tsx](../frontend/src/pages/SettingsPage.tsx)), order: account, language, Gustos, Ingredientes, Costes, then "Guardar desde el móvil" and Contraseña collapsed to one row each that expands, then Cerrar sesión.

**Done when.** Hoy → avatar → Ingredientes → back returns to Hoy. The sheet fits the everyday rows in one screen.

**Mockup:** before [1](ui-proposals/07-settings-back/before-1.png) · [2](ui-proposals/07-settings-back/before-2.png) · [3](ui-proposals/07-settings-back/before-3.png) — after [1](ui-proposals/07-settings-back/after-1.png) · [2](ui-proposals/07-settings-back/after-2.png) · [3](ui-proposals/07-settings-back/after-3.png) · [4](ui-proposals/07-settings-back/after-4.png)

**Done 2026-11-24.** The account sheet is in Hoy's address (`/?account`), so going back from Gustos, Ingredientes or Costes reopens it, and the phone's back button closes it. Opened directly, those pages fall back to `/settings`. "Guardar desde el móvil" is a row with "Configurar", like Contraseña. The link in "Añadir receta" goes to `/settings#share`, which opens it and scrolls to it.

---

## 8. Library: show "on the menu" and rating on cards

**Problem.** Browsing recipes to decide what to put on the menu, nothing shows what's already planned this week. Recipes can be sorted by rating, but the cards never show it.

**Change.** On [RecipeCard.tsx](../frontend/src/components/library/RecipeCard.tsx):
- A small badge "En el menú · jue" (its next planned day from today on) when the recipe is on this week's menu.
- "★ 4" next to the minutes when `recipe.rating` is set.

**Where.** [LibraryPage.tsx](../frontend/src/pages/LibraryPage.tsx): fetch this week's menu with `useMealPlan(mondayOf(today()))` (already done on Hoy) and pass a recipe id → date map down to the cards.

**Data.** List items already carry `rating`. Menu entries carry `recipe.id` and `date`.

**Done when.** A recipe planned for Thursday shows the badge, and rated recipes show their stars on the card.

**Mockup:** before [1](ui-proposals/08-library-on-menu/before-1.png) · [2](ui-proposals/08-library-on-menu/before-2.png) — after [1](ui-proposals/08-library-on-menu/after-1.png) · [2](ui-proposals/08-library-on-menu/after-2.png) · [3](ui-proposals/08-library-on-menu/after-3.png)

**Done 2026-11-24.** `useOnMenu` (this week and next, from today, leftovers days left out) feeds the cards, "Hace tiempo que no" (which now skips planned recipes) and the recipe page. There the badge sits first among the tags and opens `/plan?week=…#day-…`, and the menu scrolls to that day. Beyond the week ahead the badge adds the date ("jue 3").

---

## 9. Recipe: tidy the facts above the ingredients

**Problem.** Before the ingredients there can be five separate blocks: the facts row, tags, "Lleva: …", "Alérgenos: …", and the taste-fit lines. Also:
- "⚖ Normal" alone is cryptic: the word "heaviness" is only in the `aria-label`.
- "Lleva" and "Alérgenos" often repeat the same thing ("Lleva: huevo" / "Alérgenos: huevo").
- Ingredients without an amount ("Aceite de oliva", "Sal") start in the amount column, so they don't line up with the other ingredients.

**Change.**
- Spell heaviness out (for example "Ni ligera ni contundente", or "Peso: normal"). That's an i18n string change.
- Merge "Lleva" and "Alérgenos" into one line, with the allergens marked (bold or small chips), and drop "Lleva" groups that the allergens already say.
- `IngredientLine`: when there's no amount, render an empty amount cell so the name lines up.

**Where.** [RecipePage.tsx](../frontend/src/pages/RecipePage.tsx) (header, `IngredientLine`).

**Data.** Already on the recipe.

**Done when.** The tortilla's header has one facts row, one "contains / allergens" line and the tags, and every ingredient name lines up.

**Mockup:** before [1](ui-proposals/09-recipe-facts/before-1.png) · [2](ui-proposals/09-recipe-facts/before-2.png) · [3](ui-proposals/09-recipe-facts/before-3.png) — after [1](ui-proposals/09-recipe-facts/after-1.png) · [2](ui-proposals/09-recipe-facts/after-2.png) · [3](ui-proposals/09-recipe-facts/after-3.png)

**Done 2026-11-24.** Heaviness shows only when it's light or hearty: "Normal" is gone rather than reworded. "Lleva" leaves out the groups the allergens already name (`NAMED_BY_ALLERGENS` in RecipePage). "Queso" stays next to "leche", because it says more. Both go on one line, and an ingredient without an amount gets an empty amount column.

---

## 10. Library: rethink filtering

**Problem.** One sideways-scrolling row mixes a reset ("Todas"), a panel toggle ("Más filtros"), a to-do ("Por revisar"), quick filters and every tag. Active filters far along the row scroll out of sight. "Más filtros" opens inline: about 25 chips that push the recipes off the screen.

**Change.** A filter button with a count badge sits beside the search box and opens a "Filtros" sheet with every filter (shortcuts, allergens, spice, calories, protein, tags), "Borrar" and a live "Ver N recetas". The chip row keeps the shortcuts and puts active filters first, without icons and with ×. "Todas", "Más filtros" and the inline panel go. Sort stays next to the count.

**Where.** [LibraryPage.tsx](../frontend/src/pages/LibraryPage.tsx): every filter is already in the URL with its toggle, and the sheet is [Sheet.tsx](../frontend/src/components/Sheet.tsx).

**Done when.** With any filters on, all of them are visible on Recetas and each can be removed with one tap. Opening the sheet never moves the list.

**Mockup:** before [1](ui-proposals/10-library-active-filters/before-1.png) · [2](ui-proposals/10-library-active-filters/before-2.png) · [3](ui-proposals/10-library-active-filters/before-3.png) — after [1](ui-proposals/10-library-active-filters/after-1.png) · [2](ui-proposals/10-library-active-filters/after-2.png) · [3](ui-proposals/10-library-active-filters/after-3.png)

**Done 2026-11-24.** Taps in the sheet replace the history entry, so back doesn't step through them. "Borrar" keeps the search words, and the empty state's "Quitar filtros" still clears those too. A protein pill reads "30 g de proteína o más", since "30 g o más" alone says nothing outside the sheet. "Todas" and "Más filtros" are gone from the strings.

---

## Needs backend (nice to have)

### B1. Restore leftover links ☑
Removing a meal unlinks the leftovers that depended on it (`MealPlan::removeEntry` sets `leftoverOf` to null), and `entries/update` couldn't set `leftoverOf`. So the undo in #3 couldn't relink them, and a removed leftovers meal came back as a normal meal.

**Done 2026-11-25.** `POST /meal-plans/{id}/entries/restore` puts the meal back with its own id, recipe, reasons, lock, rating and cooked state. It links it again to the meals that were its leftovers, or to the meal it was the leftovers of, while they're still the same dish. "Fuera"'s undo uses it ([useTakeOffMenu.ts](../frontend/src/components/plan/useTakeOffMenu.ts)).

### B2. Timer alerts with the screen locked
Timers only beep and vibrate while the page is open. Alerts from a locked phone need web push (a service worker, VAPID keys, and a backend that schedules the push for the timer's end).

### B3. A "cooked" flag on meals ☑
"Last cooked" was the latest menu day a recipe appeared on, whether or not it was cooked, and #5 worked around that by removing meals that weren't cooked.

**Done 2026-11-25.** Meals have `cooked`: true when cook mode reaches "¡Hecho!" (from the menu) or the meal is rated, false after "No lo hicimos", null while nobody has said. Saying it locks the meal, like a rating.
- "No lo hicimos" keeps the meal, which reads "No lo hicisteis" on Menú, with an undo. Its leftovers from today on stop being leftovers: that dish is cooked then instead.
- "Last cooked" counts meals said to be cooked, and past meals nobody said weren't. Today's meal counts once it's done, and leftovers don't count.
- The planner's no-repeat window leaves out meals that weren't cooked.
- `entries/update` takes `cooked` (null clears it) and `leftoverOf`, for the undo.

**Screens:** [Hoy before](ui-proposals/b3-cooked/1-hoy-pending.png) · [Hoy after "No lo hicimos"](ui-proposals/b3-cooked/2-hoy-after.png) · [Menú](ui-proposals/b3-cooked/3-menu.png)

### Minor (not in the top 10)
- Recipes without a photo show a large empty placeholder on Hoy, Recetas and the swap suggestions. Your library has a photo on every recipe today (0 of 15 missing), so this only matters for hand-written recipes.
- "Solo desde hoy" on Compra doesn't say what it changes. Something like "Solo lo de hoy en adelante · 9 comidas" would, using `list.meals`.
- "Desde Instagram, TikTok o YouTube" in "Añadir receta" opens the whole settings page. It could jump straight to the "Guardar desde el móvil" section.
