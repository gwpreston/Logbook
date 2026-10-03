---
name: release
description: Cut the next Logbook release: find the completed phases not yet released, make sure CHANGELOG.md, VERSION, README.md, ROADMAP.md and the phase files cover them, then add the changelog compare links, tick the phase's tag item, tag master and publish the GitHub release. Run with /release once the phase's PR has been merged into gwpreston16/Logbook master.
disable-model-invocation: true
argument-hint: "[version, e.g. 2.15.0 — optional, worked out from the phases if left out]"
---

# Release Logbook

A Logbook release is one or more completed phases (see `ROADMAP.md`),
shipped as `vX.Y.Z`. The upstream repository is **gwpreston16/Logbook**;
`origin` is the fork (gwpreston/Logbook) and `upstream` is gwpreston16. Pass
`-R gwpreston16/Logbook` to every `gh` command, or `gh` may pick the fork.

Pushing a `v*` tag makes CI publish the multi-arch Docker image as `X.Y.Z`,
`X.Y`, `X` **and `latest`**, and the in-app update check offers the GitHub
release to every install. Neither can be quietly taken back, so the skill
checks everything first and asks before anything leaves this machine.

Each step checks before it acts. Releases are usually half-done when this
runs: the phase branch already carries the CHANGELOG section, `VERSION`,
the README status and ROADMAP ✅. Fill gaps; don't redo what's there.

## 1. Work out what is outstanding

```bash
git status --porcelain                 # must be empty; stop otherwise
git fetch upstream --tags && git fetch origin
git tag --sort=-v:refname | head -1    # last released tag, e.g. v2.13.0
cat VERSION
grep -n '^## \[' CHANGELOG.md | head -3
gh pr list -R gwpreston16/Logbook --state open
gh api repos/gwpreston16/Logbook --jq .permissions.push
```

- **Outstanding phases** are the ROADMAP rows marked ✅ whose phase file
  isn't yet covered by a tag: the phase file's `### Release` list has no
  ticked `Tag \`vX.Y.Z\` once merged.` item, and its version is newer than
  the last tag. There can be more than one (2.11.0 shipped 28.1 and 28.2,
  2.12.0 shipped 29.1 and 29.2). The version is the one the phase titles
  name (`+ v2.14 release`) and the CHANGELOG/VERSION use; otherwise
  `$ARGUMENTS`; otherwise ask. New features bump the minor; a major needs
  the owner's say-so and upgrade notes.
- **Stop** if any of these is true, and say why:
  - The phase's work isn't on `upstream/master` yet (its PR is still open,
    or `git merge-base --is-ancestor <phase commit> upstream/master` fails).
    Releases are cut from master only.
  - The tag `vX.Y.Z` already exists locally or on upstream.
  - A ROADMAP row for the phase is 🚧 or 📋, or the phase file still has
    unticked tasks other than the tag item.
  - Open questions for the phase are still open (CLAUDE.md §12).

Then switch to master at upstream: `git switch master && git merge --ff-only upstream/master`.

## 2. Check the content (fill what's missing)

Read the previous release's section and commit (`git log -1 --format=%B
v<previous>~1`, `git show v<previous>`) for the house style and copy it.
The writing is plain, user-facing and concrete; what the user sees, not
class names.

**CHANGELOG.md**
- A section `## [X.Y.Z] — YYYY-MM-DD` under `## [Unreleased]` (empty).
  Anything sitting under *Unreleased* moves into it.
- Opens with `Phase N: **what it is**.` and a short paragraph, with a link
  to the guide in `docs/` if there is one. Several phases: `Phases N and M:`.
- Then `### Added`, `### Changed`, `### Fixed` as needed, and
  `### Upgrade notes` (migrations, anything beyond "pull and restart",
  new env vars, things off by default).
