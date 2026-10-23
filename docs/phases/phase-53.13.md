# Phase 53.13 — CSV import round trip and part warranty details from the known-issues log + patch release

*Four open rows of the known-issues log closed: a year-first date
imports under every date order, Logbook's own export imports back
without the apostrophe it adds against formulas, editing a part with no
warranty start chooses *Purchase date* as a new part does, and the part
page says when its warranty reminder is on.*

Status: ✅ complete · released as **v3.19.5**
([#143](https://github.com/gwpreston16/Logbook/pull/143)), a patch
release after Phase 53.12's (#548) · file lives in `docs/phases/`

This is the next batch after [Phase 53.12](phase-53.12.md). Like 52.1,
52.2 and 53.5–53.12, it takes a few small open rows: K48 (MEDIUM, the
export's formula guard kept on import), K57 (LOW, year-first dates under
another date order), K71 (LOW, the warranty start chip on edit) and K39
(LOW, the warranty reminder on the part page). No open row passed the
batch checks when it was planned: each of these waited on a question,
and the owner decided all four (#455, #459, #487 and the new #547) so
that this batch could take them. It is numbered 53.13 because no phase
is in progress: Phases 53.9–53.12 are planned but not started, so the
batch is the next phase not yet defined, after 53.12 in the ROADMAP.

Read [`CLAUDE.md`](../../CLAUDE.md) (§8, §11, §12),
[`spec.md`](../../spec.md) §7.13 (*Import*, *CSV export*), §7.39
(*Parts*), and [`open-questions.md`](open-questions.md) first.

**Prerequisites:** [Phase 53.12](phase-53.12.md) merged, and its release
tagged.

---

## Goals

### CSV import

1. **K57.** With the import's date order set to day-first or
   month-first, `2026-04-06` imports as 6 April 2026 at noon, as
   `2026-04-06T09:15` and `2026-04-07 09:15` already do, instead of being
   refused with "Enter a valid date and time" (#459).
2. **K48.** Importing Logbook's own export gives back what was written:
   station `@x` comes back as `@x`, not `'@x`, notes `-5 off` as
   `-5 off`, and no station `'@x` is added to the station list (#455).

### Part warranty

3. **K71.** Editing a part saved without a warranty has *Purchase date*
   chosen under *Warranty starts from*, as a new part's form does, so a
   warranty added on edit is stored the same way as one added new
   (#487).
4. **K39.** A part whose *Remind me before it ends* is on says
   "Reminder before it ends" in its warranty card; one whose tick is off
   shows nothing about it (#547).

## Not in scope

- K13 (export writes local time with no offset): changes a CSV
  contract and needs a decision (53.6).
- K19, K49, K74 and K129: performance passes or a streaming reader, not
  one-place fixes; K74 may reopen #492 (53.12).
- K63 (SQLite id counters reset on a table rebuild): a migration and
  upgrade matter across many tables (53.7).
- K79 (the optional PaddleOCR image's install and user): Docker
  hardening, a batch of its own.
- K105 and K114: need measuring (53.10). K132: a batch of its own once
  a request to Anthropic is recorded (53.10).
- K40, K42 and K43: larger rows that also wait on #449, #450 and #451.
- Rows that still wait on an open question: K1 (#484), K3 (#485), K44
  (#452), K54 (#456), K56 (#458), K67 (#483), K72 (#488).
- App imports (Fuelio, Tracktor): their files aren't written by
  Logbook's exporter, so K48's guard never appears in them. They keep
  reading text as written.
- Showing the reminder tick in the API, MCP and Ask `parts` results:
  they already return `warranty_reminder`.

---

## Decisions (and why)

- **K57 reads a year-first date under every order** (#459, decided
  2026-10-21). `DateOrder::normalise()` tries the ISO pattern first
  whatever the chosen order, then the order's own. A four-digit year
  first can't be read as day-first or month-first, so nothing that
  imports today changes meaning. A date alone is taken as noon, as
  `CsvImporter::dateTime()` already does under ISO. Not chosen: refusing
  every value not in the chosen order, which would break files that
  import today, or leaving it.
- **K48 removes one leading `'` in every module's CSV import** (#455,
  decided 2026-10-21). `CsvImporter` drops one `'` from a cell when the
  character after it is one `CsvWriter::cell()` guards (`=`, `+`, `-`,
  `@`, tab, CR), for every column, before the row is read. That is how a
  spreadsheet treats a leading apostrophe. It doesn't depend on
  recognising Logbook's header, so a file opened and re-saved in a
  spreadsheet is read the same way. A cell such as `'abc`, whose next
  character isn't guarded, keeps its apostrophe. Not chosen: stripping
  only when the header is Logbook's own, or keeping the text and
  documenting the change.
- **K71 chooses *Purchase date* on edit when none is stored** (#487,
  decided 2026-10-21). `PartForm::values()` gives `warranty_from`
  `purchase` when the stored start is null, as the new-part form does
  (#482). A purchase-date start with no bought-on date still runs from
  the first fitting (#427), so this changes only what a warranty added on
  edit is stored as, not when a stored warranty ends. Not chosen:
  leaving neither chip chosen, or always storing a start (a change to
  saving and to existing rows).
- **K39 shows the reminder only when it is on** (#547, decided
  2026-10-21). The warranty card on `part_show.twig` lists only the
  fields that are set; a "Reminder before it ends" line when the tick
  is on follows that. Not chosen: a Yes/No row whenever there is a
  warranty, or leaving the page as it is.
- **Rows:** none passed the batch checks; the owner decided #455, #459,
  #487 and #547 on 2026-10-21 so that K48, K57, K71 and K39 could be
  taken.
- **Release:** a patch on its own after 53.12's release (#548, decided
  2026-10-21).

---

## Tasks

### 53.13.0 Start of phase

- [x] Read `open-questions.md` and the *Open* and *Scheduled* rows of
      `known-issues.md` (CLAUDE.md §12). *2026-10-23:* #455, #459, #487,
      #547 and #548 were decided when the batch was planned, and spec
      §7.13 and §7.39 already carry them. Every question still *Needs a
      decision* is about a row listed under *Not in scope* or code this
      phase doesn't change; #484 (how a CSV number's separators are
      read, K1) is untouched, as K57 changes only dates and K48 only a
      cell's leading `'`. No open row besides K39, K48, K57 and K71 is in
      the code this phase changes (K19, K49 and K129 are performance
      rows, out of scope). Spec first: §13 *in progress*.

### 53.13.1 CSV import

- [x] `DateOrder::normalise()` reads a year-first date under every
      order (K57, #459). A `DateOrderTest`: `2026-04-06` under
      day-first and month-first gives `2026-04-06`; `06/04/2026` under
      day-first still gives 6 April and under month-first 4 June.
      `ImportTest`: a day-first fuel file with `2026-04-05T09:15:00`,
      `2026-04-06` and `2026-04-07 09:15` imports all three, the second
      at noon.
- [x] `CsvImporter` removes one leading `'` before a guarded character
      in every cell (K48, #455). `ImportTest`: export a fill-up with
      station `@x` and notes `-5 off`, import the file into another
      vehicle: station `@x`, notes `-5 off`, no station `'@x` in the
      station list; a cell `'abc` keeps its apostrophe. A round trip for
      each module's text columns in `CsvExportTest` or `ImportTest`.

### 53.13.2 Part warranty

- [x] `PartForm::values()` gives `warranty_from` `purchase` when none is
      stored (K71, #487). `PartsTest`: the edit form of a part with no
      warranty has *Purchase date* checked; adding 24 months there to a
      part fitted 2026-02-10 with no bought-on date stores *Purchase
      date*, as the same part added new does. *Built in
      `EditPartAction`, beside where #482 sets the new-part form's
      default in `CreatePartAction`: `values()` also fills the API's
      `PATCH`, which keeps storing what `POST` stores.*
- [x] `part_show.twig`: a "Reminder before it ends" line in the warranty
      card when `warrantyReminder` is on; the new string in every
      catalogue in `translations/` (K39, #547). `PartsTest`: the page
      shows it with the tick on and not with it off.

### 53.13.3 Finish

- [x] Known-issues rows K39, K48, K57 and K71 *Fixed*; #455, #459, #487
      and #547 marked built in `open-questions.md`.
- [x] CHANGELOG (K48's change to what an import stores in its *Fixed*
      section); spec §13; ROADMAP status. *Done:* the import guide
      (`docs/import.md`) and the parts guide (`docs/parts.md`) say so
      too.
- [x] Merge review (the import-tester among the agents), then merge.

### Release

- [x] `VERSION` 3.19.5, CHANGELOG 3.19.5, README status: the patch
      after 53.12's release (#548).
- [x] Tag `v3.19.5` once merged.

## Design review (2026-10-23)

The part page of the sample data's clutch kit (tick on) at 375 px light
and 1280 px dark: "Reminder before it ends" sits under the warranty's
fields and above its terms, in the card's body text, with nothing about
it on a part whose tick is off. Server-rendered, so it shows without JS;
no new links, so nothing changes at a subpath. The edit form's chip is
the form's own (#482), checked by `PartsTest`. No HIGH or MEDIUM items.

## Acceptance criteria

- K39, K48, K57 and K71 each have a regression test that fails without
  the fix.
- A fuel, odometer, maintenance, documents, expenses and trips export
  imports back with its text as written.
- `composer lint`, `composer analyse` and `bin/test-all-dbs.sh` pass on
  SQLite, PostgreSQL, MySQL and MariaDB. `MarkdownLinksTest` passes.
- No migration. The new string is in every catalogue in
  `translations/`.

## Open questions

- **#455. The export's formula guard on import** (K48). *Decided
  2026-10-21:* (1) every module's CSV import removes one leading `'`
  before `=`, `+`, `-`, `@`, tab or CR. Not chosen: (2) only when the
  header is Logbook's own export; (3) keep the text and document it.
  spec §7.13.
- **#459. Year-first dates under another date order** (K57). *Decided
  2026-10-21:* (1) a year-first date is read under every order, a date
  alone at noon. Not chosen: (2) refuse every value not in the chosen
  order; (3) leave it. spec §7.13.
- **#487. The warranty start chip on edit** (K71). *Decided
  2026-10-21:* (2) *Purchase date* chosen, as a new part's form. Not
  chosen: (1) neither chosen; (3) always store a start. spec §7.39.
- **#547. The warranty reminder on the part page** (K39). *Decided
  2026-10-21:* shown in the warranty card only when it is on. Not
  chosen: a Yes/No row whenever there is a warranty; not shown. spec
  §7.39.
- **#548. Release.** *Decided 2026-10-21:* released on its own as a
  patch, after Phase 53.12's release, like 52.1 (#481), 52.2 (#486),
  53.5 (#502), 53.6 (#503), 53.7 (#506), 53.9 (#527), 53.10 (#543),
  53.11 (#545) and 53.12 (#546). 53.10 (`ImportMapper`), 53.11 and
  53.12 (`part_show.twig`) change code near these rows, but they land
  and release first, so this batch builds on them. Not chosen: with the
  next phase that releases.
