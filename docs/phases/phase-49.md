# Phase 49 — Getting started and household defaults + release

*From an empty garage to a vehicle with its mileage and its essential
reminders in about two minutes, with a dashboard that shows what needs
attention and what it costs.*

Status: ✅ complete · released as **v3.14.0** · file lives in `docs/phases/`

Today a new user signs in, fills in the welcome form (language, time
zone, units, currency) and lands in an empty garage. After that, they
are on their own with:

- a vehicle form with dozens of optional fields;
- a mileage log they may not know they need;
- MOT, tax and insurance dates spread across Documents and Reminders;
- a dashboard full of widgets about data they haven't entered yet.

Most modules are on by default, so a first vehicle shows tabs for
finance, incidents, issues and stations before it has a single fill-up.

This phase does three things:

- guides the first vehicle through the four things that make Logbook
  useful at once;
- gives new users a *Household* dashboard layout focused on what needs
  attention and what they are spending;
- switches fewer modules on for new installs.

Read [`CLAUDE.md`](../../CLAUDE.md), [`spec.md`](../../spec.md) §7.9
(*Welcome*), §7.10 (modules), §7.5 (documents), §7.6 (reminders), §7.4
(schedules), §7.24 (*Needs attention*), §7.38 (MOT history *Look up*),
§8 (the dashboard) first.

**Prerequisites:** Built after [Phase 46](phase-46.md), whose share summary the
shared user's first page reuses.

---

## Goals

1. **Set up your first vehicle.** A guided flow after the welcome form
   (and from an empty garage) in five short steps:
   1. **The vehicle:** registration first; with MOT history set up,
      *Look up* fills in make, model, fuel and first registration.
      Otherwise the type, make, model and fuel only.
   2. **Mileage now:** one number, dated today.
   3. **Key dates:** MOT due, tax due, insurance renewal. Each one gives
      a reminder (open question A); with MOT history, the MOT date is
      already filled in.
   4. **Servicing:** "Every 12 months or 10,000 miles" (or the owner's
      own interval) as a schedule, with the last service date if known.
   5. **Done:** what's next (log a fill-up, invite a household member,
      add the purchase price), each one link.

   Every step can be skipped, and the flow can be left and resumed.
2. **A getting-started card** on the vehicle's overview until it is
   complete or dismissed: "3 of 5 done", with the missing steps as
   links.
3. **A *Household* dashboard layout,** the default for new users:
   - *Needs attention*;
   - *Coming up* (next 3 months, with costs);
   - *Spend this month* and *Last 12 months*;
   - *Recent activity* across the household's vehicles.

   *Reset layout* offers it and the full layout. Existing users'
   layouts are not touched.
4. **Fewer modules on new installs** (open question C). Existing
   installs are unchanged.
5. **A shared user's first visit:** "Sam shared the Golf with you. You
   can log entries; you can't see costs." (Phase 46's summary), then the
   vehicle.
6. Release.

## Not in scope

- A tour, coach marks or tooltips laid over the app.
- Required fields beyond what the vehicle form already requires.
- Changing an existing user's modules, layout or vehicles.
- Analytics on how far people get. Logbook collects none.

---

## Spec (proposed)

Settled in 49.0:

- **`/vehicles/start`** (`Log` on the garage; a page, not a modal, each
  step a plain form that works without JS). It goes through the services
  the full forms use, so a vehicle made here is a vehicle like any other.
  The progress is in the user's settings (`getting_started`: vehicle id,
  steps done, dismissed).
- **Key dates:**
  - MOT and tax become documents with only an expiry date (open question
    A), raising their usual reminders.
  - Insurance becomes an insurance document with its renewal date, and
    the insurer if typed.
  - All three later take the full details on their own pages.
- **Servicing:** a schedule with the chosen interval (§7.4). Its first
  due point comes from the last service date, or the purchase date, or
  today.
- **The *Household* layout:** a named default layout (§8 *Dashboard*),
  using existing widgets only. *Recent activity* is the fleet History
  limited to 10 rows.
- **Module defaults for new installs** (C), recorded in `features` at
  setup, so a later change of defaults never flips an existing install.

---

## Tasks

