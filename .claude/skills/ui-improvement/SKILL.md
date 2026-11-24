---
name: ui-improvement
description: Tabooki's workflow for UI changes, one at a time — first before/after PNGs of the real app (a pair, or a short sequence when the change is a flow of taps) plus a short "why it's better", then (only once the user accepts the PNGs) the real code change. Use it whenever the user wants to work on, propose, mock up or implement an item from docs/UI-IMPROVEMENTS.md ("let's do #3", "next UI improvement", "show me the shopping list change"), or any other visible change to tabooki's frontend screens, even if they don't mention PNGs or mockups.
---

# UI improvement: propose with PNGs, implement after approval

Each UI change goes through two gates, because the user decides on how the change *looks* before any code changes:

1. **Proposal:** PNGs of the app as it is (`before`) and as proposed (`after`), with a short reason why the proposal is better. Then stop and wait. One pair is the default. When the change is about what happens as you use it (tap, then move on, then come back), show the journey as a short numbered sequence on each side instead. See "One pair or a flow" below.
2. **Implementation:** only after the user accepts the PNGs. Build what the `after` PNGs show, check it in the browser, and mark the item done.

Don't edit source files under `frontend/` or `backend/` before the user accepts. The mockup is made by editing the live page in Chrome (step 5), not the code. If the user asks for changes to the proposal, make new `after` PNGs and ask again.

The list of improvements, with their status, is [docs/UI-IMPROVEMENTS.md](../../../docs/UI-IMPROVEMENTS.md). Status marks: ☐ to do, ◐ proposed (PNGs shown, waiting), ☑ done.

## 1. Pick the item and re-check it

- Take the item the user named, or else the first ☐ in the table.
- Re-read its section *and the code it points to*: the code may have changed since the list was written. Line numbers may have moved.
- Re-check feasibility: the change must work with what the API already returns (look in `frontend/src/api/schema.d.ts` and the hooks in `frontend/src/api/`). If something needs backend work, say so in the proposal and keep that part out of the PNG, or label it in the PNG as needing the backend.
- The `.pen` design file may be out of date. The running app and the code are the truth.

## 2. Bring up the test household and a phone-sized page

Never use the user's real account for screenshots. Use the throwaway household:

```bash
python3 -I .claude/skills/ui-improvement/scripts/smoke_up.py   # from the repo root
```

It creates `smoke@example.invalid` (password `smoke-pass-123`, a test value for this local app), six typed-in recipes, and menus for last week and this week, and writes `frontend/vite.smoke.config.ts`. Then start the throwaway dev server with Bash `run_in_background`:

```bash
cd frontend && node_modules/.bin/vite --config vite.smoke.config.ts   # http://127.0.0.1:5175
```

If the item needs a state the script doesn't make (an inbox link, a taste profile, a checked item…), make it through the API or the UI of the smoke household. For a loading state, start the dev server with `SMOKE_SLOW='<regex of API paths>'` (for example `SMOKE_SLOW='^/api/(meal-plans|shopping-lists)'`): matching requests are held back 60 s (`SMOKE_SLOW_MS` to change it). Patching `window.fetch` in the page doesn't work, because the API client keeps the original `fetch` from when it loaded. Restart without the variable afterwards. If the script fails because the smoke user already exists, run `smoke_down.py` first.

