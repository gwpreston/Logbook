# Phase 54.13 — The Symfony advisories and the vendored libraries from the known-issues log + patch release

*Four open rows of the known-issues log closed: two after a decision of the
owner's, the locked Symfony packages no longer carry the 2026-11-04
advisories, and the bundled front-end libraries match `package.json` with a
test that keeps them so.*

Status: 🚧 in progress · releases **v3.21.10** together with [Phase 54.14](phase-54.14.md), a patch release (#603, #609) · file lives in `docs/phases/`

This is the batch after [Phase 54.12](phase-54.12.md). It takes K182 and
K183, the only open rows that could be fixed once #597 and #598 were
decided (2026-11-06). The rest wait on an upstream release or an API key
(see *Not in scope*). It is numbered 54.13 because 54.12 is the last row of
the ROADMAP table.

Read [`CLAUDE.md`](../../CLAUDE.md), [`spec.md`](../../spec.md) §1 (bundled
code and notices), §4 and §13, `CONTRIBUTING.md` (*Dev stack*, the vendored
libraries) and [`open-questions.md`](open-questions.md) first.

**Prerequisites:** [Phase 54.12](phase-54.12.md) merged, and `v3.21.9`
tagged (run `/release` for 54.12 first).

---

## Goals

1. **K182.** `composer audit --no-dev` is clean: `symfony/http-client`,
   `symfony/config` and `symfony/filesystem` are locked at 7.4.23 (and the
   dev-only `symfony/yaml` at 7.4.23), `composer.json` allows
   `symfony/http-client ^7.4 || ^8.0`, and CI runs `composer audit --no-dev`
   so the next advisory shows on the pull request.
2. **K183.** The bundles in `assets/vendor` and `public/assets/vendor` and
   both third-party notices files carry the versions `package.json` names
   (Alpine 3.17.5, Chart.js 4.6.1, Material Symbols 0.48.3), and a test
   fails whenever the versions in the notices differ from
   `package-lock.json`, so a Dependabot npm pull request can't merge
   without being re-vendored. (The plan said icons 0.48.2; Dependabot
   moved `package.json` to 0.48.3 on 2026-11-07, commit `d17b3a48`, so
   #604's "the versions `package.json` names" means 0.48.3.)
3. **K189.** The design and accessibility work merged after v3.21.9 (pull
   requests #206–#212, #216–#220 and #224) is recorded in this file
   (*Merged before this phase*), the ROADMAP and spec §13, so it has a
   phase to ship under.
4. **K190.** CHANGELOG `[Unreleased]` names every user-facing change since
   v3.21.9, and the K186 Docker-build line moves there from the
   already-tagged `[3.21.9]`.

## Not in scope

- **K145** needs a recorded API key. **K174** waits on `firebase/php-jwt`
  supporting phpseclib 4. **K175:** PHPStan 2.3.5 still reports 29 errors.
- **K185's** two remaining parts (the import-mapping accessibility case and
  the 60-second limit on Windows) have no known cause.

---

## Decisions (and why)

- **K182 follows #597:** widen the root constraint to
  `symfony/http-client ^7.4 || ^8.0` and lock 7.4.23, as K176 did for
  `symfony/mime`, keeping `config.platform.php` at 8.4.0. A dry run on
  2026-11-06 resolved cleanly: `http-client`, `config` and `filesystem` to
  7.4.23, `yaml` to 7.4.23, and the contracts packages to 3.8.1. Not chosen:
  raising the platform pin to 8.4.1 for Symfony 8.1 (the lock would stop
  installing on 8.4.0); waiting for an 8.0 backport (audit stays red).
- **K182's guard (#606):** a CI step `composer audit --no-dev` on the lint
  job. Not chosen: a test that the lock holds fixed versions (brittle, blind
  to new advisories); no guard. Known cost, accepted: an advisory with no
  fix yet turns CI red until it is handled.
- **K183 follows #598:** keep Dependabot's npm pull requests and fail them
  until re-vendored. Not chosen: ignoring the bundled libraries; re-vendoring
  by hand with nothing enforcing it.
- **K183's sync (#604):** re-vendor to the newer versions with
  `npm ci && npm run vendor`, committing the rebuilt bundles in `assets/`
  and `public/assets/` and both notices files, after checking the charts,
  icons and the draggable dashboard in a browser at a subpath. Not chosen:
  reverting `package.json` (Dependabot would propose the bumps again).
- **K183's check (#605):** a PHPUnit architecture test in
  `tests/Unit/Architecture/` that reads `assets/vendor/THIRD-PARTY-NOTICES.txt`
  and `package-lock.json` and compares each library's version; it needs no
  Node in CI. Not chosen: a separate CI job that re-runs `npm run vendor`
  and diffs the files.
- **K189 and K190 in this phase (owner, 2026-11-11, asked when the phase
  started):** this phase writes the CHANGELOG anyway, so K190 is fixed
  here; K189's record goes in this file rather than a separate
  retrospective phase. Not chosen: leaving either open.
- **Release (#603):** v3.21.10. The lock change touches production
  dependencies. Changed 2026-11-11 (#609): released together with
  [Phase 54.14](phase-54.14.md), whose K188 fix must reach the 3.21.9 →
  3.21.10 bare-PHP upgrade; the *Release* tasks below are done once, after
  54.14 is merged.

## Tasks

### 54.13.0 Start of phase

- [ ] Read `open-questions.md` and the *Open* and *Scheduled* rows of
      `known-issues.md` (CLAUDE.md §12). #597, #598 and #603–#606 are
      decided.

### 54.13.1 The Symfony advisories (K182)

- [ ] `composer.json`: `symfony/http-client ^7.4 || ^8.0`; `composer update`
      for `symfony/http-client`, `config`, `filesystem` and `yaml` with their
      dependencies; `composer audit --no-dev` is clean and the full suite
      passes on all four engines (outbound calls go through `OutboundHttp`:
      its tests are the check that 7.4 behaves). `.github/workflows/ci.yml`
      gets the `composer audit --no-dev` step (#606). The notices file is
      regenerated if the versions it lists changed.

### 54.13.2 The vendored libraries (K183)

- [ ] `npm ci && npm run vendor` (#604); rebuilt bundles and both
      `THIRD-PARTY-NOTICES` files committed; checked in the browser at a
      subpath (dashboard drag, a chart, icons).
- [ ] A test in `tests/Unit/Architecture/` (#605) that fails on today's
      3.17.4 against `package-lock.json`'s 3.17.5 and passes after the
      re-vendor. `CONTRIBUTING.md` (~186) says a Dependabot npm pull request
      must be re-vendored before it merges and that this test is what fails.

### 54.13.3 The record of the work merged after v3.21.9 (K189, K190)

- [ ] *Merged before this phase* below lists each pull request with what
      it changed and its audit results; ROADMAP and spec §13 name it.
- [ ] CHANGELOG `[Unreleased]` gains the missing lines (#206, #208, #209,
      #211, #212, #216, #224) and the K186 line moves there from
      `[3.21.9]`.

### 54.13.4 Finish

- [ ] Known-issues rows K182, K183, K189 and K190 *Fixed*.
- [ ] CHANGELOG; spec §13; ROADMAP status.
- [ ] Merge review; merge.

### Release

- [ ] VERSION 3.21.10, CHANGELOG 3.21.10, README status (#603): done
      in [Phase 54.14](phase-54.14.md)'s *Release*, after 54.14 is merged
      (#609). Don't run `/release` for 54.13 alone.
- [ ] Tag `v3.21.10` once 54.13 and 54.14 are both merged.

## Acceptance criteria

- `composer audit --no-dev` reports no advisories, and CI runs it.
- The notices test fails on the old bundles and passes on the rebuilt ones.
- `composer lint`, `composer analyse` and `bin/test-all-dbs.sh` pass on
  SQLite, PostgreSQL, MySQL and MariaDB. `MarkdownLinksTest` passes.
- No migration. No new strings.

## Open questions

- **#597. Decided 2026-11-06:** widen the constraint to `^7.4 || ^8.0` and
  lock 7.4.23. Not chosen: raise the platform pin and move to 8.1; wait
  for a backport.
- **#598. Decided 2026-11-06:** keep Dependabot's npm pull requests and fail
  them until re-vendored. Not chosen: ignore the bundled libraries; re-vendor
  by hand with nothing enforcing it.
- **#603. Release. Decided 2026-11-06:** on its own as patch release
  v3.21.10. Not chosen: with the next releasing phase. Changed
  2026-11-11 (#609): v3.21.10 together with Phase 54.14.
- **#604. Decided 2026-11-06:** re-vendor to the newer versions. Not chosen:
  revert `package.json`.
- **#605. Decided 2026-11-06:** a PHPUnit architecture test. Not chosen: a
  separate CI job with Node.
- **K189 and K190. Decided 2026-11-11 (owner, at the start of the phase):**
  both in this phase.
- **#606. Decided 2026-11-06:** a CI step `composer audit --no-dev`. Not
  chosen: a test of the lock's versions; no guard.
