# Phase 52 — The README and the docs, for someone deciding

*A stranger can tell in a minute who Logbook is for, what it looks like
and how to try it, and find the right guide for anything deeper.*

Status: 🚧 in progress · file lives in `docs/phases/`

The README has grown one phase at a time, and it shows:

- Its opening is a single sentence of about a hundred lines
  (`README.md` *Status*), listing every feature since Phase 1. It never
  says who the app is for, and the reader has to get through all of it
  before reaching *Quick start*.
- Pointers to the upgrade notes (3.0.0 for anyone on 2.x, 3.3.0 for
  anyone on 3.2) are buried in that sentence.
- There isn't one screenshot, so nobody can see what the app looks like
  without installing it.
- About half the file is for contributors: development commands,
  `bin/dev-setup.sh` and its flags, tests, front-end assets,
  translations and the repository layout.
- `docs/` has 21 guides and no index, apart from the README's table,
  and that table already misses one: `docs/reports.md` is linked only
  from inside the *Status* sentence. Each guide opens differently.

This phase rewrites the README for someone **deciding whether to try
Logbook**. It moves the full feature list and the contributor material
to their own files, adds screenshots that a script keeps current, and
gives `docs/` an index and a first-run guide.

It changes no app behaviour, no configuration and no API. Like
[Phase 45](phase-45.md), it is noted in the next release's changelog
rather than released on its own.

Read [`CLAUDE.md`](../../CLAUDE.md) and [`spec.md`](../../spec.md) §1
(overview), §3 (target users) and §10 (deployment) first.

**Prerequisites:**

- [Phase 45](phase-45.md) complete. The README gets its *Licence*
  section there, and 45.3 creates `CONTRIBUTING.md`, which this phase
  extends.
- [Phase 49](phase-49.md) complete. It changes the first run (the
  guided first vehicle, the *Household* layout, fewer modules on), so
  the getting-started guide and the screenshots describe what a new user
  actually sees.

---

## Goals

1. A short README (open question A), in this order:
   - who Logbook is for;
   - screenshots;
   - a quick start that ends with a vehicle and a fill-up;
   - features in nine groups, each linking to its guide;
   - documentation;
   - upgrading;
   - licence;
   - contributing.
2. The full feature list in `docs/features.md`, grouped the same way,
   so nothing in the *Status* sentence (as it stands when this phase
   starts) is lost.
3. Contributor material in `CONTRIBUTING.md` (open question F).
4. `docs/README.md` as the docs index, and `docs/getting-started.md`
   for the first run.
5. Screenshots made by a script from demo data, in light and dark, on a
   desktop and a phone, and re-made before each release.
6. Links between the README and the docs checked in CI.

## Not in scope

- Rewriting the content of the existing guides. They only get the common
  opening and table of contents in 52.4.
- A generated docs website (open question E).
- Translating the docs. The app's strings stay translated as before;
  the docs are English.
- Any change to the app, its templates or its strings. If a screenshot
  shows a layout bug, it is logged as an issue, not fixed here.

---

## Spec

Settled in §10 (deployment, *README and docs*) and §13 in 52.0:

- **The README's job.** Who Logbook is for, what it looks like, how to
  try it, and where each guide is. Nothing in it should be the only
  place a fact lives. Details belong in `docs/`, `CONTRIBUTING.md` or
  `CHANGELOG.md`.
- **Its status line.** Only the version, with a link to `CHANGELOG.md`.
  A release updates the version there, instead of adding to a feature
  list, and adds new features to `docs/features.md`.
- **Who it's for** (from §3). People who:
  - self-host;
  - run a household's cars and bikes, one vehicle or several;
  - want their records on their own server;
  - want more than a fuel app.

  It isn't for fleet operators with drivers and cost centres, or for
  people who want a hosted service.
- **Feature groups**, used in the README, `docs/features.md` and
  `docs/README.md`. Each item lists the guides it links to:
  1. Garage, people and sharing: `users-and-sharing.md`, `sso.md`.
  2. Fuel, charging and economy: `stations.md`.
  3. Maintenance, tyres, parts and issues: `issues.md`, `parts.md`.
  4. Documents, renewals and MOT history: `mot-history.md`.
  5. Costs, reports and finance: `reports.md`, `finance.md`,
     `trips.md`, `sale-pack.md`.
  6. Incidents and claims: `incidents.md`.
  7. Reminders and notifications: `notification-channels.md`.
  8. AI, the API and MCP: `ai.md`, `api.md`, `mcp.md`.
  9. Import, backup and the phone app: `import.md`,
     `import-tracktor.md`, `deployment.md`.

  The install and admin guides (`configuration.md`,
  `reverse-proxies.md`, `proxmox-lxc.md`, `demo-mode.md`) and
  `translations.md` aren't features. They are linked from *Quick start*,
  *Documentation* and the `docs/README.md` index.
- **Screenshots** live in `docs/images/` and are named by page,
  viewport and theme (for example `dashboard-desktop-light.png`). They
  are made only from demo-seed data, never from a real install, and
  each is under 300 KB.

