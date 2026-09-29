# Phase 9.1 — Vehicle details: variant, first registration, starting mileage

**Goal:** describe a vehicle more precisely and get it on the road in Logbook
faster. Record the **variant / trim** ("1.5 EcoBoost ST-Line X"), the **date
it was first registered**, and — when adding it — its **current odometer**,
so the mileage series, garage card and dashboard have a figure from day one
instead of "—" until the first fill-up.

Read `spec.md` (§6 Vehicle and OdometerReading, §7.1, §7.2, §7.8, §7.13, §8)
and `CLAUDE.md` (§6, §8, §11) before starting.

**Prerequisites:** Phase 8 complete and green; v1.0.0 tagged.

**Followed by:** [Phase 9.2](phase-9.2.md) (plug-in hybrids), which cuts
v1.1.0. The two are independent; 9.1 goes first because 9.2's vehicle-form
changes sit on top of this one's.

---

## Scope

**In:** an optional `variant` and `first_registered_on` on each vehicle, an
optional *Current odometer* on the add-vehicle form that writes the vehicle's
first odometer reading, display of all three wherever a vehicle is
described, vehicle age and lifetime average mileage, backups and
translations. No release of its own: it lands under `[Unreleased]` and ships
with Phase 9.2 as v1.1.0.

**Out:** registration / VIN lookup (DVLA or otherwise, `spec.md` §12), deriving
MOT or road-tax due dates from the registration date (see open questions),
a stored "current mileage" column, CSV import / export of vehicles (there is
none today), engine size / power / body style / colour as structured fields.

---

## Design decisions (record in `spec.md` before building)

