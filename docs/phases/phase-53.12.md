# Phase 53.12 — Tap targets, narrow screens and SQLite search from the known-issues log + patch release

*Five open rows of the known-issues log closed: compact controls get a
44 px hit area on a phone and keep how they look, the Parts and insurer
pages' small links and buttons with them, a *Coming up* row with a cost
keeps its title readable at 375 px, and on SQLite the AI model and
station searches find names with `_`, `%` and non-ASCII capitals.*

Status: 📋 planned · file lives in `docs/phases/` · releases the patch
after Phase 53.11's release, a patch release

This is the next batch after [Phase 53.11](phase-53.11.md). Like 52.1,
52.2 and 53.5–53.11, it takes a few small open rows: K16 (LOW), K35
(MEDIUM) and K55 (LOW), the tap-target rows that waited on #457 until
the owner decided it when 53.11 was planned, and K41 (HIGH, *Coming up*
rows at 375 px) and K51 (MEDIUM, SQLite search), which earlier batches
left out as larger and the owner added to this one. It is numbered
53.12 because no phase is in progress: Phases 53.9, 53.10 and 53.11 are
planned but not started, so the batch is the next phase not yet
defined, after 53.11 in the ROADMAP.

Read [`CLAUDE.md`](../../CLAUDE.md) (§6, §7, §11, §12),
[`spec.md`](../../spec.md) §7.18 (*Coming up*), §7.25 (*AI
connections*), §7.33 (*Stations*), §7.39 (*For your insurer*), §8
(*Tap targets*), and
[`open-questions.md`](open-questions.md) first.

**Prerequisites:** [Phase 53.11](phase-53.11.md) merged, and its release
tagged.

---

## Goals

### Tap targets and narrow screens

