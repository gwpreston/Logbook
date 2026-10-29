# Phase 54.5 — The 500 ms target for the dashboard and true-cost report from the known-issues log + patch release

*The two rows [Phase 54.3](phase-54.3.md) left open closed: the dashboard and
the true-cost report meet spec §8's 500 ms on the heavy household.*

Status: 📋 planned · releases **v3.21.3**, a patch release (#584, to be decided) · file lives in `docs/phases/`

Phase 54.3 removed the repeated reads (digest 861 to 247 queries, true-cost
99 to 21) and cut the dashboard from 2.3 s to 1.5 s, but what is left is
CPU, not repeated reads. The owner decided (#583, 2026-10-29) to keep the
500 ms target and reach it by lighter working-out. It is numbered 54.5
because it follows 54.4, the last row of the roadmap.

Read [`CLAUDE.md`](../../CLAUDE.md), [`spec.md`](../../spec.md) §8 *Page
budgets*, §7.24, §7.35, [phase-54.3.md](phase-54.3.md) (its *Profile* and
*Result*) and [`open-questions.md`](open-questions.md) first.

**Prerequisites:** [Phase 54.4](phase-54.4.md) merged, and `v3.21.2` tagged.

---

## Goals

1. **K149.** The dashboard answers in at most 500 ms on the heavy household
   (10 vehicles, 400 fill-ups, 150 services, 16 years of documents each;
   1.5 s after 54.3), with the same queries.
2. **K150.** The true-cost report answers in at most 500 ms on the same
   household (0.68 s after 54.3).

## Not in scope

- Loading widgets after the page, or a cache that outlives a request
  (#583 chose neither).
- Changed figures: every number shown stays the same.

---

## Decisions (and why)

- **Lighter working-out only** (#583, option 1). Profile first, as in 54.3:
  the profile is recorded here before any fix. 54.3 left these hot spots:
  the price check's exact decimal arithmetic for each fill-up (about
  370 ms), the true-cost widget (about 260 ms), the fuel analysis (about
  130 ms), reading and hydrating every table (about 110 ms), *Coming up*
  (about 90 ms), and for the report summing every item for each of up to
  16 years (0.4 s) and rendering a 350 KB page (0.3 s).
- **Figures are compared with master's** on the heavy household, byte for
  byte after masking the CSRF and CSP tokens, as in 54.3.
- **If a goal can't be met without changing a figure or adding a cache,
  stop and ask the owner**; don't relax the target (#583 rejected that).
- No migration.

---

## Tasks

### 54.5.0 Start of phase

- [ ] Read `open-questions.md` and the *Open* and *Scheduled* rows of
      `known-issues.md` (CLAUDE.md §12). #583 is decided; decide #584.
- [ ] Profile the dashboard and the report again on the heavy household and
      record it here.

### 54.5.1 Dashboard (K149)

- [ ] Cut the price check, the fuel analysis, the true-cost widget and
      whatever else the profile names, until the dashboard is within
      500 ms. Figures unchanged; tests for each change.

### 54.5.2 True-cost report (K150)

- [ ] Sum a vehicle's items once, not once per period; lighten the page's
      markup. Within 500 ms; figures unchanged.

### 54.5.3 Finish

- [ ] Spec §8 states the heavy household's times as met.
- [ ] Known-issues rows K149 and K150 *Fixed*.
- [ ] CHANGELOG; spec §13; ROADMAP status.
- [ ] Merge review (performance-auditor included); merge.

### Release

- [ ] VERSION 3.21.3, CHANGELOG 3.21.3, README status (#584).
- [ ] Tag `v3.21.3` once merged.

## Acceptance criteria

- The dashboard and the true-cost report are within 500 ms on the heavy
  household on SQLite, with figures identical to master's.
- `composer lint`, `composer analyse` and `bin/test-all-dbs.sh` pass on
  SQLite, PostgreSQL, MySQL and MariaDB. `MarkdownLinksTest` passes.
- No migration.

## Open questions

- **#583. Decided 2026-10-29:** keep the 500 ms target and reach it by
  lighter working-out. Not chosen: load the heavy widgets after the page;
  relax the target to 2 s.
- **#584. Release. Needs a decision:** on its own as patch release v3.21.3
  (recommended), or with the next releasing phase.
