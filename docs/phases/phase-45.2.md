# Phase 45.2 — Hardening: households and backups

*Prove that a shared vehicle shows each person only what they should
see, and that a backup really restores.*

Status: 🚧 in progress (started 2026-10-12) · file lives in `docs/phases/`

The access matrix checks every route against every level, one request at
a time. What it doesn't check is a **journey**: a Log driver's month of
fill-ups, a transfer half-way through a finance agreement, a share
revoked while its user is signed in. Nor does it check the places where
data leaks without a route of its own: dashboard widgets, fleet reports,
History, the calendar feed, the digest, webhooks, Ask, MCP, CSV and
print.

Backup and restore have the same gap. They are tested per table, but no
test takes a whole, realistic instance, backs it up on one engine,
restores it on another, and proves that every figure, file and share
survived.

This part tests both with [45.1](phase-45.1.md)'s golden household as
the data.

Read [`CLAUDE.md`](../../CLAUDE.md) and [`spec.md`](../../spec.md) §5,
§7.9, §7.13, §7.20, §7.21, §7.26, §7.28 and §7.30 first.

**Prerequisites:** [Phase 45.1](phase-45.1.md) complete and green. The
owner chose on 2026-10-12 to start 45.2 before then: 45.1 waits only on
the owner's `expected.csv` and a week of green nightly property runs.
Nothing here needs `expected.csv`. The restore matrix compares each
instance's figures before and after, and checks `expected.csv`'s rows
too once they are filled in.

---

## Goals

1. **Journey tests.** One per persona, run through the HTTP layer as a
   signed-in user would.
2. **A leak sweep.** Every GET route in the route inventory, and every
   other output, rendered as each restricted persona and scanned for what
   they must not see.
3. **A restore matrix.** The golden household backed up on each engine
   and restored on every other, then compared row by row, file by file
   and figure by figure.
4. **The upgrade path.** A backup from the previous release, restored on
   that release, upgraded, and its figures checked.
5. **Failure paths.** A restore that fails at any step changes nothing,
   and leaves the pre-restore backup in place.
6. A restore drill written into the docs, for owners to practise.

## Not in scope

- New permissions, or any change to backup's format. A fix restores the
  spec's behaviour.
- Off-site backup. Phase 48 handles the backup-age warning.

---

## Personas and journeys (proposed)

| Persona | Journey |
|---|---|
| Owner | Shares, changes a level, transfers with *Keep access*, archives |
| Manage partner | Edits others' entries, imports, prepares a sale pack, exports CSV |
| Log driver, no costs | A month of fill-ups and a service; edits only their own entries; sees the amounts they typed and no totals |
| Viewer | Reads everything the level allows, including documents, and nothing else |
| Admin who isn't the owner | Sees only their own and shared vehicles; backs up everything |
| Revoked mid-session | The next request, API call, calendar fetch and Ask question all fail |
| Former owner after a transfer without *Keep access* | Nothing remains visible, and their entries stay with the vehicle |

## The leak sweep (proposed)

For each restricted persona, render:

- every GET route in the inventory;
- the calendar feed and digest email;
- a webhook payload, a CSV export and a print view;
- the API's list endpoints;
- Ask's and MCP's tool results for every read tool.

Carried from Phase 45.1's *hidden means hidden* property, which covers the
services behind Reports, ownership, true cost, *Coming up*, the digest,
finance and incidents: the fuel tab's cost per distance and segment
costs, station stats, and the Ask tools must be in this sweep by name.

Then assert that none of them contains:

- an amount, unless it is on that persona's own entry;
- a vehicle outside their shares;
- another driver's destinations.

The golden household's amounts and policy numbers are distinctive values,
so each scan is an exact search for them, not a guess.

---

## Tasks

### 45.2.0 Spec first
- [x] Open questions A–C decided by the owner and logged (#416–#418,
      2026-10-12); §13.
- [x] The carried #377, decided the same day: the server's webhook gets
      none of the briefing (§7.11). Built here, because the leak sweep
      covers webhook payloads (`ReminderNotifier`;
      `MonthlyBriefingSendingTest`).

### 45.2.1 Journeys and sweep
- [ ] The persona journeys as integration tests on every engine.
- [ ] The leak sweep, driven by the route inventory, so a new route is
      swept without being listed.
- [ ] Every leak found is fixed with a regression test and noted in
      §13.

### 45.2.2 Restore matrix
- [ ] Back up the golden household (with uploads) on SQLite, PostgreSQL,
      MySQL and MariaDB. Restore each backup on each engine. Compare
      every table (normalised), every file's hash, every share, and every
      row of `expected.csv`.
- [ ] Scheduled backups, retention, and `bin/backup.php create` and
      `restore` against the same checks.

### 45.2.3 Upgrade and failure paths
- [ ] CI installs the previous release tag, loads the golden household,
      backs it up, restores on that tag, upgrades to `HEAD` and runs the
      golden test.
- [ ] Each failure leaves the data unchanged and the pre-restore backup
      present: a truncated archive, a wrong schema version, an unknown
      column, an unsafe file name, and a failure while swapping uploads.

### 45.2.4 Docs
- [ ] `docs/backup.md`: *Practise a restore*. Restore last week's backup
      onto a spare container, then check the figures you know.

---

## Acceptance criteria

1. Every persona's journey passes on every engine.
2. The leak sweep finds nothing, and fails the build if a new route
   leaks.
3. Every cell of the restore matrix reproduces the golden household
   exactly, and so does the upgrade path.
4. A failed restore never loses data.
5. Definition of done (CLAUDE.md §11) holds.

## Open questions

A–C were decided by the owner on 2026-10-12, before the phase was built.

- **A. The full restore matrix on every push?** It is 16 restores.
  - (1) On every push.
  - (2) Nightly, with a SQLite → PostgreSQL pair on every push.

  *Recommendation:* (2). It keeps CI fast, and the nightly run catches an
  engine-specific break within a day. *Decided 2026-10-12:* (2). (#416)

- **B. Upgrade path, from which release?**
  - (1) The previous minor release only.
  - (2) Every minor release since v3.0.

  *Recommendation:* (1) on every release, with (2) once now to find
  anything already broken. *Decided 2026-10-12:* as recommended. (#417)

- **C. Sweep Ask's answers too, or only its tool results?** Answers come
  from a model, so they vary.
  - (1) Tool results only. The model can't leak what it was never given.
  - (2) Both.

  *Recommendation:* (1). It tests the boundary that actually matters.
  *Decided 2026-10-12:* (1). (#418)

Carried from [Phase 43](phase-43.md):

- **#377 The server's webhook and the briefing.** `WEBHOOK_URL` got each
  member's per-vehicle distances and open-issue counts. *Decided
  2026-10-12:* it gets none of the briefing (§7.11).
