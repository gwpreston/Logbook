# Phase 53.16 — SQLite upgrade ids and the Windows smoke test from the known-issues log + patch release

*Two open rows of the known-issues log closed: a SQLite table rebuild
keeps its id counter so a deleted record's id isn't reused, and
`bin/smoke-test.sh` runs from Git Bash on Windows.*

Status: 📋 planned · file lives in `docs/phases/` · releases the patch
after Phase 53.15's release, a patch release

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
- **Release:** a patch on its own after 53.15's release.

---

## Tasks

### 53.16.0 Start of phase

- [ ] Read `open-questions.md` and the *Open* and *Scheduled* rows of
      `known-issues.md` (CLAUDE.md §12).

### 53.16.1 SQLite id counters (K63)

- [ ] List every migration that rebuilds a table on SQLite; add a helper
      that reads `sqlite_sequence` before and writes it back after.
- [ ] Upgrade test: delete the highest reading, run the rebuild up and
      down, the next id is greater than the deleted one. Repeat for
      `parts`. Run with the upgrade-tester on all four engines.

### 53.16.2 Windows smoke test (K142)

- [ ] Use `cygpath -w` (or a relative path) for `-F "file=@…"` and a
      writable temp dir for `-o`. Run `pgsql` on Windows to the end; the
      Linux run is unchanged. Update `CONTRIBUTING.md`.

### 53.16.3 Finish

- [ ] Known-issues rows K63 and K142 *Fixed*.
- [ ] CHANGELOG; spec §13; ROADMAP status.
- [ ] Merge review (upgrade-tester, deploy-checker), then merge.

### Release

- [ ] `VERSION`, CHANGELOG, README status, tag.

## Acceptance criteria

- An id deleted before a rebuild isn't reused on SQLite, up or down.
- `bin/smoke-test.sh pgsql` completes on Windows and in CI.
- `composer lint`, `composer analyse` and `bin/test-all-dbs.sh` pass.

## Open questions

- None yet.
