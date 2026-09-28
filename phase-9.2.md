# Phase 9.2 — Plug-in hybrids + v1.1.0

**Goal:** tell a **plug-in hybrid** apart from a **self-charging (or mild)
hybrid**. Today one `hybrid` fuel type covers both, so a Toyota that can
never be plugged in is offered charging on every fill-up, and a plug-in
hybrid is described no differently from one that runs on petrol alone. Split
it in two, move existing vehicles to the right one from their own history,
then cut **v1.1.0**.

Read `spec.md` (§6 Vehicle and FuelEntry, §7.1, §7.3, §7.8) and `CLAUDE.md`
(§6, §8, §11) before starting.

**Prerequisites:** [Phase 9.1](phase-9.1.md) complete and green.

---

## Scope

**In:** a new vehicle fuel type `phev`, a data migration that sorts existing
`hybrid` vehicles, the fill-up picker and form defaults for both kinds,
labels and badges, the demo seed, translations, and the v1.1.0 release.

**Out:** battery capacity for plug-in hybrids (the one capacity field stays
the fuel tank), electric-only range or utility factor, combined petrol +
electricity economy, bi-fuel vehicles (petrol + LPG / E85), AdBlue, and any
other fuel type. A second tank or energy source per vehicle is not modelled.

---

## Design decisions (record in `spec.md` before building)

- **Two vehicle fuel types instead of one:**

  | Code | Label (en) | Hint | Fits on the fill-up picker |
  |---|---|---|---|
  | `hybrid` | Hybrid | Self-charging or mild hybrid; fills with petrol only | petrol |
  | `phev` | Plug-in hybrid | Fills with petrol and charges from a plug | petrol, electricity |

  `phev` behaves exactly as `hybrid` does today. `hybrid` now behaves like a
  petrol vehicle that is labelled as a hybrid. Nothing is taken away:
  electricity stays reachable for a `hybrid` under *Other fuels*, as every
  family already is, so a vehicle set to the wrong type can still log what
  it needs.
- **Fill-up `fuel` is unchanged.** Entries still carry the energy family
  (`petrol` | `diesel` | `lpg` | `ev` | `other`); there is no `phev` fuel,
  just as there was never a `hybrid` one. The full-to-full maths, the
  separate petrol and electricity series, grades and economy by grade are
  untouched.
- **Existing vehicles are sorted by their own history, not guessed.** The
  migration moves a `hybrid` vehicle to `phev` when it has at least one
  fill-up with fuel `ev`, archived vehicles included. Every other `hybrid`
  stays `hybrid`. The owner can change either afterwards. Rollback turns
  every `phev` back into `hybrid`, which is lossless because that is what
  `hybrid` meant before.
- **Default grade** follows the fuel type as before: a petrol grade for both
  `hybrid` and `phev`. The form default is still looked up per family, so a
  plug-in hybrid's charge takes the last charging type and its fill-up the
  last petrol grade.
- **Capacity** stays litres for both (the tank). The form label reads
  *Tank capacity* for `hybrid` and `phev`, *Battery capacity* only for `ev`.
- **Changing the type on edit** needs no clean-up beyond Phase 8's rule
  (a default grade that no longer fits is cleared). Existing charges on a
  vehicle changed from `phev` to `hybrid` are kept and still shown.

---

## Tasks

### 9.2.0 Spec first
Per `CLAUDE.md` §12, update `spec.md` before building:
- [ ] §6 Vehicle — fuel type gains `phev`; `hybrid` redefined as
      self-charging / mild; `default_grade` and capacity rules for both.
- [ ] §7.1 — fuel type labels and hints on the vehicle form and cards.
- [ ] §7.3 — families that fit the vehicle: petrol for `hybrid`, petrol
      and electricity for `phev`; form default wording ("a plug-in hybrid's
      charge never takes the petrol grade").
- [ ] `ROADMAP.md` gains a Phase 9.2 row; `CHANGELOG.md` `[1.1.0]` entry.

### 9.2.1 Domain + migration
- [ ] `VehicleFuelType` (or the existing enum) gains `Phev`, with
      `fittingFamilies(): list<Fuel>` so the picker, the form default and
      any other caller ask the enum instead of repeating the rule.
- [ ] Find every `hybrid` check in the code (picker, form default, default
      grade filter, capacity label, seed, tests) and route it through the
      enum. Search for the string as well as the enum case.
- [ ] Check how `vehicles.fuel_type` is stored. If it is a plain string
      column, no schema change is needed. If there is a length limit, check
      constraint or native enum on any engine, widen it in the same
      migration.
- [ ] Data migration: `UPDATE vehicles SET fuel_type = 'phev' WHERE
      fuel_type = 'hybrid' AND EXISTS (SELECT 1 FROM fuel_entries WHERE
      fuel_entries.vehicle_id = vehicles.id AND fuel = 'ev')`, via DBAL with
      bound values; `down()` sets `phev` back to `hybrid`. Applies and rolls
      back on SQLite, PostgreSQL, MySQL and MariaDB. (MySQL cannot always
      reference the updated table in a subquery. If it refuses, select the
      ids first and update by id.)

### 9.2.2 Vehicle form
- [ ] Fuel type select: *Hybrid* and *Plug-in hybrid* next to each other,
      each with its hint (visible text or `aria-describedby`, not only a
      `title`).
- [ ] *Default grade* offers petrol grades for both; capacity label as above.
- [ ] Works in the desktop modal and as its own page, without JS.

### 9.2.3 Fill-up form
- [ ] `hybrid`: the fitting group is petrol only; electricity moves to
      *Other fuels*. *Used on this vehicle* still comes first, so a hybrid
      that has been charged before still shows its charging type there.
- [ ] `phev`: unchanged from today's hybrid (petrol and electricity).
- [ ] Offline: the cached `/fuel/new` forms are rebuilt with the new groups.
      Queued entries need no change because they carry a fuel family, never
      a vehicle type.

### 9.2.4 Display
- [ ] Fuel type label reads *Plug-in hybrid* on garage cards, the vehicle
      header, the *Your vehicles* tiles and the pinned vehicle card.
- [ ] Figures shown for a `phev` on cards and tiles are unchanged from
      today's hybrid; a `hybrid` shows its petrol figures only (it has no
      electricity series unless something was logged under *Other fuels*).

