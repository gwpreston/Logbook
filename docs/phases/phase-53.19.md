# Phase 53.19 — Declared on and backup id counters from the known-issues log + patch release

*Two open rows of the known-issues log closed, each after the owner
answered its question: only `Manage` can set or clear a modification's
*Declared on* on any path, and the backup guide says a restore may reuse
an id that was deleted before the backup.*

Status: 📋 planned · file lives in `docs/phases/` · releases **v3.19.11**, a
patch release

This is the next batch after [Phase 53.18](phase-53.18.md). It takes K3
and K146, which waited on #485 and #559; the owner answered both on
2026-10-27 while this batch was planned. It is numbered 53.19 because no
phase is active and 53.18 is the last row of the ROADMAP.

Read [`CLAUDE.md`](../../CLAUDE.md) (§11, §12), `spec.md` (§7.39
*For your insurer* and the *Backup and restore* section of §7), and
[`open-questions.md`](open-questions.md) first.

**Prerequisites:** [Phase 53.18](phase-53.18.md) merged, and
[v3.19.10](../../CHANGELOG.md) tagged.

---

## Goals

1. **K3.** *Declared on* can be set or cleared on a modification only by a
   user with `Manage` on the vehicle, on every path: the web create, edit
   and convert forms ignore a `declared_on` field from anyone else (and
   keep the stored date), and the REST API refuses it from a `Log` member
   with the same error as other `Manage`-only fields. A `Manage` user's
   behaviour is unchanged.
2. **K146.** The backup guide says that a restore sets every table's id
   counter to the highest id present, so an id deleted before the backup
   may be given to the next new record (and a webhook may then send
   `entry.deleted` and `entry.created` for the same id). No code change.

## Not in scope

- K1 and K40: both now decided (#484, #449) but each is a multi-file
  feature (a separator picker on the import preview; per-user tagging of
  the offline queue and cached pages), so each gets its own phase.
- K54 (waits on #456) and K13 (needs its own question first).
- K145 (record real Claude scan requests): needs an API key, not a fix.
- Carrying id counters in a backup (the format change): the owner chose
  the smaller option for #559.

---

## Decisions (and why)

- **K3 (#485, decided 2026-10-27):** option 1. *Declared on* records what
  the owner told the insurer, so one rule on every path keeps #444 true
  (*Mark declared* is `Manage`). Not chosen: `Log` on their own
  modifications (changes #444); the API and forms following `EntryAccess`
  per modification.
- **K146 (#559, decided 2026-10-27):** option 1, leave it and say so. The
  symptom is rare and harmless for a single-owner garage, and a backup
  format change is out of proportion. Not chosen: recording each counter
  in the backup and restoring `max(counter, MAX(id))`.

---

## Tasks

### 53.19.0 Start of phase

- [ ] Read `open-questions.md` and the *Open* and *Scheduled* rows of
      `known-issues.md` (CLAUDE.md §12).

### 53.19.1 *Declared on* is Manage-only (K3)

- [ ] `ModificationForm` and the create, edit and convert actions: a
      `declared_on` field from a viewer without `Manage` is ignored, and
      an edit keeps the stored date. `ApiModifications` (`log`, `patch`):
      the same field from a `Log` member is refused (403 with the usual
      error body). A `Manage` user still sets and clears it.
- [ ] Spec §7.39 states the rule once for every path. Tests: a `Log`
      member's crafted `declared_on` on the web create, edit and convert
      forms changes nothing; the API refuses it; `Manage` can set and
      clear it on both.

### 53.19.2 Backup id counters (K146)

- [ ] The backup guide (`docs/deployment.md#backups`) and the *Backup and
      restore* section of spec §7 say what a restore does to id counters
      and what it can cause. A test (or the existing backup test)
      keeps the documented behaviour true: after a restore the next new
      row's id is `MAX(id) + 1`.

### 53.19.3 Finish

- [ ] Known-issues rows K3 and K146 *Fixed*; open questions #485 and #559
      marked built.
- [ ] CHANGELOG; spec §13; ROADMAP status.
- [ ] Merge review (spec-keeper, security-scanner, bug-hunter), then merge.

### Release

- [ ] `VERSION`, CHANGELOG, README status.
- [ ] Tag `v3.19.11` once merged.

## Acceptance criteria

- K3 has a regression test that fails without its fix; K146's test pins
  the documented counter behaviour.
- `composer lint`, `composer analyse` and `bin/test-all-dbs.sh` pass on
  all four engines.
- `MarkdownLinksTest` passes.
- No migration; no new strings (the API error reuses an existing key).

## Open questions

- **#485. Decided 2026-10-27:** (1) `Manage` only on every path: a `Log`
  member's value is ignored (web) or refused (API). Not chosen: (2) `Log`
  on their own modifications as well; (3) `EntryAccess` per modification.
- **#559. Decided 2026-10-27:** (1) leave it, and say so in the backup
  guide. Not chosen: (2) record each counter in the backup and restore
  `max(counter, MAX(id))`.
- **#560. Decided 2026-10-27:** (1) ship on its own as a patch release,
  v3.19.11. Not chosen: (2) with the next releasing phase.
