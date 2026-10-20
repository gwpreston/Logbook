# Phase 53.6 — Import fixes from the known-issues log + patch release

*Three open rows of the known-issues log closed: a CSV import reads the
volume unit its header names and reads UTF-16 files, and the Tracktor
command line stops on rows with problems as the web page does.*

Status: 🚧 in progress · file lives in `docs/phases/` · releases the patch
after Phase 53.5's release, a patch release

This is the next batch after [Phase 53.5](phase-53.5.md). Like 52.1,
52.2 and 53.5, it takes a few small open rows, all in the import code:
K2 (HIGH), K10 and K65 (MEDIUM). K2 and K10 each needed a choice, which
the owner made when the batch was planned (#504, #505). It is numbered
53.6 because no phase is in progress, so it is the next phase not yet
defined, after 53.5 in the ROADMAP.

Read [`CLAUDE.md`](../../CLAUDE.md), [`spec.md`](../../spec.md) §7.13
(*Import*: the CSV upload, the mapping step's units, the app import
command line), and [`open-questions.md`](open-questions.md) first.

**Prerequisites:** [Phase 53.5](phase-53.5.md) merged. Built before its
release is tagged (the owner, 2026-10-20); 53.6's own release follows
`v3.18.1`.

---

## Goals

1. **K2.** A CSV whose volume column is headed `Volume (UK gallons)`,
   `Quantity (litres)` or `Volume (US gal)` opens the mapping page with that
   unit chosen, as `Odometer (Miles)` already does for distance. A bare
   gallon (`Volume (gal)`, `Volume (gallons)`) chooses the owner's
   gallon (#505), and a unit Logbook doesn't record by volume
   (`(kWh)`, `(kg)`) leaves the owner's unit chosen.
2. **K10.** A CSV saved as UTF-16 (Excel's *Unicode text*, with its
   byte-order mark) imports with its text intact: `Łódź €` stays
   `Łódź €`, and the first header matches without the mark (#504).
3. **K65.** `php bin/import-app.php <export.json>` with rows that have
   problems stops with exit code 1 and writes nothing unless
   `--skip-invalid` is given, and each problem names the Tracktor
   export's field (`date`, `odometer`), not Logbook's (`filled_at`), as
   the Fuelio command line has since K11.

## Not in scope

- Import rows that wait on an open question: K1 (#484), K48 (#455), K57
  (#459).
- K13 (export writes local time with no offset): changing the export's
  time format changes a CSV contract and needs a decision; not small.
- K49 (a 5,000-row fuel commit on PostgreSQL): a performance pass, not a
  one-place fix.
- K28 (a distance warranty's reminder reopens when the part is stored):
  it shares `ReminderGenerator::fromPart()` with K29, which waits on
  #448.
- K62, K69, K24 and K25 (LOW, outside import): candidates for a later
  batch.
- The rows 53.5 left out (K51, K85, K90, K95–K98, K40–K43, K16, K35,
  K55) for the reasons it gives.

---

## Decisions (and why)

- **K2 reads the volume column's bracket as `ImportMapper::guess()`
  already reads the distance column's.** Spec §7.13 says "the file's
  units (distance, volume) default to the unit named in the header";
  only distance did. A bare gallon chooses the owner's volume unit if it
  is a gallon, else by region as #464 decided for app imports (UK or
  Irish locale, or pounds → UK gallon, else US; #505), one helper for
  both; today `gal` matches the UK gallon's
  English symbol. `kWh` and `kg` aren't volume units, so they leave the
  owner's unit chosen. The mapping page still lets the owner change it.
- **K10 decodes UTF-16 with a byte-order mark, little- or big-endian,
  to UTF-8 and drops the mark** (#504). Excel's *Unicode text* is
  UTF-16 LE and tab-separated, and tabs are already detected. Spec
  §7.13 is updated to say so. A UTF-16 file without a mark is still
  refused as not a CSV (K61). No new string.
- **K65 copies K11's Fuelio fix into `TracktorCommand`:** rows with
  problems stop the import unless `--skip-invalid`, and problems name
  the export's fields. Spec §7.13 already says this for the command line
  "as on the web page"; its Tracktor synopsis gains `--skip-invalid`.
- **Release:** a patch on its own after 53.5's release (#503, decided
  2026-10-18).

---

## Tasks

### 53.6.0 Start of phase

- [x] Read `open-questions.md` and the *Open* and *Scheduled* rows of
      `known-issues.md` (CLAUDE.md §12). Nothing new for import since
      planning; #505's region wording corrected (below).

### 53.6.1 Import fixes

- [x] `ImportMapper::guess()` takes the volume unit from the volume
      column's header bracket through `ImportVocabulary::volumeUnit()`;
      a bare gallon takes the owner's gallon; `kwh` and `kg` are ignored
      (K2, #505). `ImportMapperTest`: `Volume (UK gallons)`,
      `Quantity (litres)` (`Fuel (…)` maps to the fuel type column),
      `Volume (US gal)`, `Volume (gal)` for US, UK, Irish, German and
      pound-paying owners, `Energy (kWh)` and `(kg)` each preselect the
      expected unit. #464's rule moved from `TracktorImporter` to
      `DisplayPreferences::gallon()`, one helper for both.
- [x] `CsvReader::read()` converts a file starting with a UTF-16 LE or
      BE byte-order mark to UTF-8 and drops the mark, before the
      Windows-1252 fallback (K10, #504). `ImportReadingTest`: a UTF-16 LE
      tab-separated file and a UTF-16 BE comma-separated one with
      `Łódź €` read back intact, the first header without the mark; a
      UTF-16 file without a mark is still refused (K61).
- [x] `TracktorCommand::run()` refuses rows with problems (exit 1,
      nothing written) unless `--skip-invalid`, and names the export's
      fields in each problem (K65). `TracktorCommandTest`: an export with
      one bad row stops without the flag and imports the others with it;
      the problem line names the Tracktor field. The usage lines in
      `ImportAppCommand` and `bin/import-app.php` list the flag.

### 53.6.2 Finish

- [x] Known-issues rows K2, K10 and K65 *Fixed*.
- [x] CHANGELOG; spec §7.13; ROADMAP status; `docs/import.md` (UTF-16,
      header volume units) and `docs/import-tracktor.md`
      (`--skip-invalid`).
- [ ] Merge review, then merge.

### Release

- [ ] `VERSION`, CHANGELOG, README status, tag: the patch after 53.5's
      release (#503).

## Acceptance criteria

- Each row (K2, K10, K65) has a regression test that fails without its
  fix.
- `composer lint`, `composer analyse` and `bin/test-all-dbs.sh` pass on
  SQLite, PostgreSQL, MySQL and MariaDB. `MarkdownLinksTest` passes.
- No migration. Any new or changed strings are in every catalogue in
  `translations/`.

## Open questions

- **#503. Release.** *Decided 2026-10-18:* released on its own as a
  patch, after Phase 53.5's release, like 52.1 (#481), 52.2 (#486) and
  53.5 (#502). None of the rows touch the Scan code that 53.3 and 53.4
  change. Not chosen: with the next phase that releases.
- **#504. K10: a UTF-16 CSV.** *Decided 2026-10-18:* read it: a file
  starting with a UTF-16 byte-order mark is converted to UTF-8, and
  spec §7.13 says so. Not chosen: refuse it with a message asking for
  CSV UTF-8.
- **#505. K2: a bare gallon in a header.** *Decided 2026-10-18:*
  `(gal)` or `(gallons)` with no country chooses the owner's gallon (the
  owner's volume unit if a gallon, else by region), as #464 decided for
  app imports. *Corrected 2026-10-20:* the Decisions wording first said
  "US → US gallons, else UK", the reverse of #464; the owner chose
  #464's rule (UK or Irish locale, or pounds → UK gallon, else US). Not chosen: `gal` as the UK gallon, as its English symbol
  reads.
