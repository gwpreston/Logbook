# Phase 54.15 — Category colours on the dashboard's rows

*The dashboard's list rows show what they are at a glance: each row's icon
tile takes its cost category's colour, as the design prototype draws its
rows, so fuel, maintenance and documents can be told apart without reading.
Status colours are untouched.*

Status: 🚧 in progress · no release of its own (goes out with the next one) · file lives in `docs/phases/`

This is the batch after [Phase 54.14](phase-54.14.md), which is complete
and released as v3.21.10. It came from an owner request on 2026-11-13
("colorize the dashboard … easier to spot information … keep our existing
brand colors"), done as a refinement with the `impeccable` skill's
*colorize* command. No known-issues row or open question covers it.

Read [`CLAUDE.md`](../../CLAUDE.md), [`spec.md`](../../spec.md) §7.8
(*Dashboard*) and §8 (*Accent colour*, *Category colours*), `DESIGN.md`
(*Colors*, *Named Rules*) and `design-import/Logbook.dc.html` (`KCOL`)
first.

**Prerequisites:** none.

---

## Goals

1. On the dashboard, most rows sat in the same grey tile. Only *Needs
   attention* (red or amber by urgency) and *Recent activity* (a coloured
   glyph on grey) told them apart. The prototype colours each row by its
   kind (`KCOL`). Bring the remaining rows into line, using the existing
   cost-category tokens only: no new colours, and nothing that changes
   with status.
2. Keep the reminder and *Coming up* rows the same on their own pages as
   on the dashboard, since they share macros.

## Not in scope

- Status colours. Red, amber and green keep meaning overdue, due soon
  and OK (*The Status Is Sacred Rule*). A tile never turns red or amber
  outside *Needs attention*.
- The widget header icons. They stay in the accent, which marks where
  you are.
- New tokens or a change to any token's value.

## Decisions (and why)

- **The tile says what a row is, the pill says how urgent.** A row's icon
  tile takes its cost category (`--c-fuel`, which is the accent,
  `--c-maint`, `--c-ins`, `--c-other`, `--c-build`), the same mapping the
  expense groups and Recent activity use (`CostGroup::color()`,
  `ActivityKind::tone()`). Tinting tiles by urgency instead would double
  the pill and make red mean less.
- **Where the mapping lives.** `ReminderSource::tone()` and
  `ForecastSource::tone()` in PHP, not in Twig (CLAUDE.md §13).
  Maintenance and tyres map to `c-maint`; documents and the first MOT to
  `c-ins`; finance and issues to `c-other`; parts to `c-build`. A manual
  reminder or *Coming up* item has no category and keeps `muted`.
  Documents and the fuel widgets set their token in the template, as
  `history.twig` already did for fuel runs.
- **An opaque wash, not a see-through one.** `.list__icon--tone` mixes 12%
  of the tone into `--surface` (`color-mix(in oklab, …)`). The glyph then
  keeps 3:1 or better on its tile in both themes and all four accents.
  The lowest are `c-other` and `c-maint` in light at 3.3:1, and `c-other`
  in dark at 3.3:1. 14% would still pass; 12% keeps the closest pairs
  clear of the line. Print gives the tile a white background.
- **The Teal accent.** Fuel takes the accent, so with Teal, fuel (teal)
  and maintenance (green) are close in hue. Their glyphs differ and each
  row names what it is, as the charts already do with the same pair, so
  this is accepted rather than adding a new token.

## Tasks

### 54.15.1 Tones

- [x] `ReminderSource::tone()` and `ForecastSource::tone()`, each with a
      docblock citing spec §8 *Category colours*.
- [x] `tests/Unit/Domain/Reminder/ReminderSourceTest.php`: every case
      of both enums maps to its category, and none maps to a status
      colour or the accent.

### 54.15.2 Tiles

- [x] `assets/css/app.css`: `.list__icon--tone` (the wash) and its print
      override; `public/assets` rebuilt.
- [x] Dashboard: *Upcoming reminders*, *Coming up* (`macros/forecast.twig`),
      *Documents*, *Recent fuel*, *Cheapest fuel*, *Efficiency trend* and
      *Recent activity*.
- [x] The shared rows elsewhere: the Reminders page
      (`macros/reminders.twig`), history (`macros/history.twig`) and tyres
      (`macros/tyres.twig`).

### 54.15.3 Paperwork

- [x] spec.md §8 *Category colours*; `DESIGN.md` *Chart categories* and
      *The Status Is Sacred Rule*; CHANGELOG [Unreleased].
- [x] ROADMAP row and section; spec §13 entry.

### 54.15.4 Finish

- [ ] The suite on MySQL and PostgreSQL (SQLite passes: 4,878 tests).
- [ ] Design review: dashboard, Reminders, *Coming up* and a vehicle's
      history at 375, 768 and 1440, light and dark, with the Teal and
      Purple accents.
- [ ] Merge review; merge (as a merge commit, not a squash).

## Acceptance criteria

- On the dashboard, every row in the widgets above shows its category in
  its icon tile. A row with no category keeps the grey tile.
- No tile outside *Needs attention* is red, amber or green by status.
- Each toned glyph is at 3:1 or better on its tile in light and dark and
  with each accent.
- Printing shows black glyphs on white tiles.
- Lint, analyse and the suite pass on SQLite, MySQL and PostgreSQL.

## Open questions

None.
