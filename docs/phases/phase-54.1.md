# Phase 54.1 — Clock-change imports, empty-widget edges and test-run guards from the known-issues log + patch release

*Six open rows of the known-issues log closed: a repeated clock-change time
on re-import, the true-cost cover note, two edges of the empty dashboard
widgets, and two test-run guards.*

Status: 🚧 in progress · releases **v3.20.1**, a patch release · file lives in `docs/phases/`

This is the next batch after [Phase 52.2](phase-52.2.md) and
[Phase 53.20](phase-53.20.md). Like them, it takes small open rows. The owner
named K1, K40 and K147; K1 (a separator picker, #484) and K40 (tag the
offline queue and cached pages by user, #449) are each a multi-file
feature decided as a phase of its own, so only K147 is taken, and the
owner asked for six rows in all. K157 and K158 joined the batch once the
owner decided their questions (#566, #571). It is numbered 54.1 because it
follows Phase 54, the last row of the roadmap.

Read [`CLAUDE.md`](../../CLAUDE.md), [`spec.md`](../../spec.md) §7.8
(*Empty widgets*), §7.13 (*Import*), §7.21 (costs excluded), §7.35 (*True
cost*), and [`open-questions.md`](open-questions.md) first.

**Prerequisites:** [Phase 54](phase-54.md) merged, and `v3.20.0` tagged.

---

## Goals

### Import and reports

1. **K147.** Re-importing an old-format export (3.19.11 or earlier) of the
   clock-fall-back night no longer adds the later `01:30` as a duplicate
   of the entry already there: a repeated time that matches an existing
   entry at the other instant is shown as *Already there* (#561).
2. **K153.** A *Last 12 months* period that starts on the purchase date
   (or ends on the sale date) still names the cover it leaves out, but
   without "so the years can differ from Since bought", which only makes
   sense beside the yearly table.

### Dashboard

3. **K157.** A *True cost* widget whose vehicles in view all have no
   costs ("No costs logged", "Not enough mileage logged") shrinks to one
   line with no period switch, like one with nothing to show (#566,
   decided 2026-10-28).
4. **K158.** An empty *Monthly spend* for a viewer with a vehicle shared
   without costs shows one line, "No costs in the last 12 months
   (excludes 1 vehicle shared without costs)" (#571, decided 2026-10-28).

### Test runs

5. **K155.** `bin/test-all-dbs.sh` starts each engine from a fresh
   `logbook_test` database, so a leftover user from an earlier run can't
   stop the rollback.
6. **K156.** The test bootstrap refuses a PostgreSQL or MySQL run whose
   test database is the app's own (no `TEST_DB_NAME`, or the same name as
   `DB_NAME`), so a run can't empty it.

## Not in scope

- **K1** (#484) and **K40** (#449): each is its own phase.
- **K145**: needs an API key to record a request, not a fix.
- **K149–K151**: performance rows that need profiling first, and a
  budget decision (#570).
- **K152** (#568) and **K154** (#569): wait on their questions.

---

## Decisions (and why)

- **K147 follows #561.** An offset-less time is still the first of the
  two. The importer then looks for an existing entry for the same vehicle
  at the *other* instant with the same values, and treats the row as
  *Already there* instead of adding a near-duplicate.
- **K153 changes the wording only.** The figures are right; the note's
  tail is dropped when the period isn't a yearly table. New strings go in
  every catalogue.
- **K157 follows #566** (option 2): `TrueCostWidget::isEmpty()` is also
  true when no vehicle in view has a figure. Spec §7.8 says an empty
  widget is one line.
- **K158 follows #571** (option 2): the costs-excluded note folds into the
  empty line, so the widget is one line and §5 and §7.21's note is kept.
- **K155** recreates the test database rather than loosening the 2.0
  migration's refusal, which protects real data.
- **K156** extends K148's guard: the test database must be named by
  `TEST_DB_NAME` and differ from `DB_NAME` on PostgreSQL and MySQL.

---

## Tasks

### 54.1.0 Start of phase

- [x] Read `open-questions.md` and the *Open* and *Scheduled* rows of
      `known-issues.md` (CLAUDE.md §12). #566 and #571 are decided, and
      the release is decided (#572).

### 54.1.1 Import and reports

- [ ] `CsvImporter` reports a repeated clock-change time that matches an
      existing entry at the other instant as *Already there* (K147).
      `CsvImporterTest`: re-import of an old-format export of the
      fall-back night adds nothing.
- [ ] The cover note's tail is dropped for a period that isn't a yearly
      table (K153), in `TrueCost` or its template and in English and
      German. `TrueCostTest` covers *Last 12 months* from the purchase
      date.

### 54.1.2 Dashboard

- [ ] `TrueCostWidget::isEmpty()` is also true when no vehicle has a
      figure (K157); `DashboardEmptyWidgetsTest` covers a vehicle with a
      purchase price and no entries.
- [ ] An empty *Monthly spend* folds the costs-excluded note into its
      line (K158), in English and German; `DashboardEmptyWidgetsTest`
      covers a member who can't see one vehicle's costs. Spec §7.8 and
      §7.21 say so. Check the widgets at 375, 768 and 1280 px, light and
      dark.

### 54.1.3 Test runs

- [ ] `bin/test-all-dbs.sh` drops and recreates `logbook_test` per engine
      before migrating (K155); run it twice on MySQL, with a second user
      added in between.
- [ ] `tests/bootstrap.php` refuses PostgreSQL and MySQL runs without
      `TEST_DB_NAME`, or with it equal to `DB_NAME` (K156); a test covers
      the check, and `CONTRIBUTING.md` says so.

### 54.1.4 Finish

- [ ] Known-issues rows K147, K153, K155, K156, K157 and K158 *Fixed*.
- [ ] CHANGELOG; spec §13; ROADMAP status.
- [ ] Merge review; merge.

### Release

- [ ] VERSION 3.20.1, CHANGELOG 3.20.1, README status (#572).
- [ ] Tag `v3.20.1` once merged.

## Acceptance criteria

- Each code row has a regression test that fails without its fix; K155
  is checked by running the script twice on MySQL.
- `composer lint`, `composer analyse` and `bin/test-all-dbs.sh` pass on
  SQLite, PostgreSQL, MySQL and MariaDB. `MarkdownLinksTest` passes.
- No migration. New strings are in every catalogue in `translations/`.

## Open questions

- **#566. Decided 2026-10-28:** *True cost* also shrinks when every
  vehicle in view has no costs (K157). Not chosen: only none in view.
- **#571. Decided 2026-10-28:** the costs-excluded note folds into the
  one empty line (K158). Not chosen: keep both lines; don't shrink while
  vehicles are excluded; drop the note when empty.
- **#572. Release. Decided 2026-10-28:** on its own as patch release
  v3.20.1. Not chosen: with the next releasing phase.
