# Phase 54.11 — The Dependabot paperwork, database versions and a Windows test from the known-issues log + patch release

*Four open rows of the known-issues log closed: the dev stack runs the
MariaDB that is supported, the deployment guide names the database versions
that are tested, Phase 54.10's paperwork says it is done, and one test no
longer fails on Windows.*

Status: 🚧 in progress · releases **v3.21.8**, a patch release (#599) · file lives in `docs/phases/`

This is the batch after [Phase 54.9](phase-54.9.md). It takes K178, K179,
K181 and K184: the three that need no decision of the owner's beyond the
ones recorded below, plus K179, a docs-only row. It is numbered 54.11
because `phase-54.10.md` (Dependabot) already exists: it was merged without
a ROADMAP table row, which is K179.

Read [`CLAUDE.md`](../../CLAUDE.md), [`spec.md`](../../spec.md) §4 (the
supported database versions) and §13, `CONTRIBUTING.md` (*Dev stack*) and
[`open-questions.md`](open-questions.md) first.

**Prerequisites:** [Phase 54.10](phase-54.10.md) merged, and `v3.21.7`
tagged.

---

## Goals

1. **K178.** The dev stack's MariaDB is 11.4, the version spec §4 and #596
   say is supported and CI tests, so `bin/test-all-dbs.sh` and
   `bin/dev-setup.sh --mariadb` test what users run.
2. **K179.** Phase 54.10's file says it is complete, its tasks are ticked,
   its prerequisite is true, and its Decisions include the ignore rules
   added in #187 and #193. Spec §13 says "(complete)", and ROADMAP's index
   table has a 54.10 row.
3. **K181.** The deployment guide's requirements name the database versions
   Logbook is tested on (PostgreSQL 17, MySQL 8.4, MariaDB 11.4) and say
   older ones may work but aren't tested.
4. **K184.** `MarkdownLinksTest::testEveryRelativeLinkInTheRepositoryResolves`
   passes on Windows as well as Linux.

## Not in scope

- **K185** (the other Windows failures). One of them (the Settings
  accessibility case) has no known cause, and the suite also hits the
  60-second limit on that machine: not small, and not a quick fix.
- **K182** and **K183** wait on #597 and #598.
- **K160, K174, K175** wait on upstream releases; **K145** needs an API key.

---

## Decisions (and why)

- **K178 follows #600:** `docker-compose.dev.yml` runs `mariadb:11.4` and
  gives it a named volume, `mariadb_11_4`, at `/var/lib/mysql`. The service
  had only the image's anonymous volume, which Compose would reuse and which
  11.4 can't open if it holds 13.0 data. A new name leaves any old data
  untouched and unused. `CONTRIBUTING.md` (*Dev stack*) says the dev
  MariaDB is 11.4, that data from the 13.0 container is not carried over,
  and that its leftover volume can be deleted with `docker volume prune`.
  Not chosen: keep the anonymous volume and document `--reset`.
- **K178 gets a test** that fails when the two drift again: the MariaDB
  image in `docker-compose.dev.yml` must equal the one `.github/workflows/ci.yml`
  tests, and both must match the version spec §4 names.
- **K179 records what happened,** it does not redo it: the tasks that were
  merged in #172 are ticked, the Decisions gain the ignore rules (phpseclib
  major, K174; PHPStan 2.3 and later, K175; database majors, K177 and #596),
  the prerequisite is true now, and the owner's *turn on alerts* box stays
  unticked because nothing here shows it was done.
- **K181 follows #601:** the guide says "Tested on PostgreSQL 17, MySQL 8.4
  and MariaDB 11.4. Older releases may work but aren't tested." Not chosen:
  "only these are supported".
- **K184 changes the test,** not the app: the assertion compares paths with
  the separators normalised (`str_replace('\', '/', …)`), as the checker
  already works with forward slashes. `MarkdownLinks::files()` is not
  touched unless the test shows it must be. No decision.
- **Release (#599):** on its own as v3.21.8. K181 changes the guide
  self-hosters read.

## Tasks

### 54.11.0 Start of phase

- [x] Read `open-questions.md` and the *Open* and *Scheduled* rows of
      `known-issues.md` (CLAUDE.md §12). #599–#601 are decided; #597 and
      #598 are open but belong to K182 and K183, which aren't in this
      phase.

### 54.11.1 The dev stack's MariaDB (K178)

- [ ] `docker-compose.dev.yml`: `image: mariadb:11.4`, the named volume
      `mariadb_11_4` at `/var/lib/mysql`, and it listed under `volumes:`.
      `CONTRIBUTING.md` (*Dev stack*) carries the note in the decision above.
      A test in `tests/Unit/Architecture/` reads both files and checks the
      MariaDB images match each other and spec §4 (it fails on today's
      `mariadb:13.0`). Run `bin/test-all-dbs.sh mariadb` on the 11.4 image.

### 54.11.2 Phase 54.10's paperwork (K179)

- [ ] `phase-54.10.md`: status `✅ complete · no release of its own`,
      prerequisite note, tasks ticked as merged in #172, Decisions for the
      ignore rules. `spec.md` §13: "(complete)" and a clause for the ignore
      rules. `ROADMAP.md`: a `54.10` row (✅) between 54.9 and 54.11.

### 54.11.3 The deployment guide (K181)

- [ ] `docs/deployment.md` (requirements, line 196): the sentence in the
      decision above. Anywhere else the guide or `README.md` names older
      minimums is changed the same way (search `10.6`, `MySQL 8.0`,
      `PostgreSQL 13`).

### 54.11.4 The Windows link test (K184)

- [ ] `tests/Unit/Docs/MarkdownLinksTest.php:42` compares with normalised
      separators. It passes on Linux in Docker; the Windows run is checked
      on a Windows checkout where possible, and the result noted.

### 54.11.5 Finish

- [ ] Known-issues rows K178, K179, K181 and K184 *Fixed*.
- [ ] CHANGELOG; spec §13; ROADMAP status.
- [ ] Merge review; merge.

### Release

- [ ] VERSION 3.21.8, CHANGELOG 3.21.8, README status (#599).
- [ ] Tag `v3.21.8` once merged.

## Acceptance criteria

- K178 has a test that fails on `mariadb:13.0` and passes on 11.4; K179,
  K181 and K184 are docs and test changes, K184's test passes on Linux.
- `composer lint`, `composer analyse` and `bin/test-all-dbs.sh` pass on
  SQLite, PostgreSQL, MySQL and MariaDB (11.4). `MarkdownLinksTest` passes.
- No migration. No new strings.

## Open questions

- **#599. Release. Decided 2026-11-05:** on its own as patch release
  v3.21.8. Not chosen: with the next releasing phase.
- **#600. Decided 2026-11-05:** a named volume, `mariadb_11_4`, so data in
  an old 13.0 container is left untouched. Not chosen: keep the volume and
  document `--reset`.
- **#601. Decided 2026-11-05:** the guide names the tested versions and
  says older ones may work but aren't tested. Not chosen: only the tested
  versions are supported.
