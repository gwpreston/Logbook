# Phase 54 — Finance scenarios, quiet empty widgets and last month's sold vehicles

*A review of Logbook's priorities found most of them already built (the
guided first run in Phase 49, "as of" dates and the working in Phases
48.1–48.2, the MOT test button and fallbacks in Phase 41). This phase
takes what was left: a scenario suite that checks one vehicle's whole life
across the page, the API and the CSV; empty dashboard widgets that shrink
to one line; and last month's briefing figures that keep a vehicle sold
during that month (#374). It also stops the test bootstrap deleting the
dev database (K148).*

Status: ✅ complete · file lives in `docs/phases/` · a minor release

Read [`CLAUDE.md`](../../CLAUDE.md) (§11, §12), `spec.md` (§7.8 *Dashboard*,
§7.11 *The monthly briefing*, §7.35 *True cost*, §8 *Show the working*),
and [`open-questions.md`](open-questions.md) first.

**Prerequisites:** [Phase 53.20](phase-53.20.md) merged.

---

## Goals

1. **Finance scenarios.** One suite of whole-life scenarios, each a
   vehicle built from purchase to sale, checks that the cost-of-ownership
   page, `GET /api/v1/vehicles/{id}/ownership?include=working` and the
   CSV give the same figures, against golden values kept in
   `tests/Fixtures/golden/`. Scenarios: purchase, PCP and sale; insurance
   cover that starts before the purchase and runs past the sale; a claim
   with a payout; a stale valuation; a member without `ViewCosts`; two
   currencies; imperial units.
2. **Quiet empty widgets (#563).** A dashboard widget with nothing to
   show shrinks to its header and one muted line, in its place. *Needs
   attention* keeps its full "Nothing needs attention", which reassures.
   *Customise* shows every widget at full size.
3. **Cover outside ownership (#564).** In the true-cost yearly and
   12-month periods, a document's days of cover before the purchase or
   after the sale count in no period; *Since bought* still counts the
   whole document on its ledger date, and the working says why the years
   don't add up to it.
4. **Last month's sold vehicles (#374).** The briefing's *Last month*
   includes a recipient vehicle archived (sold, written off or just
   archived) on or after the first day of last month, so last month's
   spend is complete. The other sections stay active vehicles only.
5. **K148.** `tests/bootstrap.php` refuses to run unless `APP_ENV` is
   `testing`, so phpunit without its configuration can't migrate or
   delete the dev database.
6. **Docs.** `docs/configuration.md` points to `docs/mot-history.md` for
   MOT history (set in Settings, not the environment).

## Not in scope

- A per-user "simple / advanced" mode: module switches (§7.10) and the
  Household layout (Phase 49.2) already give a short app.
- Assigning actions to household members: a feature of its own, listed in
  spec §12 *After 1.0* as a candidate.
- A source date on the fuel-saving insight: it already uses only prices
  no older than 48 hours and says "Today's prices" (§7.8).
- #375–#377 (other briefing questions), still open for the owner.

---

## Decisions (and why)

- **#563 (decided 2026-10-28):** collapse to one line, not hide, so the
  layout the owner arranged doesn't jump about as data comes and goes.
- **#564 (decided 2026-10-28):** drop the outside days. They paid for
  someone else's ownership; folding them into the first year would put
  costs in a period the vehicle wasn't owned.
- **#374 (decided 2026-10-28):** include. A month's spend that leaves out
  the car sold in it would undercount, and the Reports page counts it.

## Tasks

### 54.0 Start of phase

- [x] Read `open-questions.md` and the *Open* and *Scheduled* rows of
      `known-issues.md` (CLAUDE.md §12).

### 54.1 Finance scenarios

- [x] `tests/Integration/Scenario/`, one test class per scenario, built
      with the existing fixtures (`tests/Support/CostFixtures.php`,
      `FinanceFixtures`, `TrueCostFixtures`), each checking the page, the
      API (`?include=working`) and the CSV against its golden file.
- [x] Run on all four engines (`bin/test-all-dbs.sh`).

### 54.2 Quiet empty widgets (#563)

- [x] The dashboard view says per widget whether it is empty (from the
      data it already has; no logic in templates); `home.twig` adds
      `widget--empty`, and the CSS shrinks it. Not in *Customise*, and
      never *Needs attention*.
- [x] Tests: each widget's empty state is flagged, a widget with data is
      not, *Customise* shows them full size; works without JS.

### 54.3 Cover outside ownership (#564)

- [x] Confirm `TrueCost` drops the outside days (a scenario of 54.1);
      the true-cost working names the dropped amount when there is one.

### 54.4 Last month's sold vehicles (#374)

- [x] The digest's *Last month* takes recipient vehicles archived on or
      after the first of last month as well as the active ones.
- [x] Tests: sold mid-month is listed with its distance and spend; sold
      before last month is not; other sections unchanged.

### 54.5 K148 and docs

- [x] `tests/bootstrap.php` stops with a message unless the environment
      is `testing`.
- [x] `docs/configuration.md` links MOT history.

### 54.6 Finish

- [x] K148 *Fixed*; #374, #563, #564 marked built.
- [x] CHANGELOG, spec §13, ROADMAP status.
- [ ] Merge review (spec-keeper, bug-hunter, design-reviewer,
      api-contract-checker), then merge.

### Found by the merge reviews (2026-10-28)

- **First review (at `1fde47a1`), fixed here:** the user guides for #374, #563 and #564 (`docs/reports.md`,
  `docs/notification-channels.md`, `docs/getting-started.md`); spec §7.8
  lists the widgets that shrink and the ones that never do; an empty *True
  cost* drops its period switch and an empty *Recent fuel* its button, so
  each is one line; a document wholly before the purchase or after the
  sale is named in the working too; the German notes reworded.
- **Second review (at `511158c8`), fixed here:** spec §7.35 covers a
  document wholly outside ownership; spec §7.11 and the webhook guide say
  `last_month` may list an archived vehicle; working notes are described
  as display text in `openapi.json`, `docs/api.md` and `docs/mcp.md`.
- **Third review (at `e5424949`):** MERGE.
- **Left as follow-ups,** logged in `known-issues.md`:
  - From the first two reviews, K149–K155, with #565–#570 for the
    choices: the dashboard, true-cost report and digest over budget on a
    heavy household (already on master); the first year's cover note
    unseen past three years; the *Last 12 months* note wording; blank
    space beside short empty cards; `bin/test-all-dbs.sh` failing on a
    test database left with users.
  - From the issue sweep of the third review, K156–K158, with #571: a
    test run without `TEST_DB_NAME` on a server engine using the app's
    database; *True cost* with vehicles but no costs not shrinking; an
    empty *Monthly spend*'s second "costs excluded" line. K159 (this
    section credited only the first review) is fixed here.

## Acceptance criteria

- Each scenario fails if a figure on one surface differs from another or
  from its golden value.
- `composer lint`, `composer analyse`, `composer coverage:check` and
  `bin/test-all-dbs.sh` pass on all four engines.
- No migration. New strings are in every catalogue in `translations/`.

## Open questions

- **#374. Decided 2026-10-28:** include vehicles archived during last
  month in *Last month*. Not chosen: active vehicles only.
- **#563. Decided 2026-10-28:** an empty widget collapses to one line.
  Not chosen: hide it; leave it as is.
- **#564. Decided 2026-10-28:** cover outside ownership counts in no
  period. Not chosen: fold it into the first or last period.
- **#565–#571 (open, found by the merge reviews and the issue sweep):** see
  [`open-questions.md`](open-questions.md).
