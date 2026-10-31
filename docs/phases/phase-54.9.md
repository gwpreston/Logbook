# Phase 54.9 — The import number message and merge-review records from the known-issues log + patch release

*Two open rows of the known-issues log closed: an unreadable import number
says which format it was read in, and the missing merge-review records are
settled.*

Status: 📋 planned · releases **v3.21.7**, a patch release (#593) · file lives in `docs/phases/`

This is the batch after [Phase 54.8](phase-54.8.md). It takes K169, which
waited on #582 until the owner decided it, and K172, a docs-only row. It is
numbered 54.9 because it follows 54.8, the last row of the roadmap.

Read [`CLAUDE.md`](../../CLAUDE.md), [`spec.md`](../../spec.md) §7.13 (the
import's *Numbers* choice), `.claude/skills/release/SKILL.md` and
[`open-questions.md`](open-questions.md) first.

**Prerequisites:** [Phase 54.8](phase-54.8.md) merged, and `v3.21.6` tagged.

---

## Goals

1. **K169.** An import number cell the chosen *Numbers* format can't read
   shows "“65.83” isn't a number in the Decimal comma format chosen for this
   import. Write it like 1.234,56." instead of the form's "Enter a number,
   e.g. 1234.5.", in English and German. It applies to every number field
   the importer reads (distances, volumes, unit prices, plain numbers).
2. **K172.** Phases 53.16–53.20 and 54.4 each say, on their *Merge review*
   box, that the phase was merged and released without a recorded merge
   review, and the release skill's check names that box.

## Not in scope

- Running merge reviews now on the released phases (#595 chose to record
  honestly).
- **K145** needs an API key; **K160** waits on an upstream `symfony/mime`
  release.

---

## Decisions (and why)

- **K169 follows #582** (option 2): a message of the import's own, because
  the form's example is in the owner's language format, not the chosen one,
  and says nothing of the setting. #594: the text is
  "“{value}” isn't a number in the {format} format chosen for this import.
  Write it like {example}." with `{value}` the cell as typed, `{format}` the
  short name ("Decimal point" / "Decimal comma", "Dezimalpunkt" /
  "Dezimalkomma") and `{example}` `1,234.56` or `1.234,56`. German: „{value}“
  ist in dem für diesen Import gewählten Format „{format}“ keine Zahl.
  Schreibe es etwa so: {example}. A row's other fields keep their messages;
  an empty cell is unchanged. Spec §7.13 says so (written in the planning
  commit).
- **K172 follows #595** (option 1): no review ran, so none is invented; the
  boxes are ticked with a dated note saying so, which is true and lets the
  release check pass. The check in the release skill then names the box, so a
  phase with an unticked *Merge review* stops the release.

## Tasks

### 54.9.0 Start of phase

- [ ] Read `open-questions.md` and the *Open* and *Scheduled* rows of
      `known-issues.md` (CLAUDE.md §12). #582 and #593–#595 are decided.

### 54.9.1 The import number message (K169)

- [ ] `CsvImporter` (`src/Service/Import/CsvImporter.php`, around the
      `UNREADABLE` cases for `Distance`, `Volume`, `UnitPrice` and `Number`)
      adds an error `import.error.number` with `value`, `format` and
      `example` instead of letting the form answer; the generic form message
      no longer shows for these cells. New keys `import.error.number` and
      `import.number_format_name.dot` / `.comma` in
      `translations/messages+intl-icu.en.php` and `.de.php`.
      `ImportTest` (the case at `tests/Integration/Http/ImportTest.php:787`)
      checks the English text under *Decimal comma*, the German text under
      *Decimal point*, that a good cell is unchanged, and fails without the
      fix.

### 54.9.2 The merge-review records (K172)

- [ ] The *Merge review* box of `phase-53.16.md`, `53.17`, `53.18`, `53.19`,
      `53.20` and `54.4` is ticked with "(2026-10-31: merged and released
      without a recorded merge review, #595)". `.claude/skills/release/SKILL.md`
      (its *Stop* list) names the *Merge review* box.

### 54.9.3 Finish

- [ ] Known-issues rows K169 and K172 *Fixed*.
- [ ] CHANGELOG; spec §13; ROADMAP status.
- [ ] Merge review; merge.

### Release

- [ ] VERSION 3.21.7, CHANGELOG 3.21.7, README status (#593).
- [ ] Tag `v3.21.7` once merged.

## Acceptance criteria

- K169 has a regression test that fails without its fix; K172's boxes are
  ticked with the note.
- `composer lint`, `composer analyse` and `bin/test-all-dbs.sh` pass on
  SQLite, PostgreSQL, MySQL and MariaDB. `MarkdownLinksTest` passes.
- No migration. New strings are in every catalogue in `translations/`.

## Open questions

- **#593. Release. Decided 2026-10-31:** on its own as patch release
  v3.21.7, after 54.8. Not chosen: with the next releasing phase.
- **#594. Decided 2026-10-31:** the message shows the format's name and an
  example in it, translated in every catalogue. Not chosen: name the format
  only.
- **#595. Decided 2026-10-31:** record honestly. Not chosen: run the reviews
  now; Won't fix.
