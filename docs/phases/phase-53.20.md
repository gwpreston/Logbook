# Phase 53.20 — Page security headers and export times from the known-issues log + patch release

*Two open rows of the known-issues log closed, each after the owner
answered its question: every install sends the same page security headers
from the app (K54), and a CSV export carries the time with its UTC offset
so two fill-ups in the clock-change hour stay distinct on re-import
(K13).*

Status: ✅ complete · file lives in `docs/phases/` · releases **v3.19.12**, a
patch release

This is the next batch after [Phase 53.19](phase-53.19.md). It takes K54
and K13, which waited on #456 and on a question of their own (#561); the
owner answered both on 2026-10-27 while this batch was planned. It is
numbered 53.20 because no phase is active and 53.19 is the last row of the
ROADMAP.

Read [`CLAUDE.md`](../../CLAUDE.md) (§11, §12), `spec.md` (§7.13 *Import*
and the *CSV export* in §7, §9 and §10 for deployment), and
[`open-questions.md`](open-questions.md) first.

**Prerequisites:** [Phase 53.19](phase-53.19.md) merged, and
[v3.19.11](../../CHANGELOG.md) tagged.

---

## Goals

1. **K54.** Every page Logbook sends carries `X-Frame-Options: SAMEORIGIN`,
   `X-Content-Type-Options: nosniff` and `Referrer-Policy: same-origin`,
   on a bare-PHP install, `php -S` and the Docker image alike, so another
   site can't frame a signed-in page. A response that already sets one of
   these (a file download's `nosniff`, a sign-in link's
   `Referrer-Policy: no-referrer`) keeps its own value. The Docker vhost
   and the documented Apache and nginx configs no longer need to add them,
   and no response carries one twice. No page Content-Security-Policy.
2. **K13.** A CSV export writes each date and time with its UTC offset
   (`2026-10-25T01:30:00+01:00`), so the two 01:30 fill-ups on the night
   the clocks go back are different times. The importer reads the offset
   when there is one. A time with no offset (an older export, a file from
   another app) is read in the owner's time zone as before, except that a
   time that doesn't exist (02:30 on the night the clocks go forward) is
   refused with a row message, and one that happens twice is taken as the
   first and flagged with a warning on the preview.

## Not in scope

- A page Content-Security-Policy (options 2–4 of #456): the owner chose
  headers only. Alpine's CSP build and `script-src 'self'` would be a
  phase of their own.
- K1 and K40: each is a multi-file feature with its own phase (#484, #449).
- K114 (waits on #515) and K145 (needs an API key, not a fix).
- Fuelio's own `Data` column: the Fuelio import still reads local
  wall-clock `yyyy-MM-dd HH:mm`, since that is another app's format.

---

## Decisions (and why)

- **K54 (#456, decided 2026-10-27):** option 1. Sending the set the Docker
  image already adds from one middleware makes every install the same and
  fixes the clickjacking exposure without a CSP that the inline scripts
  would break. Not chosen: options 2–4, a page CSP.
- **K13 (#561, decided 2026-10-27):** option 1, an offset on every
  exported date and time, with the importer still reading offset-less
  files. A time with an offset is exact; the old format can't tell a
  repeated hour apart. Not chosen: a separate UTC column; leaving it and
  documenting the limit.
- **Release (#562, decided 2026-10-27):** on its own as a patch release,
  v3.19.12, since 53.19 takes v3.19.11. The export format change is worth
  a tagged release and an upgrade note.

---

## Tasks

### 53.20.0 Start of phase

- [x] Read `open-questions.md` and the *Open* and *Scheduled* rows of
      `known-issues.md` (CLAUDE.md §12). #456, #561 and #562 are decided;
      the other open rows (#374–#377, #537, #539 …) are not touched here.

### 53.20.1 Page security headers (K54)

- [x] A middleware in `src/Middleware/` (registered in
      `config/middleware.php`) adds the three headers to every response
      that doesn't set them. Remove the duplicates the Docker vhost
      (`docker/apache/logbook.conf`) adds, and update
      `docs/deployment.md` and spec §10 to say the app sends them.
- [x] Tests: a page, a redirect, an error page and the API each carry the
      three headers once; a download keeps its own `nosniff`, and a
      sign-in link keeps `Referrer-Policy: no-referrer`.

### 53.20.2 Export times with an offset (K13)

- [x] `CsvExporter::localDateTime()` writes ISO 8601 with the owner's
      offset. The CSV importer reads an offset when present and otherwise
      the owner's time zone; a nonexistent local time is refused with a
      row message and a repeated one is taken as the first with a preview
      warning. Spec §7 (*CSV export* and *Import*) and `docs/` say so.
- [x] Tests: two fill-ups in the clock-change hour export with different
      offsets and import as two rows; a file without offsets still
      imports; the nonexistent and repeated local times behave as
      described, on all four engines.
- [x] API contract: the REST API and webhooks are unchanged; only the
      exports' time columns change (run api-contract-checker).

### 53.20.3 Finish

- [x] Known-issues rows K54 and K13 *Fixed*; open questions #456, #561 and
      #562 marked built.
- [x] CHANGELOG (with an upgrade note on the export's new time format);
      spec §13; ROADMAP status.
- [ ] Merge review (spec-keeper, security-scanner, bug-hunter,
      import-tester, deploy-checker), then merge.

### Release

- [x] `VERSION`, CHANGELOG, README status.
- [ ] Tag `v3.19.12` once merged.

## Acceptance criteria

- K54 and K13 each have a regression test that fails without its fix.
- `composer lint`, `composer analyse` and `bin/test-all-dbs.sh` pass on
  all four engines.
- `MarkdownLinksTest` passes.
- No migration; new strings (the preview warning and the row message) are
  in every catalogue in `translations/`.

## Open questions

- **#456. Decided 2026-10-27:** (1) the app sends `X-Frame-Options
  SAMEORIGIN`, `nosniff` and `Referrer-Policy same-origin` on every
  install, and no page CSP. Not chosen: (2) that set plus a light CSP;
  (3) a full CSP with Alpine's CSP build; (4) a full CSP with
  `'unsafe-eval'`.
- **#561. Decided 2026-10-27:** (1) exported dates and times carry a UTC
  offset, and the importer still reads offset-less files. Not chosen: (2)
  a separate UTC column; (3) leave it and document the limit.
- **#562. Decided 2026-10-27:** (1) ship on its own as a patch release,
  v3.19.12. Not chosen: (2) with the next releasing phase.
