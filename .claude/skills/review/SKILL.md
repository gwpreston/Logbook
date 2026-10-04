---
name: review
description: Runs all four Logbook review agents (bug-hunter, security-scanner, performance-auditor, design-reviewer) on a branch or pull request and turns their reports into one clear MERGE or REJECT decision with the reasons. Use this whenever Gareth asks "can I merge this", "is this ready", "review this PR / branch / phase", "should this go in", "pre-merge check", "release check", or invokes /merge-review — even if he names only one concern, since the point is the combined verdict. Never merges, pushes, or edits code itself.
---

# Merge review

Give the owner a single, defensible answer to "can this be merged?" by
running every review agent, checking their evidence, and applying fixed
decision rules. The value of this skill is that the answer is **binary,
consistent and explained**: the same findings always produce the same
verdict, and every blocking reason points to proof.

You decide; you don't act. Never merge, push, approve a PR, resolve
threads, or modify `src/`, `config/`, `db/`, `templates/`, `assets/`,
`tests/` or `design-import/`. If the owner wants the report posted as a
PR comment, do that only when asked.

## 1. Establish what's being reviewed

- Default: the current branch against `origin/main`
  (`git fetch origin && git diff --stat origin/main...HEAD`), plus any
  uncommitted changes. If the owner names a PR number or branch, use that.
- If there's no diff, stop and ask what to review.
- Note the head commit SHA. The verdict is for that SHA only; any later
  commit needs a new review.
- Read `CLAUDE.md` §11 (*Definition of done*) and the phase file for the
  work (`docs/phases/phase-<n>.md`) if the branch belongs to one.

## 2. Run the definition-of-done gates

Run these once yourself, so the agents don't each run them and so the
verdict rests on one set of results. Record pass/fail and the key output.

| Gate | Command |
|---|---|
| Lint | `composer lint` |
| Static analysis | `composer analyse` |
| Tests, all engines | `bin/test-all-dbs.sh` (SQLite, Postgres, MySQL, MariaDB); if Docker isn't available, `composer test` and mark the other engines *not run* |
| Coverage | `composer test:coverage && composer coverage:check`, and changed-line coverage ≥ 80% of the `src/` lines the diff adds or modifies |
| Migrations | migrate → full rollback → migrate on Postgres and MySQL (covered by `bin/test-all-dbs.sh`) |
| Assets | `composer build-assets` then `git status --porcelain public/assets` is empty (built output committed and current) |
| Translations | the suite's translation checks pass (part of the tests) |
| Docs and config | new environment variables are in `.env.example` and `docs/configuration.md`; `CHANGELOG.md` has an entry; `spec.md` updated for any new behaviour |
| Open questions | no question in the phase file left neither decided nor carried into `docs/phases/open-questions.md` |

Check the docs, changelog and open-questions gates by reading the diff;
they're judgement calls, so quote what's missing.

## 3. Run the four agents

Launch all four agents **in parallel** (one Task call each, in the same
turn), giving each the same scope, the head SHA and the gate results from
step 2 so it doesn't rerun them:

- `bug-hunter`
- `security-scanner`
- `performance-auditor`
- `design-reviewer`

Ask each one to use its own report format and to label every finding with
its severity and whether it is **new in this diff** or **pre-existing on
main**.

If an agent file is missing from `.claude/agents/`, say so and treat that
review as *not run*. If an agent couldn't do part of its job (no Docker, no
headless browser, a database it couldn't start), record exactly what it
couldn't check.

**Skip rule:** an agent may be skipped only when the diff can't affect its
area: for example, design-reviewer when the diff touches no files under
`templates/`, `assets/`, `translations/` or `public/assets/` and changes no
visible output. Performance-auditor and security-scanner are never skipped
for changes under `src/`, `config/` or `db/`. Record every skip with its
reason.

## 4. Check the evidence

Agents can be wrong. Before a finding can block a merge:

1. It must have **proof**: a failing test, a reproducible request, a
   measurement, or a screenshot pair. If it doesn't, move it to
   *Unconfirmed*. Unconfirmed findings never block on their own.
2. For every finding that would block, **re-run its proof yourself**
   (run the failing test, repeat the request, re-measure). If it doesn't
   reproduce, move it to *Unconfirmed* and say so.
3. **Merge duplicates.** The same root cause reported by two agents (a
   cost leak found by both bug-hunter and security-scanner, say) is one
   finding at the higher severity, with both sources listed.
