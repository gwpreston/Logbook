# Phase 54.2 — CSV number separators and offline fill-ups per user from the known-issues log + minor release

*Two HIGH rows of the known-issues log closed: a CSV import that no longer
misreads `45,123` or `13.000`, and offline fill-ups that stay with the person
who made them.*

Status: ✅ complete · released as **v3.21.0**, a minor release · file lives in `docs/phases/`

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

1. **K1.** The import preview has a *Numbers* picker, like the date
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

- [x] `NumberFormat` enum (dot decimal, comma decimal) and
      `DecimalParser::parse()` taking it; the canonical form is no longer
      tried first when a format is chosen (`DecimalParserTest`).
- [x] `ImportOptions`, `ImportMapper::guess()` and the query
      (`number_format`) carry it; `CsvImporter` reads distance, volume and
      amount cells with it (`CsvImporterTest`: `45,123` and `13.000` under
      both formats, English and German owners).
- [x] Logbook's own export defaults to a decimal point, and thousands
      groups must be whole under a chosen format (#581; `ImportTest`: a
      German owner's own export reads back).
- [x] The preview's picker in `templates/import/map.twig`, translations in
      every catalogue; works without JS. Check at 375, 768 and 1280 px,
      light and dark.
- [x] Spec §7.13 says so. `docs/` import guide updated.

### 54.2.2 Offline fill-ups per user (K40)

- [x] The page carries the signed-in user's id (`<body data-user>`, and
      an `X-Logbook-User` header on personal (`no-store`) HTML pages); every
      queued item stores it (`userId`). The service worker, not `app.js`,
      remembers the user, from each page it fetches: a page served from
      its cache must not re-remember its own user, and a signed-out page
      forgets it (#580).
- [x] `sendQueued`/`flushOutbox` and the outbox list use only the current
      user's items, and send one only when the fetched form's header names
      its user; *Review* never shows another user's values. Items queued
      before the upgrade go to the first user signed in (#579).
- [x] `cacheOfflineForms` and `service-worker.js` cache a page for its
      user and serve it only to the same user; no remembered user gets the
      offline page.
- [x] A browser test or script (the repo's JS tests) covers two users on
      one browser, queued and cached (`tests/js/offline.test.js`, the real
      service worker at the root and at a subpath); `LogoutAction` and
      spec §7.15 describe the behaviour. Rebuild and commit the built
      assets.

### 54.2.3 Finish

- [x] Known-issues rows K1 and K40 *Fixed*.
- [x] CHANGELOG; spec §13; ROADMAP status.
- [x] Merge review; merge.

### Release

- [x] VERSION 3.21.0, CHANGELOG 3.21.0, README status (#575).
- [x] Tag `v3.21.0` once merged.

## Review notes

- Design: the *Numbers* select joins *How the file is written* as a
  second field after *Dates*, the same `field`/`select` markup, so the
  reading card wraps one more row (3 + 2 on the fill-up import). Checked in
  a browser at `/logbook` (a subpath) at 375 px light, 768 px dark and
  1280 px light: one column at 375 px with no sideways scroll, three at
  768 and 1280. The long option labels clip inside the select as *Dates*
  already does. The preview is a GET form and the commit carries
  `number_format` as a hidden field, so it works without JS.
- K40 in a real browser at `/logbook`: after visiting the fill-up forms
  signed in, the worker remembered user 1 and every kept page carried
  `X-Logbook-User: 1`; signing out landed on the sign-in page and left
  nobody remembered.

- Bug hunt (at `4c516492`): **HIGH**, fixed: a German or French
  owner's default *Decimal comma* couldn't read Logbook's own export
  (`48280.32` a problem row, `48280.320` read as 48280320); decided as
  #581 and built here (own export defaults to a decimal point; whole
  thousands groups under a chosen format). **MEDIUM**, fixed by the same
  change: a cost of `1.500` read as 1500 is still read so under *Decimal
  comma* (that is the format), but `0.500` is now a problem row. K40
  found sound.
- Security review (at `4c516492`): **HIGH**, fixed: with header sign-in,
  signing out (a POST answered with a page, or a redirect to the proxy)
  never showed the worker a signed-out page, so it kept the user; and the
  sign-out page named the departing user. Now `X-Logbook-User` is sent
  only on personal (`no-store`) pages whose session still holds the user,
  and any signed-out page other than the offline page clears the
  remembered user itself (`app.js`). **LOW**, fixed: the header was on
  `/manifest.webmanifest`, `/sw.js` and `/offline`, which shared caches
  may keep. Carried: K160 (`symfony/mime` advisory, on master) and K161
  (an OIDC provider that doesn't send the browser back).
- Follow-up to both reviews: the header is left off only what shared
  caches may keep (`public`, `no-cache`), the worker remembers a user only
  from an HTML page (a download or a picture names nobody), and only the
  sign-in and proxy signed-out pages clear it in the page (an error page
  or the offline page names nobody too). That review's fix covered the
  sign-out answered with a page, not the redirect to the proxy's logout
  URL; the issue sweep found that (K162). Own-export detection also needs
  no comma in any number cell, so a hand-made file with the export's
  column names and `45,123` keeps the decimal comma. Tests: `PwaTest` (all
  five offline form paths), `offline.test.js` (a download), `ImportTest`.
- Issue sweep (at `7c394378`: bug-hunter, security-scanner,
  import-tester, deploy-checker, design-reviewer, performance-auditor,
  spec-keeper, api-contract-checker): K162–K170 logged and #582 opened.
  Fixed here: **K162** (MEDIUM) and K161, the sign-out form forgets the
  user before it leaves, so a proxy or provider logout URL can't keep
  them remembered; **K165** (MEDIUM), the CHANGELOG's upgrade note for
  decimal-comma owners; **K166** (LOW), the worker no longer forgets the
  user on the offline page; **K167** (LOW), the header is only on HTML
  pages, not the API or MCP; **K170** (LOW), this file's wording. Left
  open: K163, K164, K168 (on master) and K169 (#582). Tests: `PwaTest`,
  `offline.test.js`.
- Checks (at the final commit): `bin/test-all-dbs.sh` green on SQLite,
  PostgreSQL, MySQL and MariaDB (4742 tests each, migrate, rollback and
  migrate); `node --test tests/js` 42 green; `composer lint` and
  `composer analyse` clean; line coverage 95.15% (floor 95%).

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
- **#579. Decided 2026-10-28** (found while starting it): a fill-up queued
  before the upgrade carries no user; the first user signed in after the
  upgrade takes it over and it sends as before (K40). Not chosen: never
  send it; list it with *Discard* only.
- **#580. Decided 2026-10-28** (found while starting it): a page loaded
  signed out forgets the remembered user, so no cached fill-up page is
  served until someone signs in; queued entries stay (K40). Not chosen:
  keep the last user until another signs in.
- **#581. Decided 2026-10-28** (found by the bug hunt while building it):
  Logbook's own export writes plain decimals, which a comma-decimal
  default can't read (`48280.32` a problem row, `48280.320` read as
  48280320). A file whose every matched column has its export header
  defaults to dot decimal; under a chosen format a thousands group must be
  whole (first group 1–3 digits, not a lone `0`), so `48280.320` and
  `0.500` are problem rows, never ×1000 (K1). Not chosen: always the
  locale's default; the export written in the owner's format.
- **#582. Decided 2026-10-31, built in [54.9](phase-54.9.md):** option 2, a
  new import message naming the cell, the format and an example. (Asked as
  follows. Found in review, issue sweep at
  `7c394378`): a number cell the chosen *Numbers* format can't read shows
  the form's "Enter a number, e.g. 1234.5.", whose example is in the
  owner's language format, not the chosen one (K169). Options: (1) keep
  the generic message, as Goal 1 says; (2) a new import message that names
  the *Numbers* setting and its choice; (3) the generic message with its
  example in the chosen format.)