---

## Tasks

### 52.0 Spec first
- [x] Open questions A–F decided by the owner and logged in
      `open-questions.md`.
- [x] `spec.md` §10 (the README's job and status line, the docs index,
      screenshots) and §13 (this phase). `ROADMAP.md` row.
- [x] The release task for phases after this one ("`VERSION`,
      `CHANGELOG.md`, README") reworded to the new status line and
      `docs/features.md`, in `CONTRIBUTING.md`'s release steps.

### 52.1 Content moves, nothing lost
- [x] `docs/features.md`: everything in the *Status* sentence as it
      stands when this phase starts (Phases 46–51 add to it),
      grouped as above, one short paragraph or list per group. Each
      item links to the guide that explains it.
- [x] A check, by hand and in the PR description, that every clause of
      the old sentence appears in `docs/features.md`.
- [x] `CONTRIBUTING.md` (F; on top of 45.3's contribution terms): stack,
      `composer` commands, `bin/dev-setup.sh` and its flag table, tests
      and databases, front-end assets, translations, the repository
      layout, and the pointers to `CLAUDE.md` and `spec.md`.
- [x] The 3.0.0 and 3.3.0 upgrade notes stay in `CHANGELOG.md`. The
      README links to them, and to `docs/deployment.md#upgrading`.

### 52.2 Screenshots
- [x] A maintainer-only script (B) that:
  - starts a stack with demo-seed data (`bin/demo-seed.php`) on SQLite;
  - signs in as the demo account;
  - captures each page at a desktop size (1440×900) and a phone size
    (390×844), in light and dark;
  - writes the images to `docs/images/`.
- [x] Pages (C):
  - the dashboard;
  - a vehicle's overview;
  - the fuel log, and *Add fill-up* on a phone;
  - *Coming up*;
  - a cost report;
  - *Needs attention*;
  - *Ask Logbook* with a kept conversation that the script stores
    first, so no model is called.
- [x] The demo data's dates are relative to today, so screenshots don't
      look stale.
- [x] Images compressed. The script fails if any is over 300 KB.
- [x] "Re-make the screenshots" added to `CONTRIBUTING.md`'s release
      steps.

  Built as `npm run screenshots` (`bin/screenshots.mjs`, Playwright
  1.63.0, maintainer-only). It builds the image, seeds an empty volume
  with `DEMO_MODE` on, restarts with it off so no demo banner shows, and
  stores the Ask conversation with `bin/tools/screenshot-ask.php`, which
  runs Logbook's own `costs` and `cost_per_distance` tools and refuses
  anything but a demo database. 24 images, 63–186 KB each. The demo
  seed's dates were already relative to today (Phase 35.1).

### 52.3 The README
- [x] Rewritten in the order of Goal 1, at the length A settles. The
      header has the logo mark, one line on what Logbook is, and badges
      (version, licence, CI), plus the demo link if D says so.
- [x] *Quick start* covers three paths:
  - Docker with PostgreSQL;
  - one container with SQLite;
  - plain PHP 8.4.

  Each ends with the same four steps: first visit, create the admin,
  add a vehicle, log a fill-up. Each links to `docs/getting-started.md`
  and `docs/deployment.md`.
- [x] *Features*: one short line per group with a screenshot thumbnail
      where it helps, linking to the group in `docs/features.md`. The
      thumbnails sit in *Screenshots* above, to keep the README near A's
      length; `docs/features.md` shows one per group where it helps.
- [x] *Documentation*: a short list by audience (install, use,
      integrate, contribute), with the full table moved to
      `docs/README.md`.
- [x] *Configuration*: three lines and a link to
      `docs/configuration.md`. The email server and Settings → Delivery
      move to the getting-started guide.
- [x] Every image has alt text that says what it shows.

### 52.4 The docs
- [x] `docs/README.md`: the index, grouped by audience, one line per
      guide (the current README table, regrouped, with `reports.md`
      added).
- [x] `docs/getting-started.md`, the first run after install:
  - the first admin;
  - preferences;
  - the guided first vehicle (Phase 49);
  - the first fill-up and reading;
  - setting up email;
  - inviting the household;
  - switching modules off;
  - the first backup.
- [x] Every guide in `docs/` gets the same opening: one line on what it
      covers, one on who it's for, and a table of contents when it has
      more than four sections. The guides' content is otherwise
      untouched.
- [x] A *Back to the docs index* link at the foot of each guide.

### 52.5 Checks and changelog
- [x] `bin/tools/check-docs.php`, run in CI. It fails when:
  - a relative link or anchor in the README, `CONTRIBUTING.md` or
    `docs/**/*.md` doesn't resolve;
  - a guide in `docs/` isn't linked from `docs/README.md`;
  - an image in `docs/images/` isn't used, or one that's used is
    missing.

  `docs/phases/` is exempt. The script is plain PHP, with no new
  dependency. Its checks live in `tests/Support/DocsCheck.php` on top of
  the existing `MarkdownLinks`, which now also reads HTML `src`,
  `srcset` and `href` (the README's `<picture>` screenshots).
- [x] Tests for the checker, with fixtures of a broken link, an
      orphaned guide and an unused image.
- [x] `spec-keeper` and `design-reviewer` run over the result. The
      design reviewer checks the screenshots against the prototype.

  Run 2026-10-17. No layout bug in the screenshots. Fixed: alt texts that
  described more than an image shows; getting-started wording (display
  name optional, the update check only where allowed, Settings → Account →
  Notifications, the backup warning's 30 days a default). The README's
  one-container path stays `docker build`, as `docs/deployment.md` has it,
  rather than naming the published image. The README came out at about
  150 lines rather than 120: the nine feature lines Goal 1 asks for and the
  licence text.
- [x] `CHANGELOG.md`: a line under the next release.
- [x] Known issues the owner asked for on 2026-10-17 (docs only): K15
      (CHANGELOG 3.15.0), K20 (the part `warranty` object and History
      kinds), K60 (`403 demo` in the API and MCP docs and OpenAPI), and K8
      once its policy was decided (#479: a standing *Unknown values* rule,
      spec §7.20). OpenAPI description 1.26.1. `CLAUDE.md` points at
      `CONTRIBUTING.md` for `bin/dev-setup.sh`.
- [ ] `ROADMAP.md` row ✅.

---

## Acceptance criteria

1. Someone new to Logbook can tell from the first screen of the README
   who it's for and what it looks like, and can reach a running
   instance with a vehicle and a fill-up by following *Quick start*
   alone.
2. Nothing in the old README is lost. Each fact is in
   `docs/features.md`, `CONTRIBUTING.md`, `CHANGELOG.md` or a guide.
3. Screenshots can be re-made with one command and use only demo data.
4. CI fails on a broken docs link, an orphaned guide or an unused
   image.
5. No app behaviour changes, and the tests still pass. CLAUDE.md §11
   applies where relevant (lint and analyse for the checker script, its
   tests and coverage).

## Open questions

Decided by the owner on 2026-10-17 and logged as #473–#478 in
`open-questions.md`, before it was built.

- **A. How long should the README be?** *Decided 2026-10-17 (#473):
  the recommendation.*
  - (1) About 120 lines: the order in Goal 1, with features as one line
    per group linking to `docs/features.md`.
  - (2) About 250 lines: the same order, with each feature group's
    full paragraph in the README.
  - (3) Keep today's README and add a short "who it's for" and
    screenshots at the top.

  *Recommendation:* (1). The point of the phase is the time to the
  first useful fact, and `docs/features.md` keeps every detail one click
  away.

- **B. How are screenshots made?** *Decided 2026-10-17 (#474): the
  recommendation.*
  - (1) A Playwright script added to the maintainer-only
    `package.json`, like `bin/vendor-assets.mjs`. Nothing is needed to
    install or run Logbook.
  - (2) Headless Chrome's `--screenshot` from a shell script. There's
    no Node, but signing in and dark mode are awkward.
  - (3) Taken by hand before each release.

  *Recommendation:* (1). It keeps them current at no cost to anyone
  installing Logbook, and Node is already a maintainer-only tool here.

- **C. Which pages?** *Decided 2026-10-17 (#475): the
  recommendation.* The list in 52.2, or a shorter one (dashboard,
  vehicle, fuel on a phone, a report)? Every extra page is another image
  to re-make and check each release.

  *Recommendation:* the list in 52.2. The README shows four of them, and
  `docs/features.md` and the guides use the rest.

- **D. Link a public demo?** *Decided 2026-10-17 (#476): (2), no
  demo link.* Phase 35.1 built demo mode, but the
  repository doesn't link to a running demo.
  - (1) Yes: the owner hosts one and the README links to it, with its
    reset schedule.
  - (2) No: the screenshots and `bin/dev-setup.sh --with-sample-data`
    are the try-before-you-install path.

  *Recommendation:* (1), if the owner is willing to host it, since a
  demo answers "what is it like" better than any screenshot. Otherwise
  (2).

- **E. A docs website?** *Decided 2026-10-17 (#477): the
  recommendation.*
  - (1) No: Markdown on GitHub, with `docs/README.md` as the index.
  - (2) A static site generated from `docs/` (for example MkDocs) and
    published on GitHub Pages.

  *Recommendation:* (1) for this phase, with (2) parked in §12. The
  checker in 52.5 keeps the Markdown ready to become a site later.

- **F. Where does the contributor material go?** *Decided 2026-10-17
  (#478): the recommendation.* Development commands,
  `bin/dev-setup.sh`, tests, assets, translations and the layout take
  about half of today's README.
  - (1) Moved to `CONTRIBUTING.md`, with a one-line link from the
    README.
  - (2) Left in the README, after everything for users.

  *Recommendation:* (1). People deciding whether to try Logbook don't
  need it, and GitHub surfaces `CONTRIBUTING.md` to contributors on its
  own.
