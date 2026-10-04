# Phase 31 — Import from Fuelio + v2.15 release

*Bring years of fill-ups, services and costs across from Fuelio in one
go.*

Status: ✅ complete · released as **v2.15.0** · file lives in `docs/phases/`

Fuelio is one of the most used fuel and car-cost apps, and many people
arriving at Logbook keep their history there. Fuelio exports a **CSV
file** per vehicle or a **backup ZIP** of every vehicle with its photos.
Neither is the one-table-per-file CSV that Logbook's importer (§7.13)
reads: a Fuelio CSV holds several **sections** (the vehicle, fill-ups,
cost categories, costs, favourite stations, photos) marked by `##` lines.

This phase adds a **Fuelio importer**. The web page takes a Fuelio CSV,
recognises it and reads every section. It asks only what it can't work
out: which Logbook vehicle the data belongs to, the units if the file
doesn't say, and how Fuelio's cost categories and fuel types map to
Logbook's. It previews every row with the same outcomes as the CSV
importer, and imports in one transaction. Backup ZIPs, which carry the
photos and run to hundreds of megabytes, import through
`bin/import-app.php`. Re-importing a newer export later adds only what is
new.

It also adds **CNG** as a fuel family, so bi-fuel owners bring everything
across (LPG is already a Logbook family).

It is built as a general **app importer**, so Drivvo, Tesla and ABRP can
be added later as further readers.

Read [`CLAUDE.md`](../../CLAUDE.md), [`spec.md`](../../spec.md) §6
(Vehicle, FuelEntry, ImportSource), §7.3, §7.4, §7.12, §7.13 (*Importing
from another app*), §7.33 (stations) and `docs/import.md` first.

---

## The export (confirmed 2026-10-04)

From the owner's own exports (a CSV and a backup ZIP of one petrol car in
miles and litres, March–September 2026: 18 fill-ups, one of them partial,
one cost, nine cost categories, four favourite stations, 45 photos):

- **CSV export** (`<Vehicle name>-<n>-<date>_<time>.csv`): UTF-8,
  comma-separated, values quoted except empty ones. Sections, each a
  `"## Name"` line, a header row and rows:

  | Section | Columns |
  |---|---|
  | `Vehicle` | Name, Description, DistUnit, FuelUnit, ConsumptionUnit, ImportCSVDateFormat, VIN, Insurance, Plate, Make, Model, Year, TankCount, Tank1Type, Tank2Type, Active, Tank1Capacity, Tank2Capacity, FuelUnitTank2, FuelConsumptionTank2, guid, lastupdated |
  | `Log` | Data, Odo (mi), Fuel (litres), Full, Price (optional), mpg (optional), latitude (optional), longitude (optional), City (optional), Notes (optional), Missed, TankNumber, FuelType, VolumePrice, StationID (optional), ExcludeDistance, UniqueId, TankCalc, Weather, guid, lastupdated |
  | `CostCategories` | CostTypeID, Name, priority, color, guid, lastupdated |
  | `Costs` | CostTitle, Date, Odo, CostTypeID, Notes, Cost, flag, idR, read, RemindOdo, RemindDate, isTemplate, RepeatOdo, RepeatMonths, isIncome, UniqueId, guid, lastupdated |
  | `FavStations` | NameBrand, Latitude, Longitude, StationID, Description, CountryCode, guid, lastupdated |
  | `Pictures` | Filename, Note, Type, target_id, guid, lastupdated |
  | `Category` | IdCategory, Name, guid, lastupdated (trip categories: Private, Work) |

- **Backup ZIP** (`backup-<date>_<time>.fuelio.zip`): `vehicle-1-local.csv`
  (the same format, one per vehicle) and `pictures.data`, which is itself
  a ZIP of the JPEGs the `Pictures` section names (45 files, 213 MB).
- **Units:** the `Log` header names them (`Odo (mi)`, `Fuel (litres)`,
  `mpg (optional)`); the vehicle row's codes agree (DistUnit `1` = miles,
  FuelUnit `0` = litres, ConsumptionUnit `2` = mpg UK).
- **Dates:** local `yyyy-MM-dd HH:mm`; `ImportCSVDateFormat` names the
  date part. **Numbers:** decimal point. **Yes/no:** `1`/`0`.
- **Ids:** every row's `guid` is identical between the two exports, taken
  hours apart. The **vehicle's `guid` is not** (it changes on every
  export), so rows are keyed by their own `guid`. Fill-ups and costs also
  have a per-vehicle `UniqueId`, which photos point at.
- **Money:** `Price` is the fill-up's total, `VolumePrice` the price per
  litre. No currency anywhere in the file.
- **Fuelio's consumption** is on the fill-up that *starts* a full-to-full
  segment (the latest fill-up and partials have none); a partial joins the
  segment, as in Logbook. Every figure in the sample matches Logbook's
  segment rule, to Fuelio's rounding (all 15 segments checked).
