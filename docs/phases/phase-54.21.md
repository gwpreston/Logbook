# Phase 54.21 — The native Windows test run from the known-issues log + patch release

*Six open rows of the known-issues log closed: the signing-key tests make
their keys without the server's `openssl.cnf`, the test-bootstrap, session
secret, scheduler and rollback tests pass on Windows, and an empty locale
no longer reads as the server's own region.*

Status: 🚧 in progress · releases the next patch release (**v3.21.16** if it ships before 54.20) · file lives in `docs/phases/`

This is the batch after [Phase 54.19](phase-54.19.md), the last
known-issues batch. It takes K220 to K225, all found by Phase 54.19's
native Windows run, and the only *Open* rows that need no decision
first. It is numbered 54.21, not 54.20, because 54.20 is already promised
to the same-name labels work (K214, K215, K217, K218 and K219, decided
2026-11-18 with #640 and #641) though its file isn't written yet; 54.19 is
the last row of the ROADMAP table and no phase is active.

Read [`CLAUDE.md`](../../CLAUDE.md), [`spec.md`](../../spec.md) §7.14
(internationalisation), §9 (*Test suite only*) and §13, and
[`open-questions.md`](open-questions.md) first.

**Prerequisites:** [Phase 54.19](phase-54.19.md) merged and released as
v3.21.15. It touches no code that 54.20 changes, so it need not wait
for 54.20.

---

## Goals

1. **K220.** The 42 tests that make a signing key (`TokenValidatorTest`,
   `SingleSignOnTest`, `HeaderSignInTest::testAnRs256JwtIsRefused`) pass
   on a machine whose PHP finds no `openssl.cnf`, native Windows included.
2. **K221.** `TestBootstrapTest` passes on native Windows: it starts the
   refused bootstrap with its variables set the way every OS understands.
3. **K222.** `SessionSecretFileTest` passes on native Windows: the
   owner-only `0600` check runs only where the OS has those mode bits.
4. **K223.** An empty or blank locale has no region on every server, as
   `Region::of()` says: a server whose default locale is `en_GB` no
   longer gives it the UK plate, MOT rules, gallon, tax year or mileage
   rates.
5. **K224.** `JobsPagesTest` expects the cron line the page shows, quoted
   when the install's folder needs it (a Windows path, a folder with a
   space).
6. **K225.** The refused-rollback test reads phinx's message whole,
   however wide the console is.

## Not in scope

