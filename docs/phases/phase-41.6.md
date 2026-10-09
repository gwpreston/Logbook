# Phase 41.6 — MOT history follow-ups + patch release

*The small things Phase 41's merge review found, and the edge cases it
couldn't prove.*

Status: ✅ complete · released as **v3.7.1** · file lives in `docs/phases/`

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
- [x] §7.38 wording for the proofs below (*Upsert by test number*,
      *Repeats*, *Requests*, *Review card*, *History*) and §13, before
      the code.

### 41.6.1 Phone layout (findings 1, 2)
- [x] The *Test* button wraps (`btn--wrap`: `white-space: normal`, never
      wider than its card), checked at 375 px with the provider names
      the app has: "Test Sample MOT history (development)" wraps to two
      lines; the DVSA one fits. English only: the label is "Test
      {provider}" in en and de, and wrapping takes any length.
- [x] The *Sends …* hint: icon and text on one row
      (`toggle__hint--icon`).

### 41.6.2 Fewer queries (findings 3, 4)
- [x] History counts defects per test in the query that lists the tests
      (`MotTestRepository::summariesForVehicles()`, a count in the
      select instead of a second query, which is also one query fewer
      and no hydrating of defect text); `MotReviewQueryCountTest`
      checks 2 queries → 1.
- [x] *Add all as issues* / *Add all passes as documents*: the issues
      made, the look-again date, the zone and the inspection documents
      read once per call; each test settled once at the end (also
      `applyRepeats()` and *Not now*). 15 tests and 30 defects: 330 →
      213 queries, of which 180 are `IssueService::create` itself
      (6 a defect, measured); the reads around the creates no longer
      grow with the defects (`MotReviewQueryCountTest` compares 6 and
      30 defects). See the note under *Acceptance criteria*.

### 41.6.3 Edge cases (findings 6, 7, 8)
- [x] A transport error whose message holds the vehicle URL: proved
      failing (`DvsaProviderTest::testATransportErrorNeverCarriesTheRegistrationOrTheVin`),
      fixed: the message is cut to DVSA's host and any
      `/registration/…` or `/vin/…` path is dropped before it is stored
      or logged.
- [x] DVSA reorders a stored test's defects: proved failing
      (`MotReviewTest::testReorderedDefectsKeepTheirOwnIssueAndNotNow`),
      fixed: defects are matched by text, a corrected text at the same
      place keeps its row, positions follow DVSA's order.
- [x] An older, never-reviewed test with a defect whose text matches a
      live issue from a later test: proved failing
      (`MotReviewTest::testAnOlderTestNeverReviewedIsNotAdvisedAgainAfterTheIssueItBecame`),
      fixed: only a test after the earliest one the issue came from
      advises it again.

### 41.6.4 Paperwork (finding 5)
- [x] The log's intro says what *Scheduled* means when the work was
      built in the phase that decided it (the phase file's *Decided*).

### 41.6.5 Release
- [x] `VERSION` → 3.7.1; `CHANGELOG.md` (*Fixed*); `ROADMAP.md` row ✅.
- [x] Tag v3.7.1 once merged.

---

## Acceptance criteria

1. Settings → MOT history has no element past the viewport at 375 px,
   in light and dark. *Met* (checked in the browser, both schemes: no
   element past 375 px, document width 375).
2. *Add all as issues* on 15 tests with 30 defects: the reads around
   the creates are once per call. *Met, with a different number than
   drafted:* the draft's "under 80 (from 362)" was set before the
   floor was measured. Creating one issue costs 6 statements by
   itself (a plain `IssueService::create` ×30 sends 180), so under 80
   cannot be reached without batching the issue create path, which
   belongs to no finding here. Measured: 330 → 213, the 33 above the
   creates being the request and the one read of each table.
   History runs one query fewer than before.
3. Each of findings 6–8 has a test: failing then fixed (all three
   failed before their fix). *Met.*
4. Definition of done (CLAUDE.md §11) holds. *See the PR.*

## Design review

375, 768 and 1280 px, light and dark, Settings → MOT history, signed in
as the demo owner on the dev stack (PostgreSQL), at the root. The page is
the only template changed; there is no new page or link, so no new
subpath test (the existing settings test already runs the page).

- **HIGH / MEDIUM:** none.
- *Test* button: wraps to two lines at 375 and 768 px with the longest provider
  name here (Sample MOT history (development)); one line at 1280 px, inside its card throughout. Fixed (finding 1).
- *Sends …* hint: the shield sits on the first line of the text, which
  wraps beside it, in both schemes. Fixed (finding 2).
- JS off: the page needs none.

## Open questions

None of this phase's own. #346–#349 (from Phase 41's merge review) stay
open in [`open-questions.md`](open-questions.md) until the owner
decides them; none blocks the tasks above.
