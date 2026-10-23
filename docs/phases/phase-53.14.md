# Phase 53.14 — Claude scans and large imports from the known-issues log + patch release

*Four open rows of the known-issues log closed: a scan on a Claude model
whose Test recorded `json_schema` no longer fails on a schema Anthropic
refuses, a 5,000-row CSV commits inside the time limit, a large CSV is
read without three copies in memory, and warranty reminders stop costing
a query each.*

Status: 🚧 in progress · file lives in `docs/phases/` · releases the patch
after Phase 53.13's release, a patch release

This is a batch of open rows that wait on no open question. Rows that do
(K1, K3, K13, K40, K42, K43, K44, K54, K56, K67, K72, K114, K143) are
left for the owner's answers. It is numbered 53.14 because 53.12 and
53.13 are planned and not started, so the batch follows them in the
ROADMAP.

Read [`CLAUDE.md`](../../CLAUDE.md) (§8, §11, §12),
[`spec.md`](../../spec.md) §7.13 (*Import*), §7.6 (*Reminders*), the AI
scan section, and [`open-questions.md`](open-questions.md) first.

**Prerequisites:** [Phase 53.13](phase-53.13.md) merged, and its release
tagged.

---

## Goals

### Scan

1. **K132 (HIGH).** A scan on a Claude model whose Test recorded
   `json_schema` succeeds. Start by recording one real request and
   response (or the 400) against Anthropic's structured outputs, then
   send a schema the endpoint accepts: no `maxLength`, and nullable type
   arrays within the union-parameter limit. Limits that the schema drops
   are checked in `ScanSchema`'s own result validation instead, so what
   is stored is unchanged. Test checks the real scan schema, not a
   two-property one, and a scan falls back to `tool` mode when
   `json_schema` is refused with a 400.

### Import

2. **K49.** Committing a fuel CSV at the 5,000-row limit finishes in
   well under 30 s on PostgreSQL: the commit doesn't run `analyse()`
   twice, looks stations up once per distinct name, and extends the time
   limit as long job requests do (`LongRequest`).
3. **K129.** A CSV import's peak memory is about the file size, not three
   times it: `CsvReader::read()` streams rows, and a file that still
   can't fit under `memory_limit` is refused with a message, not a 500.

### Reminders

4. **K19.** Warranty reminders are paged where they are listed, the first
   sync inserts in one statement per batch, and the reminder job's
   per-vehicle queries for ticked parts are batched.

## Not in scope

- Rows waiting on an open question (see above).
- K74, K105, K79 (53.15) and K63, K142 (53.16).
- Changing what a scan, import or reminder stores or shows. Every row
  here is behaviour-preserving.

---

## Decisions (and why)

- **Rows:** all four wait on no open question. K132's fix is chosen from
  what Anthropic accepts; if the recorded request shows a choice the
  owner must make (for example dropping a limit from the stored result),
  it goes under *Open questions* and the task waits.
- **Release:** a patch on its own after 53.13's release, like 53.12 and
  53.13 (#554).
- **Claude's scan schema (#553):** Anthropic's structured-outputs page
  (read 2026-10-23) refuses `maxLength` with a 400 and allows 16
  union-type and 24 optional parameters across the request; the scan
  schema has about 100 unions. A Claude model in `json_schema` mode gets
  a flat form: every field required as `{value, evidence}` strings,
  `""` for not shown, every list a required array, empty for none. `""`
  is turned back into null before the answer is checked against today's
  schema (with its `maxLength`), so what is stored is unchanged. The
  request is recorded with the owner's key.

---

## Tasks

### 53.14.0 Start of phase

- [x] Read `open-questions.md` and the *Open* and *Scheduled* rows of
      `known-issues.md` (CLAUDE.md §12). None of the questions still open
      bears on these rows; #539 (`strict: true` on the OpenAI-compatible
      adapter) is a different adapter. #553 (the schema a Claude model
      gets) was found while starting it and answered the same day, and
      #554 (release) records the decision below.

### 53.14.1 Scan schema

- [ ] Record one request to a Claude model with the current scan schema
      and keep it as a fixture (K132).
