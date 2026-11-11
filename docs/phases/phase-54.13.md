# Phase 54.13 — The Symfony advisories and the vendored libraries from the known-issues log + patch release

*Four open rows of the known-issues log closed: two after a decision of the
owner's, the locked Symfony packages no longer carry the 2026-11-04
advisories, and the bundled front-end libraries match `package.json` with a
test that keeps them so.*

Status: ✅ complete · releases **v3.21.10** together with [Phase 54.14](phase-54.14.md), a patch release (#603, #609) · file lives in `docs/phases/`

This is the batch after [Phase 54.12](phase-54.12.md). It takes K182 and
K183, the only open rows that could be fixed once #597 and #598 were
decided (2026-11-06). The rest wait on an upstream release or an API key
(see *Not in scope*). It is numbered 54.13 because 54.12 is the last row of
the ROADMAP table.

Read [`CLAUDE.md`](../../CLAUDE.md), [`spec.md`](../../spec.md) §1 (bundled
code and notices), §4 and §13, `CONTRIBUTING.md` (*Dev stack*, the vendored
libraries) and [`open-questions.md`](open-questions.md) first.

**Prerequisites:** [Phase 54.12](phase-54.12.md) merged, and `v3.21.9`
tagged (run `/release` for 54.12 first).

---

## Goals

1. **K182.** `composer audit --no-dev` is clean: `symfony/http-client`,
   `symfony/config` and `symfony/filesystem` are locked at 7.4.23 (and the
   dev-only `symfony/yaml` at 7.4.23), `composer.json` allows
   `symfony/http-client ^7.4 || ^8.0`, and CI runs `composer audit --no-dev`
   so the next advisory shows on the pull request.
2. **K183.** The bundles in `assets/vendor` and `public/assets/vendor` and
   both third-party notices files carry the versions `package.json` names
   (Alpine 3.17.5, Chart.js 4.6.1, Material Symbols 0.48.3), and a test
   fails whenever the versions in the notices differ from
   `package-lock.json`, so a Dependabot npm pull request can't merge
   without being re-vendored. (The plan said icons 0.48.2; Dependabot
   moved `package.json` to 0.48.3 on 2026-11-07, commit `d17b3a48`, so
   #604's "the versions `package.json` names" means 0.48.3.)
3. **K189.** The design and accessibility work merged after v3.21.9 (pull
   requests #206–#212, #216–#220 and #224) is recorded in this file
   (*Merged before this phase*), the ROADMAP and spec §13, so it has a
   phase to ship under.
4. **K190.** CHANGELOG `[Unreleased]` names every user-facing change since
   v3.21.9, and the K186 Docker-build line moves there from the
   already-tagged `[3.21.9]`.

## Not in scope

- **K145** needs a recorded API key. **K174** waits on `firebase/php-jwt`
  supporting phpseclib 4. **K175:** PHPStan 2.3.5 still reports 29 errors.
- **K185's** two remaining parts (the import-mapping accessibility case and
  the 60-second limit on Windows) have no known cause.

---

## Decisions (and why)

- **K182 follows #597:** widen the root constraint to
  `symfony/http-client ^7.4 || ^8.0` and lock 7.4.23, as K176 did for
  `symfony/mime`, keeping `config.platform.php` at 8.4.0. A dry run on
  2026-11-06 resolved cleanly: `http-client`, `config` and `filesystem` to
  7.4.23, `yaml` to 7.4.23, and the contracts packages to 3.8.1. Not chosen:
  raising the platform pin to 8.4.1 for Symfony 8.1 (the lock would stop
  installing on 8.4.0); waiting for an 8.0 backport (audit stays red).
- **K182's guard (#606):** a CI step `composer audit --no-dev` on the lint
  job. Not chosen: a test that the lock holds fixed versions (brittle, blind
  to new advisories); no guard. Known cost, accepted: an advisory with no
  fix yet turns CI red until it is handled.
- **K183 follows #598:** keep Dependabot's npm pull requests and fail them
  until re-vendored. Not chosen: ignoring the bundled libraries; re-vendoring
  by hand with nothing enforcing it.
- **K183's sync (#604):** re-vendor to the newer versions with
  `npm ci && npm run vendor`, committing the rebuilt bundles in `assets/`
  and `public/assets/` and both notices files, after checking the charts,
  icons and the draggable dashboard in a browser at a subpath. Not chosen:
  reverting `package.json` (Dependabot would propose the bumps again).
- **K183's check (#605):** a PHPUnit architecture test in
  `tests/Unit/Architecture/` that reads `assets/vendor/THIRD-PARTY-NOTICES.txt`
  and `package-lock.json` and compares each library's version; it needs no
  Node in CI. Not chosen: a separate CI job that re-runs `npm run vendor`
  and diffs the files.
- **K189 and K190 in this phase (owner, 2026-11-11, asked when the phase
  started):** fix K190 here, since this phase writes the CHANGELOG anyway;
  write K189's record (phase file, ROADMAP, spec §13) in this branch. Not
  chosen: leaving either open. Chosen while building: the record is a
  section of this file (*Merged before this phase*), with the 54.13 ROADMAP
  row, section and spec §13 entry naming the work, rather than a separate
  retrospective phase file and row.
- **Release (#603):** v3.21.10. The lock change touches production
  dependencies. Changed 2026-11-11 (#609): released together with
  [Phase 54.14](phase-54.14.md), whose K188 fix must reach the 3.21.9 →
  3.21.10 bare-PHP upgrade; the *Release* tasks below are done once, after
  54.14 is merged.

## Merged before this phase (K189)

Design and accessibility work merged on gwpreston16 after v3.21.9 tagged,
each as its own pull request from an `/impeccable` audit, polish or typeset
pass. It had no phase file, ROADMAP row or spec §13 entry (K189); this is
its record, and it ships in v3.21.10 with this phase. The design rules it
added reach spec §8 in [Phase 54.14](phase-54.14.md) (K191).

| PR | What changed | Audit result |
|---|---|---|
| #206 | `--on-red` token: dark text on solid red buttons and the tab-bar badge in the dark theme | White on dark red 2.78:1 → 6.9:1; `ColourContrastTest` checks it |
| #207 | Off-scale CSS values become tokens (`--fs-meta`, `--radius-icon`, `--radius-2xs`, `--shadow-raised`, avatar colours); the Ask draft card loses its side stripe | Drift from the #206 audit; same pixels otherwise |
| #208 | `text-wrap: balance` on headings and titles, `pretty` on paragraphs; Ask answers capped at 70ch | Line length 95 → 70 characters |
| #209 | Ask draft card: a duplicate no longer looks *Added*; settled cards not faded; even spacing; phone fields | Settled-card text 3.98:1 → AA |
| #210 | The remaining font sizes on the type scale (`--fs-figure`) | Off-scale sizes from the audit |
| #211 | Placeholders at full opacity; `ColourContrastTest` checks they aren't faded and the accent placeholder | 3.27:1 → 4.8:1 (light) |
| #212 | Unit filters join a figure and its unit with a no-break space; `keep_together` filter; `.figures` grid reflows | "mi" no longer wraps alone at 360 px |
| #216 | `scroll-padding` keeps a focused control clear of the top bar and tab bar | Focused fields hidden under the 72 px tab bar |
| #217 | Chart.js, SortableJS and the page scripts load only where used (`data-scripts`, loader in `app.js`) | Fill-up form 412 → 157 KB of script |
| #218 | Each animation gets its own reduced-motion version; SortableJS honours it | The blanket 0.01 ms rule removed |
| #219 | Vehicle chips in one scrolling row below 60rem | 304 → 62 px tall with seven vehicles |
| #220 | `--border-input` field edge, themed file button, tints laid over white | Field edge 1.25:1 → 3:1+; tinted text 4.2:1 → 4.5:1+ |
| #224 | Links in text underlined; "(optional)" labels at full contrast; Settings → Jobs and Profile reflow at 320 px; scrolling tables reachable by keyboard; P2 alert contrast | axe-core 4.10 over 32 pages, light and dark, 1280 and 320 px: no P1 left |

The CHANGELOG `[Unreleased]` section names each user-facing change above,
and the K186 Docker-build line moved there from `[3.21.9]` (K190).

## Tasks

### 54.13.0 Start of phase

- [x] Read `open-questions.md` and the *Open* and *Scheduled* rows of
      `known-issues.md` (CLAUDE.md §12). #597, #598 and #603–#606 are
      decided.

### 54.13.1 The Symfony advisories (K182)

- [x] `composer.json`: `symfony/http-client ^7.4 || ^8.0`; `composer update`
      for `symfony/http-client`, `config`, `filesystem` and `yaml` with their
      dependencies; `composer audit --no-dev` is clean and the full suite
      passes on all four engines (outbound calls go through `OutboundHttp`:
      its tests are the check that 7.4 behaves). `.github/workflows/ci.yml`
      gets the `composer audit --no-dev` step (#606). The notices file is
      regenerated if the versions it lists changed.

### 54.13.2 The vendored libraries (K183)

- [x] `npm ci && npm run vendor` (#604); rebuilt bundles and both
      `THIRD-PARTY-NOTICES` files committed; checked in the browser at a
      subpath (dashboard drag, a chart, icons).
- [x] A test in `tests/Unit/Architecture/` (#605) that fails on today's
      3.17.4 against `package-lock.json`'s 3.17.5 and passes after the
      re-vendor. `CONTRIBUTING.md` (~186) says a Dependabot npm pull request
      must be re-vendored before it merges and that this test is what fails.

### 54.13.3 The record of the work merged after v3.21.9 (K189, K190)

- [x] *Merged before this phase* above lists each pull request with what
      it changed and its audit results; ROADMAP and spec §13 name it.
- [x] CHANGELOG `[Unreleased]` gains the missing lines (#206, #208, #209,
      #211, #212, #216, #224) and the K186 line moves there from
      `[3.21.9]`.

### 54.13.4 Finish

- [x] Known-issues rows K182, K183, K189 and K190 *Fixed*.
- [x] CHANGELOG; spec §13; ROADMAP status.
- [ ] Merge review; merge. (After the pull request; merge as a merge commit, not a squash.)

### Release

- [ ] VERSION 3.21.10, CHANGELOG 3.21.10, README status (#603): done
      in [Phase 54.14](phase-54.14.md)'s *Release*, after 54.14 is merged
      (#609). Don't run `/release` for 54.13 alone.
- [ ] Tag `v3.21.10` once 54.13 and 54.14 are both merged.

## Acceptance criteria

- `composer audit --no-dev` reports no advisories, and CI runs it.
- The notices test fails on the old bundles and passes on the rebuilt ones.
- `composer lint`, `composer analyse` and `bin/test-all-dbs.sh` pass on
  SQLite, PostgreSQL, MySQL and MariaDB. `MarkdownLinksTest` passes.
- No migration. No new strings.

## Open questions

- **#597. Decided 2026-11-06:** widen the constraint to `^7.4 || ^8.0` and
  lock 7.4.23. Not chosen: raise the platform pin and move to 8.1; wait
  for a backport.
- **#598. Decided 2026-11-06:** keep Dependabot's npm pull requests and fail
  them until re-vendored. Not chosen: ignore the bundled libraries; re-vendor
  by hand with nothing enforcing it.
- **#603. Release. Decided 2026-11-06:** on its own as patch release
  v3.21.10. Not chosen: with the next releasing phase. Changed
  2026-11-11 (#609): v3.21.10 together with Phase 54.14.
- **#604. Decided 2026-11-06:** re-vendor to the newer versions. Not chosen:
  revert `package.json`.
- **#605. Decided 2026-11-06:** a PHPUnit architecture test. Not chosen: a
  separate CI job with Node.
- **K189 and K190. Decided 2026-11-11 (owner, at the start of the phase):**
  both in this phase.
- **#606. Decided 2026-11-06:** a CI step `composer audit --no-dev`. Not
  chosen: a test of the lock's versions; no guard.
