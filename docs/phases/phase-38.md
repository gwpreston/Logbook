# Phase 38 — Ask lives on Insights; the Ask page goes + release

*One place for what Logbook has spotted and what you've asked it.*

Status: 📋 planned · file lives in `docs/phases/`

Since Phase 33.4 the Insights page has the *Ask Logbook* card at the top,
but asking there always opens the thread on `/ask` (#193), and Ask keeps
its own sidebar entry, top-bar button and page (#192). The owner no longer
wants two places: **Ask's *Your questions* (the thread list with *Delete*
and *Delete all*) moves to the Insights page, and the Ask page is
removed.** Asking, the thread, sources, grounding, drafts, feedback, copy
and retention all keep working; they are reached from Insights instead.
This reverses #192 and #193.

Read [`CLAUDE.md`](../../CLAUDE.md), [`spec.md`](../../spec.md) §7.26
(*Where*, *Conversations*, *Drafting entries*, *Ask and the Insights
page*), §7.36 or wherever the MCP server's *Drafts to review* lives (the
`/ask` list), §8 (navigation) first, and
[Phase 33.4](phase-33.4.md) *Open questions* #192–#193.

**Prerequisites:** [Phase 37](phase-37.md) complete and green.

---

## Goals

1. **Your questions on Insights:** the thread list, with *Delete* and
   *Delete all*, on `/insights` when Ask is available.
2. **Asking from Insights** opens the thread on a page under Insights,
   not `/ask`.
3. **The Ask page goes:** no `/ask` page, no *Ask* sidebar entry; every
   other way in (top-bar button, dashboard link, phone quick action,
   suggestions, MCP links) points at Insights.
4. **Old links still land:** `/ask` and thread URLs redirect.
5. Release.

## Not in scope

- Any change to how Ask answers: tools, loop, grounding, system text,
  the one-at-a-time lock, failures.
- Drafting rules, *Add* / *Edit* / *Discard* / *Undo*, draft expiry.
- Thread storage (`ai_threads`, `ai_messages`), retention, backups.
- AI insights and the computed insights (§7.8, §7.26 *AI insights*).
- Making Insights available when AI is off differently from today: the
  page stays core; only the Ask parts are hidden.

---

## Design decisions

These follow from the owner's request. Anything not settled is under
*Open questions* and is not built until decided.

- **Insights page order:** *Ask Logbook* card; **Your questions**;
  computed insights; AI insights (placement of *Your questions* is open
  question A; this order is the recommendation).
