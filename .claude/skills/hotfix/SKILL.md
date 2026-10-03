---
name: hotfix
description: Ship a Logbook hotfix as a patch release: number a hotfix phase after the last released one (e.g. 30.3 after 30.2), write its phase file, record it in spec.md and ROADMAP.md, make the fix with a test, update CHANGELOG.md, VERSION and the README status, open the PR, and once it is merged tag master and publish vX.Y.(Z+1) through the /release steps. Run with /hotfix and a description of what is broken.
disable-model-invocation: true
argument-hint: "<what's broken> [version, e.g. 2.14.1 — optional, worked out from the last tag]"
---

# Hotfix release

A hotfix is a fix for something broken in the last release, shipped on its
own as a **patch** release (`vX.Y.Z` → `vX.Y.(Z+1)`). It gets a phase of its
own, numbered after the last released phase, so it has a phase file, a
ROADMAP row, a CHANGELOG section and a tag like any other release. The
precedent is [Phase 10.2](../../../docs/phases/phase-10.2.md), which shipped
as **v1.2.1**: read it and `git diff v1.2.0 v1.2.1 --stat` for the shape.

`$ARGUMENTS` describes what is broken (and may name the version). If it
doesn't say what is broken, ask.

The upstream repository is **gwpreston16/Logbook**; `origin` is the fork
(gwpreston/Logbook) and `upstream` is gwpreston16. Pass
`-R gwpreston16/Logbook` to every `gh` command.

The skill runs in two halves. **Part A** (steps 1–5) makes the fix and opens
its PR. **Part B** (step 6) runs once that PR is merged and tags and
publishes the release. If `/hotfix` is run again while a hotfix phase is
already written but not yet tagged, check where it stands and carry on from
there; don't start a second one.

## 1. Work out the phase number and version

```bash
git status --porcelain                 # must be empty; stop otherwise
git fetch upstream --tags && git fetch origin
git tag --sort=-v:refname | head -1    # last released tag, e.g. v2.14.0
cat VERSION
ls docs/phases/
grep -n '^| \[' ROADMAP.md | tail -6
gh pr list -R gwpreston16/Logbook --state open
```

- **Version:** the last tag with the patch number plus one (`v2.14.0` →
  `2.14.1`, `v2.14.1` → `2.14.2`), unless `$ARGUMENTS` names one. A hotfix
  is never a minor or major bump: the next minor versions are already
  booked by planned phases (ROADMAP 📋 rows and their phase files) and are
  never renumbered for a hotfix. Stop if the tag already exists.
- **Phase number:** find the phase (or phases) the last tag released: the
  phase file whose status says `released as **vX.Y.Z**` for that tag. Take
  the highest, `N.m`, and use `N.(m+1)` (30.2 → **30.3**; a second hotfix
  on top of 30.3 is 30.4). A whole-number phase `N` counts as `N.1`, so the
  hotfix is `N.2` (Phase 10 → 10.2). Check that `docs/phases/phase-N.(m+1).md`
  and a ROADMAP row with that number don't already exist; if they do, ask.
- **Stop if master already holds unreleased work.** Run
  `git log --oneline vX.Y.Z..upstream/master`. Anything there beyond
  release bookkeeping (e.g. Phase 31 merged but not released) would ship
  inside the patch tag and as Docker `latest`. Don't go on: tell the owner
  what is there and offer to cut the hotfix from the tag instead (a
  `hotfix-vX.Y.(Z+1)` branch from `vX.Y.Z`, tagged there, with the fix then
  merged forward into master). Do only what they choose.
- Read `docs/phases/open-questions.md` and the *Open questions* of the last
  released phase (CLAUDE.md §12). If one bears on the fix, ask before acting.

## 2. Find the cause

Reproduce the fault (a failing test is best; the `run` skill or the dev
stack from `bin/dev-setup.sh` otherwise) and find its cause before writing
anything. If the fix needs a decision that changes behaviour, data, UI or
configuration beyond putting right what was promised, it isn't a hotfix:
say so and ask the owner. A hotfix has **no migration** unless the owner
agrees to one.

## 3. Write the phase file and the spec first

Branch from master: `git switch master && git merge --ff-only upstream/master
&& git switch -c phase-N.M`.

**`docs/phases/phase-N.M.md`**, in the style of phase-10.2.md:

