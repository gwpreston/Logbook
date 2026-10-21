# Phase 53.10 — Scan and import edges from the known-issues log + patch release

*Three open rows of the known-issues log closed: the V5C update page
opens only for a scan read as a registration document, a CSV header
naming its unit in common English words sets the file's unit, and the
3.19.0 notes say what going back to 3.18.x does to an unread V5C scan.*

Status: ✅ complete · releases **v3.19.2**, a patch release after Phase
53.9's (#543) · file lives in `docs/phases/`

This is the next batch after [Phase 53.9](phase-53.9.md). Like 52.1,
52.2 and 53.5–53.9, it takes a few small open rows: K124 (MEDIUM, CSV
import), K136 (LOW, Scan) and K135 (LOW, the 3.19.0 notes, docs only).
Only these passed the batch checks: every other open row waits on an
open question, needs measuring first, or was ruled too large by an
earlier batch (*Not in scope*). K124 needed a choice, which the owner
made when the batch was planned (#544). It is numbered 53.10 because no
phase is in progress: Phase 53.9 is planned but not started, so the
batch is the next phase not yet defined, after 53.9 in the ROADMAP.

Read [`CLAUDE.md`](../../CLAUDE.md) (§11, §12),
[`spec.md`](../../spec.md) §7.13 (*Import*: the units a header names),
§7.27 (*Reading files*: *Scan the V5C* and the vehicle update page), and
[`open-questions.md`](open-questions.md) first.

**Prerequisites:** [Phase 53.9](phase-53.9.md) merged, and its release
tagged.

---

## Goals

1. **K136.** `/scan/{token}/vehicle` opens only for a scan the result
   page sends there: a *Scan the V5C* scan (target `vehicle`), or one
   read as a registration document, by the model or by the owner's
   *Read as*. A receipt's scan, read or failed, opened there by a
   crafted URL is not found, and can't be filed as a registration
   document.
2. **K124.** A CSV whose header names its unit as `Volume (litre)`,
   `(liter)`, `(ltr)`, `(UK gallon)`, `(imperial gallons)`, `(imp gal)`,
   `(US gallon)`, `Odometer (mile)` or `(kilometers)` has that unit
   preselected on the mapping page, whatever the owner's own units; a
   German `Menge (Gallonen)` is read as the owner's gallon, as `(gal)`
   is (#505, #544).
3. **K135.** The 3.19.0 notes say that after going back to 3.18.x, a
   *Scan the V5C* that couldn't be read in the last 24 hours opens as
   "not found" instead of its reason, and that scanning the file again
   after upgrading shows it.

## Not in scope

- Rows that wait on an open question: K1 (#484), K3 (#485), K29 (#448),
  K39, K40 (#449), K42 (#450), K43 (#451), K44 (#452), K45 (#461), K47
  and K59 (#454), K48 (#455), K54 (#456), K55 (#457), K56 (#458), K57
  (#459), K67 (#483), K71 (#487), K72 (#488).
- K132 (with a Claude model whose Test recorded `json_schema`, a scan
  probably fails on the scan schema's `maxLength` and type arrays):
  HIGH, but the row says the fix starts by recording a real request to
  Anthropic, and #539 asks the same of OpenAI's `strict`; a batch of
  its own once a request is recorded.
- K105 and K114 (the full-size file prepared before the request): tied
  to the owner's #515, which kept the full-size file; they need
  measuring on files the fixtures don't have.
- K16 (insurer page: a blank *Mark all* date, row buttons and toolbar
  at 375 px) and K35 (tap targets on the Parts pages): the tap-target
  rows, which wait on #457 with K55, as 52.1, 52.2 and 53.5 said.
- K41 (*Coming up* rows with a cost at 375 px) and K40–K43, K49: the
  larger rows, as 53.5 said.
- K129 (a CSV import's peak memory): a streaming reader, not a
  one-place fix (53.9).
- The rows 53.5–53.9 left out (K13, K19, K28, K51, K63, K74, K79) for
  the reasons they give.

---

## Decisions (and why)

- **K136 checks what the result page checks.** `ScanResultAction`
  sends a scan to the V5C update page when its kind is a registration
  document: the `vehicle` target (#511), the model's reading, or the
  owner's *Read as* (`?as=registration`, a link on the scanned form's
  notice). `ScanVehicleAction` takes any of the user's claimable
  uploads. It works out the kind the same way and refuses (404) any
  other, and
  `ScanResultAction::formUrl()` passes `as` on to the vehicle route, so
  a *Read as registration document* the owner chose still opens. This
  makes the code do what spec §7.27 already says: the update page is a
  registration document's details. The failed *Scan the V5C* page
  (#511) is unchanged; whether it offers *Keep the file* is #538.
- **K124 reads common English unit words** (#544). `ImportVocabulary`
  gets a fixed list of English spellings and abbreviations beside the
  catalogue words it reads now: singular and plural, `litre`/`liter`,
  `ltr`, `UK gallon`, `imperial gallon(s)`, `imp gal`, `US gallon`,
  `US gal`, `mile`, `kilometre`/`kilometer`(s). Each catalogue's bare
  gallon word (`import_app.tracktor.unit.gallon`, German `Gallonen`)
  joins `ImportMapper::BARE_GALLONS`, so it is read as the owner's
  gallon (#505). Spec §7.13 says so (updated when the
  batch was planned).
- **K135 is a note, not a 3.18 patch.** 3.18.x's actions can't be
  changed after the fact, and the scan is kept only 24 hours. The
  3.19.0 entry in `CHANGELOG.md` gets the rollback note beside its
  *No migration* line.
- **Release:** a patch on its own after 53.9's release (#543, decided
  2026-10-21).

---

## Tasks

### 53.10.0 Start of phase

- [x] Read `open-questions.md` and the *Open* and *Scheduled* rows of
      `known-issues.md` (CLAUDE.md §12). *2026-10-21:* #543 and #544
      were decided when the batch was planned, and spec §7.13 already
      carries #544; spec §7.27 now says which scans open the update page
      (K136). Every question still *Needs a decision* is about a row
      listed under *Not in scope* or code this phase doesn't change
      (#537, #539–#542 are the V5C redaction and the scan request, not
      the update page). No open row besides K124, K135 and K136 is in
      the code this phase changes.

### 53.10.1 Scan and import

- [x] `ScanVehicleAction` opens an upload only when its kind is a
      registration document, worked out as `ScanResultAction` does
      (target `vehicle`, the reading's kind, or `?as=registration`);
      `ScanResultAction::formUrl()` passes `as` on the vehicle route
      (K136). `ScanFlowTest` (or the V5C tests beside it): a read
      receipt and a failed receipt scan give 404 on GET and on a POST
      with `scan_keep=1`, and no document is filed; a receipt read
      *as registration document* still opens; a failed *Scan the V5C*
      still shows its reason (#511). *Done:* a failed scan opens only
      when it is a *Scan the V5C* one, even with `?as=registration`,
      and the update page's form posts `as` back; the failed V5C's
      reason stays covered by `ScanCrossCheckTest`.
- [x] `ImportVocabulary::volumeUnit()` and `distanceUnit()` read the
      English aliases; `ImportMapper` reads each catalogue's bare gallon
      word as the owner's gallon (K124, #544). `ImportMapperTest`: each
      of the row's headers preselects its own unit for a US-gallon owner
      and for a metric owner, and `Menge (Gallonen)` the owner's gallon.
- [x] `CHANGELOG.md` [3.19.0]: the rollback note (K135).

### 53.10.2 Finish

- [x] Known-issues rows K124, K135 and K136 *Fixed*.
- [x] CHANGELOG; spec §13; ROADMAP status. *Done:* spec §7.27 says
      which scans open the update page (K136), and `docs/import.md`
      lists the unit words a header may use (K124).
- [ ] Merge review, then merge. *2026-10-21, at `0e436c57`:* MERGE.
      import-tester found `U.S. gallons` and `U.S. gal` unread (the
      dots normalise to `u s`); fixed on the branch with `U.K.`, and
      `ImportMapperTest` covers both. Its LOW (a per-row `unit` cell's
      bare gallon) and two open questions go to `/issue-sweep`.

### Release

- [x] `VERSION` 3.19.2, CHANGELOG 3.19.2, README status: the patch
      after 53.9's release (#543).
- [ ] Tag `v3.19.2` once merged.

## Acceptance criteria

- K136 and K124 each have a regression test that fails without the fix;
  K135's note is in the 3.19.0 entry.
- `composer lint`, `composer analyse` and `bin/test-all-dbs.sh` pass on
  SQLite, PostgreSQL, MySQL and MariaDB. `MarkdownLinksTest` passes.
- No migration. Any new or changed strings are in every catalogue in
  `translations/`.

## Open questions

- **#543. Release.** *Decided 2026-10-21:* released on its own as a
  patch, after Phase 53.9's release, like 52.1 (#481), 52.2 (#486),
  53.5 (#502), 53.6 (#503), 53.7 (#506) and 53.9 (#527). None of the
  rows touch code 53.9 changes. Not chosen: with the next phase that
  releases.
- **#544. K124: which unit words a CSV header's brackets may use.**
  *Decided 2026-10-21:* a fixed list of common English spellings and
  abbreviations in code, beside Logbook's own names and symbols in every
  language, and each catalogue's bare gallon word read as the owner's
  gallon (#505). Not chosen: a per-language alias key in every
  catalogue; keeping the words as they are and warning on the preview
  when a bracketed unit isn't recognised.
