# Phase 11.1 — Tyres: what's fitted, what's stored, how far each has gone

**Goal:** answer the tyre questions Logbook cannot answer today. A `tyres`
service record says "two tyres, £240". It does not say which tyres, where
they sit, how old they are, or how far the last pair went before it wore
out. Record each **tyre** (brand, model, size, season, DOT date), where it
is (**fitted** at a position, **stored** in a set, or **retired**), and every
**tyre change** (fitted, swapped, rotated, repaired, removed). From those,
work out each tyre's **distance**, derived from the one mileage series.
Costs stay in maintenance, so nothing is counted twice.

Read `spec.md` (§6 Vehicle, OdometerReading, MaintenanceEntry and
Attachment; §7.1, §7.2, §7.4, §7.7, §7.10, §7.13, §7.16; §8) and `CLAUDE.md`
(§6, §8, §11, §12) before starting.

**Prerequisites:** Phase 10.2 complete and green; v1.2.1 tagged.

**Followed by:** [Phase 11.2](phase-11.2.md), which adds tread depth,
wear projection and age and tread reminders, then cuts v1.3.0. 11.1 has no
release of its own. It lands under `[Unreleased]` and ships with 11.2, like
Phase 9.1 did.

---

## Scope

**In:**
- A new switchable `tyres` module.
- Tyres, tyre sets and tyre changes (fit new, tyres already on the vehicle,
  swap set, rotate, repair, remove / retire).
- Positions by vehicle type, and distance per tyre derived from the mileage
  series.
- An optional link from a change to a `tyres` service record, which carries
  its cost.
- Odometer readings from changes.
- A *Tyres* vehicle tab and an overview card.
- History, print view and *Recent activity* integration.
- CSV export, backups, the demo seed, and translations (en + de).

**Out:**
- Tread depth, wear projection and every tyre reminder (Phase 11.2).
- Tyre pressures and TPMS.
- Wheels (rims) as their own entity.
- Moving a tyre or set to another vehicle.
- CSV import of tyre history.
- EU tyre-label data and size lookup.
- Attachments on tyre changes: receipts go on the linked service record.
- Seasonal-swap and rotation reminders. These are already a maintenance
  schedule ("every 10,000 km") or a manual reminder ("swap to winters in
  October"), so they are not built again here.

---

## Design decisions (record in `spec.md` before building)

### The tyre is the unit; the set is optional

- **Each tyre is its own row.** A set cannot be the unit, for two reasons.
  A front-wheel-drive car replaces its fronts long before its rears. A
  motorbike's front and rear differ in size and are replaced separately.
- **A set is an optional grouping**, used for seasonal swaps: *Winter
  wheels*, with a storage location ("Kwik Fit Southend, ref 4471"). A tyre
  belongs to at most one set.
- **Tyres and sets belong to one vehicle.** Attachments, backups, archiving
  and deletion already scope everything by `vehicle_id`, and tyres follow
  the same rule. Deleting the vehicle deletes its tyres. Archiving it keeps
  them, as history. Moving a set to another vehicle is an open question.

### What a tyre records

- **Brand and model:** free text, ≤ 60 chars each, trimmed, blank → null.
- **Size:** free text, ≤ 30 chars, normalised on save (upper case,
  whitespace collapsed), for example `205/55 R16 91V`. It is free text for
  the same reason `variant` is (Phase 9.1): sizes, load and speed indices
  and bike notations vary too much for a structured field to be right.
- **Season:** optional, `summer` | `winter` | `all_season`. Blank means not
  specified, and no badge is shown. Most UK drivers never say "summer
  tyres", so blank must look normal.
- **DOT date:** the optional four-digit code from the sidewall (`2323` =
  week 23 of 2023). It is stored as typed (`dot_code`) and as
  `manufactured_on`, a calendar `date`: the Monday of that ISO week, never
  converted through a time zone.
  - Validation, which blocks: exactly four digits, week 01–53, year 2000 or
    later (the four-digit format), and not after today in the owner's time
    zone.
  - **Age** is derived from `manufactured_on` like the vehicle's age
    (§7.2), using the same helper ("3 yrs 4 mo").
- **Notes:** optional, ≤ 500 chars.

### Positions

Positions come from the vehicle type, through one enum method
(`VehicleType::tyrePositions()`), so the forms, the tab and validation all
use the same list:

| Vehicle type | Positions |
|---|---|
| `car` | `fl` front left, `fr` front right, `rl` rear left, `rr` rear right, `spare` |
| `bike` | `front`, `rear` |

- At most one tyre per position at any moment.
- `spare` is optional and counts as fitted, but never as rolling.
- Changing a vehicle's type is refused while a tyre is fitted at a position
  the new type lacks: "Remove the tyres first: a motorbike has no rear left
  wheel." The vehicle form shows this as an error and keeps the typed
  values.

### Tyre changes are the only way a tyre moves

- **One form saves one change.** A `tyre_changes` row holds the kind, date,
  odometer, optional service record link and note. It has one
  `tyre_change_lines` row per tyre it touches, each with an action and the
  position afterwards.

  | Change kind | Lines | Odometer |
  |---|---|---|
  | `existing` (tyres already on the vehicle) | `on` | required, prefilled |
  | `fit` (fit new tyres) | `on` for the new, `off` or `retire` for any they replace | required, prefilled |
  | `swap` (seasonal: one set off, another on) | `off` for the fitted road tyres, `on` for the chosen set | required, prefilled |
  | `rotate` | `move` for each tyre whose position changes | required, prefilled |
  | `repair` (puncture and the like) | `repair` | optional |
  | `remove` (off into storage, or retired) | `off` or `retire` | required, prefilled |

  "Prefilled" means the latest reading in the owner's distance unit, as the
  fill-up form does it.
- **A tyre's state is replayed, then stored.** Status (`fitted` | `stored`
  | `retired`) and current position are computed by replaying the vehicle's
  changes in order (`done_on`, then odometer, then id). The result is
  stored on the tyre so lists can query it, like a schedule's `next_due`.
  - Every save, edit and delete of a change re-runs the replay in the same
    transaction.
  - An edit or delete that would make the replay impossible is refused with
    a message naming the problem. Examples: two tyres at one position, a
    stored tyre removed again, a retired tyre fitted.
  - Nothing is re-sequenced silently.
