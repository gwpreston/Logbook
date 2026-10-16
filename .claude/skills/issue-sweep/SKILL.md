---
name: issue-sweep
description: Kicks off a chosen set of Logbook's review subagents (bug-hunter, security-scanner, import-tester and the rest), sends everything they report to the finding-reviewer agent to decide whether each is a real issue, relays the reviewer's questions back to the agent that reported it until it's settled, and then records the confirmed issues in docs/phases/known-issues.md (and any choices in open-questions.md). Use whenever Gareth asks to "sweep for bugs", "run the bug hunter and log what it finds", "find issues and record them", "triage these findings", "record the follow-ups in known issues", or invokes /issue-sweep — including with a merge-review report as input instead of running agents. Not a merge decision: that's merge-review.
---

# Issue sweep

Find issues with the review agents, have an independent reviewer decide
which are real, get the reporting agent to dig further when the reviewer
isn't sure, and only then put them on the record. The point is that
`docs/phases/known-issues.md` holds **confirmed** issues, each with
evidence, in the format later phases rely on (`CLAUDE.md` §12).

You orchestrate; the agents investigate. Subagents can't call each
other, so every hand-off — finding to reviewer, question to reporter,
answer back to reviewer — goes through you.

Read `.claude/review-rules.md`, `CLAUDE.md` §12,
`docs/phases/known-issues.md` and the top of
`docs/phases/open-questions.md` first.

## 1. Inputs

Work out from the request:

- **Agents to run.** The ones named ("run bug-hunter and import-tester").
  If none are named, choose by the diff with the skip table in
  `.claude/skills/merge-review/SKILL.md` §3. Only read-only review
  agents; never test-writer. If an agent file is missing, say so and
  carry on with the rest.
- **Or a report instead.** If given a merge-review report (or an agent's
  report), skip step 2 and triage its findings, *Follow-ups* and
  *Unconfirmed* items.
- **Scope** as review-rules §1, and the head SHA.
- **Phase**, for the section heading and *Since*: from the branch name,
  commits (`Phase NN.N: …`) or `ROADMAP.md`. If unclear, ask.
- **Dry run** if asked ("don't write yet"): do everything except step 6.

## 2. Run the agents

Launch the chosen agents **in parallel** (one Task call each, same turn),
each with the scope, head SHA and any gate results you already have.
Keep each agent's **id** if the harness returns one, so it can be resumed
in step 4. Collect each report in full.

## 3. Collect and send to the reviewer

1. **Gather** every finding, every *Unconfirmed* item and every *Handed
   over* line from every report. A handed-over line counts as a finding
   reported by the agent that noticed it; note which agent owns the area.
2. **Merge duplicates within this run** (same cause from two agents) into
   one item listing both reporters.
3. **Send to `finding-reviewer`**, one call per reporting agent with that
   agent's items, in parallel. Give it each item's full block, the
   reporter's name, the scope, head SHA and phase.

## 4. Follow-up loop (NEEDS INFO)

For each item the reviewer marks **NEEDS INFO**:

1. **Ask the reporter.** Resume the original agent by its id if you
   can; otherwise launch the same agent again with its original finding
   block, the reviewer's questions and the scope. For a handed-over item,
   ask the **owning** agent (review-rules §5) instead, since it has the
   checks for that area. Tell it to answer each question with evidence
   (command and output, test and result, the line it read) and to say
   plainly if the finding was wrong.
2. **Send the answers back** to `finding-reviewer` with the original
   item, its first verdict and the questions.
3. At most **two rounds** per item. The reviewer gives a final verdict
   on the second round; anything still not settled is **Not recorded —
   unresolved**, listed in your summary with what's missing.

Run the loops for different items in parallel.

## 5. Decide what goes on the record

| Reviewer verdict | Action |
|---|---|
| VALID | New row in `known-issues.md` |
| NEEDS DECISION | New row (its *Issue* ends with "Needs a decision first …"), **and** a new open question |
| DUPLICATE of K*n* | No new row. Note it in the summary; if the new evidence changes the row (worse severity, more places), list the suggested change for the owner — don't edit existing rows |
| NOT A BUG | Not recorded. Listed in the summary with the reason |
| Unresolved | Not recorded. Listed with what's missing |

A regression of a *Fixed* row is a new row whose *Issue* starts
"Regression of K*n*:". Never edit or delete existing rows here; status
changes are the phase's job (`CLAUDE.md` §12).

## 6. Write

**`docs/phases/known-issues.md`**
- Next number: one more than the highest `K` number anywhere in the file;
  never reuse a number.
- Add a section at the end of the dated sections, in the file's style:
  `## Found in Phase <n> (<agents or "merge review"> at `<short-sha>`, <YYYY-MM-DD>)`
  with the table header `| # | Severity | Area | Issue | Where | Since | Status |`.
  If a section for the same phase and SHA already exists, add to it
  instead.
- One row per item, most severe first, from the reviewer's row fields,
  *Status* `Open`.

**`docs/phases/open-questions.md`** (NEEDS DECISION only)
- Next number after the highest `#` in the file.
- A row in the file's table format with the phase, the reviewer's
  *Question* (and "(found in review)"), status **Needs a decision**, the
  options in the answer column without choosing one, and today's date.
- Mention the K number in the question and the question number in the
  known-issues row, so each points to the other.
- Don't decide anything, and note in the summary that the phase file's
  *Open questions* needs the same entry (spec-keeper checks this).

Don't commit. Leave the changes for the owner to look at with
`git diff docs/phases`.

## 7. Summary

Save it as `var/issue-sweep/<branch>-<short-sha>.md` and show it:

```markdown
# Issue sweep: <branch> @ <short-sha> (Phase <n>)

Agents run: bug-hunter, import-tester · Items reviewed: 14
Recorded: 6 (K27–K32) · Open questions: 1 (#448) · Duplicates: 3 ·
Not a bug: 3 · Unresolved: 1

## Recorded
| K | Severity | Area | Issue (short) | Reporter | Rounds |
|---|---|---|---|---|---|

## Duplicates
- import-tester "UTF-16 decoded as 1252" → K10 (no change needed)

## Not a bug
- bug-hunter "Zero-cost fill-up hidden on dashboard" — spec §7.8 says
  fill-ups with no cost are left out of the cost card (quote).

## Unresolved (not recorded)
- security-scanner "Session not rotated on break-glass" — couldn't
  reproduce without SSO configured; needs a run with the forward-auth
  stack.

## For the owner
- Suggested changes to existing rows; open questions to add to the phase
  file; anything an agent couldn't run.
```

## Clean-up

Remove `var/finding-reviewer/` and the reporting agents' scratch folders
(review-rules §2), check no `review-*` compose projects are left running
(`docker compose ls`), and keep the summary.
