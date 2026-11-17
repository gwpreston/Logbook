# Phase 54.19 — Windows and the test runs from the known-issues log + patch release

*Four open rows of the known-issues log closed: a second local run of
every engine no longer fails on SQLite, the OCR tests pass on Windows,
Tesseract and Ghostscript start on a Windows server, and K185 is checked
and closed.*

Status: 📋 planned · releases **v3.21.15**, a patch release · file lives in `docs/phases/`

This is the batch after [Phase 54.18](phase-54.18.md), the last
known-issues batch. It takes K211, K212 and K213, all found by Phase
54.17's merge review, and closes K185, whose last part is probably
already fixed. K212 and K213 waited on #633 until the owner decided it on
2026-11-17 (make OCR and PDF rendering work on Windows). It is numbered
54.19 because 54.18 is the last row of the ROADMAP table and no phase is
active (54.18 is planned, not started).

Read [`CLAUDE.md`](../../CLAUDE.md), [`spec.md`](../../spec.md) §7.27
(reading files), §9 (*Reading files*, *Reading text from images*, *Test
suite only*) and §13, and [`open-questions.md`](open-questions.md) first.

**Prerequisites:** [Phase 54.18](phase-54.18.md) merged and released as
v3.21.14.

---

## Goals

1. **K211.** Running `bin/test-all-dbs.sh` twice in a row works: the
   second run's SQLite pass starts from a fresh file instead of stopping
   in the 2.0 migration's rollback with "This install has 7 users".
2. **K212.** On native Windows the OCR tests pass: `ScanOcrTest`,
   `ScanCrossCheckTest`, `RouterTest`, `ScanWithoutAiTest` and
   `OcrSettingsTest` run a Tesseract stand-in that Windows can start.
3. **K213.** On a bare-PHP Windows server, Logbook starts Tesseract and
   Ghostscript: OCR shows as working once `tesseract.exe` is on `PATH`,
   and a scanned PDF is rendered once `GHOSTSCRIPT_BINARY` names
   `gswin64c`. `docs/deployment.md` says how.
4. **K185.** A full native Windows run, on a checkout whose folders aren't
   read-only, passes with no 60-second stop, and K185 is closed with its
   causes recorded.

## Not in scope

