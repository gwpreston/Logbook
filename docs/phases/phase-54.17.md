# Phase 54.17 — Chart tables, stat tiles and the test runs from the known-issues log + patch release

*Six open rows of the known-issues log closed: with JS on, a chart's
screen-reader table is no longer an invisible tab stop and costs little to
lay out; a long figure in a narrow stat tile shrinks instead of leaving its
unit's last letter alone on the next line; an image built from a working
tree leaves the developer's git-ignored files out; a test run that can't
reset its SQLite file stops instead of testing on the old one; and
`CONTRIBUTING.md` explains the test-run lock.*

Status: ✅ complete · releases **v3.21.13**, a patch release · file lives in `docs/phases/`

This is the batch after [Phase 54.16](phase-54.16.md), the last
known-issues batch, and takes six of the seven rows its merge review
recorded: K202 (MEDIUM), K204 and K208 (LOW), the chart tables and stat
tiles 54.16's K192 work touched, and K205, K206 and K207 (LOW, K207 docs
only), the Docker image and test-run tooling its K193 and K197 work
touched. It is numbered 54.17 because 54.16 is the last row of the
ROADMAP table and no phase is active.

Read [`CLAUDE.md`](../../CLAUDE.md), [`spec.md`](../../spec.md) §8
(*Figures keep their units*, *Printing reports*), §9 (*Test suite only*),
§10 (*Docker*) and §13, `.claude/review-rules.md` §2 and
[`open-questions.md`](open-questions.md) first.

**Prerequisites:** [Phase 54.16](phase-54.16.md) merged and released as
v3.21.12.

---

## Goals

### Chart tables and stat tiles

1. **K202.** With JS on, pressing Tab never lands on a chart's hidden
   figures table: the Fuel tab's *Economy trend* and *Price trend*,
   *Odometer over time*, the true-cost *Fleet* card and the dashboard's
   *Monthly spend* have no invisible tab stop. Screen readers still read
   each table.
2. **K204.** With JS on, the Fuel and Mileage tabs of a vehicle with
   1,500 fill-ups lay out about as fast as they did in v3.21.11, though
   their screen-reader tables stay.
3. **K208.** At 375 px in German, the Fuel tab's *Durchschnittspreis* for
   an EV reads "0,301 €/kWh" on one line, a little smaller, instead of
   leaving "h" alone below. Short figures look exactly as they do today.

### Docker image and test runs

4. **K205.** An image built with `docker build .` from a developer's
   checkout no longer carries `design-import/`, `export/`, a local
   `phpunit.xml` or `phpstan.neon`, or `Thumbs.db` files.
5. **K206.** A test run that can't delete its SQLite file (on Windows,
   because another process still has it open) stops at once and says why,
   instead of testing on the old file.
6. **K207.** `CONTRIBUTING.md` says that a second test run from one
   checkout waits, with the line it prints, and that the wait doesn't
   reach a run inside Docker Desktop on macOS.

## Not in scope

- **K203** waits on #621 (how two vehicles with the same name are told
  apart).
- **K201** (the test-run lock doesn't reach across Docker Desktop on
  macOS) has no known fix short of a different lock; K207 documents it,
  and it stays *Open*.
- **K145** needs a recorded Anthropic request, so a real key. **K174**
  waits on `firebase/php-jwt` supporting phpseclib 4.
- No other row of the log is *Open*.

---

## Decisions (and why)

- **K202 (#623):** makes the code do what spec §8 *Printing reports*
  already says (the table is for screen readers with JS) and what
  `enhanceScrollRegion`'s own comment intends (a tab stop only for a box
  someone can scroll). `enhanceScrollRegion` in `assets/js/app.js` returns
  at once for a wrapper that is `.chart-table` or sits inside one, before
  it measures, and adds nothing. Wrappers whose `table` alone carries
  `.chart-table` (`dashboard/_mileage.twig`, `expenses/index.twig`, the
  Fuel *Cost* panel, `vehicles/show.twig`) don't overflow, since the
  clipped table is out of flow, so they need no change. Screen readers
  reach the table in browse mode or by table navigation. Not chosen:
  stopping the 1 px wrapper reporting overflow in CSS.
