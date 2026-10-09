# Phase 41.7 — Dashboard and overview query batching + patch release

*The dashboard reads each vehicle once, not once per widget.*

Status: ✅ complete · releases **v3.7.2** · file lives in `docs/phases/`

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

- §8 *Page budgets* (written, #350): the dashboard and an overview run
  at most 60 queries (30 proved out of reach, see below), the same
  count for 1 vehicle as for 10, held by a test; 200 ms typical and
  500 ms at most are review targets. How a page remembers its reads is
  written there too. §12 keeps 30 as the goal (#351). §13 lists the
  phase.

## Decisions (and why)

- **Measure before changing.** The statement log and `QueryCounter`
  pick the targets; nothing is batched on a guess.
- **Repositories already have batch reads** (`listForVehicles` for
  documents; readings and fill-ups to check), so this is mostly passing
  lists instead of looping. No new tables, no migration.

---

## Measured (41.7.0)

`QueryCounter` now keeps the statements it counts and, on request, who
sent each (`histogram()`, `traceCallers`). On SQLite, with 1 and with 10
vehicles each holding 12 fill-ups, 2 documents, a reading and a service:

| Page | before, 1 vehicle | before, 10 | after, 1 | after, 10 |
|---|---|---|---|---|
| `/` | 165 | 812 | 59 | 59 |
| `/vehicles/{id}` | 166 | 274 | 58 | 59 |

What repeated on the dashboard with 10 vehicles, and who asked:

| Queries | Read | By |
|---|---|---|
| 212 | `settings` (one name at a time) | `FeatureToggles` (101: every `isEnabled()`), `TyreSettingsStore` and `ReminderSettingsStore` (30 each, per vehicle), `FuelPriceConfig` |
| 110 | `odometer_readings` | `OdometerService::history` from `TyreService::build`, `ReminderSync`, `ComingUp`, `ReportService::gather` |
| 80 | `maintenance_entries` | `TyreService::records`, `CostLedger::items` (twice, for the two periods), `TrendChecks::costs` |
| 70 each | `compliance_documents`, `fuel_entries` | `CostLedger::items`, `ComplianceService::states`, `FuelService::entries` |
| 40 | `expense_entries` | `CostLedger::items` |
| 30 each | `maintenance_schedules`, `tyres`, `tyre_changes`, `finance_agreements` | `ScheduleService::list`, `TyreService::views` (three callers), `FinanceService` |
| 20 each | `vehicle_valuations`, `incidents` | `AttentionList`, `TrueCostService` |

The overview was the same, because its *Needs attention* and reminder
sync run for every vehicle in the garage, not just the one shown.

## Tasks

### 41.7.0 Measure
- [x] A seed with 1 and 10 vehicles; `QueryCounter` on `/` and
      `/vehicles/{id}`, broken down by who sent each statement.
- [x] The top repeated queries and the service behind each, above.

### 41.7.1 Batch
- [x] `RequestReads` (`Support\Cache`): during a GET or HEAD a
      repository remembers what it read; `RequestReadsMiddleware` turns
      it on and off. A statement that writes a table makes the request
      forget what came from it, seen at the connection
      (`ForgetWrittenReadsMiddleware`), so no repository has to.
- [x] `SettingRepository` reads all of an owner's settings in one query.
- [x] Per-vehicle lists of fill-ups, readings, services, schedules,
      documents, expenses, valuations, finance agreements, incidents and
      tyres and tyre changes are remembered, and `prime()` reads them for
      many vehicles at once (`VehicleDataPrimer`, called by the
      dashboard, the overview and the reminder sync). Vehicles, AI task
      assignments and the scheduler's last pass are remembered too.
- [x] The overview: primed with the whole garage, as its reminder sync
      needs it.

### 41.7.2 Tests
- [x] `PageQueryBudgetTest`: both pages within 60 queries, 10 vehicles
      no more than 1 vehicle plus 2, and four times the fill-ups the same
      count.
- [x] The same pages (`/`, `/?vehicle=`, the overview and the garage)
      render identically with the shared reads switched off.
- [x] `RequestReadsTest`, `ForgetWrittenReadsMiddlewareTest` and the
      settings repository's request tests. All run on every engine in
      `bin/test-all-dbs.sh`.

### 41.7.3 Release
- [x] `VERSION` → 3.7.2; `CHANGELOG.md` (*Changed*: faster
      dashboard and vehicle overview); `ROADMAP.md` row ✅.
- [ ] Tag v3.7.2 once merged.

---

## Acceptance criteria

1. With 10 vehicles the dashboard runs a number of queries that doesn't
   depend on the number of vehicles, and well under master's 813 (target
   in the tens).
2. The overview's count doesn't grow with the vehicle's data.
3. Nothing any page shows changes.
4. Definition of done (CLAUDE.md §11) holds.

## Open questions

- **#350 — Page budgets in the spec?** *Decided 2026-10-09:* write them
  into §8 and hold the query count by a test, for the dashboard and the
  overview. The first number was 30 queries (200/500 ms as review
  targets); measuring showed a floor of 59 without reworking the reminder
  sync and the activity feed, so the owner set **60** the same day. Logged in
  [`open-questions.md`](open-questions.md).
- **#280 — Batch the Insights page too?** *Carried 2026-10-09:* only
  where it shares a service this phase batches (it is then measured);
  nothing Insights-specific is added, and #280 stays open.
- **#351 — Bring the budget to 30?** *Parked 2026-10-09 (spec §12):* the
  reminder sync and the activity feed read their own windows; serving
  those from the primed lists would take 60 to about 30. Not in 41.7.
