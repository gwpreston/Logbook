# Phase 31 — Import from Fuelio + v2.15 release

*Bring years of fill-ups, services and costs across from Fuelio in one
go.*

Status: 📋 planned · releases **v2.15.0** · file lives in `docs/phases/`

Fuelio is one of the most used fuel and car-cost apps, and many people
arriving at Logbook keep their history there. Fuelio exports either a
**CSV file** or a **ZIP file**. Neither is the one-table-per-file CSV that
Logbook's importer (§7.13) reads. A Fuelio CSV holds several **sections**
for one vehicle (the vehicle, fill-ups, costs, cost categories, favourite
stations, and more) marked by `##` lines. The ZIP bundles more than one
file.

This phase adds a **Fuelio importer**: upload either kind of export, and
Logbook recognises it and reads every section. It asks only what it can't
work out: which Logbook vehicle the data belongs to, the units if the file
doesn't say, and how Fuelio's cost categories map to Logbook's. It previews
every row with the same outcomes as the CSV importer, and imports in one
transaction. Re-importing a newer export later adds only what is new.

It is built as a general **app importer**, so Drivvo, Tesla and ABRP
(ROADMAP *After 1.0*) can be added later as further readers.

Read [`CLAUDE.md`](../../CLAUDE.md), [`spec.md`](../../spec.md) §7.13 (CSV
import), §7.3, §7.4, §7.12, §7.33 (stations, Phase 30.1) and
`docs/import.md` first.

---

## The export comes first

Fuelio's format is not formally documented, and it changes between app
versions. **Before any parser is written**, this phase starts from real
exports: the owner's sample CSV and ZIP, anonymised with the tool below
and committed as fixtures. The section and column names in this file are
**expected** from Fuelio's known format and are marked *to confirm*. The
first task replaces them with what the sample actually contains, and
updates this file and `spec.md` before code begins (CLAUDE.md §12).

---

## Goals

1. **Detection** of a Fuelio CSV, and of a Fuelio ZIP (one CSV per vehicle,
   plus whatever else it holds, to confirm from the sample), with a clear
   message for anything else.
2. **Section readers** that turn each section into the CSV importer's row
   commands: fill-ups, maintenance records, expenses, stations and, as an
   option, service schedules.
3. **A mapping page:** for each vehicle in the export, an existing vehicle
   or a new one from the file's details; units and currency, with an
   **economy sanity check**; the date and number format; cost categories;
   fuel types.
4. **Preview and import** with the CSV importer's row outcomes per
   section, in one transaction across every vehicle in the export.
5. **Source ids** remembered, so a later export adds only new rows, even
   after imported entries are edited.
6. **ZIP safety:** strict limits on size, entries and paths.

## Not in scope

- Exporting to Fuelio.
- Fuelio's GPS trip log. It has coordinates but no places or purpose, and a
  trip (Phase 22) needs both.
- Fuel types Logbook doesn't model (LPG, CNG, hydrogen, a bi-fuel car's
  second tank). Those rows are listed as not imported, with the reason.
- Income entries, which Logbook has no place for.
- Live sync with Fuelio, or reading Fuelio's cloud backups (Google Drive,
  Dropbox) directly. The user downloads the export and uploads it.
- Drivvo, Tesla and ABRP (later readers on the same importer).

---

## Spec addition (§7.13, *Importing from another app*)