4. **Pre-existing vs new.** Confirm whether each blocking finding exists on
   `origin/main` by checking it there (scratch worktree:
   `git worktree add var/merge-review/main origin/main`, removed
   afterwards). A pre-existing problem doesn't block this merge **unless
   the diff makes it worse or makes it reachable in a new way**. List it
   as a follow-up instead.

## 5. Apply the decision rules

The verdict is **REJECT** if any one of these is true. Otherwise it is
**MERGE**.

**Gates**
- Any of lint, static analysis or the test suite fails, on any engine.
- A migration doesn't apply and roll back cleanly on Postgres and MySQL.
- Coverage is below the floor, or below 80% of the changed `src/` lines.
- Built assets are stale.
- New behaviour without a `spec.md` entry, new configuration missing from
  `.env.example`, or an open question neither decided nor logged.

**Findings (confirmed and new or made worse by this diff)**
- Any **CRITICAL** from any agent.
- Any **HIGH** from bug-hunter or security-scanner.
- Any **HIGH** from performance-auditor that is a **regression** against
  main (a HIGH that's equally bad on main is a follow-up).
- Any **HIGH** from design-reviewer that is an accessibility failure
  (contrast, keyboard, focus, status by colour alone) or makes a flow
  unusable (overflow hiding content, broken without JS).
- Three or more **MEDIUM** findings from the same agent in this diff:
  that's a pattern, not a nit.

**Incomplete review**
- Any agent that should have run (by the skip rule) didn't, or couldn't
  check a part of its area that the diff touches. The verdict is then
  **REJECT — review incomplete**, with what needs running. An unverified
  change is not a mergeable one.

Everything else (MEDIUM and LOW findings, pre-existing issues,
unconfirmed findings, open questions raised by the agents) goes in the
report as follow-ups and doesn't change the verdict.

Don't soften or bend these rules on your own judgement. If you think a
rule gives the wrong answer for this diff, say so in *Notes for the owner*
and still give the verdict the rules produce. The owner decides whether
to override.

## 6. Report

Save the report as `var/merge-review/<branch>-<short-sha>.md` and show it
in full. Use exactly this structure:

```markdown
# Merge review: <branch> @ <short-sha>

## Verdict: MERGE ✅  |  REJECT ❌  |  REJECT — review incomplete ⚠️

<One or two sentences saying why, in plain words.>

## Blocking reasons
<Only when rejected. Numbered, most serious first. Each: the rule it
breaks (from §5), the finding title, where (file:line or page), the
agent(s) that found it, and the proof you re-ran.>

1. **[CRITICAL · security] Member can read another user's fill-up costs via the API**
   Rule: CRITICAL finding. Where: src/Action/Api/FuelListAction.php:57.
   Found by: security-scanner, bug-hunter. Proof: `ApiCostLeakTest`
   fails on all engines (re-run ✔).

## To get to MERGE
<Only when rejected. The shortest list of things to do, in order, each
tied to a blocking reason. No patches.>

## Gates
| Gate | Result |
|---|---|
| Lint | ✅ / ❌ |
| … | … |

## Agents
| Agent | Ran | Findings (C/H/M/L) | Blocking | Notes |
|---|---|---|---|---|
| bug-hunter | ✅ | 0/1/2/0 | 1 | |
| security-scanner | ✅ | … | … | |
| performance-auditor | ✅ | … | … | Postgres + MySQL, heavy household |
| design-reviewer | skipped | – | – | no UI files in the diff |

## Follow-ups (don't block this merge)
<Non-blocking findings, grouped by agent, one line each with severity and
location. Pre-existing issues marked "on main".>

## Unconfirmed
<Findings without proof or that didn't reproduce, and what would confirm
them.>

## Open questions
<Collected from all agents, de-duplicated, for
docs/phases/open-questions.md. Not decided here.>

## Notes for the owner
<Anything the rules may get wrong for this diff, coverage gaps, or
what wasn't checked. Omit if empty.>
```

Keep the verdict line and the blocking reasons readable on their own: the
owner should be able to stop reading after *To get to MERGE* and know
exactly what to do.

## Re-reviews

When asked to review again after fixes, re-run everything against the new
head SHA. Don't carry results over from the previous run. In the new
report, add a short *Since last review* line listing which blocking
reasons are now resolved and any new ones.

## Clean-up

Remove the `var/merge-review/main` worktree and any scratch files the
agents left in `var/` (`var/bug-hunter/`, `var/security-scanner/`,
`var/performance-auditor/`), except screenshots in `var/design-reviewer/`
that the report links to. Keep the report file.