# Phase 54.12 — The Windows architecture tests from the known-issues log + patch release

*One open row of the known-issues log partly closed, and one found already
fixed: three source-scan tests no longer fail on Windows, and K160 is marked
*Fixed*.*

Status: 📋 planned · releases **v3.21.9**, a patch release (#602) · file lives in `docs/phases/`

This is the batch after [Phase 54.11](phase-54.11.md). Only one open row of
the log is small and needs no decision: the three architecture tests of
K185. K160 is also recorded here, as already fixed. The batch is short on
purpose: the other open rows wait on a decision, an upstream release or an
API key (see *Not in scope*). It is numbered 54.12 because 54.11 is the last
row of the ROADMAP table.

Read [`CLAUDE.md`](../../CLAUDE.md), [`spec.md`](../../spec.md) §13 and
[`open-questions.md`](open-questions.md) first.

**Prerequisites:** [Phase 54.11](phase-54.11.md) merged, and `v3.21.8`
tagged.

---

## Goals

1. **K185 (the three source-scan tests).** `MailTransportTest` (both tests)
   and `PasswordHashingTest::testOnlyPasswordHasherHashesPasswords` pass on
   Windows as well as Linux. Today they fail on a clean checkout because the
   scan builds paths with `\`, so the allowed file (`src/Support/Security/PasswordHasher.php`,
   `src/Service/Mail/EmailServerAdmin.php`) never matches its own name and is
   reported as an offender.
2. **K160.** Recorded as *Fixed*: `symfony/mime` is 7.4.22 (K176) and
   `composer audit` no longer lists the advisory. No code change.

## Not in scope

- **K185's other two parts:** `AccessibilityTest::testSignedInPages` for
  Settings fails on Windows at the import-mapping step (the `form[method="get"]`
  lookup, `AccessibilityTest.php:238`), not on a Settings page as the row
  says; the cause is unknown. `MonthlyBriefingInsightsTest` hits the
  60-second limit on that machine. Both stay open: not small. The row's text
  is corrected in 54.12.2 and returns to *Open* for them.
- **K145** needs a recorded API key. **K174** waits on `firebase/php-jwt`
  supporting phpseclib 4. **K175:** PHPStan 2.3.5 still reports 29 errors
  (checked 2026-11-06).
- **K182** and **K183** wait on #597 and #598.

---

## Decisions (and why)

- **K185 changes the tests,** not the app. In each test's `phpFiles()` helper
  the relative path is normalised with `str_replace('\', '/', …)` before it
  is used as a key, as 54.11 did for `MarkdownLinksTest` (K184). The helper is
  duplicated in the two files and stays so: no new shared class for two
  lines. No decision.
- **A regression test is the tests themselves:** they fail on Windows today
  and pass after the change. A Linux run can't show that, so the Windows run
  is noted in the phase (as 54.11.4 did).
- **K160 is documentation only:** its fix is K176's lock change.
- **Release (#602):** on its own as v3.21.9. Test-only; nobody running the
  app notices.

## Tasks

### 54.12.0 Start of phase

- [ ] Read `open-questions.md` and the *Open* and *Scheduled* rows of
      `known-issues.md` (CLAUDE.md §12). #597 and #598 are open but belong to
      K182 and K183, which aren't in this phase.

### 54.12.1 The source-scan tests (K185)

- [ ] `tests/Unit/Architecture/MailTransportTest.php` and
      `PasswordHashingTest.php`: `phpFiles()` yields forward-slash relative
      paths. Run the three tests on Windows before and after and note both
      results here; they pass on Linux either way.

### 54.12.2 The log (K160, K185)

- [ ] `known-issues.md`: K160 *Fixed* (`symfony/mime` 7.4.22, K176;
      `composer audit` clean for it). K185's text says the Settings failure is
      really the import-mapping step, and its status returns to *Open* with
      only the Accessibility and time-limit parts left once 54.12.1 is done.

### 54.12.3 Finish

- [ ] CHANGELOG; spec §13; ROADMAP status.
- [ ] Merge review; merge.

### Release

- [ ] VERSION 3.21.9, CHANGELOG 3.21.9, README status (#602).
- [ ] Tag `v3.21.9` once merged.

## Acceptance criteria

- The three tests of K185 fail on Windows before the change and pass after
  it, and still pass on Linux.
- `composer lint`, `composer analyse` and `bin/test-all-dbs.sh` pass on
  SQLite, PostgreSQL, MySQL and MariaDB. `MarkdownLinksTest` passes.
- No migration. No new strings.

## Open questions

- **#602. Release. Decided 2026-11-06:** on its own as patch release
  v3.21.9. Not chosen: with the next releasing phase.