1. **K55.** On a phone every compact control has a hit area at least
   44 × 44 px and looks as it does now: soft buttons ("Review…", "Looks
   right"), the Economy segmented tabs, back links, card links such as
   "All reminders", `<summary>` rows and breakdown label links (#457,
   spec §8).
2. **K35.** The Parts pages' small targets reach 44 px at 375 px: the
   back links at the top of a part and a modification, the part page's
   fitting-row link, the insurer page's modification title link and its
   per-row *Mark declared* button.
3. **K16.** On the insurer page, *Mark all* with a blank date says
   "Enter the date you told your insurer" beside the date field instead
   of "not in the future" in a banner; the row buttons are 44 px tall to
   tap at 375 px; and the Parts toolbar wraps instead of crowding.
4. **K41.** At 375 px, and in a narrow dashboard column, a *Coming up*
   row with a cost ("about £189.00 (last time) as of Oct 2025") keeps
   its title and meta readable: the cost wraps under its pill instead of
   squeezing the title to nothing, and the title link is a real target
   for the keyboard. On the dashboard widget, a vehicle's overview and
   `/upcoming`.

### SQLite search

5. **K51.** On SQLite, searching AI models for `gpt_4` finds `gpt_4o`
   and not `gpt-4o`; searching stations for `50%` finds "Sale 50% off";
   `öl` finds "Ölhandel Müller". The same searches give the same results
   on PostgreSQL, MySQL and MariaDB.

## Not in scope

- K13 (export writes local time with no offset): changes a CSV
  contract and needs a decision (53.6).
- K19 (warranty reminder queries and inserts), K49 (a 5,000-row fuel
  commit on PostgreSQL), K74 (Imagick slower than GD) and K129 (a CSV
  import's peak memory): performance passes or a streaming reader, not
  one-place fixes; K74 may reopen #492.
- K63 (on SQLite a table rebuild resets its id counter): a migration
  and upgrade matter across many tables (53.7).
- K79 (the optional PaddleOCR image's install and user): Docker
  hardening across the image and compose file; a batch of its own.
- K132 (a scan with a Claude model on `json_schema`): a batch of its own
  once a request is recorded (53.10).
- K40, K42 and K43: larger rows that also wait on #449, #450 and #451.
- Rows that wait on an open question: K1 (#484), K3 (#485), K39, K44
  (#452), K48 (#455), K54 (#456), K56 (#458), K57 (#459), K67 (#483),
  K71 (#487), K72 (#488).
- Controls already at 44 px (chips, the calendar's agenda links) and a
  redesign of any compact control: #457 keeps how they look.

---

## Decisions (and why)

- **K55 grows the hit area, not the control** (#457, decided
  2026-10-21). One narrow-width block in `assets/css/app.css` gives
  `.btn--soft`, `.segmented__option`, `.back-link`, `.card__link`,
  `.breakdown__label` and `<summary>` rows a `::after` (or padding where
  the control already has a positioned box) that reaches 44 × 44 px,
  centred on the control. Their drawn size, text and spacing don't
  change. This makes the CSS do what spec §8 *Tap targets* already says.
- **K35 and K16's targets come mostly from K55.** The back links and
  the per-row *Mark declared* button (`.btn--soft`) are K55 controls;
  the fitting-row link and the insurer title link are inline links in a
  list row, so they get the same hit-area rule by their own classes.
  The insurer row's *Clear* (`.btn--ghost`) gets it too.
- **K16's blank date is a field error.** `DeclareModificationsAction`
  adds `insurer.error.required` to `declared_on` when the date is blank
  (and keeps `insurer.error.future` for a future one), and the page
  shows field errors beside the field rather than only the
  `insurer.error.date` banner. The `required` attribute already stops a blank date in a
  browser; this is the no-JS and crafted-request path. The toolbar uses
  the shared `btn-row` wrap at narrow widths. The date rules stay as
  #445 and #480 decided.
- **K41 lets the cost shrink and wrap.** A `.forecast-item__end` rule
  lets the cost column shrink (`flex: 0 1 auto` with a max width) and
  lets `.as-of` wrap inside a *Coming up* row only, so the body keeps
  its room. Log rows keep `.list__end { flex: none }`: their amounts are
  short and must stay on one line. This makes the page do what WCAG
  1.4.10 (reflow) and spec §8's responsive rule already ask.
- **K51 escapes with `!` and lowers in PHP on SQLite.** Both searches
  add `ESCAPE '!'` and escape `!`, `%` and `_` with it. `!` is used
  rather than `\` because a backslash in a string literal means
  different things on MySQL (an escape) and PostgreSQL (a plain
  character), so `ESCAPE '\'` can't be written the same on both
  (CLAUDE.md §6). For non-ASCII capitals, `SessionInitMiddleware`, the
  documented platform branch that already sets `PRAGMA foreign_keys`,
  registers `LOWER` on SQLite connections as `mb_strtolower()`.
  PostgreSQL, MySQL and MariaDB already lower Unicode. This makes the
  code do what the repositories' docblocks say ("case-insensitive").
- **Rows:** the owner chose K16, K35 and K55, and added K41 and K51,
  when the batch was planned (2026-10-21).
- **Release:** a patch on its own after 53.11's release (#546, decided
  2026-10-21).

---

## Tasks

### 53.12.0 Start of phase

- [ ] Read `open-questions.md` and the *Open* and *Scheduled* rows of
      `known-issues.md` (CLAUDE.md §12).

### 53.12.1 Tap targets and narrow screens

- [ ] The narrow-width hit-area block in `assets/css/app.css` for
      `.btn--soft`, `.segmented__option`, `.back-link`, `.card__link`,
      `.breakdown__label` and `<summary>` rows (K55, #457). A
      `TapTargetTest` beside `TableWrapTest` checks the rule for each
      class; the pages K55 names checked at 375 px: every target at
      least 44 × 44 px, nothing drawn differently, no sideways scroll.
- [ ] The Parts pages: the fitting-row link in `part_show.twig`, the
      title link and *Clear* in `insurer.twig` get the hit area (K35).
      `TapTargetTest` covers their classes; `part_show`,
      `modification_show`, `insurer` and `index` checked at 375 px.
- [ ] `DeclareModificationsAction`: a blank date is a field error,
      `insurer.error.required` in every catalogue in `translations/`;
      the Parts toolbar wraps at narrow widths (K16). `InsurerListTest`:
      *Mark all* with a blank date shows the message beside the field
      and marks nothing.
- [ ] A `.forecast-item__end` rule and `.as-of` wrapping inside it
      (K41). `TapTargetTest` (or a `ForecastRowTest`) checks the rule;
      the dashboard widget, a vehicle's overview and `/upcoming`
      checked at 375 px and in a narrow dashboard column at 1440 px:
      the title link wider than 0 px and no text over text.

### 53.12.2 SQLite search

- [ ] `AiModelRepository::listForConnection()` and
      `ProviderStationRepository::searchByName()`: `ESCAPE '!'`;
      `SessionInitMiddleware` registers `LOWER` as `mb_strtolower()` on
      SQLite (K51). A test on every engine: `gpt_4` finds `gpt_4o` and
      not `gpt-4o`, `50%` finds "Sale 50% off", `öl` finds "Ölhandel
      Müller", `a!b` finds `a!b`.

### 53.12.3 Finish

- [ ] Known-issues rows K16, K35, K41, K51 and K55 *Fixed*; spec §8
      *Tap targets* no longer says "not yet built everywhere".
- [ ] CHANGELOG; spec §13; ROADMAP status.
- [ ] Merge review (the design-reviewer at 375 px among the agents),
      then merge.

### Release

- [ ] `VERSION`, CHANGELOG, README status, tag: the patch after 53.11's
      release (#546).

## Acceptance criteria

- K16, K35, K41, K51 and K55 each have a regression test that fails
  without the fix.
- At 375 px every control the rows name has a hit area of at least
  44 × 44 px and looks as it did; no page scrolls sideways.
- `composer lint`, `composer analyse` and `bin/test-all-dbs.sh` pass on
  SQLite, PostgreSQL, MySQL and MariaDB. `MarkdownLinksTest` passes.
- No migration. The new strings are in every catalogue in
  `translations/`.

## Open questions

- **#457. Tap targets at narrow widths** (K16, K35, K55). *Decided
  2026-10-21* (when Phase 53.11 was planned): (3) compact controls keep
  their look and get a 44 × 44 px hit area at narrow widths. Not
  chosen: (1) every control drawn at least 44 px tall; (2) fix only
  targets under WCAG 2.5.8's 24 px. spec §8.
- **#546. Release.** *Decided 2026-10-21:* released on its own as a
  patch, after Phase 53.11's release, like 52.1 (#481), 52.2 (#486),
  53.5 (#502), 53.6 (#503), 53.7 (#506), 53.9 (#527), 53.10 (#543) and
  53.11 (#545). None of the rows touch code 53.9, 53.10 or 53.11
  change. Not chosen: with the next phase that releases.
