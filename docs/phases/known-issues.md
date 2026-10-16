# Known issues

Bugs and problems found but not fixed when a phase finished, so a later
phase can pick them up. Read this before starting a phase
([`CLAUDE.md`](../../CLAUDE.md) §12). Undecided *choices* go in
[`open-questions.md`](open-questions.md) instead; an issue that needs a
decision before it can be fixed names its question.

- **Open:** not fixed, not yet given to a phase.
- **Scheduled:** given to a phase; the last column names it.
- **Fixed:** fixed; the last column names the phase (and commit when
  useful). Keep the row so the history stays readable.
- **Won't fix:** the owner decided to leave it; the last column says why
  and when.

Severity uses the review scale in
[`.claude/review-rules.md`](../../.claude/review-rules.md) §6. *Found* is
the phase whose review found it; *Since* says whether this phase's code
introduced it or it was already there (`master` = older code).

Numbers are sequential and never reused.

## Found in Phase 50.2 (merge review at `777936e2`, 2026-10-16)

| # | Severity | Area | Issue | Where | Since | Status |
|---|---|---|---|---|---|---|
| K1 | HIGH | Import | A comma-decimal or dot-thousands CSV cell is silently misread: `45,123` L becomes 45123 L for an English-locale user; `13.000` km becomes 13 for a German-locale user. The preview gives no warning. Needs a decision first (open question: separator picker, detection or warning). | `src/Support/Number/DecimalParser.php` | master | Open |
| K2 | HIGH | Import | A volume unit named in the CSV header (`Volume (UK gallons)`) is ignored; only distance and time-zone brackets are read, though spec §7.13 says the header sets the default. | `src/Service/Import/ImportMapper.php` | master | Open |
| K3 | MEDIUM | Access | A Log member can set or clear *Declared on* on their own modifications through the web create, edit and convert forms (a crafted field) and the API, though #444 gives *Mark declared* to Manage. Needs the API rule decided (open question). | `src/Service/Part/ModificationForm.php` (`has('declared_on')`), `ApiModifications` | 50.2 | Open |
| K4 | MEDIUM | API | `POST`/`PATCH /modifications` never return the `odometer_backwards` warning that every other write returns. | `src/Service/Api/ApiModifications.php:187` | 50.2 | Open |
| K5 | MEDIUM | Parts | A sale recorded by a member without *Can see costs* is stored with `sold_price` 0.000 instead of null, so the owner sees "sold for £0". | `src/Service/Part/PartForm.php:234` | master (web); 50.2 (API) | Open |
| K6 | MEDIUM | Ask/MCP | `vehicle_summary.running_cost_last_12_months.total` includes build (modification) spend, unlike `costs` and `cost_per_distance`. | `src/Service/Ai/Ask/Tool/VehicleSummary.php:133` | master (50.1) | Open |
| K7 | MEDIUM | UI | On the insurer page, *Clear* (and *Mark*) are blocked by the browser when the date is blank or in the future; needs `formnovalidate`. | `templates/parts/insurer.twig` | 50.2 | Open |
| K8 | MEDIUM | API docs | No standing "accept unknown enum values" rule in `docs/api.md` / OpenAPI, while History kinds, reminder `source`, webhook `kind` and attachment `owner.type` grow. Needs the enum policy decided. | `docs/api.md`, `docs/api/openapi.json` | 50.1/50.2 | Open |
| K9 | MEDIUM | Import | A derived fuel price or total that overflows returns 500 at commit (PG/MySQL/MariaDB) or at preview (all engines) instead of a row error. | `src/Service/Fuel/FuelEntryForm.php:160`, `FuelAmounts` | master | Open |
| K10 | MEDIUM | Import | UTF-16 CSV files are decoded as Windows-1252 and stored garbled (`Łódź €` → `Aódz ¬`); the BOM stays on the first header. | `src/Support/Csv/CsvReader.php` | master | Open |
| K11 | MEDIUM | Import | The Fuelio CLI imports the valid rows when others are invalid, without a skip flag, and names internal fields (`filled_at`) instead of the file's columns. | `src/Service/Import/App/Fuelio/FuelioCommand.php` | master | Open |
| K12 | MEDIUM | Import | The Fuelio CLI doesn't highlight an unknown fuel code; unknown Fuelio sections are never listed as "not read" (web or CLI), as spec §7.13 requires. | `FuelioCommand`, `FuelioReader.php:152` | master | Open |
| K13 | MEDIUM | Import/Export | Export writes local time with no offset, so two fill-ups in the DST fall-back hour collapse to one time on re-import; nonexistent local times are accepted silently. | CSV exporter, CSV importer | master | Open |
| K14 | MEDIUM | Docs | Phase 50.1 and 50.2 status lines say "in progress" and some task boxes are unticked while ROADMAP says ✅. | `docs/phases/phase-50.1.md`, `phase-50.2.md` | 50.2 | Open |
| K15 | LOW | Docs | CHANGELOG says a rollback deletes modification drafts "not yet added"; it deletes every one (pending, added, discarded). Also: 3.15.0 date, loose paragraphs in *Fixed*, and the rollback/webhook notes listed in the review. | `CHANGELOG.md` | 50.2 | Open |
| K16 | LOW | UI | Insurer page: a blank date on *Mark all* says "not in the future" and isn't tied to the field; row buttons are 38 px at 375 px; the parts toolbar crowds at 375 px. | `templates/parts/insurer.twig`, `templates/parts/index.twig` | 50.2 | Open |
| K17 | LOW | Ask | The German `parts` warranty text has a hard-coded English "or", and an ended warranty drops its date. | `src/Service/Ai/Ask/Tool/Parts.php` (`warranty()`) | 50.2 | Open |
| K18 | LOW | Scan | `PartLines` misreads space-grouped prices (`1 234,56` → 234.56), leaves the price blank after a quantity/VAT suffix, and drops a leading "2 x" quantity. | `src/Service/Ai/Scan/PartLines.php` | 50.2 | Open |
| K19 | LOW | Performance | Warranty reminders are listed (unpaged) from the moment they're ticked; the first sync does one INSERT each; the reminder job adds about 4 queries per vehicle with ticked parts. | `src/Service/Reminder/ReminderSync.php` (`warranties()`) | 50.2 | Open |
| K20 | LOW | API | `docs/api.md` understates the part `warranty` object and doesn't list the History `kinds` names. | `docs/api.md` | 50.2 | Open |
| K21 | LOW | Import | Smaller import gaps: demo rows with seconds duplicate on re-import; `{max}` shown literally when an upload is too large; converted volumes rounded twice (1 in 1,200 off by 0.001 L); a staged file is kept after a refused commit; a Fuelio ZIP after its CSV attaches no photos. | `ImportUploadAction`, CSV importer, Fuelio reader | master | Open |
| K22 | LOW | Code | Stray docblock: `Validator::raw()`'s comment now sits above `has()`. | `src/Support/Validation/Validator.php:49` | 50.2 | Open |
| K23 | LOW | Dependencies | Dev-only `symfony/yaml` CVE-2026-109413 (`composer audit --no-dev` is clean). | `composer.lock` | master | Open |

Not re-checked at `777936e2` (reported by earlier 50.2 review rounds,
recorded so they aren't lost; confirm before fixing):

| # | Severity | Area | Issue | Where | Since | Status |
|---|---|---|---|---|---|---|
| K24 | LOW | Webhooks | A title-only edit of a modification queues a `part` update for each of its parts. | `PartService` / `ModificationService` | 50.2 | Open |
| K25 | LOW | API | A modification's ETag changes when an unrelated field of one of its parts is edited. | `ApiModifications` | 50.2 | Open |
| K26 | LOW | API | The message for more than 20 rows in a parts list names the wrong limit. | `JsonInput` | 50.2 | Open |
