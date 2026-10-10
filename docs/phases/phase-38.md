# Phase 38 — Ask lives on Insights; the Ask page goes + release

*One place for what Logbook has spotted and what you've asked it.*

Status: ✅ complete · released as **v3.4.0** · open questions decided 2026-10-07 (#272–#276)

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

- **Insights page order** (#272): *Ask Logbook* card; MCP *Drafts to
  review* (while there are any); **Your questions**; computed insights;
  AI insights.
- **Your questions** is the list `/ask` shows today, newest first: title
  (the first question), last activity, *Delete*, and *Delete all* with
  its confirm. The latest 5 show; the rest (up to today's 50) sit under
  *Show all (N)*, a `<details>` disclosure that works without JS (#276).
  It shows only when Ask is available (§7.26 *Where*); with no threads it
  says "Nothing asked yet." and keeps *Keep conversations for*, which is
  always under the list (#275).
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
  goes. *Insights* stays where it is. The top-bar button stays and opens
  `/insights#ask` with the box focused (#273).
- **Other ways in:** the dashboard's Ask link and the phone app's quick
  action open Insights (with the box focused, `#ask`).
- **MCP drafts:** *Drafts to review* moves from `/ask` to the Insights
  page (above *Your questions*, while there are any), still also on the
  dashboard; the MCP tools' \"{link} to add it\" points there. Their
  buttons still work without Ask, as now.
- **Redirects:** `GET /ask` → `/insights` (301), keeping `?q=`;
  `GET /ask/threads/{id}` → `/insights/questions/{id}` (301). (`?draft=`
  was never an `/ask` parameter: *Edit* opens the entry form with
  `?draft=`, unchanged.) A `POST /ask` from an old open tab answers 303
  to the Insights box (or, with a `thread`, to that thread's page) with
  the question filled in, never dropped silently and never asked. The
  redirects are routed with AI off too; they land on Insights, which
  then has no Ask parts. The other old POST paths (feedback, delete,
  retention, Ask draft buttons) are removed, not redirected.
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
- [x] `spec.md` as *Spec changes*; open questions A–E decided first.
- [x] `ROADMAP.md` gains a Phase 38 row (📋); `open-questions.md` gains
      A–E (#272–#276); #192 and #193 noted as replaced by Phase 38.

### 38.1 Audit
- [x] List every link, form action, redirect, script, template, test and
      doc that names `/ask`, `AskPage`, `data-ask-entry` or
      `templates/ask/` (including `ask.js`, the PWA manifest's
      shortcuts, `docs/ai.md`, MCP tool messages, translations). Record
      it under this task; every item is moved, redirected or removed by
      38.2–38.5.

  Found (2026-10-07):
  - **Routes** (`config/routes.php`): `ask`, `ask.post`, `ask.progress`,
    `ask.retention`, `ask.threads.delete`, `ask.thread`,
    `ask.thread.delete`, `ask.feedback`, `ask.draft`.
  - **Actions** (`src/Action/Ask/`): `AskAction`, `AskPostAction`
    (redirects to `ask.thread`), `AskProgressAction` (`url` →
    `ask.thread`), `AskThreadDeleteAction` and `AskRetentionAction`
    (→ `ask`), `AskFeedbackAction` (`backOr(…, 'ask')`), `DraftAction`
    (`BACK` map `'ask'`, → `ask` / `ask.thread`). `AskGuard`,
    `DraftPrefill` stay.
  - **Service:** `Service/Ai/Ask/AskPage` (the page's context).
  - **Templates:** `layout.twig` (sidebar entry and top-bar button,
    `data-ask-entry`, `current_nav == 'ask'`), `home.twig` (dashboard
    link; `_review_drafts` with `back: 'home'`), `ask/index.twig`
    (`active_nav = 'ask'`), `ask/_card_form.twig` (posts to `ask.post`,
    `ask.progress`, suggestions to `ask?q=`), `ask/_review_drafts.twig`
    and `ask/_draft_card.twig` (`back` 'home' | 'ask', `ask.draft`),
    `insights/index.twig`. `ask/_card_head`, `_draft_notice` (entry
    forms) stay shared.
  - **JS:** `assets/js/ask.js` (comment names `/ask`; URLs come from data
    attributes), `tests/js/ask.test.js`.
  - **PWA:** `WebManifestAction` shortcut `/ask`.
  - **MCP:** `McpToolbox::keep()` link `home#draft-{id}`.
  - **Tests:** `AskPagesTest`, `DraftingTest`, `AiGatewayTest`,
    `DemoVisitorTest`, `RouteInventoryTest`, `McpToolsTest`,
    `tests/Support/AskTestCase`.
  - **Docs:** `docs/ai.md` (*Ask* section, retention), `docs/deployment.md`
    (proxy timeout `location <base>/ask` → `/insights/questions`),
    `docs/mcp.md`, README.
  - **Translations:** `ask.nav` stays (top-bar button label);
    `ask.lead`, `ask.title` stay (card); new keys for the thread page's
    back link and *Show all*.

### 38.2 Your questions on Insights
- [x] *Your questions* section on `/insights`: list, *Delete*, *Delete
      all* with confirm, *Show all*; only when Ask is available.
- [x] MCP *Drafts to review* on the Insights page.

### 38.3 Thread pages under Insights
- [x] `/insights/questions/{id}` with everything the Ask page shows for a
      thread; follow-ups post there.
- [x] New question from the Insights card opens its thread page; no-JS
      POST path; progress endpoint moved; `ask.js` updated (Enter sends,
      Shift+Enter new line, progress lines).
- [x] `?q=` prefill on `/insights`; `?draft={id}` *Edit* flow unchanged.

### 38.4 Remove the Ask page
- [x] Delete the `/ask` routes, page action, template and sidebar entry;
      the top-bar button as decided (B).
- [x] Redirects as in *Design decisions*.
- [x] Dashboard link, phone quick action, MCP \"{link} to add it\" point
      at Insights.
- [x] Remove translations only `/ask` used; add the new ones in every
      shipped locale.

### 38.5 Docs
- [x] `docs/ai.md`, README and screenshots: Ask is on Insights (no
      screenshot showed Ask; `docs/mcp.md` and `docs/deployment.md`'s
      proxy timeout path updated too).
- [x] Upgrade notes: the Ask page is gone; old links redirect; nothing to
      migrate (threads keep their ids). In `CHANGELOG.md`.

### 38.6 Tests
- [x] *Your questions* lists only the user's threads, newest first;
      *Delete* and *Delete all* work and need CSRF; hidden when Ask is
      unavailable (AI off, no `ask` model, `ai_ask` off, the user's *Use
      AI features* off).
- [x] Thread page: owner sees it; another user and an Ask-unavailable
      user get 404; follow-ups carry the earlier messages as before.
- [x] New question: no-JS POST lands on the thread page; progress
      endpoint works at its new path; the old path is 404.
- [x] Redirects: `/ask`, `/ask?q=`, `/ask?draft=`, an old thread URL; a
      stale `POST /ask` keeps the question and asks nothing.
- [x] Draft cards (Ask and MCP) *Add*, *Edit*, *Discard*, *Undo* work from
      the thread page and Insights; MCP drafts without Ask still work.
- [x] No *Ask* sidebar entry; navigation snapshot updated.
- [x] Existing Ask, drafting, MCP and Insights suites green after the
      route changes.
- [x] Design-reviewer clean of HIGH findings on Insights and the thread
      page at 375/768/1280 px, light and dark, with and without JS, by
      keyboard.
- [x] Suite green on SQLite, PostgreSQL, MySQL and MariaDB; coverage at
      or above the floor.

### 38.7 Release
- [x] `VERSION` → the next **minor** version (a page and a navigation
      entry are removed; no API change: the API and MCP stay as they
      are, apart from the link text).
- [x] `CHANGELOG.md`: *Changed* — Ask's questions are on Insights;
      *Removed* — the Ask page and its sidebar entry (old links
      redirect).
- [x] Rebuild assets; README and `ROADMAP.md` Phase 38 row ✅.
- [x] Tag once merged.

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

*Decided 2026-10-07 by the owner: A (1), B (2), C (1) as recommended;
D and E, found while starting, as below (#272–#276).*

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
- **D. Where *Keep conversations for* goes** (found while starting; it
  was only on `/ask`). Options: (1) inside *Your questions*, hidden with
  no threads; (2) always on Insights under the list; (3) Settings.
  *Decided:* (2).
- **E. How long *Your questions* is** (found while starting; `/ask`
  showed up to 50, no paging). Options: (1) latest 5 + *Show all*
  disclosure; (2) all of them; (3) latest 5 + a full-list page.
  *Decided:* (1).
- **Found by the reviews (2026-10-07):** #277 (the thread page's heading
  is the thread's title) and #278 (with AI off a thread's old address is
  302, not 301) built in this phase; #279 (cap MCP drafts) and #280 (an
  Insights query budget) logged in `open-questions.md` for a decision.
  #280 was decided in Phase 42. #279 *Decided 2026-10-10*: no cap; the
  Insights page shows the latest 5 drafts with *Show all (N)*, built in
  [Phase 41.8](phase-41.8.md).
