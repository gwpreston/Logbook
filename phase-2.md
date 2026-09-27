# Phase 2 — Odometer + Fuel

**Goal:** mileage as a single coherent series, and fuel logging with correct
consumption/cost math across partial fills, gaps, and EVs — all built on the
Support units/money/date layer established in Phase 1.

Read `spec.md` (§6, §7.2, §7.3, §8) and `CLAUDE.md` (§8) before starting.

**Prerequisites:** Phase 1 complete and green.

**Status: complete (2026-09-27).** Decisions recorded in `spec.md`:
- §6 FuelEntry: the quantity column is `volume` (litres, or kWh for `ev`)
  rather than `volume_litres`, since EV charges use the same shape; the price
  per unit keeps 6 decimal places so a price typed per gallon converts back
  exactly; entries carry `fuel` (no "hybrid" fuel: a hybrid fills petrol).
  `filled_at` is a UTC instant typed in the user's time zone.
- §6 OdometerReading: fill-up readings reference their entry
  (`fuel_entry_id`, `ON DELETE CASCADE`) and are written in the same
  transaction; only manual readings are edited directly.
- §7.3: when volume, price and total are all given they are kept as entered
  (discounts); money figures use the total. Liquid fuel and electricity are
  separate economy series. EV efficiency follows the distance unit
  (kWh/100 km or mi/kWh) instead of adding a preference.
- §7.2: "implausible jump" = more than 2,000 km per day since the previous
  reading (at least one day counted).
- §7.2/§7.3: vehicle page tabs are separate URLs; the fast path is
  `/fuel/new` behind a "Log fill-up" button (sidebar + mobile tab bar "+").
- Verified with `bin/test-all-dbs.sh` on SQLite, PostgreSQL 17, MySQL 8.4 and
  MariaDB 11.4 (348 tests each, including migrate → full rollback → migrate),
  and `bin/smoke-test.sh pgsql` against the production image at a subpath.

---

## Scope

**In:** odometer log (manual + readings derived from fuel), fuel entry CRUD,
consumption/cost derivations, trend charts, EV (kWh) support, plausibility
warnings.

**Out:** maintenance, compliance, reminders, reports/dashboard aggregation
(Phase 5), general attachments (Phase 3). Maintenance-derived odometer readings
arrive in Phase 3.

---

## Tasks

### 2.1 Odometer domain + migration
- [x] `OdometerReading` entity, repository, service.
- [x] Migration: `odometer_readings` (vehicle_id, reading_km, recorded_at,
      source `manual|fuel|maintenance`, note). Index `(vehicle_id, recorded_at)`.
- [x] Applies + rolls back on both DBs.

### 2.2 Fuel domain + migration
- [x] `FuelEntry` entity + enums, repository, service.
- [x] Migration: `fuel_entries` (date, odometer_km, volume_litres,
      price_per_unit, total_cost as DECIMAL with ≥3 decimals, is_partial,
      is_missed_previous, station/notes, fuel_type).
- [x] Saving a fuel entry writes/links an `OdometerReading` (source `fuel`) so
      mileage stays one series.

### 2.3 Fuel math (the careful bit)
- [x] Any two of volume / price-per-unit / total derive the third.
- [x] Distance since last **full** fill; consumption in L/100km, **mpg UK**,
      **mpg US**, and km/L.
- [x] Partial-fill handling: compute consumption only between full tanks.
- [x] Gap handling via `is_missed_previous` so a known-missing fill doesn't
      corrupt averages.
- [x] EV support: kWh as the "volume", efficiency as kWh/100km and mi/kWh — same
      entry shape.
- [x] All conversions go through the Support units engine. Unit tests with
      worked examples for each case above.

### 2.4 Odometer UI
- [x] History table + trend chart (Chart.js). Manual add/edit/delete.
- [x] Plausibility warnings (going backwards, implausible jump) — **warn, don't
      block**.

### 2.5 Fuel UI
- [x] Log fill-up form with a fast, mobile-friendly path.
- [x] List with per-fill consumption + cost; price-trend and consumption charts.

### 2.6 Vehicle detail integration
- [x] Wire odometer + fuel sections into the Phase 1 vehicle-detail shell.

### 2.7 i18n
- [x] All new strings translatable; English catalogue updated.

### 2.8 Tests
- [x] Unit (math-heavy): derivations, full-to-full consumption, partial fills,
      missed-fill gaps, UK vs US mpg, EV efficiency.
- [x] Integration: odometer + fuel CRUD, derived-reading linkage, warnings.
- [x] Pass on **both** MySQL and Postgres.

---

## Deliverables
Per-vehicle mileage log and fuel log with trustworthy consumption/cost figures,
trend charts, and EV support.

## Acceptance criteria
- [x] Any-two-derive-the-third works; ≥3-decimal inputs round-trip.
- [x] Consumption correct across partial fills and a flagged missed fill; UK and
      US mpg differ correctly.
- [x] EV entries produce sensible efficiency via the same shape.
- [x] Odometer is one coherent series across manual + fuel sources; backwards /
      jumps warn without blocking.
- [x] Suite green on both DBs; translatable; works behind a subpath.

## Gotchas
- Consumption must be computed **full-to-full**; naive per-entry division is the
  classic wrong-number bug.
- Reuse the Phase 1 date helper: a fill's "date" is user-tz, storage is UTC.
- Recompute derived figures on edit; don't trust a stale cached value.
