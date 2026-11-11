# Phase 54.14 — Big odometer readings, upgrade caches and small phone fixes from the known-issues log + patch release

*Six open rows of the known-issues log closed: a fill-up with a mistyped,
far-off odometer no longer breaks its vehicle's fuel page, a bare-PHP
upgrade no longer keeps the old templates and strings, *Look up* works
every time the Add vehicle modal opens, two kinds of link get their 44 px
on a phone, the service worker stops downloading an unused logo, and
spec §8 records the design rules added after v3.21.9.*

Status: 📋 planned · releases **v3.21.10** together with Phase 54.13, a patch release (#609) · file lives in `docs/phases/`

This is the batch after [Phase 54.13](phase-54.13.md). It takes the two
HIGH rows of the v3.21.9..HEAD release sweep (K187, K188), three small LOW
rows (K194, K195, K196) and one docs row on top of them (K191). It is
numbered 54.14 because 54.13 is the last row of the ROADMAP table and no
phase is active. It releases with 54.13 rather than after it because K188
bites on exactly the 3.21.9 → 3.21.10 bare-PHP upgrade, and a cache fix
only helps the release that carries it.

Read [`CLAUDE.md`](../../CLAUDE.md), [`spec.md`](../../spec.md) §10 (*Bare
PHP 8.4*), §7.15, §8 (*Accessibility*, *Tap targets*) and §13,
`docs/deployment.md` (*Upgrading*), `DESIGN.md` and
[`open-questions.md`](open-questions.md) first.

**Prerequisites:** [Phase 54.13](phase-54.13.md) merged (not yet released:
the two release together as v3.21.10, #609).

---

## Goals

### Two HIGH fixes

1. **K187.** A fill-up typed with an extra digit (77,320.98 km last, then
   773,209.8 km) no longer makes the vehicle's fuel page answer 500 on
   every visit: the page loads and shows the figures that reading gives,
   however large, so the owner can open the fill-up and correct it.
2. **K188.** After a bare-PHP upgrade done as `docs/deployment.md` says,
   the new release's templates and strings are what the pages show:
   `composer install` empties the compiled templates and translation
   catalogues, and the guide says so.

### Small fixes on a phone, in the service worker and in the spec

3. **K194.** *Look up* on the Add vehicle form fills the blank fields in
   place every time the modal opens, also after closing and reopening it
   without a reload and after a validation error or an earlier lookup
   re-renders the form; a chosen photo is kept.
4. **K195.** At 375 px the Insights card actions (*See cheapest near
   you*, *See reports*) and a reminder or *Coming up* row's title link
   have a hit area of at least 44 × 44 px, on the dashboard, a vehicle's
   overview, `/upcoming` and `/reminders`, and look as they do today.
5. **K196.** Installing or updating the service worker no longer
   downloads `images/logbook-mark.png` (98 KB), which no page uses.
6. **K191.** spec.md §8 records the rules `DESIGN.md` gained after v3.21.9:
   a figure and its unit are joined by a no-break space on HTML pages
   (screen and print) and by a plain space in the API, MCP, notifications
   and CSV; placeholder text meets 4.5:1 and field edges 3:1; each
   animation has its own reduced-motion version (fades kept); a focused
   control is never hidden under the phone's top or bottom bars.

## Not in scope

- **K189 and K190** (no phase file, ROADMAP row or spec §13 entry, and
  missing CHANGELOG [Unreleased] lines, for the design and accessibility
  work merged after v3.21.9): fixed as a separate docs change on master
  before 54.13 is built, so the v3.21.10 CHANGELOG carries that work under
  its own heading (owner's choice, 2026-11-11).
- **K192** waits on #607 and **K193** on #608.
- **K197** (two PHPUnit runs at once break each other) has two causes, the
  shared SQLite file and the shared `var/cache`, and no regression test
  short of running two suites at once; it needs a batch of its own.
- **K145** needs a recorded API key. **K174** waits on `firebase/php-jwt`
  supporting phpseclib 4. **K175** waits on a PHPStan release.

---

## Decisions (and why)

- **K187** makes the code do what `Decimal`'s own docblocks promise:
  `multiply()` and `divide()` fall back to brick/math when a value doesn't
  fit a PHP int, but `toScaledInt()` throws on more than 18 digits before
  the fallback is reached (K62 added the fallback for the product only).
  Both check the operands' digit count first and go straight to
  brick/math when either would overflow, rather than catching the
  exception. Nothing else changes: the fuel page shows what the stored
  reading gives, and no warning about an implausible reading is added
  (that would be a feature, not this fix). No owner decision was needed.
- **K188 (#610):** a Composer script empties the caches, so a `git pull`
  between release tags is covered too. `post-install-cmd` and
  `post-update-cmd` run `@php bin/clear-cache.php`, which can also be run
  on its own; `docs/deployment.md` *Upgrading* and `docs/translations.md`
  name it. Not chosen: a cache directory per version (misses pulls between
  tags), Twig `auto_reload` in production (a file check per template, and
  translations would still be stale), a docs step only. Confirmed from the
  code while planning: Twig's `auto_reload` is off in production, and
  Symfony's translator with `debug` off keys its catalogue cache on the
  locale and fallbacks only and never checks the source files, so both are
  stale after an upgrade.
- **K188's scope (#611):** only the `twig/` and `translations/`
  directories under the configured cache directory
  are emptied: `<root>/var/cache`, the path `AppSettings` gives
  `cacheDir`, worked out directly, without loading `.env`, building the
  container or touching the database, so the script runs before an
  install is configured. Any unexpected error is a warning and exit 0,
  never a failed install. `var/cache/staged` (an import
  mid-preview), `scheduled-tasks.lock` and the SSO discovery and JWKS
  caches are left alone. A missing directory is not an error (a fresh
  install, the Docker build).
- **K188's failure (#612):** if a file can't be deleted (the cache is
  owned by the web server user), the script prints
  `Could not clear var/cache/twig: delete it by hand or run bin/clear-cache.php as the web server user.`
  (naming the directory it couldn't clear) and exits 0, so
  `composer install` still succeeds. CLI output, English only, as the
  other `bin/` scripts.
- **K194** makes the modal do for *Look up* what it already does for
  file-drop, trip-form, first-inspection and stations, so a form in the
  modal behaves as it does on its own page. `vehicle-lookup.js` exposes
  `window.LogbookVehicleLookup.enhance(root)`, which enhances each
  `[data-vehicle-lookup]` inside `root` once (a box already enhanced is
  skipped), and the modal's `show()` calls it. No owner decision was
  needed.
- **K195** makes the code do what spec §8 *Tap targets* (#457) already
  says: both selectors join the one narrow-width block, which grows the
  hit area with a centred `::after` and changes nothing drawn. No owner
  decision was needed.
- **K196 (#613):** the file stays in `assets/images` (README.md shows it)
  and in `public/assets`; `ServiceWorkerAction`'s exclude pattern gains
  `^images/logbook-mark\.png$`, beside the notices file and the worker
  itself. Not chosen: `bin/build-assets.php` no longer publishing it.
- **K191** makes spec.md say what `DESIGN.md` and the CSS already do
  (CLAUDE.md §7: the spec wins where they differ, so it must hold the
  rules). Docs only.
- **Release (#609):** 54.13 and 54.14 release together as v3.21.10, after
  both are merged; #603 (54.13 on its own) is changed to match.

## Tasks

### 54.14.0 Start of phase

- [ ] Read `open-questions.md` and the *Open* and *Scheduled* rows of
      `known-issues.md` (CLAUDE.md §12). #609–#613 are decided.

### 54.14.1 Big odometer readings (K187)

- [ ] `Support\Number\Decimal::multiply()` and `divide()`: check each
      operand's digit count before `toScaledInt()` and use brick/math when
      it would pass 18 digits. Docblocks say so.
- [ ] `DecimalTest`: a 19-digit dividend and a 19-digit factor give the
      exact brick/math result (both throw `OverflowException` today).
- [ ] An HTTP test: a fill-up at 773,209.8 km after one at 77,320.98 km,
      and `/vehicles/{id}/fuel` answers 200 (500 today);
      `SeasonalEconomyTest` covers the same split directly.

### 54.14.2 Upgrade caches (K188)

- [ ] `bin/clear-cache.php`: empties `twig/` and `translations/` under
      `<root>/var/cache` (#611), with no `.env`, container or database; a missing directory is fine; on a
      file it can't delete it prints the #612 message and exits 0.
- [ ] `composer.json`: `post-install-cmd` and `post-update-cmd` run
      `@php bin/clear-cache.php` (#610). The Docker image is unaffected:
      its build runs `composer install --no-scripts` before `bin/` is
      copied (`Dockerfile:88`), and its cache lives in the container;
      the dev entrypoint's install may run it, which is harmless.
- [ ] `docs/deployment.md` *Upgrading* (bare PHP) says `composer install`
      clears the compiled templates and strings, and to run
      `php bin/clear-cache.php` as the web server user if it warns;
      `docs/translations.md` (~85) names the script.
- [ ] Tests: the script against a temporary cache directory holding
      `twig/`, `translations/`, `staged/` and `scheduled-tasks.lock`
      empties only the first two; a directory it can't write gives the
      message and exit code 0 (skipped on Windows and as root); an
      unexpected error (the cache path is a file) also exits 0; a test
      that `composer.json` runs it on install and update.

### 54.14.3 Look up in the modal (K194)

- [ ] `assets/js/vehicle-lookup.js`: `enhance(root)` exposed as
      `window.LogbookVehicleLookup` (and `module.exports` for Node), each
      box enhanced once; `assets/js/app.js` `show()` calls it beside the
      other enhancers. Rebuild `public/assets`.
- [ ] `tests/js/vehicle-lookup.test.js`: the script exposes `enhance`
      (fails today), and enhancing a root twice binds the button once.
- [ ] Checked in a browser at a subpath: open Add vehicle, close, reopen,
      *Look up* fills in place; after a validation error too; a chosen
      photo is kept.

### 54.14.4 Tap targets (K195)

- [ ] `assets/css/app.css` narrow-width tap-target block: add
      `.insight-card__action` and `a.list__title`. Rebuild `public/assets`.
- [ ] `TapTargetTest`: both selectors get the 44 px hit area (fails
      today). Checked at 375 px on the dashboard, a vehicle's overview,
      `/upcoming`, `/reminders` and Insights.

### 54.14.5 The unused logo (K196)

- [ ] `src/Action/Pwa/ServiceWorkerAction.php`: exclude
      `^images/logbook-mark\.png$` (#613).
- [ ] An HTTP test of `/service-worker.js`: its asset list has no
      `logbook-mark.png` (fails today) and still has
      `logbook-mark-128.png`.

### 54.14.6 spec §8 (K191)

- [ ] spec.md §8 *Accessibility* (and *Tap targets* where it fits): the
      four rules in Goal 6, each citing `DESIGN.md` and the PR it came
      from.

### 54.14.7 Finish

- [ ] Known-issues rows K187, K188, K191, K194, K195 and K196 *Fixed*
      (Phase 54.14).
- [ ] CHANGELOG [Unreleased] (K188's upgrade note under *Upgrade notes*:
      bare-PHP installs upgrading *to* 3.21.10 from 3.21.9 or earlier run
      `composer install`, which now clears the caches; if it warns, run
      `php bin/clear-cache.php` as the web server user); spec §13;
      ROADMAP status.
- [ ] Merge review; merge.

### Release

- [ ] VERSION 3.21.10, CHANGELOG 3.21.10 covering 54.13 and 54.14,
      README status (#609).
- [ ] Tag `v3.21.10` once both are merged.

## Acceptance criteria

- Each code row (K187, K188, K194, K195, K196) has a regression test that
  fails without its fix.
- After `composer install`, a production install renders the new
  templates and strings without deleting anything by hand.
- `composer lint`, `composer analyse`, `composer test:js` and
  `bin/test-all-dbs.sh` pass on SQLite, PostgreSQL, MySQL and MariaDB.
  `MarkdownLinksTest` passes.
- No migration. No new translatable strings (the one new message is CLI
  output from `bin/`).

## Open questions

- **#609. Release. Decided 2026-11-11:** with Phase 54.13 as patch release
  v3.21.10, both merged first; #603 changed to match. Not chosen: on its
  own as v3.21.11, with an upgrade note in 3.21.10 to delete the caches
  by hand.
- **#610. Decided 2026-11-11:** a Composer `post-install-cmd` and
  `post-update-cmd` running `bin/clear-cache.php`. Not chosen: a cache
  directory per version; Twig `auto_reload` in production; a docs step
  only.
- **#611. Decided 2026-11-11:** only `twig/` and `translations/` are
  emptied. Not chosen: all of `var/cache`.
- **#612. Decided 2026-11-11:** warn and let the install succeed. Not
  chosen: fail `composer install`.
- **#613. Decided 2026-11-11:** exclude the file in
  `ServiceWorkerAction`. Not chosen: stop `bin/build-assets.php`
  publishing it.
