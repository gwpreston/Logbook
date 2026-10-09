# Phase 41.7 — Dashboard and overview query batching + patch release

*The dashboard reads each vehicle once, not once per widget.*

Status: 📋 planned · file lives in `docs/phases/`

Phase 41's merge review measured the pages with a 10-vehicle household
(1,500 fill-ups, 200 readings, 150 services and 20 documents per
vehicle) on PostgreSQL 17 and MySQL 8.4:

| Page | master | phase-41 |
|---|---|---|
| `/` (dashboard) | 813 queries, 9.4 s | 816 queries, 9.4 s |
| `/vehicles/{id}` (overview) | 344 queries, 0.89 s | 355 queries, 0.91 s |

Rated **HIGH** (a common page over budget), **already on master**, so
it didn't block Phase 41. In the statement log of one dashboard request
the per-vehicle `compliance_documents WHERE vehicle_id = ?` query ran 70
times and the `odometer_readings` one 111 times: widgets and services
each read the same vehicle's rows again inside loops over vehicles.
The seed is heavier than a typical household, so the times overstate;
the query counts are the problem.

Read [`CLAUDE.md`](../../CLAUDE.md), [`spec.md`](../../spec.md) §7.8
(dashboard and widgets), §7.18 (*Coming up*), §7.24 (*Needs attention*),
§7.6 (reminder sync) and the existing query-count tests
(`tests/Integration/Http/AttentionQueryCountTest.php`,
`SpendWidgetsTest.php`) first.

**Prerequisites:** [Phase 41](phase-41.md) merged. Independent of
[Phase 41.6](phase-41.6.md). Before [Phase 42](phase-42.md), which adds
two computed insights to the same pages.

---

## Goals

1. Measure: which widget and service reads what, per vehicle, on the
   dashboard and the overview.
2. Batch those reads across vehicles (`IN (…)`, read once per request),
   so the query count doesn't grow with the number of vehicles.
3. Guard it with query-count tests, so it can't creep back.
4. Patch release.

## Not in scope

- Changing what any widget shows, or any figure. Every page renders the
  same before and after (compared in a test).
- Caching across requests.
- Other pages, unless the same shared service is the cause (then they
  benefit for free and are measured too).

---

## Spec changes

- §8 (or §7.8): the page budgets, **if** the owner decides #350 that
  way: for example "the dashboard and an overview run a bounded number
  of queries, not one per vehicle per widget", with the numbers.

## Decisions (and why)

- **Measure before changing.** The statement log and `QueryCounter`
  pick the targets; nothing is batched on a guess.
- **Repositories already have batch reads** (`listForVehicles` for
  documents; readings and fill-ups to check), so this is mostly passing
  lists instead of looping. No new tables, no migration.

---

## Tasks

### 41.7.0 Measure
- [ ] A test seed (or the shared `tests/Support/ReviewDataset.php`, if
      it exists by then) with 1, 5 and 10 vehicles; `QueryCounter` on
      `/` and `/vehicles/{id}`, broken down by repository call.
- [ ] Record the top repeated queries and the widget or service behind
      each in this file.

### 41.7.1 Batch
- [ ] Per-vehicle reads of compliance documents, odometer readings and
      any other repeated table done once per request for every vehicle
      on the page (the reminder sync, *Coming up*, *Needs attention* and
      the widgets share them).
- [ ] The overview: the same reads shared between its cards.

### 41.7.2 Tests
- [ ] Query counts on the dashboard and overview don't grow with the
      number of vehicles (1 vs 10), as `AttentionQueryCountTest` does
      for readings.
- [ ] The rendered dashboard and overview are identical before and
      after, on SQLite, PostgreSQL, MySQL and MariaDB.

### 41.7.3 Release
- [ ] `VERSION` → next patch; `CHANGELOG.md` (*Changed*: faster
      dashboard and vehicle overview); `ROADMAP.md` row ✅.

---

## Acceptance criteria

1. With 10 vehicles the dashboard runs a number of queries that doesn't
   depend on the number of vehicles, and well under master's 813 (target
   in the tens).
2. The overview's count doesn't grow with the vehicle's data.
3. Nothing any page shows changes.
4. Definition of done (CLAUDE.md §11) holds.

## Open questions

- **#350 — Page budgets in the spec?** Options: write the budgets (30
  queries, 200/500 ms) into §8 and enforce them in tests; or keep them
  as review targets only. Recommendation: write them in, for the
  dashboard and the overview at least, so a test holds them. Logged in
  [`open-questions.md`](open-questions.md); decide before 41.7.2.