- Build it from the phase files' task lists, `spec.md` and `git log
  v<previous>..upstream/master`. Don't invent features that aren't merged.

**VERSION** holds `X.Y.Z` (no `v`).

**README.md**
- `> **Status: vX.Y.Z.**` in the blockquote near the top.
- One clause per new phase added to that status paragraph, before
  `in English and German`, in the same style as the clauses before it.
- A row in the *Documentation* table for each new `docs/*.md` guide.

**ROADMAP.md** rows ✅; **phase files** say `Status: ✅ complete · released
as **vX.Y.Z**`.

If any of this was missing, it's real content: show the user the changes
and get their OK on the wording before going on.

## 3. Prepare the release commit

On master:

1. CHANGELOG footer: point `[Unreleased]` at `vX.Y.Z...HEAD` and add the
   new compare link above the previous one:
   ```
   [Unreleased]: https://github.com/gwpreston16/Logbook/compare/vX.Y.Z...HEAD
   [X.Y.Z]: https://github.com/gwpreston16/Logbook/compare/vPREV...vX.Y.Z
   ```
2. In each released phase file, under `### Release`, add
   `- [x] Tag \`vX.Y.Z\` once merged.` (and fix the status line if it
   still says `releases`).
3. Commit (with the Co-Authored-By line from the session's attribution):
   `Release vX.Y.Z: compare links in the changelog; tick the tag item`
   (add "; CHANGELOG, VERSION and README" etc. if step 2 changed them).

Extract the release notes: the CHANGELOG text between `## [X.Y.Z]` and the
next `## [`, without the heading and without trailing blank lines. Write it
to the scratchpad, e.g. `notes-X.Y.Z.md`. That is exactly what earlier
releases used as their body.

## 4. Ask before publishing

Show the user: the version, the phases it covers, the commit, the release
notes, and what happens next (push to master, tag, Docker `latest`, GitHub
release marked latest). Wait for a clear yes.

## 5. Push, tag and release

**If `permissions.push` was true** (the active `gh` account is the owner):

```bash
git push upstream master
gh run list -R gwpreston16/Logbook --branch master --limit 1   # wait for green
git tag -a vX.Y.Z -m "Logbook X.Y.Z"      # annotated, on the release commit
git push upstream vX.Y.Z
gh release create vX.Y.Z -R gwpreston16/Logbook \
  --title "Logbook X.Y.Z" --notes-file <scratchpad>/notes-X.Y.Z.md --latest
```

Wait for master's CI to pass before tagging: the tag builds and publishes
the image, so tagging a red commit ships a broken `latest`.

**If not** (the usual case for the `gwpreston` account, which works from
the fork): the tag and release need someone who can push to gwpreston16.

1. Push the commit to a branch on the fork (`release-vX.Y.Z`) and open a
   PR into gwpreston16 master titled `Release vX.Y.Z`, body ending with the
   session's PR attribution line.
2. Tell the user it must be merged as a fast-forward or merge commit (not a
   squash that changes the commit), then the tag goes on the merged commit.
3. Offer the remaining commands for the owner, with the merged commit:
   `gh auth switch -u gwpreston16` if that account is logged in here
   (check `gh auth status`), or to be run from the owner's machine:
   ```bash
   git fetch upstream && git switch master && git merge --ff-only upstream/master
   git tag -a vX.Y.Z -m "Logbook X.Y.Z" && git push upstream vX.Y.Z
   gh release create vX.Y.Z -R gwpreston16/Logbook --title "Logbook X.Y.Z" \
     --notes-file notes-X.Y.Z.md --latest
   ```
   If the user switches account and asks you to carry on, do the tag and
   release steps from the block above.

## 6. Check and report

```bash
gh release view vX.Y.Z -R gwpreston16/Logbook
git ls-remote --tags upstream vX.Y.Z
gh run list -R gwpreston16/Logbook --limit 3        # the tag's image build
```

Report: the version and phases released, the release URL, the tag's
commit, and the image build's state (or what's left for the owner to do).
If any step was skipped or failed, say so plainly.
