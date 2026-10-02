# Phase 30 — Import from Fuelio and Drivvo + v2.13 release

*Bring years of fill-ups, services and costs across from another app in
one go.*

Status: 📋 planned · releases **v2.13.0** · file lives in `docs/phases/`

Most people who arrive at Logbook already keep a log somewhere else, and
Fuelio and Drivvo are two of the most used fuel and car-cost apps. Both
export CSV, but not the one-table-per-file CSV that Logbook's importer
(§7.13) reads. Their exports hold several **sections** in one file
(vehicle, fill-ups, costs, categories and more). Drivvo's headers and values
are also **translated** into the phone's language.

This phase adds an **app importer**: upload a Fuelio or Drivvo export, and
Logbook recognises it, reads every section, and asks only what it can't
work out. That means which Logbook vehicle the data belongs to, the units
if the file doesn't say, and how the app's cost categories map to Logbook's.
It then previews every row with the same outcomes as the CSV importer and
imports in one transaction.

Re-importing a newer export later adds only what is new.

Read [`CLAUDE.md`](../../CLAUDE.md), [`spec.md`](../../spec.md) §7.13 (CSV
import), §7.3, §7.4, §7.21 and `docs/import.md` first.

---

## Goals

1. **Format detection** for Fuelio and Drivvo exports, whatever the
   phone's language, with a clear message for anything else.
2. **Section parsers** that turn each section into the same row commands the
   CSV importer uses: fill-ups, maintenance records, expenses and, as
   options, schedules and trips.
3. **A mapping page:** the vehicle (an existing one, or a new one from the
   file's vehicle details), units and currency, the date format, cost
   categories, and fuel types. Each starts from what the file says, with a
   **sanity check** that shows what economy those units imply.
4. **Preview and import** with the CSV importer's row outcomes (*import*,
   *invalid*, *duplicate*) per section, in one transaction.
5. **Source ids** remembered, so importing a later export adds only new
   rows, even after the owner edits imported ones.
6. Header and value tables for the languages Drivvo exports in, with a
   manual column-mapping fallback when a language isn't known.

## Not in scope

- Exporting **to** Fuelio or Drivvo.
- Fuelio's GPS trip log. It has coordinates but no places or purpose, and a
  trip needs both (Phase 22).
- Fuel types Logbook doesn't model (LPG, CNG, hydrogen, bi-fuel second
  tanks). Those rows are listed as not imported, with the reason.
- Income entries, which Logbook has no place for.
- Photos and attachments, which aren't in either app's CSV.
- Live sync with either app.
- Tesla and ABRP imports (ROADMAP *After 1.0*).

---

## Spec addition (§7.13, *Importing from another app*)