> - **Where:** Settings → *Import from another app*
>   (`/settings/import-app`), and a link on each vehicle's import pages
>   ("Coming from Fuelio?"). It needs `Manage` on each target vehicle (Phase
>   19), and the `fuel` module on (maintenance and expense sections need
>   their modules).
> - **Step 1, upload** (one file; staged as CSV imports are):
>   - **A Fuelio CSV** (`.csv`, up to `MAX_UPLOAD_MB`, up to **20,000 rows**
>     across its sections). Encoding and delimiter are detected as §7.13
>     does.
>   - **A Fuelio ZIP** (`.zip`, up to `MAX_UPLOAD_MB`). It is read **in
>     memory or a private temporary directory, never at paths taken from
>     entry names**. At most **50 entries** and **10 ×** the upload limit
>     uncompressed, with no nested archives; anything over the limits is
>     refused before reading. Every `.csv` entry in Fuelio's format is a
>     vehicle to import. Other entries are listed and ignored, unless the
>     sample shows they are receipt or cost images referenced by rows (see
>     *Attachments* below).
>   - **Detection** reads the `##` section markers and the header rows
>     under them, and recognises Fuelio by its section set (*to confirm*:
>     `## Vehicle`, `## Log`, `## CostCategories`, `## Costs`,
>     `## FavStations`, and others the sample has). Anything else gets:
>     "This doesn't look like a Fuelio export. For other CSV files, use the
>     import on each tab."
> - **Step 2, map** (a GET form, bookmarkable, working without JS):
>   - **Vehicles:** one row per vehicle in the export, each mapped to an
>     existing active vehicle the user can manage, **Create a new vehicle**
>     (prefilled from the file's vehicle section: name, make, model, year,
>     registration, VIN and fuel type where present), or *Skip*.
>   - **Units:** distance, volume and consumption, from the file's vehicle
>     section where it states them (*to confirm*), else from the target
>     vehicle and owner; plus currency.
>     - A **sanity line** per vehicle, from the first full-to-full segments
>       in the file: "With miles and UK gallons, these fill-ups average 46.3
>       mpg (6.1 L/100 km). Change the units if that looks wrong."
>     - A result outside 1–40 L/100 km (or 5–40 kWh/100 km) is highlighted.
>     - Where the export carries Fuelio's own consumption per fill-up, the
>       line also says whether Logbook's figure matches it.
>   - **Number and date format:** detected from the values, shown with three
>     example rows as read, and changeable.
>   - **Cost categories:** each Fuelio cost category maps to a Logbook
>     **maintenance category** (rows go to Maintenance), an **expense
>     category** (rows go to Expenses), or *Don't import*. Fuelio's
>     built-in categories have fixed defaults (service and repairs → their
>     maintenance categories; insurance, parking, tolls, fines and car wash
>     → expense categories, *to confirm* against the sample's category
>     list). User-made categories match by name, else default to
>     maintenance `other`, highlighted.
>   - **Fuel types:** each fuel type in the file maps to a Logbook family
>     and, optionally, a grade. Petrol, diesel and electricity map by
>     default; LPG and CNG map to *Not supported*.
>   - **Option:** *Import recurring costs as service schedules* (off by
>     default). Fuelio costs with a repeat distance or interval become
>     maintenance schedules on the mapped category, with *last done* from
>     the most recent matching record.
> - **Step 3, preview:** one table per section per vehicle, each row with
>   its outcome as in §7.13: *import*; *invalid*, with reasons; *duplicate*;
>   *already imported* (below); and *not imported*, with a reason, for rows
>   this phase deliberately skips (income, unsupported fuel, cost templates,
>   GPS trips). Section totals come first ("Fill-ups: 412 to import, 3
>   invalid"). New stations and new vehicles are listed.
> - **Step 4, import:** **one transaction for the whole export**, every
>   vehicle included. Rows are written by the same services as the forms,
>   so fill-ups write their readings, schedules and reminders sync, and
>   `created_by` is the importing user. The result page gives totals per
>   vehicle and section, every row not imported with its file, line and
>   reason, and the economy check's count of unusual imported fill-ups
>   linking to `?check=1`.
> - **Mapping rules** (columns *to confirm* from the sample):
>   - **Fill-ups:** date and time (local, in the owner's time zone),
>     odometer, volume, total and/or price per unit (any two derive the
>     third, as the form does), full or partial, missed previous, notes, and
>     the **station** (Phase 30.1). The station is matched by Fuelio's
>     station id to an imported favourite station, else by normalised name,
>     else by position within 150 m, else created from the name or city. A
>     fill-up's own coordinates are used only for that match and are
>     **never stored**. Second-tank rows follow the fuel type mapping.
>   - **Costs:** date, odometer (a reading is written as the maintenance
>     form does), title, category as mapped, cost, notes.
>   - **Favourite stations:** each becomes a station record with its name,
>     brand and position (a public place), matched to an existing station
>     first. Logbook favourites are set for the importing user.
>   - **Attachments** (only if the ZIP holds images referenced by rows): each
>     is validated as any upload (§7.12: type and size) and attached to the
>     entry it belongs to, with EXIF stripped (Phase 26.4's rule for
>     photos). Unreferenced images are listed and ignored.
>   - Amounts are never converted between currencies. A row in another
>     currency is invalid, as in §7.13.
> - **Source ids** (`import_sources`): each imported row records the app
>   (`fuelio`), the export's vehicle key (*to confirm*: Fuelio's vehicle id,
>   else its name and VIN), the row's own id where the export has one (*to
>   confirm*), else a hash of its date, odometer and amount, and the Logbook
>   entity it became. On a later import, a row with a known origin is
>   *already imported*, even if the Logbook entry has been edited since.
> - **CLI:** `php bin/import-app.php <file.csv|file.zip> [--vehicle <id> |
>   --create] [--dry-run]`, for very large exports. It uses the detected or
>   default mappings, and `--dry-run` prints the preview.