In Chrome (load the `anthropic-skills:chrome-browser` skill first if this session hasn't used Chrome yet):

1. Make a new tab. Resize the window to **614 × 1350**. Chrome won't go narrower than ~614 CSS px, and the screen caps the height (on this Mac the page ends up 614 × 813, which is 390 × 516 once zoomed). That's a short phone, so scroll to the part the item is about rather than trying to fit the whole screen.
2. Open `http://127.0.0.1:5175/login` and sign in with `javascript_tool`:
   `await fetch('/api/login', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({email: 'smoke@example.invalid', password: 'smoke-pass-123'})})`
3. **Then** clear the cached "signed out" state and leave the login page in the same call: `localStorage.removeItem('tabooki-offline'); location.href = '/'`. Otherwise the app keeps showing the login page, because while you're still on it the login page saves "signed out" again.
4. Navigate to the screen, then set a true phone width (390 CSS px):
   `document.documentElement.style.zoom = String(innerWidth / 390)`
   A full navigation (the `navigate` tool, a reload) resets the zoom: set it again after each one. Navigating by clicking inside the app keeps it.

If a page stays on its loading state or shows a load error, check `await fetch('/api/me')` in the page: the session can expire mid-item (401), and signing in again (step 2) fixes it.

Wait for things that load in the background (for example "Ordenando los ingredientes por secciones…" on Compra) before taking the "before" screenshot.

## 3. `before` PNGs

**One pair or a flow.** Use one `before.png` and one `after.png` when a single moment shows the difference (a row that got shorter, a label that appeared). Use a numbered sequence (`before-1.png`, `before-2.png`… and `after-1.png`, `after-2.png`…) when the point lies in how the screen responds across a few steps of a journey: what a tap turns into, what stays put when you move to the next step, what's there when you come back. #6 (cook timers) needed one: tap the timer, move to another step, leave cook mode and come back. Keep it to the steps that make the point, usually 2–4 per side. The two sides don't need the same number. Give each image a short caption saying what just happened ("2 · Tocas: cuenta atrás en el mismo sitio").


Scroll to the part of the screen the item is about (`window.scrollTo(0, y)` with a number you reuse for the "after"). Move the mouse out of the way (`hover` at the top-right corner) so the cursor isn't in the picture. Then take a `screenshot` (for a flow, one per step, doing the real taps in between) with `save_to_disk: true` and no `scale` (a scaled screenshot is saved at that smaller size). It's saved as a JPEG at the path the tool prints. Convert it into the item's folder:

```bash
mkdir -p docs/ui-proposals/NN-slug
sips -s format png "<saved .jpg path>" --out docs/ui-proposals/NN-slug/before.png   # or before-1.png, before-2.png… for a flow
```

`NN` is the item number with two digits, and `slug` is a few words from its title (`01-shopping-one-line`).

## 4. Think about the whole app, not just the element

Before making the `after` PNGs, design the change as part of the screen and the app, not as one element added or changed on its own. The user turned down an `after.png` (#6) that put a running "40:00" timer back on screen and still showed the "Temporizador 40 minutos" button that starts that same timer. That's two things saying the same, and a tap would start a duplicate.

- **The rest of the screen, in the new state.** Look at everything else visible once the change is in. What repeats it, contradicts it, or offers an action that no longer makes sense? Hide, merge or change those as part of the proposal. Don't leave them as they were just because the item didn't mention them.
- **Every state the change creates.** Follow it through before, during and after: running, ended, dismissed; none, one, many; loading, error. Each state should show only what's useful then. For example, the start button comes back once its timer is dismissed.
- **Where a control lives and what a tap does to it.** A control that starts something should turn into that thing in the same place: the start button *becomes* the countdown. It shouldn't stay put while the result appears somewhere else. Something that persists across steps or screens (a running timer, a selection) gets one fixed spot, the same on every step, ideally within thumb reach at the bottom. If that spot depends on something else staying in view (the bottom buttons), make sure it does. The user asked for exactly this on #6.
- **Other screens.** Look for other screens that show the same thing or do the same action (Hoy and Menú, the meal sheet and a meal row, the recipe page and cook mode). Use the same words, icons and components there. If the change belongs there too, include it, or say so in the proposal.
- In the proposal notes, mention these knock-on changes in a few words, and build them in step 7 along with the main change.

## 5. `after` PNGs: edit the live page, not the code

Without touching the source, change the DOM of the same page in the same state with `javascript_tool` until it shows the proposal, then screenshot it from the **same scroll position** so the two PNGs compare one-to-one. If the change makes things taller or shorter above that point, the same scroll number shows different content: scroll so the same heading sits at the same height instead (`heading.getBoundingClientRect().top + scrollY - offset`). Convert it the same way into `after.png` (or `after-1.png`…). For a flow, keep the mock in a `<style>` tag and elements appended to `<body>` (outside React's tree), with a small `window.__…` function that switches the mocked state. Then real in-app clicks (next step, close and reopen) re-render the page and your mock survives. Moving nodes React manages can break the page.

- Reuse what's already on the page: clone an existing element and change its text, hide or move elements, copy the class lists of similar elements. Tailwind only generates classes that appear in the source, so a new class combination may do nothing; use inline `style` for anything new (colours from the CSS variables, e.g. `var(--muted-foreground)`).
- Use the real data on the page (names, amounts, warnings…). Don't invent content that the API wouldn't give.
- Anything tappable must look tappable: use the app's own button shapes (`sticker-sm bg-card` outlined buttons, the icon-over-label tiles of the meal sheet, `Chip`), not bare muted text. The user turned down a plain-text "No lo hicimos" (#5) because it didn't look like something to press. Check that it fits at 390 px next to what's already there.
- Keep added text to the minimum: a two- or three-word label beats a sentence on a list row, and the full text can stay one tap away. The user rejected a proposal (#2, full warning sentence on each meal row) for having too much text on screen. On #6 the user cut the running timer down to just the clock ("39:41", not "39:41 40 minutos"): once something is running, show only what changes. Any label it needs can go in its `aria-label`. The same goes for the notes on the proposals page: a sentence or two each.
- Show the state that makes the point. If the proposal *is* something that appears after an action (a toast, a warning line), show that state. If an action only reveals where things moved (for example details moved into a "…" panel), keep `after.png` in its everyday state and describe the revealed part in words: an open panel can eat the space and hide the benefit. If the revealed state *is* the point, make it a flow instead (see step 3).
- React may re-render and undo your DOM edits (a background refetch, a live update). If that happens, take the screenshot right after the edit, in the same `browser_batch`.

Look at every PNG before showing them: same scroll position, nothing cut off, the change visible at a glance, and nothing left on screen that the change made redundant (step 4).

## 6. Present the proposal and stop

The user often reviews from their phone (Claude Code's remote session), where local file paths don't open. So show the **images themselves**, not paths: they go on the private proposals page, **https://claude.ai/artifact/MK2kWgvstmnTjUkWVQWRof** ("Tabooki UI proposals"). Its source is [docs/ui-proposals/index.html](../../../docs/ui-proposals/index.html).

1. In `index.html`, add the new proposal as an `<article class="proposal">` right after the header, in place of the "Nothing waiting right now." line: copy the structure of the existing one (number and screen, a "Waiting for your OK" status, the title, the `<figure>`s with real `alt` text and the PNGs' pixel `width`/`height`. A flow goes in a `<div class="flow">` per side, with an `<h3>` "Antes"/"Después" and numbered captions, as #6 does. "Why it's better", "What it costs"). Older proposals stay below under "Done" as `<details>`.
2. Publish it with the Artifact tool. If this conversation hasn't published it yet, first `read` it with `url` set to the page's address. Then publish with that `url`, `file_path` `docs/ui-proposals/index.html`, and `files` mapping each PNG's served path (`NN-slug/before.png`) to its file under `docs/ui-proposals/`. Map only the new PNGs; files already published stay. If a redo renames or drops PNGs, map the old served paths to `null`. Don't pass `icon`.
3. In the reply, give the page link first, then the same short text:
   - **Why it's better:** 2–4 sentences on what the person using the app gets (faster to scan in the shop, no hidden meaning, fewer taps…), not on the code.
   - **What it costs or leaves out**, in a line or two: what moves or gets hidden, what needs the backend, any limit.
   - The question: accept, or what to change.

Mark the item ◐ in the table in `docs/UI-IMPROVEMENTS.md`. Leave the dev server and smoke household running while waiting, because the next step needs them. Don't start implementing in the same turn.

## 7. After acceptance: implement

- Make the change in the source, matching the `after` PNGs. Follow the code's existing style (comments that say why, i18n keys in both `es` and `en`, the `sticker`/`Chip`/`Button` primitives in `components/ui.tsx`).
- Check: `cd frontend && npm run typecheck && npm run lint`. The repo has no formatter. Don't run Prettier with its defaults, because it rewrites whole files with semicolons and double quotes. Match the style by hand, or run `npx prettier --write --no-semi --single-quote --print-width 180 <files>`, then check with `git diff --stat` that only your lines changed.
- The smoke dev server runs with `hmr: false`, so reload the page (and set the zoom again). Put the screen in the same state (each step, for a flow) and compare with the `after` PNGs. If it differs, fix it, or say how and why it differs.
- Exercise what the change does, not only how it looks (an undo really undoes, a panel really opens). Toasts disappear after 6 s, faster than a `find` plus a click, so do the action and the follow-up tap in one `javascript_tool` call (`[...document.querySelectorAll('button')].find(b => b.textContent.trim() === 'Deshacer').click()`), then screenshot.
- In `docs/UI-IMPROVEMENTS.md`, mark the item ☑ and add the PNGs under its section (`**Mockup:** [before](ui-proposals/NN-slug/before.png) · [after](ui-proposals/NN-slug/after.png)`, or every numbered PNG for a flow).
- On the proposals page, move the item into "Done": turn its `<article>` into a `<details class="proposal">` with a "Built" status (copy #1's shape) and add `loading="lazy"` to its images. Note anything that differs from the mockup, then republish (step 6.2).
- Tear down: stop the :5175 dev server (TaskStop), close the Chrome tab, and run
  `python3 -I .claude/skills/ui-improvement/scripts/smoke_down.py`.
- Commit only when the user asks, and then directly on `main`.

## 8. Report

Say what changed (files), how it was checked (typecheck, lint, the browser comparison), anything that differs from the `after` PNGs, and which item comes next in the table.