- [x] `ScanSchema::schema()` sends no `maxLength` and at most the allowed
      union types to Anthropic; the limits are enforced on the result.
      Built as `ScanSchema::flat()` (#553), sent by `AnthropicAdapter`
      through `ResponseFormat::$flat`; `StructuredOutput::unflatten()`
      turns its blanks back into nulls before the check.
- [x] `ConnectionTester` tests with the real scan schema; `AiGateway`
      falls back to `tool` mode on a 400 from `json_schema`.
- [x] Tests: schema contains no `maxLength` for Anthropic; a result over
      a dropped limit is trimmed or refused as before; fallback on 400.
      `ScanSchemaTest`, `AiGatewayTest`, `ConnectionTesterTest`.

### 53.14.2 Import speed and memory

- [x] `ImportAction` + `CsvImporter::import()` reuse one analysis;
      stations resolved once per name; `LongRequest` applied (K49).
      Measure 5,000 rows on PostgreSQL before and after. The commit's
      fill-ups go through `FuelService::import()`: inside the import's
      transaction, no savepoint, read-back or reading lookup per row, and
      each station name linked once. Measured 2026-10-23 in the dev stack
      (Docker Desktop on Windows; 5,000 fill-ups, 25 stations), preview
      then commit wall time (user CPU):

      | Engine | Before | After |
      |---|---|---|
      | PostgreSQL 17 | 2.9 s, 20.5 s (4.2 s) | 0.4 s, 6.9 s (1.5 s) |
      | MySQL | 2.3 s, 15.6 s (4.4 s) | 0.5 s, 4.4 s (1.5 s) |
      | SQLite (bind mount) | 10.5 s, 34.3 s (5.8 s) | 0.5 s, 6.5 s (1.6 s) |

      `ImportTest::testAStationNamedOnManyRowsIsCreatedOnceAndEveryFillUpWritesItsReading`.
- [x] `CsvReader::read()` streams; a too-large file gets a message
      (K129). Test with a raised `MAX_UPLOAD_MB` and a low
      `memory_limit`. Both import steps read the staged file with
      `CsvReader::fromFile()`: UTF-16, Windows-1252 and NULs are converted
      a chunk at a time into a temporary file; once reading has added half
      of what `memory_limit` left when it began, it stops with `CsvTooLarge` ("The file is
      too large to read…", 422, the staged copy removed). A 20 MB file
      now peaks under 1.5 times its size. `CsvReaderStreamTest`,
      `ImportTest::testACsvTooLargeForTheMemoryLimitIsRefusedWithAMessage`.

### 53.14.3 Warranty reminders

- [x] `ReminderSync::warranties()` pages; batch inserts; fewer queries in
      the reminder job (K19). A test counts queries for 200 ticked parts.
      The Reminders page pages *Upcoming* 25 at a time (#555). New
      reminders that tell no webhook go in one statement a hundred
      (`ReminderRepository::insertGeneratedMany()`). The sync reads once
      per request for its own run when nothing else does, so the job primes
      every vehicle's parts, fittings and readings as a page does: 10
      vehicles with ticked parts cost the sync 166 queries before, 21
      after, and 200 ticked parts still 21 (23 on the sync that adds 190).
      `WarrantyReminderQueryCountTest`,
      `PartWarrantyReminderTest::testTheRemindersPagePagesUpcomingTwentyFiveAtATime`.

### 53.14.4 Finish

- [ ] Known-issues rows K19, K49, K129 and K132 *Fixed*.
- [ ] CHANGELOG; spec §13; ROADMAP status.
- [ ] Merge review (import-tester, performance-auditor, ai-evaluator),
      then merge.

### Release

- [ ] `VERSION`, CHANGELOG, README status, tag: the patch after 53.13's
      release.

## Design review (2026-10-23)

The Reminders page with 42 open reminders (the sample data and 30 ticked
part warranties) at 1280 px light, 768 px light and 375 px dark. Page 1
ends *Upcoming* with "1–25 of 42" and *Later*; page 2 starts with
*Sooner* and ends at "26–42 of 42". The pager sits inside the group's
card, under its last row, as on the Parts and Fuel tabs; at 375 px the
button and count share one line. Overdue and due show whole on both
pages, and the heading keeps the whole count. The links are plain
`?page=` anchors, so paging works without JS; under `APP_BASE_PATH`
they carry the subpath
(`PartWarrantyReminderTest::testThePagesLinkUnderASubpath`). No HIGH or
MEDIUM items.

## Acceptance criteria

- Each row has a test or a recorded measurement that fails without the
  fix.
- `composer lint`, `composer analyse` and `bin/test-all-dbs.sh` pass on
  all four engines. `MarkdownLinksTest` passes.
- No migration.

## Open questions

- **#553. The scan schema a Claude model gets** (K132, found while
  starting it). *Decided 2026-10-23:* (1) a flat form for Anthropic's
  `json_schema`: every field required as `{value, evidence}` plain
  strings with `""` for not shown, every list a required array (empty
  for none), `""` turned back into null before the check. Not chosen:
  (2) always `tool` mode for scans on Claude (no enforcement, and a
  forced tool can't be used with extended thinking); (3) decide after
  the recording. spec §7.27 *Schemas*.
- **#555. Paging warranty reminders** (K19, found while building it).
  *Decided 2026-10-23:* (1) the Reminders page's *Upcoming* is paged 25 at
  a time with the app's usual `?page=` links (*Sooner* / *Later*), the
  heading still giving the whole count; overdue and due stay whole. Not
  chosen: (2) the first 25 and a *Show all* link; (3) unpaged, the listing
  part of K19 *Won't fix*. spec §7.6.
- **#554. Release.** *Decided 2026-10-23:* released on its own as a
  patch, after Phase 53.13's release, like 53.12 (#546) and 53.13
  (#548). Not chosen: with the next phase that releases.
