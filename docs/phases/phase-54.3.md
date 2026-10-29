# Phase 54.3 — Dashboard, true-cost and digest speed from the known-issues log + patch release

*Three open performance rows of the known-issues log closed: the dashboard,
the true-cost report and the monthly digest job on a heavy household.*

Status: ✅ complete · releases **v3.21.1**, a patch release · file lives in `docs/phases/`

This is the batch after [Phase 54.2](phase-54.2.md). The owner asked for the
open rows that wait on no decision; after K1 and K40 (54.2) only K149,
K150 and K151 are left that do. They share one theme and one method:
profile, then remove the reads repeated per vehicle or per widget. K145
(needs an API key) and K152 and K154 (wait on #568, #569) stay out. It is
numbered 54.3 because it follows 54.2, the last row of the roadmap.

Read [`CLAUDE.md`](../../CLAUDE.md), [`spec.md`](../../spec.md) §8 *Page
budgets* (#350, #359), §7.8, §7.11 (the digest), §7.35 (*True cost*), and
[`open-questions.md`](open-questions.md) first.

**Prerequisites:** [Phase 54.2](phase-54.2.md) merged, and `v3.21.0` tagged.

---

## Goals

1. **K149.** The dashboard answers in at most 500 ms on the heavy
   household (10 vehicles with 400 fill-ups, 150 services and 16 years of
   documents each; it took about 3.1 s on SQLite), within the query limits
   spec §8 already sets (60; 80 with every module on and a price provider
   synced), and the same count for 1 vehicle as for 10.
2. **K150.** The true-cost report for all vehicles is within 500 ms and 60
   queries on the same household (about 0.6–0.7 s and 99 queries), and the
   count doesn't grow with the number of vehicles.
3. **K151.** The monthly digest job's query count doesn't grow with the
   number of vehicles (about 64 per vehicle, 641 for ten, about 3 s), and
   its figures don't change.

## Not in scope

- **K145**: needs an API key to record a request, not a fix.
- **K152** (#568) and **K154** (#569): wait on their questions.
- New features or changed figures: every number shown stays the same.

---

## Decisions (and why)

- **Profile first.** K149 names per-widget reads over full history as the
  likeliest cause but didn't profile. The first task records what the
  time and queries go to (the repo's profiling and `QueryCounter`
  helpers) and writes it in this file before any fix.
- **The dashboard budget is spec §8's, not the review's 30.** The spec
  already sets 60 queries (80 with every module on, #350, #359) and 500 ms
  at most; K149 is over on time, not on the query limit.
- **Reports and the digest get budgets (#576).** The true-cost report (and
  the other reports) join the 60-query, 500 ms page budget; the digest
  job gets a test that its query count doesn't grow with vehicles. They
  are written into §8 and held by tests, so a repeated per-vehicle read
  can't creep back.
- **Figures are unchanged.** Each fix is a regression-tested refactor:
  read once for all vehicles, then group in PHP, behind the same
  repositories. No migration; an index only if profiling shows one is
  missing, and then portable on all four engines.

---

## Tasks

### 54.3.0 Start of phase

- [ ] Read `open-questions.md` and the *Open* and *Scheduled* rows of
      `known-issues.md` (CLAUDE.md §12). #570 is decided (#576) and the
      release is decided (#577).
- [x] Profile the three cases on the heavy household and record the
      queries and time per widget, report section and digest step here
      (*Profile*, below).

#### Profile (master, SQLite, 2026-10-29)

The heavy household: 10 vehicles, each with 403 fill-ups, 150 services and
16 years of insurance and inspection documents, on PHP 8.5 with the test
app's SQLite file. The time is CPU in PHP more than queries (the dashboard
runs 59 and is inside spec §8's 60).

| Case | Time | Queries |
|------|------|---------|
| Dashboard, warm | 2.1 s (first load 2.3 s) | 59 |
| `/reports/true-cost` | 0.65 s | 99 |
| Scheduled tasks run with the monthly digest | 2.3 s | 847 |

Where the dashboard's first load goes (ms): needs-attention 570 (of which
the trend checks 228, the economy checks 170), the true-cost widget 298,
mileage 231, insights 183, recent fuel 170, vehicle snapshots 140, fuel
histories 123, reading all vehicles' tables once 106, coming up 91, spend
67, reminders 38. Ten fuel histories were worked out forty times (four
widgets each) and ten economy checks twenty times, 820 ms of it; the
mileage widget converted every reading to the owner's day once per period
(about 230,000 conversions).

The digest: one `Reports` comparison with a ledger read per vehicle in
*Last month*, and a read of the vehicle's tables per vehicle and per step,
because a job reads the database as before (spec §8): about 64 queries a
vehicle.

### 54.3.1 Dashboard (K149)

- [x] Remove the reads repeated per vehicle or over full history in
      `DashboardService` and the widgets it builds; `PageQueryBudgetTest`
      and a timing check on the heavy household (500 ms, review target).
      Figures are compared with master's on the same data. **Partly:**
      2.3 s to 1.5 s; 500 ms is not reached (#583).

### 54.3.2 True-cost report (K150)

- [x] `TrueCostService` reads once for all vehicles; a query-budget test
      for `/reports/true-cost` (60 queries, same for 1 and 10 vehicles).
      99 queries to 21; the time (0.68 s) is not under 500 ms (#583).

### 54.3.3 Digest job (K151)

- [x] `DigestSummary` and `ReminderNotifier::sendDigest` read once for all
      vehicles; a test counts queries for 1 and 10 vehicles and expects
      the same, and the digest text is unchanged. Done by scoping the
      remembered reads to each user's digest (`RequestReads::scoped()`),
      which the sync, the checks and *Last month* already use.

### 54.3.4 Finish

- [x] Spec §8 *Page budgets* adds the reports and the digest job.
- [x] Known-issues rows: K151 *Fixed*; K149 and K150 stay *Open* with
      what was measured, and #583 asks what to do about the time.
- [x] CHANGELOG; spec §13; ROADMAP status.
- [ ] Merge review (performance-auditor included); merge.

### Release

- [x] VERSION 3.21.1, CHANGELOG 3.21.1, README status (#577).
- [ ] Tag `v3.21.1` once merged.

## Result (SQLite, the heavy household, 2026-10-29)

| Case | Master | Now |
|------|--------|-----|
| Dashboard, warm | 2.3 s, 61 queries | 1.5 s, 61 queries |
| `/reports/true-cost` | 0.69 s, 99 queries | 0.68 s, 21 queries |
| Digest job, ten vehicles | 861 queries (one vehicle: 130 in the test; ten: 662) | 247, the same as for one vehicle |

Every figure is the same: the dashboard and report pages, the digest text
and its webhook JSON are byte-for-byte what master gives on this data
(compared after masking the CSRF and CSP tokens).

What changed: a page works a vehicle's fuel history and economy checks out
once (they ran four and two times per vehicle); the mileage widget finds a
reading's local day once per instant and zone; the price check bisects its
30-day window instead of rescanning the vehicle's fill-ups for each one; the
true-cost report reads every table once for all vehicles (the primer the
dashboard uses); and the digest keeps its reads for the length of one
user's run.

What is left of the dashboard's 1.5 s is CPU in PHP spread over many
checks and widgets: the price check's exact decimal arithmetic for each of
4,000 fill-ups (about 370 ms), the true-cost widget (about 260 ms), the
fuel analysis itself (about 130 ms for ten vehicles), loading and
hydrating every table (about 110 ms) and *Coming up* (about 90 ms). The
true-cost report's 0.68 s is 0.4 s of working out (a period's costs are
summed over every item for each of up to 16 years) and 0.3 s of rendering
a 350 KB page. None is a repeated read; getting under 500 ms means
changing how those are worked out or shown (#583).

## Acceptance criteria

- Each row has a test that fails without its fix; figures unchanged.
- The heavy household meets the budgets on SQLite, and the query counts
  hold on all four engines.
- `composer lint`, `composer analyse` and `bin/test-all-dbs.sh` pass on
  SQLite, PostgreSQL, MySQL and MariaDB. `MarkdownLinksTest` passes.
- No migration (or one that rolls back on all four engines, if an index
  turns out to be needed).

## Open questions

- **#570 / #576. Decided 2026-10-28:** the dashboard's budget is already
  in spec §8 (#350, #359); the true-cost report and the other reports join
  it (60 queries, 500 ms), and the digest job's query count must not grow
  with vehicles. Not chosen: time only, with no new query budgets.
- **#583. Time on the heavy household. Decided 2026-10-29:** keep the
  500 ms target and reach it by lighter working-out, in
  [Phase 54.5](phase-54.5.md).
- **#577. Release. Decided 2026-10-28:** on its own as patch release
  v3.21.1. Not chosen: minor release; with the next releasing phase.
