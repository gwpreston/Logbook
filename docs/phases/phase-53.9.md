# Phase 53.9 — Install, dev-stack and test-run edges from the known-issues log + patch release

*Five open rows of the known-issues log closed: a bare-PHP install
without mbstring is refused by Composer instead of failing later, two
smoke tests can run at once, the suite passes in the dev container, the
dev stack picks up a changed `composer.lock`, and the import command
line says so when there is no user yet.*

Status: 📋 planned · file lives in `docs/phases/` · releases the patch
after Phase 53.8's release, a patch release

This is the next batch after [Phase 53.7](phase-53.7.md). Like 52.1,
52.2, 53.5, 53.6 and 53.7, it takes a few small open rows: K123 (HIGH),
K121, K122 and K128 (LOW), all about installing Logbook or running its
checks, and K127 (LOW, the app import command line), which the owner
added when the batch was planned. K123 needed a choice, which the owner
made then (#528). It is numbered 53.9 because Phase 53.6 is in progress
(its release not yet tagged) and 53.7 and 53.8 already follow it, so no
free number sorts straight after it: the batch is the next phase not yet
defined, after 53.8 in the ROADMAP.

Read [`CLAUDE.md`](../../CLAUDE.md) (§4 *Commands*, §11),
[`spec.md`](../../spec.md) §7.13 (*Import*: the app import command
line), §9 (`SESSION_SECRET_FILE`), §10 (*Bare PHP 8.4*), §11 (the
production image's smoke test),
[`CONTRIBUTING.md`](../../CONTRIBUTING.md) (the dev stack and the smoke
test), and [`open-questions.md`](open-questions.md) first.

**Prerequisites:** [Phase 53.8](phase-53.8.md) merged, and its release
tagged.

---

## Goals

1. **K123.** `composer install` on a PHP without the mbstring extension
   stops and names `ext-mbstring`, as it does for `gd` and `exif`; the
   spec's bare-PHP list and `docs/deployment.md` name it. No install
   gets as far as "Call to undefined function mb_strcut()" (#528).
2. **K121.** `bin/smoke-test.sh` runs its stack under
   `COMPOSE_PROJECT_NAME` when that is set (`logbook-smoke` otherwise),
   so two smoke runs with different names, two review agents or a
   developer beside one, never tear down each other's containers and
   volumes.
3. **K122.** The four tests that expect no session secret pass in the
   dev stack's `app` container, where `SESSION_SECRET_FILE` is set, as
   they do in CI.
4. **K128.** After a pull that changes `composer.lock`, starting the dev
   stack brings `vendor/` in line with the lock; nobody has to know to
   run `composer install` or `bin/dev-setup.sh --stop --reset`.
5. **K127.** `php bin/import-app.php <export>` on an install with no
   users yet says there is no user and to finish first-run setup, for
   Tracktor and Fuelio both, instead of asking for `--as`.

## Not in scope

- Rows that wait on an open question: K1 (#484), K3 (#485), K29 (#448),
  K39, K40 (#449), K42 (#450), K43 (#451), K44 (#452), K45 (#461), K47
  and K59 (#454), K48 (#455), K54 (#456), K55 (#457), K56 (#458), K57
  (#459), K67 (#483), K71 (#487), K72 (#488).
- K124 (CSV header unit words other than Logbook's own): which words
  count, and in which languages, is a choice; a candidate once it is
  asked. K129 (a CSV import's peak memory): a streaming reader, not a
  one-place fix.
- The Scan rows from 53.3's review not given to 53.8 (K105, K106, K107,
  K111–K113, K115–K120): Phase 53.8 changes the V5C and cross-check
  code they sit in, K106 and K107 each need the owner's call, and K113
  needs measuring first.
- K79 (the optional PaddleOCR image's install and user): Docker
  hardening across the image and compose file; a batch of its own.
- The rows 53.5, 53.6 and 53.7 left out (K13, K16, K19, K28, K35, K41,
  K49, K51, K63, K74, K85, K89, K90, K95–K98) for the reasons they give.

---

## Decisions (and why)

- **K123 requires the extension** (#528). Four `src/` classes and
  `bin/ai-eval.php` call `mb_strcut()` or `mb_strimwidth()`, which
  `symfony/polyfill-mbstring` doesn't provide, so an install without
  mbstring fails at its first AI request, Ask thread, capped job output
  or scanned amount. `ext-mbstring` joins `ext-intl`, `ext-gd` and the
  others in `composer.json`'s `require`, so Composer refuses the
  install up front, as spec §10 already says it does for `gd` and
  `exif`; spec §10's bare-PHP list names it (updated when the batch was
  planned) and `docs/deployment.md`'s requirements follow. The Docker
  image already has it. Not chosen: replacing the calls with UTF-8-safe
  trimming of our own.
- **K121 honours the name the caller gives.** The script hard-codes
  `-p logbook-smoke`, which overrides `COMPOSE_PROJECT_NAME`, while
  `.claude/review-rules.md` §2 asks each review agent for its own
  `review-<agent>` project. Taking the name from `COMPOSE_PROJECT_NAME`
  with `logbook-smoke` as the default keeps CI and every documented
  command as they are.
- **K122 clears both ways of giving a secret.** Spec §9's
  `SESSION_SECRET_FILE` (#222) is read when `SESSION_SECRET` is empty,
  so a test that means "no secret" has to clear both. The test app
  factory (`AppTestCase::createApp()` and the helpers built on it) sets
  `SESSION_SECRET_FILE` empty unless a test gives it, as
  `NotificationChannelsMigrationTest` already does by hand; the tests
  of the file itself (`SessionSecretFileTest`) still pass it.
- **K128 installs when the lock has changed, in development only.**
  `docker/entrypoint.sh` installs only when `vendor/autoload.php` is
  missing. It also runs `composer install` when `composer.lock` differs
  from what `vendor/` was installed from (the lock's `content-hash`
  against a copy kept in `vendor/`), still only with
  `APP_ENV=development` and Composer present, so the production image
  is unchanged.
- **K127 says what is missing.** `TracktorCommand::user()` and
  `FuelioCommand::user()` treat "not exactly one user" as "more than
  one". With none, they print that the install has no user yet and to
  finish first-run setup in the browser, and exit 1 before reading the
  file, as spec §7.13's command line stops on other problems.
- **Release:** a patch on its own after 53.8's release (#527, decided
  2026-10-20).

---

## Tasks

### 53.9.0 Start of phase

- [ ] Read `open-questions.md` and the *Open* and *Scheduled* rows of
      `known-issues.md` (CLAUDE.md §12).

### 53.9.1 Installing and running the checks

- [ ] `composer.json` requires `ext-mbstring` (lock refreshed with
      `composer update --lock`); `docs/deployment.md`'s bare-PHP
      requirements name it (K123, #528). A test
      (`tests/Unit/Docs/` beside `LicenceTest`): every `mb_*` function
      called in `src/` and `bin/` is either one
      `symfony/polyfill-mbstring` defines or `composer.json` requires
      `ext-mbstring`, and `docs/deployment.md` lists every required
      `ext-*`.
- [ ] `bin/smoke-test.sh` uses `${COMPOSE_PROJECT_NAME:-logbook-smoke}`
      for every `-p` (K121); `CONTRIBUTING.md` says how to give a run
      its own name. A test: the script has no hard-coded `-p
      logbook-smoke` and passes `sh -n`.
- [ ] `AppTestCase::createApp()` (and `DeliveryPageTest::mailApp()` if
      it bypasses it) sets `SESSION_SECRET_FILE` to empty unless the
      test gives it (K122). A test: with `SESSION_SECRET_FILE` pointing
      at a file in the process environment, `createApp()` without it
      has no session secret; the four named tests pass in the dev `app`
      container.
- [ ] `docker/entrypoint.sh` runs `composer install` in development when
      `composer.lock`'s `content-hash` differs from the one recorded in
      `vendor/` at the last install (K128). A test runs the entrypoint's
      check in a temporary directory: a missing or stale record means
      install, a matching one means none; `APP_ENV=production` never
      installs.
- [ ] `TracktorCommand::user()` and `FuelioCommand::user()` print "no
      user yet, finish first-run setup" when the install has no users
      (K127). `TracktorCommandTest` and `FuelioCommandTest`: with no
      users and no `--as`, exit 1 with that message and nothing read or
      written.

### 53.9.2 Finish

- [ ] Known-issues rows K121, K122, K123, K127 and K128 *Fixed*.
- [ ] CHANGELOG (an upgrade note for bare-PHP installs: mbstring is now
      required); spec §13; ROADMAP status.
- [ ] Merge review, then merge.

### Release

- [ ] `VERSION`, CHANGELOG, README status, tag: the patch after 53.8's
      release (#527).

## Acceptance criteria

- Each row (K123, K121, K122, K128, K127) has a regression test that
  fails without its fix.
- `composer lint`, `composer analyse` and `bin/test-all-dbs.sh` pass on
  SQLite, PostgreSQL, MySQL and MariaDB, and the suite passes inside the
  dev `app` container. `MarkdownLinksTest` passes.
- `bin/smoke-test.sh pgsql` still passes with no `COMPOSE_PROJECT_NAME`
  set; the Docker image builds and is unchanged by K128.
- No migration. Any new or changed strings are in every catalogue in
  `translations/`.

## Open questions

- **#527. Release.** *Decided 2026-10-20:* released on its own as a
  patch, after Phase 53.8's release, like 52.1 (#481), 52.2 (#486),
  53.5 (#502), 53.6 (#503) and 53.7 (#506). K123 is HIGH for bare-PHP
  installs, and none of the rows touch code 53.6–53.8 change. Not
  chosen: with the next phase that releases.
- **#528. K123: require mbstring or stop needing it.** *Decided
  2026-10-20:* require it: `ext-mbstring` in `composer.json`, spec §10's
  bare-PHP list and `docs/deployment.md`, so Composer refuses an install
  without it. Not chosen: replacing `mb_strcut()` and `mb_strimwidth()`
  with UTF-8-safe trimming that needs no extension.
