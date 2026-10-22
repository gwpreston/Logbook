# Phase 53.14 — Claude scans and large imports from the known-issues log + patch release

*Four open rows of the known-issues log closed: a scan on a Claude model
whose Test recorded `json_schema` no longer fails on a schema Anthropic
refuses, a 5,000-row CSV commits inside the time limit, a large CSV is
read without three copies in memory, and warranty reminders stop costing
a query each.*

Status: 📋 planned · file lives in `docs/phases/` · releases the patch
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
  53.13.

---

## Tasks

### 53.14.0 Start of phase

- [ ] Read `open-questions.md` and the *Open* and *Scheduled* rows of
      `known-issues.md` (CLAUDE.md §12).

### 53.14.1 Scan schema

- [ ] Record one request to a Claude model with the current scan schema
      and keep it as a fixture (K132).
- [ ] `ScanSchema::schema()` sends no `maxLength` and at most the allowed
      union types to Anthropic; the limits are enforced on the result.
- [ ] `ConnectionTester` tests with the real scan schema; `AiGateway`
      falls back to `tool` mode on a 400 from `json_schema`.
- [ ] Tests: schema contains no `maxLength` for Anthropic; a result over
      a dropped limit is trimmed or refused as before; fallback on 400.

### 53.14.2 Import speed and memory

- [ ] `ImportAction` + `CsvImporter::import()` reuse one analysis;
      stations resolved once per name; `LongRequest` applied (K49).
      Measure 5,000 rows on PostgreSQL before and after.
- [ ] `CsvReader::read()` streams; a too-large file gets a message
      (K129). Test with a raised `MAX_UPLOAD_MB` and a low
      `memory_limit`.

### 53.14.3 Warranty reminders

- [ ] `ReminderSync::warranties()` pages; batch inserts; fewer queries in
      the reminder job (K19). A test counts queries for 200 ticked parts.

### 53.14.4 Finish

- [ ] Known-issues rows K19, K49, K129 and K132 *Fixed*.
- [ ] CHANGELOG; spec §13; ROADMAP status.
- [ ] Merge review (import-tester, performance-auditor, ai-evaluator),
      then merge.

### Release

- [ ] `VERSION`, CHANGELOG, README status, tag: the patch after 53.13's
      release.

## Acceptance criteria

- Each row has a test or a recorded measurement that fails without the
  fix.
- `composer lint`, `composer analyse` and `bin/test-all-dbs.sh` pass on
  all four engines. `MarkdownLinksTest` passes.
- No migration.

## Open questions

- None yet. Anything K132's recorded request turns up is added here
  before the task that needs it.