- **Fuel codes:** Tank1Type `100`, FuelType `110` (petrol). Other families
  aren't in the sample, so every code maps on the mapping page.
- **Stations:** `StationID` matches the favourite station's;
  `City` is "Name - Place".
- **Photos:** `Type` 1, `target_id` = the fill-up's `UniqueId`, 2–4 per
  fill-up, 3–6 MB each.
- **No GPS trips** in the export (only the trip category list).

---

## Goals

1. **CNG** as a fuel family: kg, kg/100 km or mi/kg, its own series.
2. **Detection** of a Fuelio CSV and a Fuelio backup ZIP, with a clear
   message for anything else.
3. **Section readers** that turn each section into the CSV importer's row
   commands: fill-ups, maintenance records, expenses, stations, photos
   (command line) and, as an option, service schedules.
4. **A mapping page:** an existing vehicle or a new one from the file's
   details; units with an **economy sanity check** against Fuelio's own;
   the date format; cost categories; fuel types.
5. **Preview and import** with the CSV importer's row outcomes per
   section, in one transaction.
6. **Source ids** remembered, so a later export adds only new rows, even
   after imported entries are edited.
7. **The command line** for backup ZIPs, with strict archive limits.

## Not in scope

- Exporting to Fuelio.
- Fuelio's GPS trip log: decided as private trips, but the sample has no
  trips to build and test against (spec §12, #147).
- Hydrogen and other fuels Logbook doesn't model: a code mapped to *Don't
  import* is listed as not imported.
- Income entries, which Logbook has no place for.
- ZIP uploads on the web page (#148), and live sync with Fuelio or its
  cloud backups.
- Drivvo, Tesla and ABRP (later readers on the same importer).

---

## Spec

The full text is in `spec.md`: §6 Vehicle and FuelEntry (`cng`),
ImportSource; §7.3 *CNG*; §7.13 *Importing from another app*; §12; §13.

---

## Decisions (and why)

- **The real export decided the parser.** The format is undocumented. A
  parser written from guesses would pass its own tests and fail on real
  files, so the owner's sample is the specification.
- **Rows keyed by their own guid.** The vehicle's guid changes on every
  export; the rows' don't. Keying by the row alone survives renames and
  re-exports.
- **The web takes CSVs; the command line takes ZIPs.** A backup with
  photos is hundreds of megabytes, far past `MAX_UPLOAD_MB` and most PHP
  upload limits. The CSV carries everything but the photos and is small.
- **Strict ZIP limits, with one known nested archive.** Archives are a
  classic way to exhaust disk or write outside a folder. Entry count,
  sizes, ratios and paths are checked before anything is read, and only
  `pictures.data` may be an archive inside the archive.
- **One transaction for the whole file.** A half-imported garage is worse
  than none.
- **The sanity line on units.** Miles read as kilometres, or gallons as
  litres, is the commonest silent import mistake. Comparing with Fuelio's
  own figure makes it obvious before anything is written.
- **Source ids, not just duplicate keys.** Duplicate keys break once an
  imported entry is edited. A remembered origin makes "import the latest
  export" safe for people moving across gradually.
- **No fill-up coordinates.** A year of them is a map of someone's life.
  They only pick the station, whose position is a public place.
- **CNG in kg, as its own series.** It's sold by mass; mixing it with a
  bi-fuel car's petrol would make both figures meaningless.

---

## Tasks

### Fixtures first
- [x] Take the owner's sample Fuelio CSV and backup ZIP (2026-10-04).
- [x] **Confirm the format** from the sample (above; spec §7.13).
- [x] `bin/tools/anonymise-import.php`: replaces the name, registration,
      VIN, station names, places, notes, coordinates and guids (consistently
      within a file), and shifts dates by a constant, keeping structure and
      numbers. Photos are replaced by tiny generated JPEGs with an EXIF
      block. Only anonymised files are committed, to
      `tests/Fixtures/import/fuelio/`.
- [x] Record each fixture's expected result (rows per section, totals, and
      a few exact rows) beside it.
- [x] Synthetic fixtures from the confirmed format for what the sample
      lacks: kilometres and a decimal comma, a second vehicle, a diesel, a
      bi-fuel petrol and CNG car (tank 2), an EV in kWh, costs with
      income, templates and repeats, and a user-made category. Built in
      code by `tests/Support/FuelioCsv`, so each test shows its rows.

### CNG
- [x] `cng` in the vehicle and fill-up fuel enums; a third energy kind
      (gas, kg) with kg/100 km and mi/kg; its own consumption series and
      Fuel tab figures; no grades; the picker group; the CSV import and
      export, API and Ask accept it.
- [x] Translations (en, de).

### Spec and docs
- [x] §6, §7.3 and §7.13's new parts in `spec.md`; §12; the Phase 31 line
      in §13.
- [x] `docs/import.md`: *From Fuelio*: exporting a CSV or backup from the
      app (menus change between versions), what is imported and what
      isn't, the command line for backups with photos, and re-importing a
      newer export. It says Logbook isn't affiliated with Fuelio.

### Migration
- [x] `import_sources` (spec §6). Reversible on every engine; in backups
      and `bin/export-user.php`.

### Code
- [x] `Service\Import\App\ArchiveReader` (ZIP limits, private temporary
      directory, the one nested `pictures.data`).
- [x] `Service\Import\App\SectionSplitter` (the `##` sections and their
      header rows).
- [x] `Service\Import\App\Fuelio\FuelioReader` (detection, and a typed
      row per section) and `FuelioImporter`, whose rows go through the
      forms' own parsers.
- [x] `Service\Import\App\UnitSanity` (economy under the chosen units,
      paired with Fuelio's figure on the segment's first fill-up).
- [x] Category and fuel mapping, station matching (id, name, 150 m),
      schedules and photos: inside `FuelioImporter`, with the choices in
      `AppImportOptions`.
- [x] `Repository\ImportSourceRepository` (already-imported detection).
- [x] Actions (`Action\ImportApp\*`) and templates for upload, map,
      preview and import; the CLI (`bin/import-app.php`, through
      `FuelioCommand`).
- [x] Translations (en, de).

### Tests
- [x] The owner's fixture, as CSV and as ZIP, is detected and split, and
      gives the expected row counts and exact rows.
- [x] Units prefilled from the header; the sanity line matches Fuelio's
      own figures, and is highlighted when miles are read as kilometres.
- [x] Decimal comma and day-first dates read correctly.
- [x] Categories: built-in defaults; user categories by name; maintenance
      against expense routing; *Don't import*.
- [x] Fuel codes: CNG in kg, electricity in kWh, tank 2 rows, *Don't
      import*.
- [x] Partial, full and missed flags give the segments and economy the
      fixture records.
- [x] Stations: matched by Fuelio id, name or 150 m; favourites created and
      set; fill-up coordinates never stored.
- [x] **ZIP safety:** `../`, an absolute path, an entry over the limit,
      more than 50 entries, a nested archive other than `pictures.data`, a
      second level of nesting, and a compression bomb are all refused
      before reading; nothing is written outside the temporary directory,
      and it is removed afterwards.
- [x] Multi-vehicle ZIP: one transaction; a failure in the second vehicle
      writes nothing for the first.
- [x] Photos attached to the right fill-up, validated, EXIF stripped.
- [x] Re-import: the same export gives all *already imported*; a newer
      export gives only new rows; an edited imported entry is still
      *already imported*.
- [x] Option: repeating costs become schedules with *last done*.
- [x] Access: no `Manage` on the target is refused; a disabled module's
      rows are not offered.
- [x] Web: a ZIP is refused with the command-line hint.
- [x] CLI `--dry-run` writes nothing.
- [x] CNG: economy in kg/100 km and mi/kg, a separate series from petrol
      on a bi-fuel car.
- [x] Integration suite green on every engine.

### Release
- [x] `CHANGELOG.md` **2.15.0**: import from Fuelio; CNG. Upgrade notes:
      one migration; no configuration.
- [x] Bump `VERSION`, rebuild assets, update the README status and the
      documentation table entry for `docs/import.md`.
- [x] Tag `v2.15.0` once merged.

---

## Acceptance criteria

1. The owner's own Fuelio CSV imports completely on the web page, and the
   backup ZIP on the command line with its photos: every fill-up, cost and
   favourite station, with the economy per tank matching Fuelio's.
2. Services land in Maintenance and running costs such as parking in
   Expenses, by the category mapping.
3. A ZIP with several vehicles imports into the chosen Logbook vehicles,
   new or existing, in one go.
4. Importing a newer export a month later adds only that month.
5. A malformed or malicious ZIP is refused with a clear message, and
   nothing is written.
6. A CNG fill-up is logged in kg and its economy kept apart from petrol.
7. Definition of done (CLAUDE.md §11) holds.

## Open questions

Answered on 2026-10-04, before the phase was built (the full text is in
`spec.md` §6, §7.3, §7.13 and §12):

- **#145 The sample.** *Decided 2026-10-04:* the owner supplied a CSV
  export and a backup ZIP; the format above is confirmed from them.
- **#146 LPG and CNG.** *Decided 2026-10-04:* bring them across. LPG is
  already a Logbook family; CNG is added in this phase, measured in kg
  with its own consumption series.
- **#147 Fuelio GPS trips.** *Decided 2026-10-04:* import them as private
  trips with "Fuelio trip" as the places. *Parked:* the sample has no
  trips, so it waits for an export that does (spec §12).

Found while starting this phase:

- **#148 Backup size.** *Decided 2026-10-04:* the owner's backup is
  213 MB (its photos), against `MAX_UPLOAD_MB` of 10. The web page takes
  the CSV only; backup ZIPs import with `bin/import-app.php`.
- **#149 The nested `pictures.data`.** *Decided 2026-10-04:* allowed as
  the one nested archive, by that exact name, one level deep, under the
  same limits. Any other nested archive is refused.