- **K204 (#624):** `.js .chart-table` gains `content-visibility: auto`
  with `contain-intrinsic-size: 1px 1px`, so the browser skips laying out
  a table that isn't near the screen while keeping it in the
  accessibility tree. #607 chose full tables, so rows are not capped.
  Done when, measured as K204 was (desktop Chrome, a vehicle with 1,500
  fill-ups, a full layout of each tab), the Fuel tab is back near
  v3.21.11's 5.5 ms and the Mileage tab near its 2.3 ms. If the rule
  doesn't get there (a table near the screen is still laid out), the
  builder records the timings in the task and in K204's row, which goes
  back to *Open*. No cap on rows is added without a new question. Not
  chosen: dropping K204 from the batch.
- **K205 (#625):** follows #608 (developer-local files stay out of the
  image). `.dockerignore` gains `design-import`, `export`, `phpunit.xml`,
  `phpstan.neon` and `**/Thumbs.db`, with a comment naming K205 and #608.
  `phpunit.xml.dist` and `phpstan.neon.dist` stay as they are (the image
  needs neither, but they're tracked and not what the row is about). Not
  chosen: turning `.dockerignore` into an allow-list.
- **K206 (#626):** stop at once. Deleting the SQLite file moves from
  `tests/bootstrap.php` into a small `Tests\Support` helper whose delete
  call can be replaced in a test. When the delete fails, the bootstrap
  writes to STDERR and exits 1:
  `Can't delete {file}: another process has it open. Close it (a leftover bin/ script or a run in the dev container) and run the tests again.`
  `{file}` is the path the run uses (`$settings->database->name`), so the
  `bin/test-all-dbs.sh` runs name `var/testing-all-dbs.sqlite`. The
  warning PHP prints for the failed `unlink()` is suppressed, so the line
  above is the only one. Not chosen: retrying for up to 5 seconds first.
- **K207:** docs only, no decision. `CONTRIBUTING.md` *Tests and
  databases* says that a second `composer test` from the same checkout
  waits on `var/testing.lock`, printing
  `Waiting for another test run in this checkout to finish…`, and that a
  run inside Docker Desktop on macOS doesn't share that lock (K201).
- **K208 (#627):** keeps spec §8 *Figures keep their units*: the figure
  and its unit stay joined, and only a long figure shrinks. The `ui.stat`
  macro adds `stat__value--long` when the formatted value is longer than
  9 characters (counted as Twig's `length` counts the string, the
  no-break space as one). `.stat` gets `container-type: inline-size`
  (its width comes from the `.stats` grid's `minmax()` columns, so it
  doesn't collapse), and `.stat__value--long` gets a font size of
  `clamp(var(--fs-md), <n>cqi, var(--fs-figure))`, with `<n>` chosen so
  "0,301 €/kWh" fits one line in a 132 px tile and a wide tile keeps
  `--fs-figure`. `overflow-wrap: anywhere` stays as the last resort below
  `--fs-md`, so nothing overflows a tile. A value of 9 characters or
  fewer is unchanged at every width. Not chosen: every figure shrinking
  in a narrow tile; a break only after `/`.
- **Release (#622):** on its own as patch release v3.21.13, after 54.16.

## Tasks

### 54.17.0 Start of phase

- [x] Read `open-questions.md` and the *Open* and *Scheduled* rows of
      `known-issues.md` (CLAUDE.md §12). #622–#627 were decided when the
      phase was planned. Reviewed 2026-11-16: none of the questions still
      open (#221, #390, #391, #460, #462, #537–#542, #551) bears on K202,
      K204, K205, K206, K207 or K208, and none was found while starting it.

### 54.17.1 Chart tables (K202, K204)

- [x] `assets/js/app.js` `enhanceScrollRegion()`: return at once for a
      wrapper that is `.chart-table` or inside one, with a comment naming
      K202 and #623. Export `enhanceScrollRegion` beside the offline
      helpers for Node.
- [x] `tests/js/scroll-region.test.js`: jsdom-free, with stub elements
      whose `scrollWidth` is larger than their `clientWidth`. A
      `.table-wrap.chart-table` wrapper gets no `tabindex`, `role` or
      name (fails today); an ordinary `.table-wrap` that overflows still
      does.
- [x] `assets/css/app.css` `.js .chart-table`: `content-visibility: auto`
      and `contain-intrinsic-size: 1px 1px`, with a comment naming K204
      and #624. Rebuild `public/assets`. The printed report's
      `.js .print-report .chart-table` sets `content-visibility: visible`,
      so every row still prints (checked with print emulation on the
      true-cost report).
- [x] A CSS check (as `TapTargetTest` reads `app.css`) that
      `.js .chart-table` has `content-visibility: auto` (fails today).
- [x] Measured as K204 was: the Fuel and Mileage tabs of a vehicle with
      1,500 fill-ups in desktop Chrome, before and after, recorded here.
      If they aren't back near 5.5 ms and 2.3 ms, record the timings in
      K204's row and set it back to *Open* (#624).
      *Measured 2026-11-16* (Chromium via Playwright, 1280 × 800, a copy
      of the dev database with vehicle 1 raised to 1,500 fill-ups; median
      of 20 full layouts forced by changing the root font size, a frame
      apart so `content-visibility` can take effect). This machine is
      slower than K204's, so v3.21.11's rule (`display: none`) was timed
      on the same page as the baseline:

      | Tab | v3.21.11 rule | master (v3.21.12) | 54.17 |
      |---|---|---|---|
      | Fuel | 9.6–11.1 ms | 76–78 ms | 9.4–11.8 ms |
      | Mileage | 5.1–5.2 ms | 26–34 ms | 4.5–5.3 ms |

      Back to v3.21.11's cost on both tabs, so K204 is *Fixed*.
- [x] Checked in a browser with JS on: Tab through the Fuel tab, the
      Mileage tab, the true-cost report and the dashboard and land on no
      invisible stop; a screen reader (or the accessibility tree) still
      lists each chart table.
      *Checked 2026-11-16* (Playwright, 1280 px): no Tab press lands in a
      chart table on the Fuel tab, the Mileage tab or the true-cost
      report, and no chart-table wrapper has a `tabindex`. On the
      dashboard Tab still reaches *Monthly spend*'s month links, as
      designed: the table shows itself while one has focus
      (`.js .monthly-spend__table:focus-within`, 437 × 488 px), and its
      wrapper is no longer a stop of its own. The accessibility tree
      lists *Economy trend*, *Price trend*, *Odometer over time* and the
      true-cost table by name.

### 54.17.2 Stat tiles (K208)

- [x] `templates/macros/ui.twig` `stat()`: `stat__value--long` when the
      value is longer than 9 characters (#627).
- [x] `assets/css/app.css`: `.stat` `container-type: inline-size`;
      `.stat__value--long` font size
      `clamp(var(--fs-md), <n>cqi, var(--fs-figure))`. Rebuild
      `public/assets`. `DESIGN.md` notes the long-figure rule beside the
      stat tile.
- [x] Tests: the German Fuel tab for an EV renders
      `stat__value--long` on *Durchschnittspreis* and not on a figure of
      9 characters or fewer (fails today); a CSS check that
      `.stat__value--long` has `--fs-md` as its floor.
- [x] Found by the four-engine run: a value can be markup from a macro
      (Coming up's *Next 3 months* "—" with "No known cost" for screen
      readers), and Twig's `length` counted its tags, marking a one-
      character dash long. The macro now counts what the tile shows, with
      a `shown_length` filter (`TwigExtension::shownLength()`: tags and
      visually-hidden text dropped, entities as one character);
      `ShownLengthTest`, and `ComingUpTest` passes again. spec §8 says so.
- [x] Checked in a browser at 375 px, German and English, light and dark:
      "0,301 €/kWh" on one line, short tiles unchanged; and wide.
      *Checked 2026-11-16* (Playwright, the Kia EV6 of the dev data):
      `<n>` is **15** (`15cqi`). "0,301 €/kWh" is 132.6 px at 22 px and the
      tile's content box 124 px at 375 px, so it shows at 18.6 px on one
      line; 20.6 px at 768 px (137 px box); 22 px at 1280 px (189 px).
      "318,29 €" keeps 22 px at every width, light and dark alike, and no
      value overflows its tile. In English "£0.055/km" (9 characters)
      keeps 22 px and "£0.301/kWh" shrinks to 18.6 px. "17,9 kWh/100 km"
      still wraps before "km" at 375 and 768 px, as it did at 22 px: its
      unit has an ordinary space and is too wide even at `--fs-md` (logged
      as a known issue).

### 54.17.3 Docker image and test runs (K205, K206, K207)

- [x] `.dockerignore`: `design-import`, `export`, `phpunit.xml`,
      `phpstan.neon`, `**/Thumbs.db`, with a comment naming K205 and
      #608 (#625).
- [x] `LicenceTest`'s `.dockerignore` check lists the five new entries
      (fails today).
- [x] A local `docker build .` from a checkout with `design-import/`: the
      image has no `/var/www/html/design-import` (checked by hand,
      recorded here). *Checked 2026-11-16* (Docker 29.9.3, Windows): built
      from this checkout, which has `design-import/`; the image has no
      `design-import`, `export`, `phpunit.xml` or `phpstan.neon` under
      `/var/www/html` and no `Thumbs.db` anywhere in it.
- [x] `tests/Support`: a helper that deletes the SQLite file with a
      replaceable delete call; `tests/bootstrap.php` uses it and, on
      failure, prints the #626 message naming the file and exits 1.
      `TestSqliteFile::delete()`. Found while building: on Windows,
      deleting a file another PHP process has open *succeeds* but leaves
      it pending until that process closes it, and nothing can be created
      under its name meanwhile, so the run went on and failed in Phinx
      with a long trace. The default delete therefore also creates the
      file again, empty, and a failed create counts as a failed delete
      (#626's message, exit 1). Checked by hand with a second process
      holding `var/testing.sqlite` open: one line, exit 1; released, the
      run goes on.
- [x] A unit test with a delete call that fails: the helper reports
      failure and the message names the file it was given (fails today:
      the bootstrap carries on).
- [x] `CONTRIBUTING.md` *Tests and databases*: the waiting second run and
      its line, and K201's macOS gap (K207).
- [x] A docs test that `CONTRIBUTING.md` contains
      `TestRunLock::WAITING`'s text (fails today).

### 54.17.5 The native Windows suite (K210, asked for by the owner 2026-11-16)

- [x] `CostVisibilityTemplateTest` names templates with forward slashes on
      every OS, as its exception lists do (32 cases failed on Windows).
- [x] Each test starts with no time limit: `Tests\Support\NoTimeLimit`, a
      PHPUnit extension registered in `phpunit.xml.dist`, lifts it after
      every test. The runs that stopped part-way died on "Maximum
      execution time of 60 seconds exceeded" in a SQLite commit: a job
      action (`LongRequest::allow()`, Ask, Scan, OCR settings) had called
      `set_time_limit()` inside the PHPUnit process, and on Windows PHP
      counts that limit in wall-clock time. `NoTimeLimitTest` (fails with
      `--no-extensions`).

### 54.17.4 Finish

- [x] Known-issues rows K202, K204, K205, K206, K207 and K208 *Fixed*
      (Phase 54.17), or K204 back to *Open* with its timings (#624).
      All six *Fixed*. K209 (an EV's "17,9 kWh/100 km" still splits at
      375 px in German) found while building, *Open*; the owner chose to
      leave it (#632).
      K210 (the native Windows suite: `CostVisibilityTemplateTest`'s
      backslash paths, and runs that stop part-way) found while checking
      it, and fixed in 54.17.5 at the owner's request.
- [x] CHANGELOG [Unreleased]; spec §13; ROADMAP status.
- [ ] Merge review; merge. (After the pull request; merge as a merge
      commit, not a squash.)

### Release

- [x] VERSION 3.21.13, CHANGELOG 3.21.13, README status (#622).
- [ ] Tag `v3.21.13` once merged.

## Acceptance criteria

- Each code row has a regression test that fails without its fix: no tab
  stop on a chart-table wrapper (K202), `content-visibility` on
  `.js .chart-table` (K204), the long-figure class and its CSS (K208),
  the `.dockerignore` entries (K205), the failed delete (K206), and the
  lock in `CONTRIBUTING.md` (K207).
- K204's timings are recorded, before and after.
- `composer lint`, `composer analyse` and `bin/test-all-dbs.sh` pass on
  SQLite, PostgreSQL, MySQL and MariaDB; `composer coverage:check`
  passes; the JS tests pass.
- `MarkdownLinksTest` passes.
- No migration and no new setting. No new translated string (the #626
  message is test tooling, in English as the lock's line is).
- The Docker image builds on amd64 and arm64.

## Design review (2026-11-16)

K202, K204 and K208 change what the browser does; K208 is the only one
that changes how anything looks. Checked in Chromium (Playwright) on a copy
of the dev data at 375, 768 and 1280 px, light and dark, German and
English, with JS on, and in print media for the true-cost report. The
Impeccable audit (scoped to the 54.17 CSS and the `ui.stat` macro) and its
detector on the EV's Fuel tab found no HIGH or MEDIUM items. No figure
overflows its tile; short figures keep `--fs-figure`; the CSS uses
existing tokens only. `DESIGN.md` notes the long-figure rule beside the
stat tiles; no token, colour, font or radius changed, so
`.impeccable/design.json` is unchanged.

Two LOW notes, not changed: an EV's "17,9 kWh/100 km" still splits at
375 and 768 px in German (K209, #632), as it did at 22 px; and a shrunk
long figure sits beside a full-size short one in the same row, which #627
accepted. On the dashboard Tab still reaches *Monthly spend*'s month
links, by design: the table shows while one has focus; at 375 px it
overflows (469 px in a 306 px box) and ArrowRight from a focused link
scrolls it, so its columns stay reachable without the wrapper's old tab
stop. At 375 px, as at 1280, no Tab press lands on a hidden chart table
on the Fuel tab, the Mileage tab, the true-cost report or the dashboard.
Only the Impeccable audit and its detector ran; the change is two CSS
rules and a class, so critique and polish were not. Not covered: a
real screen reader (the accessibility tree was read instead), a
no-JS pass (the changed rules are scoped to `.js`, or apply the same
without JS), and a subpath run (no new page or link).

## Open questions

- **#622 — Is Phase 54.17 released on its own (v3.21.13) or with the next
  releasing phase?** *Decided 2026-11-15:* on its own as patch release
  v3.21.13, after 54.16. Not chosen: with the next releasing phase.
- **#623 — How does a chart's screen-reader table stop being a tab stop
  with JS on (K202)?** *Decided 2026-11-15:* `enhanceScrollRegion` skips
  any `.chart-table` wrapper. Not chosen: stopping the wrapper's overflow
  in CSS.
- **#624 — How is the layout cost of the screen-reader chart tables cut
  without capping rows (K204)?** *Decided 2026-11-15:*
  `content-visibility: auto` on `.js .chart-table`, measured; if it falls
  short, the timings are recorded and K204 goes back to *Open*. Not
  chosen: dropping K204.
- **#625 — How are git-ignored developer files kept out of an image built
  from a working tree (K205)?** *Decided 2026-11-15:* listed in
  `.dockerignore`. Not chosen: an allow-list.
- **#626 — What does a test run do when it can't delete its SQLite file
  (K206)?** *Decided 2026-11-15:* stops at once with a message naming the
  file. Not chosen: retry for up to 5 seconds, then stop.
- **#627 — How does a stat tile fit a figure and unit too wide for it
  (K208)?** *Decided 2026-11-15:* a value longer than 9 characters
  shrinks with its tile's width, no smaller than `--fs-md`; other values
  are unchanged. Not chosen: every figure shrinking in a narrow tile; a
  break only after `/`; letting the unit wrap whole (which would relax
  §8).
- **#632 — An EV's economy figure ("17,9 kWh/100 km", 15 characters) still
  splits its unit in a narrow stat tile at 375 and 768 px in German
  (K209, found while building).** *Decided 2026-11-16:* leave it, since
  #627 kept `overflow-wrap` as the last resort; K209 stays *Open* as a
  recorded LOW. It is 129 px wide at the `--fs-md` floor in a 124 px
  tile, so `overflow-wrap: anywhere` breaks it. Not chosen: a lower floor
  for long figures (e.g. `--fs-sm`); `.stats` dropping to one column when
  a tile holds such a figure.