- **Your questions** is the list `/ask` shows today, newest first: title
  (the first question), last activity, *Delete*, and *Delete all* with
  its confirm. A long list shows the latest few with *Show all* (count
  and paging as today's list; no new limit invented here). It shows only
  when Ask is available (§7.26 *Where*); empty, it is left out.
- **Thread page:** a thread opens on its own page under Insights
  (`/insights/questions/{id}`), with everything the Ask page shows for a
  thread today: the conversation, sources, grounding marks, draft cards,
  feedback, *Copy*, the follow-up box, the connection line. A breadcrumb
  or back link returns to *Insights*. Another user's thread answers 404,
  as now.
- **New question:** the Insights card posts as a new question and opens
  the new thread's page (replaces #193's \"opens on `/ask`\"). Without JS,
  the POST answers with the thread page; with JS, \"Reading your
  logbook…\" and the progress lines as today, then the thread page.
- **Suggestions:** the four suggestions fill the Insights box
  (`/insights?q=`) instead of `/ask?q=`.
- **Progress endpoint:** moves with the page
  (`/insights/questions/progress/{token}`); the old path is removed, not
  redirected (it is JSON polled by the page's own script).
- **Navigation:** the *Ask* sidebar entry (`forum`, `data-ask-entry`)
  goes. *Insights* stays where it is. The top-bar button is open
  question B.
- **Other ways in:** the dashboard's Ask link and the phone app's quick
  action open Insights (with the box focused, `#ask`).
- **MCP drafts:** *Drafts to review* moves from `/ask` to the Insights
  page (above *Your questions*, while there are any), still also on the
  dashboard; the MCP tools' \"{link} to add it\" points there. Their
  buttons still work without Ask, as now.
- **Redirects:** `GET /ask` → `/insights` (301), keeping `?q=`;
  `GET /ask?draft=…` and any thread URL under `/ask` → the matching
  Insights URL (301). A `POST /ask` from an old open tab is answered
  by redirecting to Insights with the question kept in the box, never
  dropped silently and never asked twice.
- **When Ask isn't available:** Insights shows no Ask card, no *Your
  questions*, no MCP drafts list unless MCP drafts exist (as §7.36
  says today), and the thread pages answer 404 — the same rule `/ask`
  has now.
- **Code:** `AskPage` and its actions become Insights' question actions;
  `DraftCards` stays shared. Templates under `templates/ask/` move or
  are deleted; nothing left that only `/ask` used.

---

## Spec changes

Written into `spec.md` before any code:

- §7.26 *Where*: Ask is on the Insights page (box, *Your questions*,
  thread pages); no `/ask` page; the redirects.
- §7.26 *Conversations*: threads listed on Insights under *Your
  questions*, with *Delete* and *Delete all*.
- §7.26 *Ask and the Insights page*: rewritten; #192 and #193 marked as
  replaced by this phase; the page order; suggestions to `/insights?q=`.
- §7.26 *Drafting entries* and the MCP section: \"`/ask`\" → the
  Insights page.
- §8: the sidebar without *Ask*; the top-bar button as decided (B).
- §13: the phase summary.

---

## Tasks

### 38.0 Spec first
- [ ] `spec.md` as *Spec changes*; open questions A–C decided first.
- [ ] `ROADMAP.md` gains a Phase 38 row (📋); `open-questions.md` gains
      A–C; #192 and #193 noted as replaced by Phase 38.

### 38.1 Audit
- [ ] List every link, form action, redirect, script, template, test and
      doc that names `/ask`, `AskPage`, `data-ask-entry` or
      `templates/ask/` (including `ask.js`, the PWA manifest's
      shortcuts, `docs/ai.md`, MCP tool messages, translations). Record
      it under this task; every item is moved, redirected or removed by
      38.2–38.5.

### 38.2 Your questions on Insights
- [ ] *Your questions* section on `/insights`: list, *Delete*, *Delete
      all* with confirm, *Show all*; only when Ask is available.
- [ ] MCP *Drafts to review* on the Insights page.

### 38.3 Thread pages under Insights
- [ ] `/insights/questions/{id}` with everything the Ask page shows for a
      thread; follow-ups post there.
- [ ] New question from the Insights card opens its thread page; no-JS
      POST path; progress endpoint moved; `ask.js` updated (Enter sends,
      Shift+Enter new line, progress lines).
- [ ] `?q=` prefill on `/insights`; `?draft={id}` *Edit* flow unchanged.

### 38.4 Remove the Ask page
- [ ] Delete the `/ask` routes, page action, template and sidebar entry;
      the top-bar button as decided (B).
- [ ] Redirects as in *Design decisions*.
- [ ] Dashboard link, phone quick action, MCP \"{link} to add it\" point
      at Insights.
- [ ] Remove translations only `/ask` used; add the new ones in every
      shipped locale.

### 38.5 Docs
- [ ] `docs/ai.md`, README and screenshots: Ask is on Insights.
- [ ] Upgrade notes: the Ask page is gone; old links redirect; nothing to
      migrate (threads keep their ids).

### 38.6 Tests
- [ ] *Your questions* lists only the user's threads, newest first;
      *Delete* and *Delete all* work and need CSRF; hidden when Ask is
      unavailable (AI off, no `ask` model, `ai_ask` off, the user's *Use
      AI features* off).
- [ ] Thread page: owner sees it; another user and an Ask-unavailable
      user get 404; follow-ups carry the earlier messages as before.
- [ ] New question: no-JS POST lands on the thread page; progress
      endpoint works at its new path; the old path is 404.
- [ ] Redirects: `/ask`, `/ask?q=`, `/ask?draft=`, an old thread URL; a
      stale `POST /ask` keeps the question and asks nothing.
- [ ] Draft cards (Ask and MCP) *Add*, *Edit*, *Discard*, *Undo* work from
      the thread page and Insights; MCP drafts without Ask still work.
- [ ] No *Ask* sidebar entry; navigation snapshot updated.
- [ ] Existing Ask, drafting, MCP and Insights suites green after the
      route changes.
- [ ] Design-reviewer clean of HIGH findings on Insights and the thread
      page at 375/768/1280 px, light and dark, with and without JS, by
      keyboard.
- [ ] Suite green on SQLite, PostgreSQL, MySQL and MariaDB; coverage at
      or above the floor.

### 38.7 Release
- [ ] `VERSION` → the next **minor** version (a page and a navigation
      entry are removed; no API change: the API and MCP stay as they
      are, apart from the link text).
- [ ] `CHANGELOG.md`: *Changed* — Ask's questions are on Insights;
      *Removed* — the Ask page and its sidebar entry (old links
      redirect).
- [ ] Rebuild assets; README and `ROADMAP.md` Phase 38 row ✅.
- [ ] Tag once merged.

---

## Acceptance criteria

1. With Ask available, Insights shows the *Ask Logbook* card and *Your
   questions*; asking opens the thread on a page under Insights, with
   everything the Ask page used to show.
2. There is no Ask page or *Ask* sidebar entry; every old `/ask` link
   lands on the matching Insights page.
3. With Ask unavailable, Insights shows no Ask parts and the thread pages
   are 404.
4. Drafts (Ask and MCP) and retention work as before.
5. Definition of done (CLAUDE.md §11) holds.

## Open questions

Numbered in `open-questions.md` when logged. Not built until decided.

- **A. Where *Your questions* sits on Insights.** Options: (1) under the
  *Ask Logbook* card, before the insights; (2) after the AI insights, at
  the foot of the page; (3) a tab or toggle (*Insights* / *Your
  questions*) on the page. *Recommendation:* (1), so a question asked is
  next to its answers and the box.
- **B. The top-bar *Ask* button.** Options: (1) remove it; (2) keep it,
  opening Insights with the box focused (`/insights#ask`); (3) replace it
  with an *Insights* button. *Recommendation:* (2): one tap to ask stays,
  and it lands in the new home.
- **C. Thread page or inline?** Options: (1) each thread on its own page
  under Insights (as drafted); (2) the open thread shown inline on the
  Insights page, replacing the list while open. *Recommendation:* (1):
  threads can be long, linked and bookmarked, and the Insights page stays
  short.
