# Phase 52.2 — More quick wins from the known-issues log: Parts polish and docs

*Eight more small rows of the known-issues log closed: four on the Parts
pages and Ask's `parts` tool, and four in the docs and logs.*

Status: 📋 planned · file lives in `docs/phases/`

This is the second batch after [Phase 52.1](phase-52.1.md). Like that
phase, it takes small open rows that need little or no decision.
Batch 3 is Parts polish found in Phase 50.2's reviews: K17, K36, K37
and K38. Batch 4 is docs and logs: K30, K32, K33 and K34. It is numbered
52.2 because it follows 52.1 and branches off master before Phase 53.1.

Read [`CLAUDE.md`](../../CLAUDE.md), [`spec.md`](../../spec.md) §7.24
(*Needs attention*), §7.26 (the Ask tools), §7.39 (*Parts and
modifications*), §7.36 (*Demo mode*), §8 *Printing reports*, and
[`open-questions.md`](open-questions.md) first.

**Prerequisites:** [Phase 52.1](phase-52.1.md) merged.

---

## Goals

### Batch 3: Parts polish

1. **K17.** Ask's (and MCP's) `parts` tool words a warranty with the
   same translated strings as the part page. German has no English "or"
   in it, and an ended warranty keeps its end date ("Warranty ended 3
   Mar 2026").
2. **K36.** No blank gap of about 50 px between the *For your insurer*
   link and the first modification row on the Parts tab.
3. **K37.** A new part's *Warranty starts from* has *Purchase date*
   selected, so a start is always shown (#482, decided 2026-10-17).
4. **K38.** The demo banner doesn't print on the *For your insurer*
   report, or on any other printed page.

### Batch 4: docs and logs

5. **K30.** K1 (decimal separators in CSV import) and K3 (who may set
   *Declared on* outside the insurer page) each get a question in
   `open-questions.md`, with the options already seen. They are
   **logged, not decided** here. K8's question was logged and decided as
   #479 in Phase 52.
6. **K32.** Spec §8 *Printing reports* lists every page that uses
   `.print-report`. It lists the five Phase 17.2 pages but not the six
   added since: Forecast, Finance, True cost, Trip claim, Claims history
   and *For your insurer*.
7. **K33.** Spec §7.24 says undeclared modifications are not a *Needs
   attention* item and points to §7.39, so #438's citation of §7.24 is
   right.
8. **K34.** Phase 50.2's task 50.2.0 names the questions found while
   starting (#444–#447) as well as #437–#443.

## Not in scope

- Deciding K1 or K3. This phase only logs their questions (CLAUDE.md
  §12). Each fix goes to a later phase once its question is decided.
- The other tap-target rows (K16, K35, K55). They need a design pass
  across many pages, not a quick fix.
- K39 (show *Remind me before it ends* on the part page). It waits on
  its own decision.

---

## Decisions (and why)

Apart from K37, which #482 decided, these need no decision from the
owner. Each makes the code or docs say what the spec already says.

- **K17 reuses the part page's strings:**
  `part.warranty.until_both`, `until_date`, `until_distance`, `active`,
  `ended_on` and `ended`. They are already translated, so the tool and
  the page word a warranty the same way, and the hand-joined "or" goes
  away. `ask.result.in_warranty` is removed if nothing else uses it.
  The tool's data fields (`active`, `until`, `until_odometer`) don't
  change, only `display`.
- **K37 follows #482** (decided by the owner): the form chooses
  *Purchase date* for a new part. Nothing stored changes for existing
  parts, and the API's default stays #427's.
- **K38 hides the demo banner in print on every page,** not only on the
  insurer report. Like the navigation, it is chrome rather than content,
  and every printed page would show it otherwise.
- **K33 adds a line to §7.24,** rather than changing #438. #438's
  decision ("no *Needs attention* item") is about §7.24's subject, so
  §7.24 is where a reader would look for it.
- **K30 logs the questions with the options already in the K30 row,**
  plus a recommendation each. The owner decides them later, as for any
  other open question.

---

## Tasks

### 52.2.0 Start of phase

- [ ] Read `open-questions.md` and the *Open* and *Scheduled* rows of
      `known-issues.md` (CLAUDE.md §12). #482 is decided; decide the
      release.

### 52.2.1 Parts polish (batch 3)

- [ ] `Parts::warranty()` builds `display` from the `part.warranty.*`
      strings, keeping an ended warranty's date (K17).
      `PartsToolsTest` covers it in English and German, for an ended
      warranty with a date and one with both limits.
- [ ] Remove the gap under the *For your insurer* link on the Parts tab
      (K36). Check it at 375 px and 1280 px.
- [ ] *Purchase date* selected in *Warranty starts from* on a new part's
      form: the values for a new part carry `warranty_from=purchase`,
      and an edit shows what's stored (K37, #482). `PartsTest` checks
      the chip on the new-part form, and that a part saved with it and
      no bought-on date runs its warranty from the first fitting.
- [ ] `.demo-banner` hidden under `@media print` (K38). A test checks
      the rule is there; the insurer report is checked in print preview.

### 52.2.2 Docs and logs (batch 4)

- [ ] `open-questions.md`: questions for K1 and K3, each with its
      options and a recommendation, *Open*. The K1 and K3 rows name
      them, and K30 is *Fixed* (K30).
- [ ] Spec §8 *Printing reports*: all eleven `.print-report` pages
      (K32).
- [ ] Spec §7.24: one line saying undeclared modifications aren't an
      item, pointing to §7.39 (K33).
- [ ] `phase-50.2.md` task 50.2.0 names #444–#447 (K34).

### 52.2.3 Finish

- [ ] Known-issues rows K17, K30, K32, K33, K34, K36, K37 and K38
      *Fixed*.
- [ ] CHANGELOG *Unreleased*; spec §13; ROADMAP status.
- [ ] Merge review, then merge.

## Acceptance criteria

- Each code row (K17, K37, K38) has a regression test. K36 is checked
  by screenshot at 375 px and 1280 px.
- `composer lint`, `composer analyse` and `bin/test-all-dbs.sh` pass on
  SQLite, PostgreSQL, MySQL and MariaDB. `MarkdownLinksTest` passes.
- No migration. Any new or changed strings are in English and German.
- K1's and K3's questions are in `open-questions.md`, *Open*.

## Open questions

- **#482. Decided 2026-10-17:** option 2. A new part's form has
  *Purchase date* selected in *Warranty starts from*, since a bought-on
  date is usually entered. A purchase-date start with no bought-on date
  still runs from the first fitting, as `PartWarranty` already does. The
  API and imports keep #427's default (the purchase date when there is
  one, else the first fitting). Not chosen: a hint only (1); a chip that
  follows the bought-on date as it's typed, with JS (3). spec §7.39.
- **Release.** Not decided yet. It could go with Phase 52.1's v3.16.3
  if both are merged before that tag, or be its own patch. Decide at
  52.2.0.