- **Variant is free text.** Column `variant` (`string`, 100, nullable),
  trimmed, empty → null. Trim names are brand- and market-specific ("ST-Line
  X", "AMG Line Premium Plus", "xDrive30d M Sport") and change every model
  year, so a picklist would always be wrong. Named `variant`, not `trim`,
  because *trim* is also a string function in PHP, SQL and Twig — searching
  the codebase for it would be useless.
- **Variant joins the vehicle's descriptive line.** Everywhere the app shows
  "year make model" it now shows "year make model variant". Where space is
  tight (garage card, *Your vehicles* tile, pinned vehicle card) the line
  truncates with an ellipsis and carries the full text in a `title`; the
  vehicle header shows it in full. The sidebar vehicles list keeps the name
  only.
- **First registered is a calendar date, not an instant.** Column
  `first_registered_on` (`date`, nullable) — like `performed_on`, it is
  never converted through a time zone. It is **not** the model year and
  **not** the purchase date; the form hints say so ("As on the registration
  document (V5C / logbook)").
- **Validation** (block): not after today in the owner's time zone; not
  before 1885-01-01. **Warn, don't block** (the app's usual stance for
  plausible-but-odd data): a model year more than one year *after* the
  registration year ("Year 2024 but first registered 2021 — check both").
  A model year well *before* registration is normal (imports, cars
  registered late) and is not flagged.
- **Current mileage is not a column.** The current odometer is, and stays,
  the latest reading in the one mileage series (§7.2). A second place to
  store it would disagree with that series the moment a fill-up is logged.
  Instead:
  - **Add vehicle:** an optional *Current odometer* field (owner's distance
    unit, the existing odometer parser; 0 is valid for a new car). When
    filled, saving the vehicle also writes a `manual` odometer reading at
    *now*, in the **same transaction** as the vehicle insert. Blank writes
    nothing.
  - **Edit vehicle:** no editable field. The form shows the current reading
    read-only with an *Add reading* link (the existing reading form; modal
    on desktop). A wrong starting figure is corrected by editing that reading
    on the Mileage tab, like any other — an edit form that silently appends
    to the mileage log would be a surprise.
- **Age and lifetime average are derived, never stored.** *Age* is whole
  years and months from `first_registered_on` to today in the owner's time
  zone ("7 yrs 6 mo"; "4 mo" under a year). *Average per year since first
  registered* is the current reading ÷ age in years, shown only once the
  vehicle is at least 90 days old and has a reading. It assumes the odometer
  read ~0 at first registration, which holds for new cars; the label makes
  the basis clear. No registration date → neither figure (no fallback to
  the model year, which would be up to a year out).

---

## Tasks

### 9.1.0 Spec first
Per `CLAUDE.md` §12, update `spec.md` before building:
- [x] §6 Vehicle — `variant` (optional, ≤ 100 chars) and
      `first_registered_on` (optional calendar date).
- [x] §7.1 — the descriptive line gains the variant; validation and the
      model-year warning; the add form's *Current odometer* and what it
      writes; the edit form's read-only current reading.
- [x] §7.2 — the reading written by *Add vehicle* is an ordinary `manual`
      reading; age and *average per year since first registered* on the
      Mileage tab.
- [x] §7.8 / §7.13 — pinned vehicle card line; backup schema note.
- [x] `ROADMAP.md` gains a Phase 9.1 row; `CHANGELOG.md` `[Unreleased]`
      entry (including the upgrade note: two nullable columns, backup schema
      rule, no config changes).

### 9.1.1 Domain + migration
- [x] Migration: `vehicles.variant` (`string`, 100, `'null' => true`) and
      `vehicles.first_registered_on` (`date`, `'null' => true`). Reversible;
      applies and rolls back on SQLite, PostgreSQL, MySQL and MariaDB.
- [x] `Vehicle` entity, repository and `Row` mapping carry both; the date
      is a `DateTimeImmutable` at midnight with no time-zone conversion (read
      through `Row`, which already handles MySQL's string dates).
- [x] `Vehicle::descriptiveLine()` (or the existing formatter) builds
      "year make model variant", skipping blanks — one place, used by every
      template.

### 9.1.2 Vehicle form
- [x] Add / edit: *Variant / trim* beside *Model*; *First registered* beside
      *Year* (native date input, works without JS; hint text as above).
- [x] Add only: *Current odometer* with the owner's distance unit shown,
      placed after the fuel / tank fields. Hint: "Leave blank if you'll log
      it with your first fill-up."
- [x] Edit only: current reading (or "No readings yet") read-only, with
      *Add reading*.
- [x] Model-year warning shown as a notice after save (the vehicle is saved).
- [x] Works in the desktop modal and as its own page; errors keep typed
      values.

### 9.1.3 Services
- [x] `VehicleService::create()` takes an optional starting reading and
      writes vehicle + reading in one transaction through the existing
      odometer service (so plausibility, units and `recorded_at` behave
      exactly as a reading added on the Mileage tab). A failure rolls back
      both.
- [x] `VehicleAge` (or a method on an existing service) returns typed age
      and lifetime average; no date maths in templates or Actions.

### 9.1.4 Display
- [x] Descriptive line with variant on: garage cards, vehicle header,
      dashboard *Your vehicles* tiles and pinned vehicle card, delete
      confirmation page, *Log entry* vehicle picker.
- [x] Vehicle overview gains a small *Details* card: variant, first
      registered (owner's date format) with age, VIN, purchase date — only
      the rows that are set; hidden when none are.
      *As built:* the overview already had a *Details* card (with VIN) and an
      *Ownership* card (with purchase date), so variant and first registered
      (with age) were added to the existing *Details* card, shown only when
      set, instead of a second card with the same heading (spec.md §7.1).
- [x] Mileage tab stats: *Average per year since first registered* beside
      the existing monthly average, when it can be shown.
- [x] Archived vehicles show the same details (it is their history).

### 9.1.5 Backup
- [x] Backups include the new columns automatically; the schema version
      moves, so the upgrade note repeats the existing rule (restore an
      older backup with its own version first, then upgrade).

### 9.1.6 i18n
- [x] English and German for every new label, hint, warning and figure:
      *Variante / Ausstattung*, *Erstzulassung* (German registration
      documents use exactly this term), *Aktueller Kilometerstand*,
      *Alter*, *Ø pro Jahr seit Erstzulassung*. Age uses ICU plurals
      ("1 Jahr", "7 Jahre").

### 9.1.7 Tests
- [x] Unit: descriptive line with and without year / variant; variant
      trimmed, blank → null, 101 chars rejected; registration date in the
      future (owner's time zone, around midnight) rejected, 1884 rejected;
      model-year warning for year = reg year + 2, none for + 1 or for a
      much older model year.
- [x] Unit (age): 29 Feb registration on 28 Feb / 1 Mar of a non-leap year;
      exactly 1 year; under a month; owner's time zone ahead of UTC on the
      anniversary. Lifetime average hidden under 90 days and with no
      reading.
- [x] Integration: add a vehicle with a current odometer of 0, of a value
      in miles (stored as km), and blank — the reading exists, is `manual`,
      shows on the Mileage tab, garage card and dashboard; a forced failure
      writing the reading leaves no vehicle behind. Edit shows the reading
      read-only and does not write one. Migration up / down.
- [x] Integration: a later fill-up or imported fuel history dated *before*
      the starting reading sits in order in the series (plausibility
      warnings as usual, nothing blocked).
- [x] Pass on **both** MySQL and Postgres (plus MariaDB and SQLite via
      `bin/test-all-dbs.sh`); smoke test at a subpath.

---

## Deliverables
Vehicles that record their variant and first registration date, show their
age and lifetime average mileage, and can be added with their current
odometer so every mileage figure works from the start. Released with
Phase 9.2 as Logbook v1.1.0.

## Acceptance criteria
- [x] Variant and first registration date are optional, validated, and shown
      wherever the vehicle is described; long variants truncate cleanly.
- [x] A registration date is never shifted by the owner's time zone.
- [x] Adding a vehicle with a current odometer produces exactly one manual
      reading; the garage card and dashboard show it immediately.
- [x] Editing a vehicle never writes an odometer reading.
- [x] Existing vehicles are untouched; every existing figure is unchanged.
- [x] Age and lifetime average appear only when they can be computed.
- [x] Suite green on both DBs; translatable (en + de); works behind a
      subpath with deep-link refresh; Docker and bare-PHP paths both work.

## Gotchas
- **Don't add a `current_mileage` column.** It would be right for one day.
  The series is the single source of truth.
- **`date`, not `datetimetz`.** Storing the registration date as a UTC
  instant turns 14 March into 13 March for anyone west of UTC.
- **The starting reading and the vehicle share a transaction.** Otherwise a
  failed reading leaves a vehicle whose owner thinks the mileage was saved.
- **Model year ≠ registration year**, in either direction; only the
  impossible direction warns.
- Hybrids, EVs and bikes need nothing special here — all three fields are
  fuel- and type-agnostic.

## Open questions
- **Date the starting reading?** A car bought months ago might have its
  mileage from the MOT certificate or the sale. An optional "as of" date
  (default today) on the add form would cover it; left out for now to keep
  the form short. *Answered in Phase 12:* an optional *As of* beside
  *Current odometer*, stored at local noon on that date.
- **MOT due from first registration?** A natural follow-up: the first MOT
  is due at 3 years in Great Britain but 4 years for cars and motorcycles
  in Northern Ireland, and other countries differ again, so it needs a
  region rule rather than a constant. Would sit with compliance reminders.
- **Registration lookup** would fill variant and first registration
  automatically — still in "After 1.0".