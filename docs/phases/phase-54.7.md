# Phase 54.7 — Digest fleet line, cost per distance and archived rows from the open-questions log

*Three decisions of 2026-10-31 built: the digest's fleet line says when it
covers only some vehicles, its 12-month cost per distance never overstates,
and webhook rows say a vehicle is archived.*

Status: 📋 planned · releases **v3.21.5**, a patch release (#587) · file lives in `docs/phases/`

This is the batch after [Phase 54.6](phase-54.6.md). The owner decided
#375, #376 and #567 on 2026-10-31, after 54.6's plan was written; they are
a phase of their own. #377 and #565 were decided the same day with nothing
to build.

Read [`CLAUDE.md`](../../CLAUDE.md), [`spec.md`](../../spec.md) §7.11 (the
monthly digest: *Last month*, *Fleet line*, *Webhook JSON*), §7.35 (true
cost, `reachesBack`) and [`open-questions.md`](open-questions.md) first.

**Prerequisites:** [Phase 54.6](phase-54.6.md) merged, and `v3.21.4` tagged.

---

## Goals

- **Fleet line (#375):** when the viewer may see the costs of only some of
  the listed vehicles, the spend carries "vehicles whose costs you see"
  (translated), and the JSON `fleet` gains `spend_partial: true`.
- **Cost per distance (#376):** the digest's 12-month cost per distance and
  its average are "—" unless the vehicle's readings reach back to the start
  of the 12 months (`reachesBack`). The Reports page is unchanged.
- **Archived rows (#567):** each `last_month` webhook row carries `archived`
  (boolean), a compatible addition.
- No migration. Released on its own as patch release v3.21.5 (#587).

---

## Tasks

### 54.7.0 Start of phase

- [ ] Read `open-questions.md` and the *Open* and *Scheduled* rows of
      `known-issues.md` (CLAUDE.md §12).

### 54.7.1 Digest decisions (#375, #376, #567)

- [ ] The fleet line label and `spend_partial` (#375).
- [ ] The reach-back rule on the 12-month cost per distance (#376).
- [ ] `archived` on each `last_month` row (#567).
- [ ] Tests for each, in English and German, on the digest text and JSON;
      docs/api and notification docs updated; new strings in every
      catalogue in `translations/`.

### 54.7.2 Finish

- [ ] CHANGELOG; spec §13; ROADMAP status.
- [ ] Merge review; merge.

### Release

- [ ] VERSION 3.21.5, CHANGELOG 3.21.5, README status (#587).
- [ ] Tag `v3.21.5` once merged.

## Acceptance criteria

- Each of #375, #376 and #567 has a regression test that fails without its
  change.
- `composer lint`, `composer analyse` and `bin/test-all-dbs.sh` pass on
  SQLite, PostgreSQL, MySQL and MariaDB. `MarkdownLinksTest` passes.
- No migration.

## Open questions

- **#587. Release. Decided 2026-10-31:** on its own as patch release
  v3.21.5, after 54.6. Not chosen: with the next releasing phase.