---

## Decisions (and why)

- **The real export decides the parser.** The format is undocumented. A
  parser written from guesses would pass its own tests and fail on real
  files, so the owner's sample is the specification.
- **ZIP and CSV through one path.** A ZIP is unpacked into the same list of
  CSV sections a CSV gives, so detection, mapping and preview don't care
  which was uploaded.
- **Strict ZIP limits.** Archives are a classic way to exhaust disk or write
  outside a folder. Entry count, total size and path rules are checked
  before anything is read.
- **One transaction across every vehicle.** A half-imported garage is worse
  than none. Either the whole export lands or nothing does.
- **The sanity line on units.** Miles read as kilometres, or gallons as
  litres, is the commonest silent import mistake. Showing the economy it
  gives (and Fuelio's own, where present) makes it obvious before anything
  is written.
- **Source ids, not just duplicate keys.** Duplicate keys break once an
  imported entry is edited. A remembered origin makes "import the latest
  export" safe for people moving across gradually.
- **No fill-up coordinates.** Logbook doesn't store where each fill-up
  happened, since a year of them is a map of someone's life. They only pick
  the station, whose position is a public place.
- **A general importer, one reader now.** Drivvo, Tesla and ABRP can follow
  as readers without changing the mapping, preview or import steps.

---

## Tasks

### Fixtures first
- [ ] Take the owner's sample Fuelio CSV and ZIP exports.
- [ ] `bin/tools/anonymise-import.php`: replaces registrations, VINs,
      station names, notes and coordinates, and shifts dates by a constant,
      keeping structure and numbers. Only anonymised files are committed,
      to `tests/Fixtures/import/fuelio/`.
- [ ] **Confirm the format** from the sample: the ZIP's contents; each
      section and its columns; units in the vehicle section; date, number
      and boolean formats; row ids; vehicle ids; the built-in category list;
      whether images are included and how rows refer to them. Update every
      *to confirm* in this file and in `spec.md`, then remove the marks.
- [ ] Record each fixture's expected result (rows per section, totals, and
      a few exact rows) beside it.
- [ ] Where possible, add further anonymised exports: an EV in kWh, a
      diesel in km and litres, a bi-fuel car, and an older app version.

### Spec and docs
- [ ] §7.13's new part in `spec.md`; the Phase 31 line in §13.
- [ ] `docs/import.md`: *From Fuelio*: exporting a CSV or ZIP from the app
      (with a note that menus change between versions), what is imported
      and what isn't, and re-importing a newer export. It also says Logbook
      isn't affiliated with Fuelio.

