# Phase 53 — Import from ABRP + release

*Your EV's charging sessions, mileage and drives from A Better
Routeplanner, without typing them in.*

Status: 📋 planned · file lives in `docs/phases/`

ABRP (A Better Routeplanner) records an EV's drives and charging
sessions through *My Drives*, when the car has a live data connection.
That record is exactly what an EV owner would otherwise log by hand in
Logbook: energy added per charge, where it was added, and the odometer.

ABRP's only way out for that data is ***My Drives → Export***. It gives
an Excel (`.xlsx`) file, either for a chosen interval of days or for a
single drive or charge. It needs ABRP Premium (or a trial), a live data
connection, and *Save My Activity* switched on for the vehicle. There is
no API for reading drive history. ABRP's own help describes the export,
but not the file's columns.

So this phase begins by **getting real exports**, as Phase 31 did for
Fuelio. No column is guessed. The reader then joins Phase 31's app
importer, as Tracktor's does in [Phase 52](phase-52.md). `spec.md` §12
lists ABRP among the later readers; this phase takes it off that list.

Logbook isn't affiliated with ABRP or Iternio.

Read [`CLAUDE.md`](../../CLAUDE.md) and [`spec.md`](../../spec.md) §7.13
(*Importing from another app*), §7.3 (EV fill-ups, charging types, full
and partial), §7.2 (readings and plausibility), §7.22 (trips), §7.33
(stations) and §12 first.

**Prerequisites:** [Phase 45.3](phase-45.3.md) complete: hardening passed (no feature phase starts before it).
Independent of Phases 46 to 52.

---

## What is known, and what the fixtures must settle

Known from ABRP's help:

- My Drives exports to Excel, for an interval of days or for one
  activity.
- An activity is a drive or a charging session.
- Recording needs Premium and live data.

To be read from real exports in 51.1 before anything else is written:

- the sheets and columns of an interval export, and of a single-activity
  export (which may be a time series rather than a summary);
- whether a charge carries the energy added, start and end state of
  charge, power, cost and a place;
- whether a drive carries distance, energy used, start and end place,
  and the **odometer**;
- the time zone of the timestamps;
- whether rows have a stable id;
- the units in each case (km or miles, kWh, Wh/km).

The proposals below assume a summary per activity. If the exports don't
give one, 51.0 rewrites this file before any code is written.

---

## Goals

1. Settings → *Import from another app* accepts an ABRP export. So does
   `bin/import-app.php`.
2. **Charging sessions** become EV fill-ups: energy, charging type, cost
   (open question C), full or partial (D), and the station (E).
3. **Odometer readings** come from the activities that carry one,
   thinned so the mileage log isn't flooded (B).
4. **Drives** come across as private trips only if the owner chooses
   (F).
5. A preview and one transaction. Re-importing an overlapping interval
   adds only new activities (G).
6. Docs (`docs/import-abrp.md`), fixtures and release.

## Not in scope

- Sending anything to ABRP. Logbook's live data stays local.
- Routes, GPS tracks and time series, beyond the summary each activity
  needs.
- Plug-in hybrids' petrol fills, which ABRP doesn't record.
- Tesla's and Drivvo's readers, which stay in §12.

---

## Spec (proposed)

Settled in §7.13 in 51.0, from the fixtures:

- **Reading `.xlsx`** needs a new dependency (open question A). Before
  any cell is read, it gets Phase 31's ZIP safety limits (entries, sizes,
  ratio, paths), XML read without external entities, and a row limit.
- **Vehicle:** the export's vehicle is mapped to an existing `ev` (or
  `phev`) vehicle, or a new `ev` vehicle.
- **Charge → fill-up:**
  - **Energy:** the energy added in kWh (open question C covers grid
    energy against battery energy).
  - **Charging type:** chosen from the session's peak power (AC up to
    22 kW; DC above, split into rapid and ultra-rapid at §7.3's
    thresholds). *Home* applies when the place matches a private place
    named *Home* (§7.33).
  - **Cost:** the export's cost if it has one, else C's rule.
  - **Full or partial:** D.
  - **Time:** the session's end, in the owner's time zone.
  - **Odometer:** the session's, when given.
