# Phase 54.6 — The true-cost cover note and a leading decimal comma from the known-issues log + patch release

*Three open rows of the known-issues log closed: the true-cost cover note
shows under the yearly table for every vehicle, and `,5` reads like `.5`.*

Status: 📋 planned · releases **v3.21.4**, a patch release (#586) · file lives in `docs/phases/`

This is the batch after [Phase 54.4](phase-54.4.md). The issue sweep of
v3.21.2 found that 54.4's note still goes missing when the purchase or sale
year is one of the latest three but has no working (K171), and that the
template does less than spec §7.35 says (K173). The owner took both, decided
how the note shows (#585), and added K168, a small parser fix. It is
numbered 54.6 because it follows 54.5, the last row of the roadmap.

Read [`CLAUDE.md`](../../CLAUDE.md), [`spec.md`](../../spec.md) §7.35
(*True cost*, *Cover outside ownership*), §7.13 (the import's *Numbers*
choice), §7.27 (*Numbers* read in the user's locale), and
[`open-questions.md`](open-questions.md) first.

**Prerequisites:** [Phase 54.5](phase-54.5.md) merged, and `v3.21.3` tagged.

---

## Goals

1. **K171.** On the true-cost report, a vehicle bought (or sold) in one of
   the latest three years still shows its "£x of cover before you bought it
   isn't counted" (or "… after you sold it …") note when that year has no
   costs inside ownership and so no working. The figures don't change.
2. **K173.** The note shows under the yearly table for every year that
   leaves cover out, whatever the vehicle's age, as spec §7.35 says, and
   still in that year's working when it has one. The "after you sold it"
   note shows there too.
3. **K168.** A number with a leading decimal comma (`,5`, `-,5`) reads as
   0.5 under the import's *Decimal comma* and for a German-locale user, as
   `.5` already does.

## Not in scope

- **K172**: the missing merge-review record for 54.4 and 53.16–53.20 is a
  paperwork question for the owner, not a code fix.
- **K164** and **K163**: the offline outbox and the fill-up shortcut are a
  different area (the service worker and `app.js`); another batch.
- **K169** waits on #582; **K145** needs an API key; **K160** waits on an
  upstream `symfony/mime` release.

---

## Decisions (and why)

- **K171 and K173 follow #585** (option 1): the under-table loop runs over
  every year, not only those past the latest three, so the note no longer
  depends on whether the year has a drawn working. It makes the template do
  what spec §7.35 already says ("seen however old the vehicle is, not only
  when that year has a drawn working"); §7.35 adds that it also stays in
  the year's working. One template change closes both rows. It uses the
  existing `working.true_cost.cover_before` / `cover_after` wording.
- **K168** makes the comma reading do what the dot reading does: an empty
  whole part before the decimal symbol is 0, as `canonical()` already
  allows for `.5`. A lone separator (`,`) stays invalid.
- **Release (#586):** on its own as patch release v3.21.4, after 54.5's
  v3.21.3, so it builds on 54.5's lighter true-cost working-out.
- No migration.

---

## Tasks

### 54.6.0 Start of phase

- [ ] Read `open-questions.md` and the *Open* and *Scheduled* rows of
      `known-issues.md` (CLAUDE.md §12). #585 and #586 are decided; #375, #376 and #567 were decided 2026-10-31
      and are built in 54.6.3 (#377 and #565 decided, nothing to build).

### 54.6.1 The cover note under the yearly table (K171, K173)

- [ ] `templates/reports/true_cost.twig` names each year's cover outside
      ownership under the yearly table for every year, not only
      `slice(3)`; the figures are unchanged. `TrueCostPagesTest` covers a
      vehicle bought in one of the latest three years with no costs that
      year (K171), a vehicle three years old or younger with cover before
      the purchase, and a sold vehicle with cover after the sale (K173),
      in English and German.

### 54.6.2 A leading decimal comma (K168)

- [ ] `DecimalParser::withSymbols()` / `ungroup()` read an empty whole part
      as 0, so `,5` and `-,5` read as 0.5 and −0.5 with a comma decimal; `,`
      alone stays invalid. `DecimalTest` covers both, and an import
      under *Decimal comma* in `ImportTest`.

### 54.6.3 Digest decisions from the open-questions log (#375, #376, #567)

- [ ] The digest's *Fleet line* says "vehicles whose costs you see"
      (translated, every catalogue) when the viewer may see the costs of only
      some of the listed vehicles; the JSON `fleet` gains
      `spend_partial` (#375).
- [ ] The 12-month cost per distance and its average are "—" unless the
      vehicle's readings reach back to the start of the 12 months
      (`reachesBack`, as true cost); the Reports page is unchanged (#376).
- [ ] Each `last_month` row in the webhook JSON carries `archived`
      (boolean) (#567).
- [ ] Tests for each, in English and German, on the digest text and JSON;
      docs/api and notification docs updated.

### 54.6.4 Finish

- [ ] Known-issues rows K168, K171 and K173 *Fixed*.
- [ ] CHANGELOG; spec §13; ROADMAP status.
- [ ] Merge review; merge.

### Release

- [ ] VERSION 3.21.4, CHANGELOG 3.21.4, README status (#586).
- [ ] Tag `v3.21.4` once merged.

## Acceptance criteria

- K168, K171 and K173 each have a regression test that fails without its
  fix; true-cost figures unchanged.
- `composer lint`, `composer analyse` and `bin/test-all-dbs.sh` pass on
  SQLite, PostgreSQL, MySQL and MariaDB. `MarkdownLinksTest` passes.
- No migration. New strings are in every catalogue in `translations/`.

## Open questions

- **#585. Decided 2026-10-30:** option 1, the note under the yearly table
  for every year that leaves cover out, and still in the year's working
  (K171, K173). Not chosen: only for years without a drawn working, with
  §7.35 narrowed to match.
- **#375. Decided 2026-10-31:** label the fleet line. Not chosen: leave
  it; leave the fleet spend out.
- **#376. Decided 2026-10-31:** require readings that reach back for the
  digest's 12-month cost per distance. Not chosen: match Reports.
- **#377. Decided 2026-10-31:** keep distances and open issues on the
  server's webhook. Not chosen: drop the briefing from its copy.
- **#565. Decided 2026-10-31:** shrink the reassurance lines, as today.
- **#567. Decided 2026-10-31:** add `archived` to `last_month` rows.
  Not chosen: leave it.
- **#586. Release. Decided 2026-10-30:** on its own as patch release
  v3.21.4, after 54.5. Not chosen: with the next releasing phase.