### Migration
- [ ] `import_sources` (app, source vehicle key, source id or hash, entity
      type, entity id, imported_at; unique on app + vehicle key + source
      id). Reversible on every engine; included in backups.

### Code
- [ ] `Service\Import\App\ArchiveReader` (ZIP limits, safe in-memory or
      private-temp reading, entry list).
- [ ] `Service\Import\App\SectionSplitter` (the `##` sections and their
      header rows).
- [ ] `Service\Import\App\Fuelio\Detector` and one reader per confirmed
      section, producing the CSV importer's row commands.
- [ ] `Service\Import\App\UnitSanity` (economy from the first segments under
      the chosen units, compared with Fuelio's own where present).
- [ ] `Service\Import\App\CategoryMapper`, `FuelMapper`, `StationMatcher`
      (id, name, 150 m), `ScheduleBuilder`, and `AttachmentLinker` if images
      are confirmed.
- [ ] `Service\Import\App\SourceRegistry` (already-imported detection).
- [ ] Actions and templates for the four steps; the CLI.
- [ ] Translations (en, de).

### Tests
- [ ] Every fixture, as CSV and as ZIP, is detected and split, and gives the
      expected row counts and exact rows.
- [ ] Units prefilled from the vehicle section; the sanity line is correct,
      matches Fuelio's own consumption where present, and is highlighted
      when miles are read as kilometres.
- [ ] Decimal comma and day-first dates read correctly; month-first only
      when chosen.
- [ ] Categories: built-in defaults; user categories by name; maintenance
      against expense routing; *Don't import*.
- [ ] Fuel types: LPG and CNG rows shown as not imported; electric in kWh.
- [ ] Partial, full and missed flags give the segments and economy that
      the fixture's recorded figures say.
- [ ] Stations: matched by Fuelio id, name or 150 m; favourites created and
      set; fill-up coordinates never stored.
- [ ] **ZIP safety:** a path with `../`, an absolute path, an entry over the
      limit, more than 50 entries, a nested ZIP, and a compression bomb are
      all refused before reading; nothing is written outside the temporary
      directory, and it is removed afterwards.
- [ ] Multi-vehicle ZIP: each vehicle mapped separately; one transaction;
      a failure in the second vehicle writes nothing for the first.
- [ ] Re-import: the same export gives all *already imported*; a newer
      export gives only new rows; an edited imported entry is still
      *already imported*.
- [ ] Option: recurring costs become schedules with *last done*.
- [ ] Attachments (if confirmed): attached to the right entry, validated,
      EXIF stripped.
- [ ] Access: no `Manage` on a target is refused; a disabled module's
      sections are not offered.
- [ ] CLI `--dry-run` writes nothing.
- [ ] Integration suite green on every engine.

### Release
- [ ] `CHANGELOG.md` **2.15.0**: import from Fuelio (CSV or ZIP). Upgrade
      notes: one migration; no configuration.
- [ ] Bump `VERSION`, rebuild assets, update the README status and the
      documentation table entry for `docs/import.md`.

---

## Acceptance criteria

1. The owner's own Fuelio CSV and ZIP exports import completely: every
   fill-up, cost and favourite station, with the economy per tank matching
   Fuelio's.
2. Services land in Maintenance and running costs such as parking in
   Expenses, by the category mapping.
3. A ZIP with several vehicles imports into the chosen Logbook vehicles,
   new or existing, in one go.
4. Importing a newer export a month later adds only that month.
5. A malformed or malicious ZIP is refused with a clear message, and
   nothing is written.
6. Definition of done (CLAUDE.md §11) holds.

## Open questions

- **The sample:** please upload an export of each kind. Even a short one
  helps, as long as it has a few fill-ups, a partial fill, some costs in
  different categories and a favourite station. It is anonymised before
  anything is committed.
- **LPG and CNG:** skip them (drafted), or add the fuel families to Logbook
  first so bi-fuel owners can bring everything across?
- **Fuelio GPS trips:** skip (drafted), or import them as private trips
  with "Fuelio trip" as the place names, so the distances are at least
  there?