- **Readings:** source `import`, at most one per day per vehicle (the
  day's last). Every reading goes through §7.2's plausibility check, and
  the preview shows the ones it flags.
- **ABRP's own consumption** (Wh/km) is used only for Phase 31's sanity
  check against Logbook's figure. It is never stored.
- **Re-import:** `import_sources` with app `abrp`. The source id is the
  export's own id when it has one, else the vehicle, kind and start time
  to the second (G).

---

## Tasks

### 51.0 Spec first
- [ ] Fixtures read (51.1). This file and §7.13 are corrected to what the
      exports contain.
- [ ] Open questions A–G decided by the owner and logged; §4 for the
      dependency, §12 (ABRP removed), §13; `ROADMAP.md` row.

### 51.1 Fixtures
- [ ] Real exports, anonymised (names, places and coordinates replaced,
      VIN removed), into `tests/Fixtures/import/abrp/`:
  - an interval export with drives and charges;
  - a single drive;
  - a single charge;
  - one in miles and one in km, if the settings change the file.

### 51.2 Reader
- [ ] The `.xlsx` reader behind the safety limits.
- [ ] `Import\App\Abrp\AbrpReader`: sheets to the shared import rows;
      sheets and columns it doesn't know are listed as not read.
- [ ] Mapping page entries: vehicle, units, cost rule (C), full
      threshold (D), stations (E), trips (F).

### 51.3 Pages, docs and release
- [ ] *ABRP* on Settings → *Import from another app*, with a note that
      My Drives needs Premium and live data.
- [ ] `docs/import-abrp.md`: exporting from ABRP, what comes across.
- [ ] Translations (every shipped locale).
- [ ] Tests:
  - each fixture end to end on every engine;
  - overlapping intervals imported twice add nothing;
  - charging types at the power boundaries;
  - readings thinned per day in the owner's time zone;
  - kWh/100 km matching ABRP's within the sanity range;
  - a malformed or oversized `.xlsx` refused with nothing written.
- [ ] `VERSION`, `CHANGELOG.md`, README; `ROADMAP.md` row ✅; tag once
      merged.

---

## Acceptance criteria

1. An EV owner using ABRP can import a period of charging and mileage.
   They see each charge as a fill-up and each day's mileage as a reading,
   checked in the preview first.
2. Importing an overlapping period later adds nothing twice.
3. Nothing in the import is guessed from a column the fixtures didn't
   show.
4. Definition of done (CLAUDE.md §11) holds.

## Open questions

Numbered in `open-questions.md` when logged. Not built until decided.

- **A. Which `.xlsx` library?**
  - (1) `openspout/openspout` (MIT, streaming, small).
  - (2) `phpoffice/phpspreadsheet` (MIT, large).
  - (3) A minimal reader of our own over `ZipArchive` and XMLReader.

  *Recommendation:* (1). It reads row by row within the memory limits of
  a Raspberry Pi.

- **B. How many readings?**
  - (1) One per day per vehicle (the day's last).
  - (2) One per activity.
  - (3) Only those on charging sessions.

  *Recommendation:* (1). Daily is plenty for every figure Logbook
  derives, and a busy month stays readable on the Mileage tab.

- **C. Charging cost and energy.** ABRP may not know what a charge cost,
  and it may report energy into the battery rather than from the grid.
  - (1) A price per kWh per charging type, typed on the mapping page
    (for example a home tariff), used only when a row has no cost.
    Energy as the export gives it, labelled.
  - (2) Cost left at 0 (free), with a note on each.
  - (3) Rows without a cost left out.

  *Recommendation:* (1). (2) would make a car look free to run.

- **D. Full or partial?** Logbook's efficiency needs full-to-full
  segments.
  - (1) Full when the end state of charge reaches a threshold set on the
    mapping page (default 100%, often 80% for daily charging).
  - (2) Every charge partial, so no per-segment efficiency.
  - (3) Every charge full.

  *Recommendation:* (1).

- **E. Stations.**
  - (1) Link a charge to an existing station within 100 m.
  - (2) Also create stations for unmatched public chargers.
  - (3) None.

  *Recommendation:* (1). The preview lists the unmatched places, so the
  owner can add the ones they use.

- **F. Drives as trips?**
  - (1) Not imported. They give readings only.
  - (2) Imported as private trips, while the `trips` module is on and the
    owner ticks it, with places as the export names them.

  *Recommendation:* (1), with (2) parked. Places are personal data
  (§7.22 keeps destinations with their driver), and private mileage
  comes from the mileage log anyway.

- **G. Re-import key** when rows have no id.
  - (1) Vehicle, kind and start time to the second.
  - (2) Also the energy or distance.

  *Recommendation:* (1). (2) would duplicate a session ABRP later
  corrects.