- **K145** needs a recorded Anthropic request, so a real key. **K174**
  waits on `firebase/php-jwt` supporting phpseclib 4. **K201** (the
  test-run lock doesn't reach across Docker Desktop on macOS) has no known
  fix; Phase 54.17's K207 documents it.
- **K209** stays *Open*: the owner chose to leave it (#632).
- **Naming an unwritable folder** instead of a 500 when an upload can't be
  staged (what K185's read-only folders showed) is a separate choice, not
  asked here.
- No other row of the log is *Open*.

---

## Decisions (and why)

- **K211:** the script makes the SQLite pass start from nothing, as K155
  made the server engines do. Inside the run container, before
  `phinx migrate`, the `sqlite` pass removes `var/testing-all-dbs.sqlite`
  and its `-wal`, `-shm` and `-journal` files. The file is the script's
  own (K198), so nothing else is using it. No choice was needed.
- **K213 (#633):** option (1), make OCR and PDF rendering work on a
  bare-PHP Windows install. Spec §1 promises "an ordinary PHP web
  server" without naming an OS, and the fix is small. Not chosen:
  documenting Linux and Docker as the only hosts (2); only skipping the
  tests (3).
- **The null device (#635):** one small helper,
  `Support\Process\NullDevice::path()`, returns `NUL` when
  `PHP_OS_FAMILY` is `Windows` and `/dev/null` otherwise.
  `ProcessRunner::start()` (stdin) and `GhostscriptRenderer` (stdin,
  stdout and stderr) use it. Linux behaviour is unchanged. Not chosen:
  pipes instead of a device on every OS.
- **Ghostscript's name on Windows (#637):** `GHOSTSCRIPT_BINARY` keeps
  its default `gs` on every OS. `docs/deployment.md` gets a Windows note:
  put `tesseract.exe` on `PATH` (or set `TESSERACT_BINARY` to its full
  path), and set `GHOSTSCRIPT_BINARY=gswin64c`. Not chosen: defaulting to
  `gswin64c` on Windows.
- **K212's stand-in (#636):** the stand-in's logic moves into
  `tests/Fixtures/ocr/fake-tesseract.php`, behaving exactly as the `sh`
  script does today (`FAKE_TESSERACT_MODE` ok, sleep, fail, empty and
  broken; `FAKE_TESSERACT_TSV`; `FAKE_TESSERACT_ARGS` with
  `OMP_THREAD_LIMIT`). `fake-tesseract` stays as a one-line `sh` launcher
  (`exec php "$(dirname "$0")/fake-tesseract.php" "$@"`) and
  `fake-tesseract.cmd` is its Windows twin
  (`@php "%~dp0fake-tesseract.php" %*`). The eight test classes take
  their `FAKE` path from one shared constant that picks the launcher by
  OS. Not chosen: a second copy of the logic in a `.cmd` file.
- **K185 (#638):** after `attrib -R /S /D var` and a full native
  `composer test` on Windows with no 60-second stop, K185 becomes *Fixed
  (Phase 54.19)*, recording that the source-scan tests were fixed in
  54.12, the time limit by K210 in 54.17, and that the import-mapping
  failure was the checkout's read-only folders, not the app. If the
  60-second stop still happens, the timing is recorded and K185 stays
  *Open*. Not chosen: closing it as *Won't fix*.
- **Release (#634):** on its own as patch release v3.21.15, after 54.18.

## Tasks

### 54.19.0 Start of phase

- [ ] Read `open-questions.md` and the *Open* and *Scheduled* rows of
      `known-issues.md` (CLAUDE.md §12). #633 to #638 were decided when
      the phase was planned; check that none still open bears on these
      rows.

### 54.19.1 Every engine twice in a row (K211)

- [ ] `bin/test-all-dbs.sh`: the `sqlite` pass removes
      `var/testing-all-dbs.sqlite` and its `-wal`, `-shm` and `-journal`
      files inside the run container before migrating, with a comment
      naming K211 beside K155's.
- [ ] Regression test (`tests/Unit/Docker/TestAllDbsScriptTest`): the
      script's `sqlite` pass removes its file before `phinx migrate`
      (fails today).
- [ ] Checked by hand: two runs of `bin/test-all-dbs.sh sqlite` in a row
      both pass; the result goes in the phase file.

### 54.19.2 Tesseract and Ghostscript on Windows (K213)

- [ ] `Support\Process\NullDevice::path()` (#635); `ProcessRunner::start()`
      and `GhostscriptRenderer` use it in place of `/dev/null`.
- [ ] Unit test: `NullDevice::path()` is `NUL` on Windows and `/dev/null`
      elsewhere; `ProcessRunner` starts `php -v` and reads its output on
      the OS running the test (fails today on Windows).
- [ ] `docs/deployment.md`: the Windows note on `tesseract.exe` and
      `GHOSTSCRIPT_BINARY=gswin64c` (#637); spec §9 already says it.

### 54.19.3 The OCR tests on Windows (K212)

- [ ] `tests/Fixtures/ocr/fake-tesseract.php` with today's behaviour;
      `fake-tesseract` as its `sh` launcher; `fake-tesseract.cmd` as its
      Windows launcher (#636).
- [ ] One shared constant for the launcher's path, used by the eight
      classes that point at the stand-in (`RouterTest`,
      `ScanCrossCheckTest`, `ScanOcrTest`, `OcrReaderTest`,
      `OcrSettingsTest`, `ScanPageQueriesTest`, `ScanWithoutAiTest`,
      `TesseractEngineTest`).
- [ ] Checked: those classes pass natively on Windows and on Linux
      (`bin/test-all-dbs.sh`).

### 54.19.4 K185 checked

- [ ] On a Windows checkout with `attrib -R /S /D var` done, a full
      native `composer test`: record the result. With no 60-second stop,
      K185 *Fixed* as #638 says; otherwise the timing goes in its row and
      it stays *Open*.

### 54.19.5 Finish

- [ ] Known-issues rows K211, K212 and K213 *Fixed* (Phase 54.19); K185
      as 54.19.4 found; #633 and #635–#638 marked as built.
- [ ] CHANGELOG [Unreleased]; spec §13; ROADMAP status.
- [ ] Merge review; merge. (After the pull request; merge as a merge
      commit, not a squash.)

### Release

- [ ] VERSION 3.21.15, CHANGELOG 3.21.15, README status (#634).
- [ ] Tag `v3.21.15` once merged.

## Acceptance criteria

- K211 and K213 each have a regression test that fails without their fix;
  K212's classes pass natively on Windows and on Linux.
- `composer lint`, `composer analyse` and `bin/test-all-dbs.sh` pass on
  SQLite, PostgreSQL, MySQL and MariaDB; `composer coverage:check`
  passes.
- `MarkdownLinksTest` passes.
- No migration and no new setting. No new translated string.

## Open questions

- **#633 — Should OCR and Ghostscript rendering work on a bare-PHP install
  on Windows (K212, K213)?** *Decided 2026-11-17:* (1) yes: the
  platform's null device, and an OCR stand-in Windows can run. Not
  chosen: Linux and Docker only, documented (2); only skipping the tests
  (3).
- **#634 — Is Phase 54.19 released on its own (v3.21.15) or with the next
  releasing phase?** *Decided 2026-11-17:* on its own as patch release
  v3.21.15, after 54.18. Not chosen: with the next releasing phase.
- **#635 — How does Logbook pick the null device for the tools it starts
  (K213)?** *Decided 2026-11-17:* `NUL` on Windows, `/dev/null`
  elsewhere, from one helper. Not chosen: pipes instead of a device on
  every OS.
- **#636 — How do the OCR tests get a stand-in that runs on Windows
  (K212)?** *Decided 2026-11-17:* a PHP stand-in with an `sh` launcher
  and a `.cmd` launcher, picked by OS. Not chosen: a second copy of the
  logic in a `.cmd` file.
- **#637 — What does Logbook do about Ghostscript's `gswin64c` name on
  Windows (K213)?** *Decided 2026-11-17:* document it; the default stays
  `gs`. Not chosen: defaulting to `gswin64c` on Windows.
- **#638 — How is K185 closed once its last part is checked?** *Decided
  2026-11-17:* *Fixed (Phase 54.19)* with each part's cause recorded, or
  left *Open* with the timing if the 60-second stop still happens. Not
  chosen: *Won't fix*.
