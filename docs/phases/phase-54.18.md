# Phase 54.18 — Telling same-named vehicles apart from the known-issues log + patch release

*One open row of the known-issues log closed: two vehicles with the same
name (two "Ford Fiesta"s with no nickname) are told apart by their
registration, else their year, on the true-cost *Fleet* card and in every
vehicle picker.*

Status: 📋 planned · releases **v3.21.14**, a patch release · file lives in `docs/phases/`

This is the batch after [Phase 54.17](phase-54.17.md), the last
known-issues batch. It takes K203 (MEDIUM), the only *Open* row that can
be built now: it waited on #621 until the owner decided it on 2026-11-15
(the registration, only on a clash). It is numbered 54.18 because 54.17
is the last row of the ROADMAP table and no phase is active (54.17 is
complete and released as v3.21.13).

Read [`CLAUDE.md`](../../CLAUDE.md), [`spec.md`](../../spec.md) §7.1
(*Telling same-named vehicles apart*), §7.7 (the true-cost report), §8
and §13, and [`open-questions.md`](open-questions.md) first.

**Prerequisites:** [Phase 54.17](phase-54.17.md) merged and released as
v3.21.13.

---

## Goals

1. **K203.** With two vehicles called "Ford Fiesta" (no nicknames), the
   true-cost *Fleet* card's table headers and chart legend read
   "Ford Fiesta (AB12 CDE)" and "Ford Fiesta (XY34 ZZZ)", and so does
   every vehicle picker: Reports, Ownership, True cost, Incident history,
   Stations, the app-import maps and the scan picker. A household with no
   two names alike sees no change.

## Not in scope

