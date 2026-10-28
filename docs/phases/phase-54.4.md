# Phase 54.4 — The true-cost cover note from the known-issues log + patch release

*One open row of the known-issues log closed: the first year's "cover before
you bought it" note shows on the true-cost report however old the vehicle is.*

Status: 📋 planned · releases **v3.21.2**, a patch release · file lives in `docs/phases/`

This is the batch after [Phase 54.3](phase-54.3.md). The owner asked for
the open rows that wait on no decision; after 54.1–54.3 the only ones left
were K145 (needs an API key, not a fix), K152 and K154. The owner decided
the questions those two waited on (#568, #569), which leaves K152 as the
one row to build; K154 closes as *Won't fix*. It is numbered 54.4 because
it follows 54.3, the last row of the roadmap.

Read [`CLAUDE.md`](../../CLAUDE.md), [`spec.md`](../../spec.md) §7.35
(*True cost*, *Cover outside ownership*), §7.8, and
[`open-questions.md`](open-questions.md) first.

**Prerequisites:** [Phase 54.3](phase-54.3.md) merged, and `v3.21.1` tagged.

---

## Goals

1. **K152.** On the true-cost report, the note for cover before the
   purchase (and after the sale) shows once under the yearly table,
   whatever the vehicle's age: it no longer depends on the first year being
   one of the three with a drawn working. The figures don't change.

## Not in scope

- **K145**: needs an API key to record a request, not a fix.
- **K154** (#569): the owner decided to leave the layout as it is; it is
  closed as *Won't fix* in this plan, not built.
- A working for every year (not chosen in #568).

---

## Decisions (and why)

- **K152 follows #568** (option 2): the note once under the table. The
  yearly table's working is limited to the latest three years and *Since
  bought* for length; a single line under the table names the cover left
  out at either end of ownership, so it is seen for a vehicle of any age.
  It uses the existing `working.true_cost.cover_before` / `cover_after`
  wording, and K153's shorter wording (Phase 54.1) where the period isn't
  a yearly table.
- **K154 follows #569** (option 1): blank space beside a tall neighbour is
  accepted, because spec §7.8 says the arranged layout doesn't move as data
  comes and goes. Spec §7.8 says so in a sentence.
- No migration.

---

## Tasks

### 54.4.0 Start of phase

- [ ] Read `open-questions.md` and the *Open* and *Scheduled* rows of
      `known-issues.md` (CLAUDE.md §12). #568 and #569 are decided and the
      release is decided (#578).

### 54.4.1 The cover note (K152)

- [ ] `templates/reports/true_cost.twig` (and `TrueCost`/`TrueCostWorking`
      as needed) shows the cover-outside note once under the yearly
      table, for a vehicle with more than three years; the figures are
      unchanged. `TrueCostTest` and a report page test cover a vehicle
      older than three years with cover before the purchase, in English
      and German; spec §7.35 says so. Check at 375 and 1280 px, light and
      dark, and with JS off.

### 54.4.2 Finish

- [ ] Known-issues row K152 *Fixed*; K154 *Won't fix* (done in the plan).
- [ ] CHANGELOG; spec §13; ROADMAP status.
- [ ] Merge review; merge.

### Release

- [ ] VERSION 3.21.2, CHANGELOG 3.21.2, README status (#578).
- [ ] Tag `v3.21.2` once merged.

## Acceptance criteria

- K152 has a regression test that fails without its fix; figures unchanged.
- `composer lint`, `composer analyse` and `bin/test-all-dbs.sh` pass on
  SQLite, PostgreSQL, MySQL and MariaDB. `MarkdownLinksTest` passes.
- No migration. New strings are in every catalogue in `translations/`.

## Open questions

- **#568. Decided 2026-10-28:** option 2, the note once under the table
  (K152). Not chosen: a working for every year; leave it to the year's own
  page.
- **#569. Decided 2026-10-28:** option 1, leave the layout (K154, *Won't
  fix*). Not chosen: dense packing; a full-width row of empty widgets.
- **#578. Release. Decided 2026-10-28:** on its own as patch release
  v3.21.2. Not chosen: with the next releasing phase; folded into 54.3.