```markdown
# Phase N.M — <what is fixed, in the user's words> + vX.Y.Z

*<one line: what the user sees put right>*

Status: 🚧 in progress · releases **vX.Y.Z** · file lives in `docs/phases/`

**Goal:** <what is broken, who sees it, and what it should do instead>.

Read `spec.md` (<sections>) and `CLAUDE.md` (§11) before starting.

**Prerequisites:** [Phase N.m](phase-N.m.md) released as vX.Y.(Z-1).

---

## Scope
**In:** … **Out:** … (keep it to the fault; nothing new)

## Cause
<what goes wrong and why, with file and function names>

## Design decisions
<only if there is a choice to record>

## Tasks
### N.M.0 Spec first
- [ ] `spec.md` §13 entry (and §7.x if a rule needs recording);
      `ROADMAP.md` row and section.
### N.M.1 Fix
- [ ] …
### N.M.2 Tests
- [ ] A test that fails before the fix and passes after.
### Release
- [ ] `CHANGELOG.md` **X.Y.Z**: *Fixed* — …; upgrade notes.
- [ ] Bump `VERSION`, rebuild assets if any changed, update the README status.
- [ ] Tag `vX.Y.Z` once merged.

---

## Definition of done
- `composer lint`, `composer analyse` and `composer test` pass; CI green
  on SQLite, PostgreSQL, MySQL and MariaDB.
- <the fault, stated as fixed>
- Works at a subpath; the Docker image builds; the bare-PHP path needs no
  extra step.

## Open questions
None. <or what is still open, never guessed>
```

Keep the title's `+ vX.Y.Z`: `/release` reads a phase's version from it.

**`spec.md`**: a bullet in §13 straight after the last released phase's
bullet (before any planned phase's bullet, so §13 stays in phase order):
`- **Phase N.M — <title> + vX.Y.Z.** <one or two sentences>; release vX.Y.Z.`
If the fix pins down a rule the spec didn't state, add one sentence to the
§7 section it belongs to.

**`ROADMAP.md`**: a row in the status table after the last released phase
(above the 📋 rows) marked 🚧, and a section after that phase's section:

```markdown
## Phase N.M — <title> + vX.Y.Z
*<one line>*

- <what is fixed>
- Release **vX.Y.Z**.

→ [`docs/phases/phase-N.M.md`](docs/phases/phase-N.M.md)
```

Show the user the phase file's goal, cause and scope and get their OK
before writing the fix.

## 4. Fix, test and document

- Make the fix, following CLAUDE.md (§5–§8, and §11 for done). Add the
  test from the phase file; for a fix tests can't see (CSS, layout) add a
  structural test as 10.2 did and check it in the browser.
- Rebuild assets if any source asset changed, and commit the built ones.
- New or changed user-facing strings go in every catalogue in
  `translations/`.
- `composer lint && composer analyse && composer test`. Run the suite on
  PostgreSQL, MySQL and MariaDB as well as SQLite when the fix touches the
  database (`bin/dev-setup.sh --mysql` etc.).

Then the release content, in the house style of the last release
(`git show vX.Y.(Z-1) --stat`, its CHANGELOG section):

**CHANGELOG.md**: a section under `## [Unreleased]` (left empty):

```markdown
## [X.Y.Z] — YYYY-MM-DD

Phase N.M: <what is fixed, in a few words>.

### Fixed
- <what the user saw, and what happens now; plain and concrete, no class
  names>

### Upgrade notes
- None: no migrations, no configuration changes and no change to the backup
  format.
```

Adjust the upgrade notes if any of that isn't true. Don't add the compare
links yet; `/release` adds them.

**VERSION**: `X.Y.Z`.

**README.md**: only the version in `> **Status: vX.Y.Z.**`. A fix isn't a
new capability, so no clause goes into the status paragraph (v1.2.1 added
none). A new `docs/` guide would get a row in the *Documentation* table,
but a hotfix shouldn't need one.

**Phase file**: tick every task except the tag item, set the status to
`✅ complete · releases **vX.Y.Z**`. **ROADMAP.md**: the row ✅.

## 5. Open the PR

Commit (message `Phase N.M: <what is fixed>; vX.Y.Z`, ending with the
session's Co-Authored-By line), push the branch to `origin` and open a PR
into gwpreston16 master titled `Phase N.M: <title> + vX.Y.Z`. The body says
what was broken, the cause, the fix, how it was tested, and ends with the
session's PR attribution line.

Wait for CI. Tell the user the PR must be merged as a fast-forward or merge
commit (not a squash), and that `/hotfix` (or `/release`) finishes the
release once it is merged. Stop here until it is.

## 6. Tag and publish (after the merge)

Check the PR is merged and its commit is on `upstream/master`
(`git merge-base --is-ancestor <commit> upstream/master`), and that
`git log --oneline vX.Y.(Z-1)..upstream/master` still holds only this
hotfix. Then read `.claude/skills/release/SKILL.md` and follow its steps 3
to 6 for vX.Y.Z and Phase N.M: the changelog compare links, ticking the tag
item, the release commit, **asking the owner before anything is pushed or
tagged**, waiting for master's CI to go green, tagging, the GitHub release
marked `--latest` (or the fork PR and the commands for the owner when the
active `gh` account can't push upstream), and the final check.

The tag publishes the Docker image as `X.Y.Z`, `X.Y`, `X` and `latest`, and
the in-app update check offers it to every install: neither can be taken
back, so never skip the ask.

## 7. Report

The phase number, version, what was fixed and its cause, the PR, and the
release URL and image build state (or what is left for the owner). Say
plainly if any step was skipped or failed.
