---
name: ui-improvement
description: Tabooki's workflow for UI changes, one at a time — first a before/after pair of PNGs of the real app plus a short "why it's better", then (only once the user accepts the PNGs) the real code change. Use it whenever the user wants to work on, propose, mock up or implement an item from docs/UI-IMPROVEMENTS.md ("let's do #3", "next UI improvement", "show me the shopping list change"), or any other visible change to tabooki's frontend screens, even if they don't mention PNGs or mockups.
---

# UI improvement: propose with PNGs, implement after approval

Each UI change goes through two gates, because the user decides on how the change *looks* before any code changes:

1. **Proposal:** two PNGs of the same screen, `before.png` (the app as it is) and `after.png` (as proposed), with a short reason why the proposal is better. Then stop and wait.
2. **Implementation:** only after the user accepts the PNGs. Build what `after.png` shows, check it in the browser, and mark the item done.

Don't edit source files under `frontend/` or `backend/` before the user accepts. The mockup is made by editing the live page in Chrome (step 4), not the code. If the user asks for changes to the proposal, make a new `after.png` and ask again.

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

If the item needs a state the script doesn't make (an inbox link, a taste profile, a checked item…), make it through the API or the UI of the smoke household. If the script fails because the smoke user already exists, run `smoke_down.py` first.

In Chrome (load the `anthropic-skills:chrome-browser` skill first if this session hasn't used Chrome yet):

1. Make a new tab. Resize the window to **614 × 1350**. Chrome won't go narrower than ~614 CSS px, and the screen caps the height (on this Mac the page ends up 614 × 813, which is 390 × 516 once zoomed). That's a short phone, so scroll to the part the item is about rather than trying to fit the whole screen.
2. Open `http://127.0.0.1:5175/login` and sign in with `javascript_tool`:
   `await fetch('/api/login', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({email: 'smoke@example.invalid', password: 'smoke-pass-123'})})`
3. **Then** clear the cached "signed out" state, or the app keeps showing the login page: `localStorage.removeItem('tabooki-offline')`.
4. Navigate to the screen, then set a true phone width (390 CSS px):
   `document.documentElement.style.zoom = String(innerWidth / 390)`
   A full navigation (the `navigate` tool, a reload) resets the zoom: set it again after each one. Navigating by clicking inside the app keeps it.

Wait for things that load in the background (for example "Ordenando los ingredientes por secciones…" on Compra) before taking the "before" screenshot.

## 3. `before.png`

Scroll to the part of the screen the item is about (`window.scrollTo(0, y)` with a number you reuse for the "after"). Move the mouse out of the way (`hover` at the top-right corner) so the cursor isn't in the picture. Then take a `screenshot` with `save_to_disk: true`. It's saved as a JPEG at the path the tool prints. Convert it into the item's folder:

```bash
mkdir -p docs/ui-proposals/NN-slug
sips -s format png "<saved .jpg path>" --out docs/ui-proposals/NN-slug/before.png
```

`NN` is the item number with two digits, and `slug` is a few words from its title (`01-shopping-one-line`).

## 4. `after.png`: edit the live page, not the code

Without touching the source, change the DOM of the same page in the same state with `javascript_tool` until it shows the proposal, then screenshot it from the **same scroll position** so the two PNGs compare one-to-one. If the change makes things taller or shorter above that point, the same scroll number shows different content: scroll so the same heading sits at the same height instead (`heading.getBoundingClientRect().top + scrollY - offset`). Convert it the same way into `after.png`.

- Reuse what's already on the page: clone an existing element and change its text, hide or move elements, copy the class lists of similar elements. Tailwind only generates classes that appear in the source, so a new class combination may do nothing; use inline `style` for anything new (colours from the CSS variables, e.g. `var(--muted-foreground)`).
- Use the real data on the page (names, amounts, warnings…). Don't invent content that the API wouldn't give.
- Keep added text to the minimum: a two- or three-word label beats a sentence on a list row, and the full text can stay one tap away. The user rejected a proposal (#2, full warning sentence on each meal row) for having too much text on screen. The same goes for the notes on the proposals page: a sentence or two each.
- Show the state that makes the point. If the proposal *is* something that appears after an action (a toast, a warning line), show that state. If an action only reveals where things moved (for example details moved into a "…" panel), keep `after.png` in its everyday state and describe the revealed part in words: an open panel can eat the space and hide the benefit. It's still two PNGs; offer to show the other state if it matters.
- React may re-render and undo your DOM edits (a background refetch, a live update). If that happens, take the screenshot right after the edit, in the same `browser_batch`.

Look at both PNGs before showing them: same scroll position, nothing cut off, the change visible at a glance.

## 5. Present the proposal and stop

The user often reviews from their phone (Claude Code's remote session), where local file paths don't open. So show the **images themselves**, not paths: they go on the private proposals page, **https://claude.ai/artifact/MK2kWgvstmnTjUkWVQWRof** ("Tabooki UI proposals"). Its source is [docs/ui-proposals/index.html](../../../docs/ui-proposals/index.html).

1. In `index.html`, add the new proposal as an `<article class="proposal">` right after the header, in place of the "Nothing waiting right now." line: copy the structure of the existing one (number and screen, a "Waiting for your OK" status, the title, the two `<figure>`s with real `alt` text and the PNGs' pixel `width`/`height`, "Why it's better", "What it costs"). Older proposals stay below under "Done" as `<details>`.
2. Publish it with the Artifact tool. If this conversation hasn't published it yet, first `read` it with `url` set to the page's address. Then publish with that `url`, `file_path` `docs/ui-proposals/index.html`, and `files` mapping each PNG's served path (`NN-slug/before.png`) to its file under `docs/ui-proposals/`. Map only the new PNGs; files already published stay. Don't pass `icon`.
3. In the reply, give the page link first, then the same short text:
   - **Why it's better:** 2–4 sentences on what the person using the app gets (faster to scan in the shop, no hidden meaning, fewer taps…), not on the code.
   - **What it costs or leaves out**, in a line or two: what moves or gets hidden, what needs the backend, any limit.
   - The question: accept, or what to change.

Mark the item ◐ in the table in `docs/UI-IMPROVEMENTS.md`. Leave the dev server and smoke household running while waiting, because the next step needs them. Don't start implementing in the same turn.

## 6. After acceptance: implement

- Make the change in the source, matching `after.png`. Follow the code's existing style (comments that say why, i18n keys in both `es` and `en`, the `sticker`/`Chip`/`Button` primitives in `components/ui.tsx`).
- Check: `cd frontend && npm run typecheck && npm run lint`.
- The smoke dev server runs with `hmr: false`, so reload the page (and set the zoom again). Put the screen in the same state and compare with `after.png`. If it differs, fix it, or say how and why it differs.
- In `docs/UI-IMPROVEMENTS.md`, mark the item ☑ and add the PNGs under its section (`**Mockup:** [before](ui-proposals/NN-slug/before.png) · [after](ui-proposals/NN-slug/after.png)`).
- On the proposals page, move the item into "Done": turn its `<article>` into a `<details class="proposal">` with a "Built" status (copy #1's shape) and add `loading="lazy"` to its images. Note anything that differs from the mockup, then republish (step 5.2).
- Tear down: stop the :5175 dev server (TaskStop), close the Chrome tab, and run
  `python3 -I .claude/skills/ui-improvement/scripts/smoke_down.py`.
- Commit only when the user asks, and then directly on `main`.

## 7. Report

Say what changed (files), how it was checked (typecheck, lint, the browser comparison), anything that differs from `after.png`, and which item comes next in the table.
