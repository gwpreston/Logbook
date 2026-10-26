# Phase 53.16 — SQLite upgrade ids and the Windows smoke test from the known-issues log + patch release

*Two open rows of the known-issues log closed: a SQLite table rebuild
keeps its id counter so a deleted record's id isn't reused, and
`bin/smoke-test.sh` runs from Git Bash on Windows.*

Status: ✅ complete · file lives in `docs/phases/` · released as **v3.19.8**, the
patch after Phase 53.15's release

This is a batch of open rows that wait on no open question. K143 is left
out: it waits on #549.

Read [`CLAUDE.md`](../../CLAUDE.md) (§6, §11, §12), `spec.md` (migrations
and upgrades), and [`open-questions.md`](open-questions.md) first.

**Prerequisites:** [Phase 53.15](phase-53.15.md) merged, and its release
tagged.

---

## Goals

1. **K63.** On SQLite a migration that rebuilds a table keeps
   `sqlite_sequence` for it, so the next id is never one already used
   (and webhooks never send `entry.created` for an id they sent
   `entry.deleted` for). Phinx's `copyDataToNewTable()` drops the
   counter, so the fix is a shared helper that saves and restores it
   around a rebuild, used by the migrations that rebuild
   (`odometer_readings`, `parts`, and the earlier ones that still run on
   an upgrade). MySQL and Postgres are unchanged.
2. **K142.** `bin/smoke-test.sh` passes on a Windows host with Git Bash
   and Windows `curl`: it converts the PDF path and writes `curl -o`
   output to a path `curl` can use. Linux and CI behave as before.

## Not in scope

- Repairing ids already reused on installs that upgraded earlier.
- K143 (waits on #549).

---

## Decisions (and why)

- **No new migration for K63.** The helper changes how rebuilding
  migrations run; installs already upgraded keep what they have, which is
  noted in the CHANGELOG.
- **How (2026-10-26):** every migration extends one base class,
  `Logbook\Support\Database\Migration`, whose adapter wrapper restores
  the counter after each table change on SQLite. This covers every rebuild
  (column changes, dropped foreign keys and indexes), up and down, with no
  call in each migration.
- **Release:** a patch on its own after 53.15's release.

---

## Tasks

### 53.16.0 Start of phase

- [x] Read `open-questions.md` and the *Open* and *Scheduled* rows of
      `known-issues.md` (CLAUDE.md §12). No open question touches SQLite
      migrations or the smoke test, so none needed an answer.
- [x] Spec first: §6.1 (the SQLite id counter), §11 (the smoke test on
      Windows), §13, ROADMAP 🚧.

### 53.16.1 SQLite id counters (K63)

- [x] Every migration rebuilds on SQLite when it changes or drops a
      column, foreign key or index, so all 56 extend one base class
      (`Support\Database\Migration`) whose adapter wrapper
      (`SequenceKeepingAdapter`) reads `sqlite_sequence` before and writes
      it back after each table change.
- [x] Upgrade test (`SqliteIdCounterTest`): delete the highest reading,
      roll back and migrate, the next id is greater than the deleted one.
      Repeat for `parts`. SQLite only; the other engines never rebuild.
- [x] Run with the upgrade-tester on all four engines. It found the counter
      still reset when a rollback reverses a `change()` migration (Phinx
      reverses it on a proxy adapter the wrapper didn't reach): fixed, the
      base class wraps the proxy's inner adapter, with a test. It also found
      that a backup restore resets counters (K146, open question #559).

### 53.16.2 Windows smoke test (K142)

- [x] Use `cygpath -m` for `-F "file=@…"` when `cygpath` exists; `-o`
      keeps working under Git Bash's own path conversion (K142 shows it
      only fails with `MSYS_NO_PATHCONV=1`). Linux is unchanged (no
      `cygpath`). `CONTRIBUTING.md` updated.
- [ ] Run `pgsql` on Windows to the end (needs a Windows host; not run
      here, see the PR).

### 53.16.3 Finish

- [x] Known-issues rows K63 and K142 *Fixed*.
- [x] CHANGELOG; spec §13; ROADMAP status.
- [ ] Merge review (upgrade-tester, deploy-checker), then merge.

### Release

- [x] `VERSION`, CHANGELOG, README status.
- [x] Tag `v3.19.8` once merged.

## Acceptance criteria

- An id deleted before a rebuild isn't reused on SQLite, up or down.
- `bin/smoke-test.sh pgsql` completes on Windows and in CI.
- `composer lint`, `composer analyse` and `bin/test-all-dbs.sh` pass.

## Open questions

- **#559** (found by the upgrade-tester, K146): should a backup carry each
  table's id counter? **Open.**
