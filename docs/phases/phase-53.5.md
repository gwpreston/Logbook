# Phase 53.5 — API edges and narrow-screen fixes from the known-issues log + patch release

*Four open rows of the known-issues log closed: two API writes that
answer wrongly at their edges, and two pages that break on a phone or at
low contrast.*

Status: 📋 planned · file lives in `docs/phases/` · releases the patch
after Phase 53.4's release, a patch release

This is the next batch after [Phase 52.2](phase-52.2.md). Like 52.1 and
52.2, it takes a few small open rows that need no decision. Batch 1 is
the REST API: K27 and K50. Batch 2 is layout and contrast: K52 and K53.
All four are MEDIUM and older than Phase 53. It is numbered 53.5 because
no phase is in progress, so it is the next phase not yet defined, after
53.4 in the ROADMAP.

Read [`CLAUDE.md`](../../CLAUDE.md), [`spec.md`](../../spec.md) §7.20
(*REST API*, the modification and schedule writes), §7.33 (*Stations*),
§8 (*Accessibility*, the colour contrast test), and
[`open-questions.md`](open-questions.md) first.

**Prerequisites:** [Phase 53.4](phase-53.4.md) merged, and its release
tagged.

---

## Goals

### Batch 1: the REST API

1. **K27.** Retrying `POST /vehicles/{id}/modifications` with a stored
   part in `parts_fitted` (`{"part": 12}`) or `parts_removed` answers the
   stored modification with `duplicate: true`, as spec §7.20 "Safe to
   retry" says, instead of 422 `part_id`.
2. **K50.** `POST` and `PATCH` on `/vehicles/{id}/schedules` with a
   20-digit `baseline_odometer` or `interval_distance` answer 422 with a
   field error, as `interval_km` already does, instead of 500
   `internal_error`.

### Batch 2: layout and contrast

3. **K52.** At 375 px, `/stations/near` and `/stations/{id}` don't
   scroll sideways as a whole page. Only their tables scroll, inside
   their boxes.
4. **K53.** On `/reminders` at 960 px and wider, the red reminders count
   on the active sidebar link meets 4.5:1 in the light and dark themes,
   with every accent colour.

## Not in scope

- Rows that wait on an open question: K1, K3, K29, K39, K44, K45 (#461),
  K47, K48, K54, K56, K57, K67, K71, K72, K93, and K59, which is what
  #454 asks (which number readings the grounding check accepts).
- K85: it needs an eval of the enlarge rule first.
- K65 (Tracktor command line skips nothing and names Logbook's fields):
  a good next candidate, copying K11's Fuelio fix; left for another
  batch.
- K51 (SQLite search and `_`, `%`, non-ASCII capitals). The `ESCAPE`
  half is small, but the non-ASCII half needs a function registered on
  SQLite connections or filtering in PHP. Not a one-place fix.
- K90 and K95 (Scan page and prefilled fill-up form). Phase 53.3 changes
  both.
- K96, K97 and K98 (scanned parts lines and numbers). Small, but in the
  Scan reading code that 53.3 and 53.4 build on; a later batch.
- Larger rows (K40–K43, K49) and the tap-target rows (K16, K35, K55),
  as 52.1 and 52.2 said.

---

## Decisions (and why)

These need no decision from the owner. Each makes the code do what the
spec already says.

- **K27 scans for a duplicate before checking the parts lists.** Spec
  §7.20 defines a retry by the vehicle, `done_on` and title. The first
  call has already fitted or removed the stored parts, so checking the
  lists first refuses every retry that names one. The modification's
  own fields are still parsed first, so a malformed body is still 422.
- **K50 checks a distance's size before converting it to km.** The
  other numeric fields already answer 422 with `validation.too_large`;
  converting first lets the overflow escape as a 500. The same string is
  used, so nothing new to translate.
- **K52 gives `.table-wrap` a position,** so the hidden caption and
  label text inside a table stays in its scroll box. It fixes every
  `.table-wrap` table with hidden text, not only the two named.
- **K53 changes only the badge on the active link.** Elsewhere the badge
  already passes; the active link's accent tint under it is what lowers
  the contrast.
- **Release:** a patch on its own after 53.4's release (#502, decided
  2026-10-18).

---

## Tasks

### 53.5.0 Start of phase

- [ ] Read `open-questions.md` and the *Open* and *Scheduled* rows of
      `known-issues.md` (CLAUDE.md §12).

### 53.5.1 The REST API (batch 1)

- [ ] `ApiModifications::logModification()` scans for a duplicate
      before `lists()` (K27). `ApiPartsTest`: a retry with
      `{"part": id}` in `parts_fitted`, and one with it in
      `parts_removed`, each answer the stored modification with
      `duplicate: true` and change no part.
- [ ] `JsonInput::schedule()` refuses a too-large `baseline_odometer` or
      `interval_distance` with a field error before `$toKm` (K50). An
      API test: `POST` and `PATCH` with `"99999999999999999999.99"`
      answer 422 naming the field.

### 53.5.2 Layout and contrast (batch 2)

- [ ] `.table-wrap` positioned in `assets/css/app.css` (K52). A test
      checks the rule; `/stations/near` and `/stations/{id}` checked at
      375 px with no sideways page scroll.
- [ ] The badge on `.nav-link[aria-current="page"]` meets 4.5:1 (K53).
      `ColourContrastTest` covers `red` on `surface` + `accent-soft` +
      `red-soft` in both themes and every accent.

### 53.5.3 Finish

- [ ] Known-issues rows K27, K50, K52 and K53 *Fixed*.
- [ ] CHANGELOG; spec §13; ROADMAP status.
- [ ] Merge review, then merge.

### Release

- [ ] `VERSION`, CHANGELOG, README status, tag: the patch after 53.4's
      release (#502).

## Acceptance criteria

- Each code row (K27, K50, K52, K53) has a regression test that fails
  without its fix. K52 is also checked by screenshot at 375 px.
- `composer lint`, `composer analyse` and `bin/test-all-dbs.sh` pass on
  SQLite, PostgreSQL, MySQL and MariaDB. `MarkdownLinksTest` passes.
- No migration. Any new or changed strings are in every catalogue in
  `translations/`.

## Open questions

- **#502. Release.** *Decided 2026-10-18:* released on its own as a
  patch, after Phase 53.4's release, like 52.1 (#481) and 52.2 (#486).
  None of the rows touch the Scan code that 53.3 and 53.4 change. Not
  chosen: with the next phase that releases.
