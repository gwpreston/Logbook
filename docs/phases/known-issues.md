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
| K14 | MEDIUM | Docs | Phase 50.1 and 50.2 status lines say "in progress" and some task boxes are unticked while ROADMAP says ✅. | `docs/phases/phase-50.1.md`, `phase-50.2.md` | 50.2 | Fixed (release v3.15.0) |
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

## Found in Phase 50.2 (bug-hunter, security-scanner, spec-keeper at `2e03b6e0`, 2026-10-16)

| # | Severity | Area | Issue | Where | Since | Status |
|---|---|---|---|---|---|---|
| K27 | MEDIUM | API | Retrying `POST /vehicles/{id}/modifications` with an existing part in `parts_fitted` (`{"part": 12}`) or `parts_removed` (`{"part": 12, "outcome": "kept"}`) returns 422 `part_id` instead of the stored modification with `duplicate: true` (spec §7.20 "Safe to retry"): the first call has already fitted or removed the part, and the parts lists are validated before the duplicate scan. A retry whose rows are all new parts or `came_off` rows works. | `src/Service/Api/ApiModifications.php:160` (`lists()` runs before the duplicate scan at `:174`; `RecordPartsForm::choices(…, 0)`) | 50.2 | Open |
| K28 | MEDIUM | Reminders | A "Warranty ends" reminder for a warranty with a distance limit (e.g. 36 months / 60,000 km) that the owner marked *Checked it* reopens as Upcoming, with its notification state cleared, when the part is taken off and kept as a spare, and again when it is refitted: the reminder's occurrence key drops the end mileage while the part is off the car. Spec §7.6 re-opens only on editing the warranty or the first fitting's date. A time-only warranty is not affected. | `src/Service/Reminder/ReminderGenerator.php:279`, `:303` (`fromPart()`, status-gated `$untilKm` in `occurrence`) | 50.2 | Open |
| K29 | MEDIUM | Parts | A stored part whose warranty has only a distance limit never gets a warranty reminder, although spec §7.39 says "a stored spare's warranty runs on, so it still reminds": off the car its distance limit is measured at the mileage it came off, so later driving never ends it (fitted at 10,000 km, stored at 20,000 km, 30,000 km limit: "in warranty" and silent at any odometer). Needs a decision first (open question #448: does a part's distance warranty count only while it is fitted). | `src/Service/Reminder/ReminderGenerator.php:279`, `src/Service/Part/PartWarranty.php:57`, `spec.md:9786-9787`, `spec.md:2315-2320` | 50.2 | Open |
| K30 | MEDIUM | Docs | K1 (decimal separators in CSV import), K3 (who may set *Declared on* outside the insurer page) and K8 (policy on new enum values) say they need a decision, but `open-questions.md` has no question for any of them, as CLAUDE.md §12 requires. Options seen: K1 a separator picker on the preview, detection with a warning, or a warning only parsing by locale; K3 `Manage` only on every path, `Log` on their own modifications (changes #444), or the API follows `EntryAccess`; K8 a standing "accept unknown enum values" rule in `docs/api.md`, a note per field, or a version bump per new value. | `docs/phases/known-issues.md` (K1, K3, K8), `docs/phases/open-questions.md`, `docs/phases/phase-50.2.md` *Open questions* | 50.2 | Open |
| K31 | LOW | Performance | The Ask and MCP `parts` tools read the vehicle's latest odometer reading twice per part (no request cache outside GET/HEAD): 104 queries for Ask and 156 for MCP at 50+ parts, against 2 for `find_vehicles`; 22–134 ms Ask, 30–163 ms MCP on SQLite, Postgres and MySQL. Reading it once per vehicle removes the repeats. | `src/Service/Ai/Ask/Tool/Parts.php:128-129` (`row()`), `src/Service/Part/PartService.php:213-231`, `src/Repository/OdometerReadingRepository.php` (`latestKm()`) | 50.2 | Open |
| K32 | LOW | Docs | Spec §8 *Printing reports* lists only the five Phase 17.2 pages and says the print layout is "scoped to these pages by `.print-report`", but six more use `.print-report` and aren't listed: Forecast, Finance, True cost, Trip claim, Claims history and *For your insurer*. | `spec.md:10146-10152`, `spec.md:10175`; `templates/{forecast/index,finance/index,reports/true_cost,trips/claim,incidents/history,parts/insurer}.twig` | master | Open |
| K33 | LOW | Docs | Open question #438 cites spec §7.24, but §7.24 doesn't mention modifications not declared to an insurer; the decision is only in §7.39. Add a line to §7.24, or point #438 at §7.39 only. | `docs/phases/open-questions.md:543`, `spec.md:6382` (§7.24) | 50.2 | Open |
| K34 | LOW | Docs | Phase 50.2's task 50.2.0 names only questions #437–#443; the four found while starting (#444–#447) are decided but no task names them, unlike Phase 50.1, which names #428. | `docs/phases/phase-50.2.md:91-92` | 50.2 | Open |

## Found in Phase 50.2 (design review at `2e03b6e0`, 2026-10-16)

| # | Severity | Area | Issue | Where | Since | Status |
|---|---|---|---|---|---|---|
| K35 | MEDIUM | UI | Several tap targets on the new pages are under 44 px at 375 px: the breadcrumb links (40–42 px), the part page's fitting-row link (16 px), the insurer page's modification title link (20 px) and the per-row *Mark declared* button (38 px). | `templates/parts/insurer.twig`, `modification_show.twig`, `part_show.twig`, `index.twig` | 50.1/50.2 | Open |
| K36 | LOW | UI | About 50 px of blank space between the *For your insurer* link and the first modification row on the Parts tab. | `templates/parts/index.twig:39` | 50.2 | Open |
| K37 | LOW | UI | On a new part, neither *Warranty starts from* radio is selected, and the hint doesn't say which applies when neither is chosen. | `templates/parts/part_form.twig` | 50.2 | Open |
| K38 | LOW | UI | The demo banner prints above the *For your insurer* report (demo mode only). | `templates/parts/insurer.twig`, print CSS | 50.2 | Open |
| K39 | LOW | UI | The part page shows the warranty but not whether *Remind me before it ends* is on; the tick appears only on the form. Needs a decision (open question) on whether to show it. | `templates/parts/part_show.twig` | 50.2 | Open |