### 9.2.5 Demo seed + backup
- [ ] `bin/dev seed`: the seeded hybrid, which has EV charges, becomes
      `phev`; add or relabel so the seed still shows one of each where
      cheap, otherwise just the `phev`. README's seed description follows.
- [ ] Backups: no new columns, but the data migration changes values, so
      the existing rule applies (restore an older backup with its own version
      first, then upgrade; the migration then sorts its hybrids).

### 9.2.6 i18n
- [ ] English and German: *Hybrid* / *Hybrid (Voll- oder Mildhybrid)*,
      *Plug-in hybrid* / *Plug-in-Hybrid*, both hints, and *Tank capacity*
      / *Tankinhalt*.

### 9.2.7 Release v1.1.0
- [ ] `VERSION` → `1.1.0`; sidebar, Settings and `/health` show it.
- [ ] `CHANGELOG.md` `[1.1.0]` gathers Phase 9.1 and 9.2 with upgrade notes:
      two nullable vehicle columns (9.1); hybrids with charges become
      plug-in hybrids (9.2, check yours); backup schema rule; no config
      changes.
- [ ] `ROADMAP.md`: Phase 9.1 and 9.2 rows ✅.
- [ ] Tag `v1.1.0`; image published as `1.1.0`, `1.1`, `1` and `latest`.

### 9.2.8 Tests
- [ ] Unit: `fittingFamilies()` for every fuel type; picker groups for a
      `hybrid` (petrol fits, electricity under *Other fuels*) and a `phev`
      (both fit); a `hybrid` with a past charge shows it under *Used on this
      vehicle*; form default per family for a `phev`.
- [ ] Unit: default grade accepts a petrol grade for both, rejects a
      charging type for `hybrid`; capacity label by type.
- [ ] Integration (migration): a hybrid with one `ev` fill-up → `phev`; a
      hybrid with none → `hybrid`; an archived hybrid with charges → `phev`;
      petrol, diesel and EV vehicles untouched; rollback restores `hybrid`
      for all of them; migrate → rollback → migrate is stable.
- [ ] Integration: add / edit vehicles of both types (page and modal); log
      a fill-up and a charge on each; economy figures for an existing plug-in
      hybrid identical before and after the upgrade.
- [ ] Pass on **both** MySQL and Postgres (plus MariaDB and SQLite via
      `bin/test-all-dbs.sh`); smoke test at a subpath.

---

## Deliverables
Self-charging and plug-in hybrids told apart, with a fill-up form that only
offers charging where it makes sense, existing vehicles sorted automatically
from their history; released together with Phase 9.1 as Logbook v1.1.0.

## Acceptance criteria
- [ ] A vehicle can be *Hybrid* or *Plug-in hybrid*; each is labelled
      clearly with its hint.
- [ ] After upgrading, every hybrid that had ever been charged is a plug-in
      hybrid and the rest are hybrids; nothing else changes.
- [ ] A hybrid's fill-up form leads with petrol only; a plug-in hybrid's
      with petrol and electricity; either can still log anything under
      *Other fuels*.
- [ ] Every economy, cost and grade figure is identical before and after
      the upgrade.
- [ ] `/health`, sidebar and Settings show v1.1.0; changelog and roadmap
      updated.
- [ ] Suite green on both DBs; translatable (en + de); works behind a
      subpath with deep-link refresh; Docker and bare-PHP paths both work.

## Gotchas
- **`phev` is a vehicle type, never a fuel.** Adding it to the fill-up
  `Fuel` enum would split the economy series and break CSV import.
- **Don't hide electricity from a `hybrid`**, only demote it. A vehicle set
  to the wrong type must still be able to log what went in.
- **Scattered `hybrid` checks** are the main risk. One enum method, then
  grep until nothing else compares the string.
- **The migration decides from data the owner can see.** Say so in the
  changelog so an owner whose plug-in hybrid was never charged in Logbook
  knows to change it by hand.