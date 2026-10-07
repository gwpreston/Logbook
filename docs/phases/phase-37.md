# Phase 37 — Space between the Fuel prices providers + patch release

*The provider choices on Settings → Fuel prices sit apart like every other
list of choices in Settings.*

Status: 📋 planned · file lives in `docs/phases/`

On Settings → *Fuel prices*, the *Provider* card lists *Off*, *UK Fuel
Finder* and *Sample prices (demo)* as bordered options, but the three boxes
touch: each border runs straight into the next, with no space between them,
so the list reads as one block split by lines. Settings → *Jobs* → *How
jobs run* draws the same kind of list (*On page visits*, *External URL*)
with a gap between each bordered option. The provider list should match
it. Nothing about providers, sync or prices changes.

The phase also carries the Phase 36.4 reviews' questions the owner decided
on 2026-10-07 (#265–#269): chips at 44 px with a tick, a demoted admin's
held job failures cleared, the per-run breaker over the failed-job alert,
an all-ticked *Receives* stored as "all" (existing channels converted),
and held failures sent by one run only.

Read [`CLAUDE.md`](../../CLAUDE.md) (§7, §11) and
[`spec.md`](../../spec.md) §8 (*Settings layout*) and §7.34 (fuel prices
settings) first.

**Prerequisites:** [Phase 36](phase-36.md) complete and green.

---

## Scope

**In:** the spacing between the provider options on the *Fuel prices*
page; a check of every other bordered list of choices in Settings for the
same fault; the decided 36.4 review fixes (#265–#269, task 37.7); a
visual check; a patch release.

**Out:**
- Any change to the provider options' wording, order, icons, privacy line
  or licence line.
- Any change to the *Jobs* page (it is the reference, not the target).
- Provider behaviour, credentials, sync or the demo provider's data.

---

## Cause

Found in 37.1 (2026-10-07). Both lists use the same option card,
`label.toggle`. The space between two of them comes only from
`.fieldset > .toggle + .toggle { margin-top: var(--space-2) }` (and a
copy of it for the sale pack's options), which matches cards that are
**direct children** of a `.fieldset`. The *How jobs run* cards are; the
provider cards sit one level down, inside `<div class="stack"
role="radiogroup">`, and `.stack` has no CSS at all. So the rule never
matches and the borders meet. The same holds for any list of option
cards whose parent is not a `.fieldset` (a plain `.card` section, a
`.field`).

---

## Design decisions

- **One look for a list of bordered choices.** Radio (pick one) and
  checkbox (pick any) lists of option cards use the same spacing: the gap
  the *How jobs run* card uses today. No new size is introduced.
- **Fix it where it is shared.** If both lists use the same option-card
  macro or class, the spacing belongs on its list wrapper (a `gap` on the
  shared stack), not on the *Fuel prices* template alone. If the provider
  list has its own markup, move it onto the shared one rather than copying
  the spacing rule.
- **The selected state is unchanged.** The chosen option keeps its accent
  border and tint; with a gap, its full rounded border now shows on all
  four sides.
- **The note under the list keeps its space.** \"One provider at a time.
  Switching off keeps the data already downloaded.\" stays under the last
  option with the spacing it has now.
- **Spec:** one sentence in §8 *Settings layout*: a list of bordered
  choices (radio or checkbox) has a gap between each option. No behaviour
  change.

---

## Tasks

### 37.0 Spec first
- [ ] `spec.md` §8 *Settings layout*: bordered choices are spaced apart;
      §13 gains the phase summary.
- [x] `ROADMAP.md` gains a Phase 37 row (📋); `CHANGELOG.md`
      `[Unreleased]` gets a *Fixed* entry.
- [ ] `spec.md` §7.11 and §8 for the decided 36.4 questions (#265–#269).

### 37.1 Find the cause
- [x] Compare the *Fuel prices* provider list's markup and CSS with the
      *How jobs run* triggers'; write the cause into *Cause* above.

### 37.2 Fuel prices provider list
- [x] Give the provider options the same gap as the *How jobs run*
      options, through the shared list wrapper (design decisions). Done
      by removing the unstyled `div.stack`: the options are now the
      fieldset's own children, as on *Jobs*, so the existing
      `.fieldset > .toggle + .toggle` rule spaces them (22 px between
      cards, measured, the same as *How jobs run*). The radiogroup's
      `aria-describedby` moved to the fieldset, which already groups the
      radios under its legend. No CSS changed.
- [ ] Check with each provider selected (*Off*, *UK Fuel Finder*,
      *Sample prices (demo)*): the selected border and tint are whole and
      nothing shifts when the choice changes.
- [ ] Check with *Sample prices (demo)* hidden (production), so two
      options remain and still have the gap.

### 37.3 Every other list of choices
Check each bordered radio or checkbox list in Settings and on the profile
page for the same fault, and fix any with the same shared rule:
- [ ] Settings → *Jobs* → *How jobs run* (reference; must not change).
- [ ] Settings → *AI* (connections, task routing), *Notifications*
      (channels), *Appearance* (accent, theme), *Modules*, *Backups*, and
      any other card with option cards.
- [ ] Forms outside Settings that use the same option-card macro (the
      confirm pages, the incident and finance type choices, *Log entry*
      chooser), if any.
- [x] Record the list checked and what was changed under this task.

      Measured on 2026-10-07 at 1280 px (the space between each pair of
      adjacent option cards): Settings → *Fuel prices* **0 px (the fault,
      fixed)**; *Jobs*, *Modules*, *Updates* 22 px; *Delivery* (*Where
      members can send*) 8 px; *Notifications*, *Reminders*, *Users*,
      *Add user*, *Profile*, *Transfer*, *History print*, *Cheapest near
      me*: one option card, nothing to space; *AI → Add a connection*,
      *Log a trip*: option cards in separate places, not a list; *Saved
      journey* form, *Log a fill-up* 14 px and *Sharing* 16 px (the
      card's or form's own gap); *Sale pack* options 14 px. No other list
      had the fault, so none changed. The differing gaps (8, 14, 16 px)
      are not touching borders and are left as they are (acceptance 2).

### 37.4 Visual check
At 375, 768 and 1280 px, light and dark, blue and purple accents, with
and without JS, and by keyboard (focus ring whole on each option):
- [ ] *Fuel prices* provider list matches the *How jobs run* spacing.
- [ ] No list found in 37.3 changes except where it had the fault.
- [ ] Design-reviewer clean of HIGH findings on the changed pages.

### 37.5 Tests
CSS only, so nothing new for PHPUnit to assert about spacing. Keep the
suite green and:
- [ ] Integration (HTTP): Settings → *Fuel prices* renders the provider
      options inside the shared list wrapper the CSS relies on, so a later
      template change can't drop it silently.
- [ ] Suite green on SQLite, PostgreSQL, MySQL and MariaDB; coverage at or
      above the floor.

### 37.7 The 36.4 reviews' questions (decided 2026-10-07)
- [ ] **#265:** the shared chip is 44 px tall (`--control-h`), and a
      chosen chip (`:checked`, `aria-pressed="true"`) shows a tick as
      well as the fill; chips that mark where you are (`aria-current`)
      don't. The segmented control keeps its height.
- [ ] **#266:** demoting an admin (Settings → Users, or the admin groups
      at single sign-on) clears their held job failures
      (`jobs.held_failures`).
- [ ] **#267:** the per-run breaker stays armed for the failed-job alert
      sent after the run, so a host skipped in the run is skipped there
      too; disarmed afterwards even when the alert throws.
- [ ] **#268:** a *Receives* with every offered box ticked is saved as
      "all" (null), so a category added later reaches it; a migration
      converts the full lists saved under v3.3.0 (personal channels and
      email). Rolling back leaves them as "all", which is what they
      meant.
- [ ] **#269:** held failures are sent only by the run whose delete
      removed them (the row's `updated_at` as read, affected rows = 1),
      so two runs finishing together can't both send them.
- [ ] Tests for each, run on SQLite, PostgreSQL and MySQL.

### 37.6 Release
- [ ] `VERSION` → the next patch version after Phase 36's release.
- [ ] `CHANGELOG.md`: *Fixed* — the fuel price providers in Settings are
      spaced apart like other choices, and the 37.7 fixes. One data
      migration (#268), no schema or config changes, no backup format
      change.
- [ ] Rebuild assets; `ROADMAP.md` Phase 37 row ✅.
- [ ] Tag once merged.

---

## Acceptance criteria

1. On Settings → *Fuel prices*, each provider option is a separate card
   with the same gap between them as Settings → *Jobs* → *How jobs run*.
2. No other list of choices gains or loses spacing except where it had
   the same fault.
3. Definition of done (CLAUDE.md §11) holds.

## Open questions

None of its own. Before it started, the open Phase 36.4 questions
(#265–#270) were put to the owner and decided on 2026-10-07; #265–#269
are built here (37.7), #270 needs nothing. Found while starting it and
decided the same day: #271 (convert the all-ticked lists saved under
v3.3.0: yes, by a migration).