> - **Where:** Settings → *Import from another app*
>   (`/settings/import-app`), and a link on each vehicle's import pages
>   ("Coming from Fuelio or Drivvo?"). It needs `Manage` on the target
>   vehicle (Phase 19) and the relevant modules on.
> - **Step 1, upload:** one file at a time (`MAX_UPLOAD_MB`; up to **20,000
>   rows** across its sections), staged as CSV imports are. Encoding and
>   delimiter are detected as §7.13 does.
>   - **Detection** reads the section markers (lines starting with `##`)
>     and the header rows under them, and recognises Fuelio and Drivvo by
>     their section sets and known header names in any supported language.
>   - Anything else gets: "This doesn't look like a Fuelio or Drivvo export.
>     For other CSV files, use the import on each tab." No partial guesses
>     are made.
> - **Step 2, map** (a GET form, bookmarkable, working without JS):
>   - **Vehicles:** one row per vehicle in the file (Fuelio exports one per
>     file; Drivvo may hold several), each mapped to an existing active
>     vehicle the user can manage, or **Create a new vehicle** prefilled
>     from the file (name, make, model, year, registration, VIN, fuel type
>     where present). A file vehicle can also be skipped.
>   - **Units:** distance, volume and currency. They are prefilled from the
>     file's own vehicle settings where the export has them (Fuelio), else
>     from the target vehicle and owner. Each vehicle shows a **sanity
>     line** worked out from the first full-to-full segments in the file:
>     "With kilometres and litres, these fill-ups average 6.1 L/100 km (46.3
>     mpg UK). Change the units if that looks wrong." A result outside 1–40
>     L/100 km (or 5–40 kWh/100 km) is highlighted.
>   - **Number and date format:** detected from the values (decimal comma
>     or point; day-first, month-first or ISO), shown with three example
>     rows as read, and changeable.
>   - **Cost categories:** each category in the file maps to a Logbook
>     **maintenance category** (record goes to Maintenance) or **expense
>     category** (record goes to Expenses), or to *Don't import*. Defaults
>     come from a translated name table ("Oil change", "Cambio de aceite",
>     "Ölwechsel" → maintenance `oil`; "Parking", "Estacionamento" → expense
>     `parking`; "Insurance" → expense `insurance`). Anything unmatched
>     defaults to maintenance `other` and is highlighted.
>   - **Fuel types:** each fuel type in the file maps to a Logbook fuel
>     family and, optionally, a grade. Defaults: petrol and gasoline words →
>     petrol, diesel → diesel, electricity and kWh → electric. LPG, CNG and
>     other unsupported types map to *Not supported*, so their rows show as
>     not imported.
>   - **Options:** *Import recurring costs as service schedules* (off by
>     default; Fuelio costs with a repeat interval, and Drivvo reminders with
>     a distance or time repeat, become maintenance schedules); *Import
>     Drivvo routes as trips* (off by default; needs the `trips` module; each
>     route becomes a trip with its origin, destination and reason, as
>     private unless *Mark as business* is ticked).
> - **Step 3, preview:** one table per section and vehicle, each row with
>   its outcome as in §7.13 (*import*, *invalid* with reasons,
>   *duplicate*), plus *not imported* with a reason for rows this phase
>   deliberately skips (income, unsupported fuel, templates, GPS trips).
>   Section totals are shown at the top ("Fill-ups: 412 to import, 3
>   invalid, 0 duplicates").
> - **Step 4, import:** one transaction for the whole file. Rows are written
>   by the **same services as the forms**, so fill-ups write their readings,
>   schedules and reminders sync, and `created_by` is the importing user. The
>   result page gives section totals, every row not imported with its line
>   and reason, and the economy check's count of unusual imported fill-ups
>   linking to `?check=1`.
> - **Mapping rules:**
>   - *Fill-ups:* date and time (local, in the owner's time zone), odometer,
>     volume, total and/or price per unit (any two derive the third, as the
>     form does), full or partial, missed previous, station (from the
>     station name, or the city when there is no name), notes. Coordinates
>     are **never** imported. Fuelio's second-tank rows follow the fuel type
>     mapping.
>   - *Costs and services:* date, odometer (a reading is written as the
>     maintenance form does), title, category (as mapped), cost, notes;
>     Drivvo service lines with several items become one record with the
>     items listed in the description and the summed cost.
>   - *Recurring costs* (option): repeat distance and/or months become a
>     schedule on the mapped category, with "last done" from the most recent
>     matching record.
>   - Amounts are never converted between currencies. A row in another
>     currency is invalid, as in §7.13.
> - **Source ids:** each imported row records its origin (`import_sources`:
>   app, the file's vehicle key, the app's row id when the export has one,
>   else a hash of the row's date, odometer and amount; and the Logbook
>   entity it became). On a later import, rows with a known origin are
>   *already imported* (not *duplicate*), even if the Logbook entry has been
>   edited since, so a newer export adds only new rows.
> - **Languages:** header and value tables (section names, column headers,
>   yes/no and "full tank" values, category and fuel names) for English,
>   Spanish, Portuguese, Polish, German, Italian and French. With an unknown
>   language, Step 2 adds a **column picker per section**, like the CSV
>   importer's, and the import still works.
> - **CLI:** `php bin/import-app.php <file> --vehicle <id> [--create]
>   [--dry-run]` for very large exports. It uses the detected or default
>   mappings, and prints the preview with `--dry-run`.

---

## Decisions (and why)

- **One importer for sections, on top of the existing row parsers.** The
  CSV importer already knows how to read a fill-up or a service safely.
  This phase only has to split the file, translate headers, and map
  categories, so validation stays in one place.
- **The sanity line on units.** Units are the commonest silent import
  mistake: miles read as kilometres or gallons as litres. Showing the
  resulting economy makes a wrong choice obvious before anything is
  written.
- **Categories map to maintenance or expense.** Both apps keep services and
  other costs in one list. Logbook keeps them apart (schedules, history,
  sale pack), so each category is placed once and every row follows it.
- **Source ids, not just duplicate keys.** Duplicate keys break as soon as
  an imported entry is edited. A remembered origin makes "import the latest
  export" safe for people moving over gradually.
- **No coordinates.** Logbook doesn't store locations of fill-ups, and a
  year of them is a map of someone's life.
- **Real exports as test fixtures.** Both formats are undocumented and
  change between app versions and languages. Tests against anonymised real
  files are the only reliable specification.

---

## Tasks

### Spec and docs
- [ ] §7.13's new part in `spec.md`; the Phase 30 line in §13.
- [ ] `docs/import.md`: *From Fuelio* and *From Drivvo*: how to export from
      each app (with a note that menus change between versions), what is
      imported and what isn't, and re-importing a newer export. It also
      says Logbook isn't affiliated with either app.

### Fixtures first
- [ ] Collect real exports: Fuelio (petrol in miles and UK gallons, diesel
      in km and litres, an EV in kWh, a bi-fuel car) and Drivvo (English,
      Spanish, Portuguese, Polish, German; one with several vehicles; one
      with routes).
- [ ] `bin/tools/anonymise-import.php`: replaces registrations, VINs,
      station names, notes and coordinates, and shifts dates by a constant,
      keeping structure and numbers. Committed fixtures are anonymised only.
- [ ] Record each fixture's expected result (rows per section, totals, a
      few exact rows) beside it.

### Migration
- [ ] `import_sources` (app, source vehicle key, source id or hash, entity
      type, entity id, imported_at; unique on app + vehicle key + source
      id). Reversible on every engine; included in backups.

### Code
- [ ] `Service\Import\App\FormatDetector`, `SectionSplitter`,
      `HeaderDictionary` (per language) and `ValueDictionary` (yes/no, full,
      categories, fuels).
- [ ] `Service\Import\App\Fuelio\*` and `Service\Import\App\Drivvo\*`:
      section readers producing the CSV importer's row commands.
- [ ] `Service\Import\App\UnitSanity` (economy from the first segments
      under the chosen units).
- [ ] `Service\Import\App\CategoryMapper`, `FuelMapper`, `ScheduleBuilder`,
      `RouteToTrip`.
- [ ] `Service\Import\App\SourceRegistry` (already-imported detection).
- [ ] Actions and templates for the four steps; the CLI.
- [ ] Translations (en, de) for the UI; the dictionaries themselves are
      data, not UI strings.

### Tests
- [ ] Every fixture: detected correctly, sections split, the expected row
      counts and exact rows, units prefilled as expected.
- [ ] A Drivvo file in an unsupported language falls back to column
      pickers and imports.
- [ ] Unit sanity line: correct for each fixture; highlighted when miles
      are read as kilometres.
- [ ] Decimal comma and day-first dates read correctly; month-first only
      when chosen.
- [ ] Categories: defaults per language; maintenance against expense
      routing; *Don't import*.
- [ ] Fuel types: LPG and CNG rows shown as not imported; electric in kWh.
- [ ] Partial, full and missed flags give the same segments the app's own
      totals imply (to the fixture's recorded figures).
- [ ] Coordinates never stored anywhere.
- [ ] Re-import: the same file → all *already imported*; a newer export →
      only new rows; an edited imported entry is still *already imported*.
- [ ] One transaction: a failure mid-way writes nothing.
- [ ] Options: recurring costs become schedules with *last done*; Drivvo
      routes become trips only with the trips module on.
- [ ] Access: no `Manage` on the target → refused; a disabled module's
      sections are not offered.
- [ ] CLI `--dry-run` writes nothing.
- [ ] Integration suite green on every engine.

### Release
- [ ] `CHANGELOG.md` **2.13.0**: import from Fuelio and Drivvo. Upgrade
      notes: one migration; no configuration.
- [ ] Bump `VERSION`, rebuild assets, update the README status and the
      documentation table entry for `docs/import.md`.

---

## Acceptance criteria

1. A Fuelio export of a petrol car in miles and UK gallons imports with
   the right units, giving the same economy per tank as Fuelio showed,
   and its services land in Maintenance and its parking in Expenses.
2. A Spanish Drivvo export with two vehicles imports into two Logbook
   vehicles, one existing and one created from the file.
3. Importing a newer export a month later adds only that month.
4. Nothing is written until the last step, and a failure writes nothing.
5. No coordinates are stored.
6. Definition of done (CLAUDE.md §11) holds.

## Open questions

- **Fixtures:** can you provide real Fuelio and Drivvo exports (yours or
  volunteers'), to be anonymised with the tool before they are committed?
  The phase can't be finished reliably without them.
- **LPG and CNG:** skip them (drafted), or add the fuel families to Logbook
  first so bi-fuel owners can bring everything across?
- **Fuelio GPS trips:** skip (drafted), or import them as private trips
  with "Fuelio trip" as the place names, so the distances are at least
  there?
- **Other apps:** worth adding aCar, Fuelly or Simply Auto later with the
  same section importer?