- **Retire reasons:** `worn` | `damaged` | `puncture` | `sold` | `other`.
  A retired tyre keeps its history and lifetime figures.
- **Rotate** takes a new position for every fitted tyre and must be a
  permutation of the fitted positions (the spare may join it).
- **Swap set** does two things in one change:
  - takes every fitted road tyre off into a set (an existing one, or a new
    name with a storage location);
  - fits a stored set's tyres at the positions they last had, which can be
    changed per tyre.

  The spare is left alone.
- **Fit new tyres** takes one description (brand, model, size, season)
  applied to every chosen position, plus an optional DOT code per position.
  This is how pairs are bought. Any tyre already at a chosen position must
  be dealt with on the same form: *Retire* (reason, default `worn`) or
  *Keep in storage*. The form works without JS: the description fields
  show once and the DOT fields show per position.
- **Tyres already on the vehicle** (`existing`) is how an owner starts. Its
  odometer hint reads: "If you don't know when they were fitted, leave
  today's reading: distance counts from now." A tyre whose first change is
  `existing` shows its distance as "12,400 mi since 3 Oct 2026", not as a
  lifetime figure.

### Distance is derived, never stored

- A tyre's **distance** is the sum of its rolling segments. A segment starts
  when the tyre goes on (or moves) to a non-spare position and ends when it
  comes off, moves to `spare`, or is retired.
- An open segment runs to the vehicle's current reading (§7.2).
- Segment ends use the change's odometer (canonical km).
- A segment that would come out negative (the odometer went backwards)
  counts as 0 and is flagged on the tab. Plausibility warnings on the
  readings themselves are unchanged.
- **This is why fit, swap, rotate, remove and existing require an
  odometer.** Distance per tyre is the point of the feature, and the
  prefill makes the field a single keystroke.

### Costs stay in maintenance