- **The other lists of vehicles** (the garage, fleet history, forecast,
  the dashboard's finance widget, the reminders calendar and the rest):
  #629 applies the label first where vehicles sit side by side as a
  choice or a column; the helper makes the rest a one-line change for a
  later phase.
- **K145** needs a recorded Anthropic request, so a real key. **K174**
  waits on `firebase/php-jwt` supporting phpseclib 4. **K201** (the
  test-run lock doesn't reach across Docker Desktop on macOS) has no known
  fix; Phase 54.17's K207 documents it.
- No other row of the log is *Open*.

---

## Decisions (and why)

- **K203 (#621):** option (1), the registration only on a clash. It
  changes nothing for the households that don't have one, and a
  registration is what an owner tells their cars apart by. Not chosen:
  always the registration (2); unique names enforced on save (3), which
  leaves existing clashes; leaving it to nicknames (4).
- **Where (#629):** the true-cost *Fleet* card (its table's column
  headers and its chart legend, `TrueCostCharts::fleet()`) and every
  vehicle picker: `reports/index.twig`, `reports/ownership.twig`,
  `reports/true_cost.twig`, `incidents/history.twig`,
  `stations/near.twig`, `stations/_nearby.twig`, `import_app/map.twig`,
  `import_app/tracktor_map.twig` (the target picker) and
  `scan/pick.twig`. Not chosen: every list of several vehicles (about 18
  templates); the Fleet card alone.
- **The label (#630):** a clashing name gains its registration as
  stored, "Ford Fiesta (AB12 CDE)"; one with no registration gains its
  year, "Ford Fiesta (2019)". Vehicles still alike after that (no
  registration and the same or no year) are numbered in the order they
  were added, oldest first: "Ford Fiesta (1)", "Ford Fiesta (2)". A
  vehicle whose name clashes with no other keeps its plain name. Names
  are compared after trimming and ignoring case, since "Ford fiesta" and
  "Ford Fiesta" look alike. The wrapper is a new string
  `vehicle.name_distinct`: "{name} ({detail})" in English and German. A
  picker's archived marker follows it, as today: "Ford Fiesta (AB12 CDE)
  (Archived)". Not chosen: stopping at the year, leaving two vehicles
  identical.
- **Which vehicles clash (#631):** only those shown together in the same
  list, chart or picker. An archived Fiesta that a picker doesn't list
  doesn't change the active one's label there. Not chosen: the whole
  garage, archived included.
- **How it's built:** one place decides the labels, as
  `Vehicle::description()` does for the descriptive line: a
  `VehicleLabels` service that takes the vehicles shown together and
  returns a label per vehicle id, used by `TrueCostCharts` and through a
  Twig function `vehicle_labels(vehicles)` by the templates.
  `Vehicle::name()` is unchanged.
- **Release (#628):** on its own as patch release v3.21.14, after 54.17.

## Tasks

### 54.18.0 Start of phase

- [ ] Read `open-questions.md` and the *Open* and *Scheduled* rows of
      `known-issues.md` (CLAUDE.md §12). #621 and #628–#631 were decided
      when the phase was planned; check that none of those still open
      bears on K203.

### 54.18.1 Telling same-named vehicles apart (K203)

- [ ] `VehicleLabels` service: given the vehicles shown together, a label
      per id as #630 and #631 say; `vehicle.name_distinct` in every
      catalogue in `translations/`.
- [ ] Unit tests: no clash keeps the plain name; two Fiestas with
      registrations; one without a registration gets its year; two with
      neither are numbered oldest first; "Ford fiesta" clashes with
      "Ford Fiesta"; a nickname that matches another vehicle's make and
      model clashes too.
- [ ] `TrueCostCharts::fleet()` names each series by its label; the
      Fleet table's headers follow (they read the series).
- [ ] Twig function `vehicle_labels(vehicles)`; the nine picker templates
      (#629) show `labels[vehicle.id]` in place of `vehicle.name()`.
- [ ] HTTP test: two "Ford Fiesta"s with different registrations; the
      true-cost report's Fleet table headers, its chart data and its
      picker, and the Reports picker, show both registrations (fails
      today); a household with one Fiesta shows "Ford Fiesta" alone.
- [ ] Checked in a browser at 375 px and wide, English and German: the
      Fleet table, legend and pickers with two Fiestas.

### 54.18.2 Finish

- [ ] Known-issues row K203 *Fixed* (Phase 54.18); #621 and #629–#631
      marked as built.
- [ ] CHANGELOG [Unreleased]; spec §13; ROADMAP status.
- [ ] Merge review; merge. (After the pull request; merge as a merge
      commit, not a squash.)

### Release

- [ ] VERSION 3.21.14, CHANGELOG 3.21.14, README status (#628).
- [ ] Tag `v3.21.14` once merged.

## Acceptance criteria

- K203 has a regression test that fails without its fix (the HTTP test
  above), and `VehicleLabels` has unit tests for each case in #630.
- `composer lint`, `composer analyse` and `bin/test-all-dbs.sh` pass on
  SQLite, PostgreSQL, MySQL and MariaDB; `composer coverage:check`
  passes.
- `MarkdownLinksTest` passes.
- No migration and no new setting.
- `vehicle.name_distinct` is in every catalogue in `translations/`.

## Open questions

- **#621 — How should two vehicles with the same displayed name be told
  apart (K203)?** *Decided 2026-11-15:* (1) the registration (or the year
  when there's none) only when two names clash. Not chosen: always the
  registration (2); unique names enforced on save (3); leave it to
  nicknames (4).
- **#628 — Is Phase 54.18 released on its own (v3.21.14) or with the next
  releasing phase?** *Decided 2026-11-15:* on its own as patch release
  v3.21.14, after 54.17. Not chosen: with the next releasing phase.
- **#629 — Where is the distinct label applied first (K203)?** *Decided
  2026-11-15:* the true-cost *Fleet* card and every vehicle picker. Not
  chosen: every list of several vehicles; the Fleet card alone.
- **#630 — What does a clashing name look like, and what if the
  registration doesn't separate them (K203)?** *Decided 2026-11-15:* the
  registration, else the year, else a number in the order added. Not
  chosen: stopping at the year.
- **#631 — Which vehicles count when deciding whether two names clash
  (K203)?** *Decided 2026-11-15:* only those shown together. Not chosen:
  the whole garage, archived included.
