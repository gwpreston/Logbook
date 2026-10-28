# Phase 54.2 — CSV number separators and offline fill-ups per user from the known-issues log + minor release

*Two HIGH rows of the known-issues log closed: a CSV import that no longer
misreads `45,123` or `13.000`, and offline fill-ups that stay with the person
who made them.*

Status: 🚧 in progress · releases **v3.21.0**, a minor release · file lives in `docs/phases/`

This is the batch after [Phase 54.1](phase-54.1.md). The owner asked for
the open rows that wait on no decision, and chose K1 and K40: each was
decided as a phase of its own (#484, #449) and neither is a quick win.
The other open rows were left out (below). It is numbered 54.2 because it
follows 54.1, the last row of the roadmap.

Read [`CLAUDE.md`](../../CLAUDE.md), [`spec.md`](../../spec.md) §7.13
(*Import*), §7.15 (*Installable app*), §7.9 (*Sign-out*), and
[`open-questions.md`](open-questions.md) first.

**Prerequisites:** [Phase 54.1](phase-54.1.md) merged, and `v3.20.1` tagged.

---

## Goals

### Import

1. **K1.** The import preview has a *Number format* picker, like the date
   order picker, defaulting to the owner's locale. Under *1,234.56* the
   cell `45,123` is 45123 and `13.000` is 13; under *1.234,56* they are
   45.123 and 13000. The choice is kept in the preview's address and
   applies to the import. A cell the chosen format can't read is invalid
   with the same message as before, not silently read another way.

### Offline

2. **K40.** A fill-up queued offline is sent only by the user who queued
   it, and a cached fill-up page is shown only to the user it was cached
   for. A second person on the same browser never sends, sees or reviews
   the first person's queued entries, notes or station, nor sees their
   name, vehicle, registration or odometer on a cached page.

## Not in scope

- **K145**: needs an API key to record a request, not a fix.
- **K149–K151**: performance rows that need profiling first and #570.
- **K152** (#568) and **K154** (#569): wait on their questions.
- Number formats for the Fuelio and other app imports, which keep the
  format of the app that wrote them (#573).

---

## Decisions (and why)

- **K1 follows #484** (option 1): a picker, not detection or a warning.
  Two formats (#573): dot decimal (`1,234.56`) and comma decimal
  (`1.234,56`). A space as thousands separator is read in both. The
  default is the owner's locale, as the date order's is, so a file that
  is right for the owner imports as before.
- **K40 follows #449** (option 1): tag, don't clear. The browser keeps the
  signed-in user's id locally at sign-in (#574); each queued item and each
  cached page carries its user's id, and one is sent or shown only when it
  matches. Nothing is deleted at sign-out, so an unsent entry survives an
  expired session. A page cached for another user, or with nobody
  remembered, is not served; the offline page is.
- **Both rows are web and import only.** No migration, no API change.

---

## Tasks

### 54.2.0 Start of phase

- [x] Read `open-questions.md` and the *Open* and *Scheduled* rows of
      `known-issues.md` (CLAUDE.md §12). #573, #574 and #575 are decided.
      None still open bears on K1 or K40 (log intro, 2026-10-28).

### 54.2.1 Number format on import (K1)

- [ ] `NumberFormat` enum (dot decimal, comma decimal) and
      `DecimalParser::parse()` taking it; the canonical form is no longer
      tried first when a format is chosen (`DecimalParserTest`).
- [ ] `ImportOptions`, `ImportMapper::guess()` and the query
      (`number_format`) carry it; `CsvImporter` reads distance, volume and
      amount cells with it (`CsvImporterTest`: `45,123` and `13.000` under
      both formats, English and German owners).
- [ ] The preview's picker in `templates/import/map.twig`, translations in
      every catalogue; works without JS. Check at 375, 768 and 1280 px,
      light and dark.
- [ ] Spec §7.13 says so. `docs/` import guide updated.

### 54.2.2 Offline fill-ups per user (K40)

- [ ] The page carries the signed-in user's id; `app.js` remembers it at
      sign-in and every queued item stores it (`userId`).
- [ ] `sendQueued`/`flushOutbox` and the outbox list use only the current
      user's items; *Review* never shows another user's values.
- [ ] `cacheOfflineForms` and `service-worker.js` cache a page for its
      user and serve it only to the same user; no remembered user gets the
      offline page.
- [ ] A browser test or script (the repo's JS tests) covers two users on
      one browser, queued and cached; `LogoutAction` and spec §7.15
      describe the behaviour. Rebuild and commit the built assets.

### 54.2.3 Finish

- [ ] Known-issues rows K1 and K40 *Fixed*.
- [ ] CHANGELOG; spec §13; ROADMAP status.
- [ ] Merge review; merge.

### Release

- [ ] VERSION 3.21.0, CHANGELOG 3.21.0, README status (#575).
- [ ] Tag `v3.21.0` once merged.

## Acceptance criteria

- Each row has regression tests that fail without its fix.
- `composer lint`, `composer analyse` and `bin/test-all-dbs.sh` pass on
  SQLite, PostgreSQL, MySQL and MariaDB. `MarkdownLinksTest` passes.
- No migration. New strings are in every catalogue in `translations/`.
- Works at a subpath and with JS off for the import preview.

## Open questions

- **#573. Decided 2026-10-28:** the picker offers two formats, dot
  decimal and comma decimal (K1). Not chosen: a third, space-grouped
  format. App imports keep their own formats.
- **#574. Decided 2026-10-28:** the browser remembers the last signed-in
  user; queued items and cached pages carry their user and are sent or
  shown only to a match (K40). Not chosen: also clearing at sign-out.
- **#575. Release. Decided 2026-10-28:** on its own as minor release
  v3.21.0. Not chosen: a patch release, or with the next releasing phase.