### 49.0 Spec first
- [x] Open questions A–D decided by the owner and logged (#411–#414);
      §7.8, §7.9, §7.10, §13 written; `ROADMAP.md` row already there.

### 49.1 Getting started
- [x] `/vehicles/start` and its five steps, with *Look up* when MOT
      history is set up.
- [x] Resume and dismiss; the empty garage's *Set up your first vehicle*;
      the overview card.
- [x] The flow starts straight after the welcome form for a user with no
      vehicles (decided B, #412). A user who skips step 1 or dismisses is
      not sent again. German strings added with the English ones.

### 49.2 Household defaults
- [x] The *Household* layout and *Reset layout*'s choice (*Recent activity*
      at 10 rows on the preset only; decided 2026-10-14).
- [x] Module defaults written at setup (C); Settings → Modules shows
      "Switched off when Logbook was installed; switch on any time."
      beside the modules still off; an explicit `FEATURES_*` variable wins.
- [x] The "Switch on" hint where a module's absence surprises (*Incident*
      in the *Log* chooser, shown to an admin only).
- [x] The shared user's first visit (a card above the vehicle, once per
      share created from this release; the welcome form leads to it).
- [x] Translations (English and German, the shipped locales).

### 49.3 Tests and release
- [x] Each step saved through the real services; skipping each; resuming
      after sign-out; two first vehicles by two users at once
      (`GettingStartedTest`).
- [x] Existing users and installs unchanged after upgrade (layout,
      modules, settings): `HouseholdDefaultsTest`, and the upgrade from
      `v3.13.0` (see the merge review).
- [x] The new flow passes an automated journey on WebKit (iPhone 13) and
      Chromium (Pixel 7) phone profiles, and WebKit with JavaScript off:
      first-run setup to step 5, one throwaway instance each. Run once
      from a scratch Playwright install on 2026-10-14, not committed (Phase
      52 question B is still open).
- [ ] A repeat session with two people new to Logbook: both reach
      step 5 without help (owner-run; carried to the loose ends in
      `open-questions.md`, released without it).
- [x] Design review (375, 768, 1280 px; light and dark; JS off; German
      at 375 px). Fixed: the step indicator and link rows were unstyled
      (now a segmented progress and 44 px rows), the overview card got a
      progress bar, the long vehicle name wraps in *Open*, German uses
      *du*. Left as they are: the Household grid's ragged heights are the
      dashboard's own `align-items: start` grid, the same on every layout.
- [x] An accepted invitation (not a password reset) starts the setup flow
      for a user with no vehicles (#415); `UsersTest` covers both.
- [x] `VERSION`, `CHANGELOG.md`, README; `ROADMAP.md` row ✅.
- [x] Tag `v3.14.0` once merged.

---

## Acceptance criteria

1. A new user can go from an empty garage to a vehicle with its mileage,
   MOT, tax, insurance and a service schedule, each with a reminder,
   without leaving the flow, and can skip any step.
2. A new user's dashboard shows what needs attention, what's coming up
   and what they've spent, and nothing about data they don't have.
3. New installs start with fewer tabs. Existing installs see no change.
4. Definition of done (CLAUDE.md §11) holds.

## Open questions

Decided by the owner on 2026-10-14 and logged as #411–#415 in
`open-questions.md`; each took its recommendation.

- **A. MOT and tax dates: documents or reminders?** *Decided 2026-10-14 (#411): (1), the recommendation.*
  - (1) Documents with only an expiry date, which raise their usual
    reminders and show on the Documents tab.
  - (2) Manual reminders only.

  *Recommendation:* (1). The dates belong to documents everywhere else
  in Logbook (renewals, the sale pack, MOT history), and a manual
  reminder would be a second place for them.

- **B. When does the flow start?** *Decided 2026-10-14 (#412): (1), the recommendation.*
  - (1) Straight after the welcome form, for a user with no vehicles of
    their own or shared.
  - (2) Only from the empty garage's button.

  *Recommendation:* (1), with *Skip for now* on the first step. A shared
  user with vehicles never sees it.

- **C. Modules on for new installs.** *Decided 2026-10-14 (#413): (1), the recommendation.*
  - (1) On: fuel, maintenance, compliance, reminders, reports. Off:
    tyres, finance, incidents, issues, stations, trips.
  - (2) Also tyres on.
  - (3) Unchanged.

  *Recommendation:* (1). The AI modules stay as they are (on, but doing
  nothing until set up). Each module switched off gets a one-line
  "Switch on" hint where its absence would surprise (for example, *Log
  incident* on the vehicle's menu).

- **D. A preset interval for servicing?** *Decided 2026-10-14 (#414): (1), the recommendation.*
  - (1) 12 months or 10,000 miles (16,000 km), editable.
  - (2) No preset; the owner types it.

  *Recommendation:* (1), labelled "a common interval; check your
  handbook".

- **E. Invitation accepted, no vehicles: go to the setup flow?** *Decided 2026-10-14 (#415): (b), the recommendation.*
  - (a) Leave it: only the welcome form leads there.
  - (b) Also after an invitation is accepted.

  *Recommendation:* (b). It is the first-run path for a household member
  with no shared vehicle.
