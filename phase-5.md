# Phase 5 — Expenses + Reports + Dashboard

**Goal:** roll fuel / maintenance / compliance costs into expenses, provide
per-vehicle and fleet reports over time, and the at-a-glance rearrangeable widget
dashboard.

Read `spec.md` (§6, §7.7, §7.8) and `CLAUDE.md` (§7, §8) before starting.

**Prerequisites:** Phase 4 complete and green.

---

## Scope

**In:** expense roll-up (+ ad-hoc expenses), reports (category breakdowns,
cost/distance, cost/month, date-range filter), CSV export, draggable widget
dashboard persisted per user, respecting feature toggles and archived-exclusion.

**Out:** CSV import + backup/restore (Phase 6), PDF export (later), the feature
toggle management UI (Phase 6 — the dashboard just respects toggles that exist).

---

## Tasks

### 5.1 Expense roll-up
- [x] `ExpenseEntry` + migration; fuel / maintenance / compliance costs surface
      as expenses via source reference or a unified service-layer query.
- [x] Ad-hoc expense CRUD (cost 0 valid).

### 5.2 Reports service
- [x] Per-vehicle and fleet breakdowns (fuel / maintenance / compliance / other).
- [x] Cost/distance and cost/month; date-range filter.
- [x] Archived vehicles excluded unless explicitly included.

### 5.3 Reports UI
- [x] Tables + charts (Chart.js), with the date-range filter.

### 5.4 CSV export
- [x] Per module and for reports; values formatted per the user's units/currency;
      UTF-8.

### 5.5 Dashboard
- [x] Widgets: fleet summary, upcoming reminders, recent fuel, spend this month,
      efficiency trend, compliance status.
- [x] Drag to rearrange (SortableJS); layout persisted per user.
- [x] Fully readable with JS disabled (a sensible static order as fallback).

### 5.6 Feature-toggle awareness
- [x] Widgets and reports for a disabled module are hidden.

### 5.7 i18n
- [x] All new strings translatable; English catalogue updated.

### 5.8 Tests
- [x] Unit: aggregation correctness across timezone, units, currency, and
      archived-exclusion.
- [x] Integration: CSV export output, dashboard layout persistence.
- [x] Pass on **both** MySQL and Postgres.

---

## Deliverables
Trustworthy per-vehicle and fleet cost reporting with CSV export, and a
rearrangeable dashboard giving an at-a-glance view of the whole garage.

## Acceptance criteria
- [x] Fuel / maintenance / compliance costs roll into expenses; ad-hoc expenses
      work; zero is valid.
- [x] Reports are correct across date ranges, units, and currency; archived
      excluded by default.
- [x] CSV export round-trips numbers at the correct precision.
- [x] Dashboard rearranges and persists per user; works without JS via a fallback
      order.
- [x] Suite green on both DBs; translatable; works behind a subpath.

## Gotchas
- Aggregation is where wrong-totals bugs resurface: sum in canonical SI / DECIMAL
  and convert only at display; include a test that crosses a DST boundary.
- Persist dashboard layout in `settings` (scope = user) as JSON.
- Avoid double-counting: if fuel/maintenance costs surface as expenses via a
  query, make sure ad-hoc expenses don't duplicate them.