- **A change has no cost column.** Fit, swap, repair and remove forms have
  optional *Cost* and *Garage / shop* fields. When either is filled, saving
  writes a `tyres` service record in the **same transaction** and links it
  through `tyre_changes.maintenance_entry_id` (`ON DELETE SET NULL`). The
  record's title is generated ("2 × Michelin Primacy 4, front") and
  editable afterwards like any other.
- Instead, the change can **link an existing service record**. A select
  offers the vehicle's `tyres` records within 30 days of the change date,
  so history logged before this phase can be joined up.
- **The cost ledger (§7.7) does not change.** It already reads maintenance
  costs, so a tyre cost is counted once, under maintenance.
- **Cost per distance** for a *retired* tyre is the linked fit record's cost
  split evenly across that change's `on` lines, divided by the tyre's
  lifetime distance ("£4.90 per 1,000 mi", in the owner's distance unit).
  Tyres still fitted show none, because the figure falls as they run.
- **With the `maintenance` module off**, the cost, garage and link fields
  are hidden and tyre changes still work. Links that already exist are
  kept, untouched.

### Odometer readings

- **A change's reading joins the series (§7.2)** as a new source, `tyre`
  (label *Tyres*), at local noon on `done_on`, as a document's does:
  - written in the change's transaction;
  - moved or removed when the change is edited;
  - deleted with it (`odometer_readings.tyre_change_id`, `ON DELETE CASCADE`);
  - checked for plausibility with the usual warning, never blocked.
- **One reading, never two.** When the change is linked to a service record
  that has an odometer, the record's reading covers it and the change
  writes none. The two stay in step:
  - a form that writes both writes the same date and odometer to both;
  - editing the service record's date or odometer updates its linked
    changes (with a replay) in the same transaction;
  - deleting the service record unlinks the change, which then writes its
    own reading in that transaction.

### Where it shows

- **Tyres tab** (`/vehicles/{id}/tyres`), after Maintenance, with the
  shared header and toolbar:
  - *Export CSV* and *Fit tyres* as the add button. *Swap set*, *Rotate*,
    *Repair* and *Remove* sit in a menu beside it, each also its own page.
  - Sections:
    - **On the vehicle:** a grid in position order (a 2 × 2 grid plus the
      spare for a car, two cards for a bike). Each card shows position,
      brand, model, size, season badge, age and distance. Plain HTML, not
      a drawing, so it works without JS and reads well to a screen reader.
    - **In storage:** grouped by set, with the set's storage location.
    - **Retired:** folded away, newest first, with lifetime distance, cost
      per distance where known, and reason. Lifetime distance answers "how
      long did my last ones last?"
    - **Changes:** newest first, 25 per page. Each row shows kind, summary,
      odometer, and the linked service record's cost with a link to it.
  - An empty tab offers *Tyres already on the vehicle* first, then *Fit
    tyres*.
- **Overview:** a *Tyres* card (fitted tyres in one line each: position,
  brand, model, age) with *All tyres →*. It is hidden when the vehicle has
  no tyres.
- **Log entry chooser** (§7.8) gains *Tyre change*, which opens *Fit tyres*
  with the vehicle picker.
- **History (§7.16):**
  - tyre changes join `ActivityFeed` as the kind *Tyres*, with the chip
    `?kind=tyres`;
  - summary lines: "Fitted 2 × Michelin Primacy 4 (front)", "Swapped to
    Winter wheels", "Rotated 4 tyres", "Repaired front left";
  - **a linked change is never listed on its own.** Its service record's
    row carries the summary as a second line, and that row counts under
    both the *Service* and *Tyres* chips;
  - tyre readings are left out like other owned readings;
  - fill-up runs are broken by a tyre row, as by any other entry.
- **Print view:** *Tyres* is a kind, included by default. The header block
  gains *Tyres fitted*: each fitted tyre's position, brand, model, size and
  age.
- **Recent activity** lists tyre changes plainly, with the same
  never-twice rule.
- **Garage cards, *Your vehicles* tiles and the pinned card do not change
  in 11.1.** Tyre reminders reach them through the existing "N due" and
  *Next due* in 11.2.

### Module toggle

- `tyres` joins §7.10's modules: enabled unless `features` says otherwise,
  falling back to `FEATURES_TYRES` (default true).
- **Off** removes the Tyres tab, the overview card, the chooser entry, the
  *Tyres* chip and rows in history and print, and the tyres CSV. Its routes
  answer 404. Readings already written by tyre changes stay in the mileage
  log, as fill-up readings do when `fuel` is off. Linked service records
  are untouched.

---

## Tasks

### 11.1.0 Spec first
Per `CLAUDE.md` §12, update `spec.md` before building:
- [ ] §6: new **Tyre**, **TyreSet**, **TyreChange** and **TyreChangeLine**
      entries. OdometerReading gains the `tyre` source and `tyre_change_id`.
- [ ] New §7.17 *Tyres*: everything under the design decisions above.
- [ ] §7.1: the vehicle type change refused while tyres are fitted at
      positions the new type lacks.
- [ ] §7.2: the `tyre` reading source and label.
- [ ] §7.7: tyre costs are maintenance costs (no new ledger line).
- [ ] §7.8: the chooser entry; *Recent activity* lists tyre changes.
- [ ] §7.10: the `tyres` module.
- [ ] §7.13: tyres in CSV export and backups.
- [ ] §7.16: the *Tyres* kind, the linked-change rule and the print header.
- [ ] §9: `FEATURES_TYRES`.
- [ ] §12: remove "Tyre-life tracking". §13: a Phase 11.1 entry.
- [ ] `ROADMAP.md` gains a Phase 11.1 row and section.
- [ ] `CHANGELOG.md` `[Unreleased]` gets an entry, with the upgrade note:
      new tables, a new reading source, new `FEATURES_TYRES` (default on),
      and the backup schema rule.

### 11.1.1 Domain + migration
- [ ] Migration: `tyre_sets`
  - columns: id, vehicle_id FK cascade, name (100), storage_location
    (200, nullable), notes (500, nullable), created/updated (UTC);
  - index `(vehicle_id)`.
- [ ] Migration: `tyres`
  - columns: id, vehicle_id FK cascade, set_id FK nullable
    `ON DELETE SET NULL`, brand (60), model (60), size (30), season,
    dot_code (4), manufactured_on (`date`), status, position,
    retired_reason, notes (500), created/updated;
  - brand, model, size, season, dot_code, manufactured_on, position,
    retired_reason and notes are all nullable;
  - index `(vehicle_id, status)`.
- [ ] Migration: `tyre_changes`
  - columns: id, vehicle_id FK cascade, kind, done_on (`date`),
    odometer_km (`decimal(12,3)`, nullable), maintenance_entry_id FK
    nullable `ON DELETE SET NULL`, note (500, nullable), created/updated;
  - index `(vehicle_id, done_on)`.
- [ ] Migration: `tyre_change_lines`
  - columns: id, change_id FK cascade, tyre_id FK cascade, action,
    position (nullable);
  - unique `(change_id, tyre_id)`.
- [ ] Migration: `odometer_readings.tyre_change_id` (nullable FK,
      `ON DELETE CASCADE`), plus the `tyre` source.
- [ ] Codes are plain strings, as maintenance categories are. Check how
      `source` is constrained today on every engine, and widen it in the
      same migration if needed.
- [ ] All reversible. They apply and roll back on SQLite, PostgreSQL, MySQL
      and MariaDB.
- [ ] Enums: `TyreSeason`, `TyrePosition`, `TyreStatus`, `TyreChangeKind`,
      `TyreLineAction` and `TyreRetireReason`.
- [ ] `VehicleType::tyrePositions()` returns the ordered positions for each
      type.
- [ ] `DotCode` value object: parse, validate, and convert to
      `manufactured_on`.
- [ ] Entities and `Row` mapping. Dates are `DateTimeImmutable` at
      midnight, with no time-zone conversion.

### 11.1.2 Services
- [ ] `TyreRepository` (DBAL only, bound parameters), covering tyres, sets,
      changes and lines.
- [ ] `TyreChangeService`: one method per kind. Each validates, writes the
      change, lines, readings and any service record, then replays and
      stores tyre state, all in one transaction.
- [ ] Edit and delete of a change go through the same replay. A refusal
      rolls back everything and returns a typed error for the form.
- [ ] `TyreReplay`: a pure function from ordered changes to states and
      segments, unit-testable without a DB.
- [ ] `TyreDistance`: segments to distance per tyre, and cost per distance
      for retired tyres. Returns typed results; no maths in templates or
      Actions.
- [ ] `MaintenanceService` edit and delete keep linked changes in step
      (date, odometer, reading ownership) in the same transaction.
- [ ] `VehicleService::update()` refuses a type change that would strand
      fitted tyres.
- [ ] `ActivityFeed` gains the *Tyres* kind and the linked-change rule.

### 11.1.3 Forms
- [ ] *Tyres already on the vehicle*, *Fit tyres*, *Swap set*, *Rotate*,
      *Repair* and *Remove*. Each is its own page and a desktop modal
      (`data-modal`, with `return` honoured, §5).
- [ ] All work without JS. Errors keep the typed values. Hints are visible
      text or use `aria-describedby`.
- [ ] Date defaults to today in the owner's time zone. Odometer is in the
      owner's distance unit, prefilled with the latest reading, and uses
      the existing odometer parser.
- [ ] Cost and garage, or *Link a service record*, only when the
      `maintenance` module is on.
- [ ] Sets: create one inline on *Swap set* and *Remove* (name and storage
      location). A small edit page renames a set or updates its storage.
      A set can only be deleted while it is empty.
- [ ] Tyre edit page: brand, model, size, season, DOT and notes (never
      state or position, which only change through a change). Deleting a
      tyre removes its lines. A change left with no lines is deleted with
      its reading, but any linked service record is kept. Then the replay
      runs.

### 11.1.4 Display
- [ ] Tyres tab (sections as above), overview card and chooser entry.
- [ ] History: kind chip, rows, the service-row second line, print kind
      and header block. *Recent activity*.
- [ ] Mileage tab: the *Tyres* source label. A tyre reading's edit link
      opens its change.
- [ ] Archived vehicles show their tyres read-only, like their other
      history. Changes are still editable, as for other entries.
- [ ] Feature toggle: everything listed under *Module toggle* is gated.
      Routes sit behind the existing route-group middleware.

### 11.1.5 CSV export + backup
- [ ] *Export CSV* on the Tyres tab offers two files.
  - Tyres: brand, model, size, season, DOT, manufactured on, status,
    position, set, storage location, distance (owner's unit), retired
    reason.
  - Changes: date, kind, odometer (owner's unit), tyres, positions, linked
    service record, cost.
  - Both UTF-8 and formatted per §7.7.
- [ ] Backups include the four new tables and the new column automatically.
      The schema version moves, so the upgrade note repeats the rule:
      restore an older backup with its own version first, then upgrade.

### 11.1.6 Demo seed
- [ ] `DemoDataSeeder`: one petrol car starts with `existing` tyres a year
      ago. Its front pair is replaced mid-year with a linked, costed
      service record, so there are retired fronts with a lifetime distance
      and cost per distance. It has a stored *Winter wheels* set with a
      storage location, swapped on in November and off in March, and one
      rotation.
- [ ] The motorbike has front and rear, with the rear replaced once.
- [ ] README's seed description follows.

### 11.1.7 i18n
- [ ] English and German for every label, hint, kind, action, position,
      reason, error and summary line:
  - *Reifen*, *Reifenwechsel*, *Reifensatz*, *Lagerort*;
  - *Sommerreifen*, *Winterreifen*, *Ganzjahresreifen*;
  - *Vorne links* / *Vorne rechts* / *Hinten links* / *Hinten rechts* /
    *Reserverad*, and for bikes *Vorderrad* / *Hinterrad*;
  - *Positionen tauschen*, *Reparatur*, *Ausgemustert*;
  - *DOT-Nummer*, *Herstellungsdatum*.
- [ ] Summaries use ICU plurals ("{count, plural, one {# Reifen} other
      {# Reifen}} montiert").

### 11.1.8 Tests
- [ ] **Unit:** `DotCode`
  - `2323` → Monday of ISO week 23, 2023;
  - week 00 and 54 rejected;
  - a code in the owner's future rejected;
  - three digits (pre-2000) rejected;
  - `manufactured_on` never shifted by the time zone.
- [ ] **Unit:** positions by vehicle type; size normalised; season blank →
      null.
- [ ] **Unit (replay):**
  - fit, then swap, then swap back leaves the state as before;
  - rotate as a permutation, and refused when not one;
  - fitting to an occupied position without dealing with the tyre there
    is refused;
  - deleting a middle change that the later ones depend on is refused
    with a message;
  - a retired tyre can't be fitted.
- [ ] **Unit (distance):**
  - segments across fit, rotate to spare and back, and swap;
  - an open segment runs to the current reading;
  - spare time not counted;
  - a negative segment gives 0 and is flagged;
  - `existing` shows "since";
  - cost per distance splits a two-tyre record in half, with none for a
    fitted tyre.
- [ ] **Integration:**
  - each change kind through its form (page and modal), with and without
    JS;
  - *Fit tyres* with a cost writes exactly one service record and one
    reading;
  - linking an existing record writes no second reading;
  - editing the record's odometer moves the change;
  - deleting the record leaves the change with its own reading;
  - a forced failure writing the reading leaves no change, lines or
    record behind.
- [ ] **Integration:**
  - the vehicle type change is refused while rear tyres are fitted;
  - the `tyres` module off gives 404 routes, with the tab, chip, card and
    chooser gone and readings kept; on restores everything;
  - `maintenance` off hides cost fields and tyre changes still save;
  - history lists a linked change once;
  - the print header shows fitted tyres;
  - CSV output;
  - backup → restore round-trip;
  - migration up and down.
- [ ] Pass on **both** MySQL and Postgres (plus MariaDB and SQLite via
      `bin/test-all-dbs.sh`); smoke test at a subpath.

---

## Deliverables
Tyres recorded per vehicle, each with its position, set, age and distance
worked out from the mileage series. Every tyre change is logged, and costs
flow through the service records that already exist. Released with
Phase 11.2 as Logbook v1.3.0.

## Acceptance criteria
- [ ] An owner can record the tyres already on a vehicle, fit new ones,
      swap sets, rotate, repair and retire, on a car and on a motorbike.
- [ ] Each tyre's distance matches the mileage series, excluding time as a
      spare or in storage. A retired tyre shows its lifetime distance and,
      when costed, its cost per distance.
- [ ] A tyre cost appears once, under maintenance, in every report and in
      history.
- [ ] Every change with an odometer produces exactly one reading, owned by
      the change or by its service record, never both.
- [ ] An edit that would break the sequence is refused with a clear
      message; nothing is re-sequenced silently.
- [ ] Switching `tyres` off removes it everywhere and loses nothing.
- [ ] A DOT date is never shifted by the owner's time zone.
- [ ] Existing data untouched; every existing figure unchanged.
- [ ] Suite green on both DBs; translatable (en + de); works behind a
      subpath with deep-link refresh; Docker and bare-PHP paths both work.

## Gotchas
- **No cost on the tyre or the change.** A second cost path would double
  count in reports the moment someone also logs the service record.
- **No stored distance.** It would be right until the next fill-up. Store
  only the state the replay needs to be queryable (status, position).
- **One reading per event.** A swap touches eight tyres but is one visit
  to one odometer: one change, one reading.
- **The replay is the rule-keeper.** Validate every edit and delete by
  replaying the vehicle's changes, not by checking the edited row alone.
  A middle-of-history edit is where sequence bugs hide.
- **The spare fits but doesn't roll.** Easy to miss in the distance sum.
- **`done_on` is a calendar date** (like `performed_on`), not an instant.
  Only its reading is placed at local noon.
- **`maintenance` off must not break tyres.** Guard every read of the
  linked record.

## Open questions
- **Moving a set between vehicles** (a winter set sold with the car, or
  moved to its replacement). It needs tyres to change `vehicle_id` and
  history to show the move; left out to keep every scope per vehicle.
- **Wheels:** some owners store winter tyres on their own rims. A free-text
  note on the set covers it for now.
- **CSV import** of tyre history for owners coming from a spreadsheet.