- **K145** needs a recorded Anthropic request, so a real key. **K174**
  still waits on `firebase/php-jwt` supporting phpseclib 4 (its
  `JWT.php` still loads `\phpseclib3\Crypt\RSA`). **K201** (the
  test-run lock doesn't reach across Docker Desktop on macOS) has no known
  fix; Phase 54.17's K207 documents it.
- **K209** stays *Open*: the owner chose to leave it (#632).
- **K214, K215, K217, K218 and K219** are *Scheduled* for Phase 54.20.
- No other row of the log is *Open*.

---

## Decisions (and why)

- **K220 (#644):** the tests bring their own OpenSSL configuration. A
  minimal `tests/Fixtures/openssl.cnf` (a `[ req ]` section naming an
  empty distinguished-name section) is committed, and every key the tests
  make passes it as `'config'` to `openssl_pkey_new()`, on every OS:
  `FakeIdentityProvider` `rsa()` and `ec()`, and `HeaderSignInTest`'s RSA
  key. Checked on macOS while planning: with `OPENSSL_CONF=/nonexistent`
  a bare `openssl_pkey_new()` returns false, and with the minimal file
  passed it makes both an RSA and an EC key with no PHP warning. OpenSSL
  still queues its own start-up errors about the missing file, so the
  shared helper empties `openssl_error_string()` after making a key. The
  path comes from one place (a helper in `tests/Support`), so a new key
  site can't forget it. The app itself makes no keys (phpseclib's
  `createKey()` is the only key-making code in its dependencies, and
  nothing calls it), so `src/` is unchanged. Not chosen: passing the file only when `OPENSSL_CONF` is
  unset or unreadable (2); a `CONTRIBUTING.md` note telling Windows
  contributors to set `OPENSSL_CONF` (3).
- **K221:** `TestBootstrapTest` starts PHP with `proc_open()` and an
  argument array (no shell), passing an environment of `getenv()` with
  `APP_ENV=development`, `DB_DRIVER=sqlite` and `DB_NAME` laid over it.
  The whole environment is passed, not only the three, because a bare
  array replaces it, and on Windows PHP won't start without `SystemRoot`.
  The test reads the exit code and the combined output as it does today.
  No choice was needed.
- **K222:** `SessionSecretFileTest` keeps every check but the file's mode,
  which it makes only when `PHP_OS_FAMILY` isn't `Windows` (with a comment
  naming K222: Windows has no owner-only mode bits, and the file's ACL is
  what protects it there). The rest of the test runs on every OS. No
  choice was needed.
- **K223 (#645):** `Region::of()` returns null when the locale is empty
  or only spaces (`trim($locale) === ''`), before asking ICU, so it no
  longer falls back to the server's default locale. A locale with a
  region (`en_GB`) or without one (`en`, `de`) reads as today. That fixes
  every caller at once: plate style, inspection rules, the gallon
  default, the tax year, mileage rates and station countries. spec §7.14
  says it. Not chosen: null only for exactly `''`, so a blank locale still
  reaches ICU (2).
- **K224 (#646):** `JobsPagesTest` takes the line from
  `SchedulerHealth::cronLine()` in the app's container, HTML-escapes it,
  and asserts the page shows it. The page's quoting is right, so the test
  stops building its own copy. A new unit test,
  `tests/Unit/Service/Jobs/SchedulerHealthTest`, pins the quoting rule:
  a plain path (`/var/www/logbook`) stays bare, while one with a space
  (`/Users/me/My Logbook`) and a Windows path (`C:\Logbook`) are quoted
  with `escapeshellarg()`. Not chosen: copying the rule into the test (2).
- **K225 (#647):** `tests/Support/Migrator::run()` sets `COLUMNS` to a
  wide value (`1000`) while phinx runs and puts back what was there
  before (unset if it was unset), so no phinx message in the tests is
  wrapped at the console's width. phinx runs in-process with a
  `BufferedOutput`, and Symfony Console reads the width from `COLUMNS`
  first, so this works on every OS. The row's "for the child process" is
  wrong: there is no child. Not chosen: removing whitespace in this one
  assertion, which leaves every other phinx message open to the same
  wrap (2).
- **Release (#643):** on its own as the next patch release (v3.21.16 if
  it ships before 54.20, which would otherwise take it). Not chosen: with
  the next releasing phase.

## Tasks

### 54.21.0 Start of phase

- [x] Read `open-questions.md` and the *Open* and *Scheduled* rows of
      `known-issues.md` (CLAUDE.md §12). #643 to #647 were decided when
      the phase was planned; check that none still open bears on these
      rows, and whether 54.20 has shipped (it decides this release's
      number). *Done 2026-11-19:* none of those still open (#221, #390,
      #391, #460, #462, #537–#542, #551) bears on K220 to K225. The
      *Open* rows are K145, K174, K201 and K209, each out of scope as
      above. 54.20 has no file or ROADMAP row yet, so this release is
      **v3.21.16**.

### 54.21.1 Signing keys without the server's openssl.cnf (K220)

- [x] `tests/Fixtures/openssl.cnf` and one shared path for it in
      `tests/Support` (#644); `FakeIdentityProvider::rsa()`, `::ec()` and
      `HeaderSignInTest`'s key pass it as `'config'`. The helper is
      `Logbook\Tests\Support\TestKeys` (`rsa()`, `ec()`, `CONFIG`).
- [x] Regression test: a child PHP started with
      `OPENSSL_CONF=/nonexistent` makes an RSA and an EC key through the
      shared helper (fails today: `openssl_pkey_new()` returns false, as
      it does on macOS with that variable set). `TestKeysTest`; without
      `'config'` it fails with "openssl could not make an RSA key".

### 54.21.2 An empty locale has no region (K223)

- [x] `Support\I18n\Region::of()`: null when `trim($locale) === ''`
      (#645), with the docblock naming K223.
- [x] Regression test (`tests/Unit/Support/I18n/RegionTest`): with
      `Locale::setDefault('en_GB')`, `Region::of('')` and `Region::of('  ')`
      are null and `PlateStyle::forLocale('')` is `Neutral`; `en_GB`
      still gives `GB` and `en` null; the default is put back after (fails
      today on any OS). Without the fix it fails: "Failed asserting that
      'GB' is null".
- [x] spec §7.14 already says it (#645); CHANGELOG under *Fixed*.

### 54.21.3 The tests that fail on native Windows (K221, K222, K224, K225)

- [x] `TestBootstrapTest`: `proc_open()` with an argument array and
      `getenv()` plus the three variables, no shell (K221).
- [x] `SessionSecretFileTest`: the `0600` check skipped on Windows only
      (K222).
- [x] `JobsPagesTest`: expect `SchedulerHealth::cronLine()`, HTML-escaped
      (#646); new `SchedulerHealthTest` for a plain path, one with a
      space and a `C:\` path (K224).
- [x] `tests/Support/Migrator::run()`: `COLUMNS=1000` while phinx runs,
      restored after (#647). Regression test: with `COLUMNS=40` set first,
      the refused rollback's message still contains
      `bin/export-user.php` (fails today: `COLUMNS=40 vendor/bin/phpunit
      --filter testRollingBackWorksWithOneUserAndIsRefusedWithTwo` fails
      on macOS) (K225). The regression test is
      `UsersAndSharingMigrationTest::testTheRefusalIsReadWholeOnANarrowConsole`;
      without the fix it fails with the message split over lines.

### 54.21.4 Checked on native Windows

- [ ] A native `composer test` on Windows (PHP 8.5 from WinGet, as 54.19's
      run, with `OPENSSL_CONF` unset): the 47 tests named in K220 to K225
      pass. Record the result in this file; any test still failing goes
      in its row, which stays *Open*.

### 54.21.5 Finish

- [ ] Known-issues rows K220 to K225 *Fixed* (Phase 54.21); #644 to #647
      marked as built.
- [ ] CHANGELOG [Unreleased]; spec §13; ROADMAP status.
- [ ] Merge review; merge. (After the pull request; merge as a merge
      commit, not a squash.)

### Release

- [ ] VERSION, CHANGELOG and README status for the next patch release
      (#643): 3.21.16 unless 54.20 has taken it.
- [ ] Tag the release once merged.

## Acceptance criteria

- K220, K223 and K225 each have a regression test that fails without
  their fix on any OS; K224 has `SchedulerHealthTest`; K221 and K222 pass
  in the native Windows run of 54.21.4.
- `composer lint`, `composer analyse` and `bin/test-all-dbs.sh` pass on
  SQLite, PostgreSQL, MySQL and MariaDB; `composer coverage:check`
  passes.
- `MarkdownLinksTest` passes.
- No migration and no new setting. No new translated string.

## Open questions

- **#643 — Is Phase 54.21 released on its own or with the next releasing
  phase?** *Decided 2026-11-19:* on its own as the next patch release
  (v3.21.16 if it ships before 54.20). Not chosen: with the next
  releasing phase.
- **#644 — How do the tests make signing keys when PHP finds no
  `openssl.cnf` (K220)?** *Decided 2026-11-19:* (1) a minimal
  `tests/Fixtures/openssl.cnf`, passed as `'config'` at every key site on
  every OS, from one shared path. Not chosen: passing it only when
  `OPENSSL_CONF` is unset or unreadable (2); a `CONTRIBUTING.md` note
  only (3).
- **#645 — What does `Region::of()` give for an empty locale (K223)?**
  *Decided 2026-11-19:* (1) null for an empty or blank locale, before
  ICU is asked, whatever the server's default locale. Not chosen: null
  for exactly `''` only (2).
- **#646 — How does `JobsPagesTest` work out the cron line it expects
  (K224)?** *Decided 2026-11-19:* (1) from `SchedulerHealth::cronLine()`,
  HTML-escaped, with a new `SchedulerHealthTest` pinning the quoting.
  Not chosen: copying the quoting rule into the test (2).
- **#647 — How does the rollback test stop phinx's message wrapping at
  the console's width (K225)?** *Decided 2026-11-19:* (1)
  `Migrator::run()` sets a wide `COLUMNS` while phinx runs and restores
  it. Not chosen: removing whitespace in that one assertion (2).
