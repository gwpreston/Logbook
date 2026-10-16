# Phase 51.4 — Import fixes from the known-issues log + patch release

*Five open import rows of the known-issues log fixed, each with a
regression test.*

Status: 🔨 in review ([#115](https://github.com/gwpreston16/Logbook/pull/115)) · releases as **v3.16.1** · file lives in `docs/phases/`

Phase 51's start-of-phase check (CLAUDE.md §12) found K9, K11, K12, K21
and K61 still open in [`known-issues.md`](known-issues.md). They are all
import code that Phase 51 didn't change, so they were left for their own
branch off master. They are numbered 51.4 because Phase 51's own steps are
51.0 to 51.3. The work ships after Phase 51's v3.16.0 as a patch release.

Read [`CLAUDE.md`](../../CLAUDE.md), [`spec.md`](../../spec.md) §7.3
(*Any two derive the third*), §7.13 (CSV import, *Importing from another
app*) and §7.20 (*Retries are safe*) first.

**Prerequisites:** [Phase 51](phase-51.md) merged (v3.16.0).

---

## Goals

1. **K9.** If the form works out a fill-up's price, volume or total from
   the other two and it is too large for its field, that field shows an
   error. This applies on the form, the API and the CSV import. Before,
   it was a 500, or a database error at commit.
2. **K11.** `bin/import-app.php` stops when a Fuelio file has rows with
   problems, unless `--skip-invalid` says to import the others. Problems
   name Fuelio's columns, not Logbook's fields.
3. **K12.** The command line lists fuel codes Logbook doesn't recognise.
   Sections Logbook doesn't read are listed under the web preview and on
   the command line.
4. **K21.** Five smaller gaps:
   - times stored with seconds match their exported minute;
   - the "too large" message names the upload limit;
   - miles and gallons are rounded once;
   - a refused commit keeping its staged file is settled as intended;
   - a backup imported after its CSV brings the photos.
5. **K61.** A binary file on a module's CSV import page is refused as not
   a CSV.

## Not in scope

- K1 and K2: they wait on their open questions (CLAUDE.md §12).
- K10 (UTF-16 decoded as Windows-1252). K61's check leaves UTF-16 files
  that start with a byte-order mark to be read as before.
- The same K11 pattern in Phase 51's `TracktorCommand`: recorded as K65.

---

## Decisions (and why)

None needed the owner: each fix brings the code back to what spec §7.13
already says. Where the spec was silent, it now says:

- **A derived value's limit is its field's.** Volume and price can be up
  to 999,999 and the total up to 99,999,999,999, as when typed. The error
  goes on the derived field, with its own message, because the owner
  didn't type that number.
- **Duplicates compare times to the minute.** The forms and the export
  have no seconds. Two fill-ups in the same minute at the same odometer
  are the same entry, for the API's safe retries too (§7.20).
- **Conversions round once,** straight to the stored places (km and
  litres to 3, price per litre to 6). That matches what the form does
  with miles and gallons typed to 3 places. An exported file (6 places)
  still imports back to exactly the stored values.
- **A refused commit keeps its staged file.** The page re-rendered after
  a refusal (rows to skip, nothing importable) posts back to the same
  file. The day-old sweep still removes it. §7.13 now says so.
- **A later backup's photos go to fill-ups already imported,** matched
  by file name (without its extension, since a stored photo takes the
  extension its type says). A photo the fill-up already has counts as
  *already imported*, so running the backup again adds nothing. No
  migration: photos still aren't recorded as import sources.
- **"Not a CSV"** means a known binary signature (ZIP, `.xls`, PDF, PNG,
  JPEG, GIF, gzip, 7z, RAR, SQLite), or NUL bytes in the first 8 KB of a
  file without a UTF-16 byte-order mark.

---

## Tasks

- [x] `FuelEntryForm::withinLimits()` and `fuel.derived_too_large`.
      `FuelAmounts` checks a derived volume for zero without `compare()`,
      which overflowed (K9).
- [x] `FuelioCommand`: `--skip-invalid`, Fuelio's column names in
      problems, unknown fuel codes, *Not read* sections (K11, K12).
- [x] *Not read from the file* on the Fuelio map page (K12).
- [x] `DuplicateKey` to the minute. `upload.too_large` gets `max`. The
      CSV and Fuelio importers convert straight to the stored places
      (K21).
- [x] `ImportSourceRepository::entities()`. `FuelioImporter` attaches a
      backup's photos to fill-ups imported earlier, using two queries,
      not two per row (K21).
- [x] `CsvReader::isBinary()` and `import.error.not_csv` on the module
      CSV upload (K61).
- [x] Regression tests: `FuelEntryFormTest`, `FuelAmountsTest`,
      `DuplicateKeyTest`, `ImportTest`, `ImportAppTest`,
      `FuelioCommandTest`.
- [x] spec.md §7.3, §7.13, §7.20 and §13; `docs/import.md`;
      `bin/import-app.php`; ROADMAP.
- [x] Known-issues rows K9, K11, K12, K21 and K61 *Fixed*. K65 recorded.
- [x] Release: VERSION 3.16.1, CHANGELOG 3.16.1, README status.
- [ ] Merge, then tag `v3.16.1`.

## Acceptance criteria

- Each row has a regression test for the behaviour it describes.
- `composer lint`, `composer analyse` and `bin/test-all-dbs.sh` pass on
  SQLite, PostgreSQL, MySQL and MariaDB.
- No migration. Templates and new strings are translated (English and
  German).

## Open questions

None new. K1 and K2 keep theirs in
[`open-questions.md`](open-questions.md).
