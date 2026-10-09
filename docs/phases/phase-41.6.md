# Phase 41.6 — MOT history follow-ups + patch release

*The small things Phase 41's merge review found, and the edge cases it
couldn't prove.*

Status: 🚧 in progress · file lives in `docs/phases/`

Phase 41's merge review (2026-10-09, at c14d588) passed with MERGE. Its
four MEDIUM findings were fixed before the PR (#343–#345). This phase
takes the **LOW** findings that were left, and the three **unconfirmed**
edge cases: each is either proved with a failing test and fixed, or
shown not to happen and closed. Nothing here changes what MOT history
does for an owner on the normal path.

Read [`CLAUDE.md`](../../CLAUDE.md), [`spec.md`](../../spec.md) §7.38
and [Phase 41](phase-41.md) (its *Open questions* and the merge review's
follow-ups) first.

**Prerequisites:** [Phase 41](phase-41.md) merged. Independent of
[Phase 41.7](phase-41.7.md) and [Phase 42](phase-42.md).

---

## Goals

1. Settings → MOT history fits a phone screen.
2. *Add all as issues* and History read MOT data once, not per defect
   or per row.
3. The three unconfirmed edge cases are proved and fixed, or closed.
4. A registration never reaches the stored last-call status.
5. The phase file and the log use the same status words. Patch release.

## Not in scope

- The open questions #346–#349 (distance before the purchase date, who
  may use *Look up*, the settings on rollback, recorded answers in git).
  Each changes behaviour, so each waits for the owner (CLAUDE.md §12).
  A decided one joins this phase's tasks, or a later phase.
- Dashboard and overview query counts: [Phase 41.7](phase-41.7.md).
- Anything that needs real DVSA credentials (the recorded-answer check
  stays an open task in [Phase 41](phase-41.md)).

---

## Findings this phase takes

From the merge review report (`var/merge-review/phase-41-c14d588.md`,
not committed):

| # | Severity | Agent | Finding | Where |
|---|---|---|---|---|
| 1 | LOW | design-reviewer | The *Test {provider}* button reaches the card edge at 375 px with a long provider name | `templates/settings/mot_history/index.twig` |
| 2 | LOW | design-reviewer | The provider's "Sends …" hint puts its shield icon on a line of its own | `templates/settings/mot_history/index.twig` (`toggle__hint`) |
| 3 | LOW | performance-auditor | History loads every MOT defect's text only to count them (the documents are already reused since #344's commit) | `src/Service/History/ActivityFeed.php` `motTestsOf()` |
| 4 | LOW | performance-auditor | *Add all as issues* runs about 12 queries per defect (362 for 15 tests and 30 defects): `lookAgain()`, `madeIssues()` and `settle()` re-read per defect | `src/Service/MotHistory/MotReview.php` |
| 5 | LOW | spec-keeper | The phase file says *Decided*; the log says *Scheduled* for the same questions | `docs/phases/phase-41.md`, `docs/phases/open-questions.md` |
| 6 | LOW, unconfirmed | security-scanner | A transport error's message may carry the vehicle URL, and so the registration, into `mot_history.status`, which admins see, and into job output | `src/Service/MotHistory/Uk/DvsaClient.php` `send()` |
| 7 | unconfirmed | bug-hunter | Defects are matched by position on a refresh: if DVSA reorders a test's defects, an issue link or *Not now* lands on another defect's text | `src/Repository/MotTestRepository.php` `upsertDefects()` |
| 8 | unconfirmed | bug-hunter | `applyRepeats()` may give an issue an "advised again" note dated before the defect it came from, from an older test never reviewed | `src/Service/MotHistory/MotReview.php` `applyRepeats()` |

---

## Spec changes

Only if a task's proof changes behaviour:

- §7.38 *Upsert by test number*: how a test's defects are matched on a
  refresh (by position today), if task 41.6.3 changes it to text.
- §7.38 *Repeats*: only a defect on a test **after** the one the issue
  came from counts as advised again, if task 41.6.3 shows otherwise
  today.
- §7.38 *Requests*: the stored error never contains a registration or
  VIN.

## Decisions (and why)

- **Prove first.** An unconfirmed finding gets a failing test before any
  code changes. If the test passes, the finding is closed with the test
  kept as a regression guard.
- **No behaviour changes on the normal path** without an owner's
  decision. The open questions stay open.

---

## Tasks

### 41.6.0 Spec first
- [x] §7.38 wording for the proofs below (*Upsert by test number*, *Repeats*, *Requests*, *Review card*, *History*) and §13, before the code.

### 41.6.1 Phone layout (findings 1, 2)
- [ ] The *Test* button wraps (or reads *Test connection*, with the
      provider's name in the hint), checked at 375 px with the longest
      provider name in en and de.
- [ ] The *Sends …* hint: icon and text on one line.

### 41.6.2 Fewer queries (findings 3, 4)
- [ ] History counts defects per test (`COUNT … GROUP BY mot_test_id`)
      instead of loading them; −1 query and less hydration per History
      page, checked with `QueryCounter`.
- [ ] *Add all as issues* / *Add all passes as documents*: the issues
      made, the look-again date and the inspection documents read once
      per call, each test settled once at the end. Target under 80
      queries for 15 tests and 30 defects (from 362), checked with
      `QueryCounter` in a test.

### 41.6.3 Edge cases (findings 6, 7, 8)
- [ ] A transport error whose message holds the vehicle URL: the stored
      status and the job output show no registration or VIN.
- [ ] DVSA reorders a stored test's defects: each issue link and *Not
      now* stays with its own text.
- [ ] An older, never-reviewed test with a defect whose text matches a
      live issue from a later test: no "advised again" note dated before
      the issue.

### 41.6.4 Paperwork (finding 5)
- [ ] One status word for decided questions in the phase file and the
      log (or a note in the log's intro that *Scheduled* means decided
      and built).

### 41.6.5 Release
- [ ] `VERSION` → 3.7.1; `CHANGELOG.md` (*Fixed*); `ROADMAP.md` row ✅.

---

## Acceptance criteria

1. Settings → MOT history has no element past the viewport at 375 px,
   in light and dark.
2. *Add all as issues* on 15 tests with 30 defects runs under 80
   queries; History runs no more queries with MOT history on than
   before this phase, less one.
3. Each of findings 6–8 has a test: failing then fixed, or passing and
   kept.
4. Definition of done (CLAUDE.md §11) holds.

## Open questions

None of this phase's own. #346–#349 (from Phase 41's merge review) stay
open in [`open-questions.md`](open-questions.md) until the owner
decides them; none blocks the tasks above.
