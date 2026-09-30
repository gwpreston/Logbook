# Phase 24 — Needs attention + v2.4 release

*What is wrong right now, on one short list, with the fix one tap away.*

Status: 📋 planned · releases **v2.4.0** · file lives in `docs/phases/`

Logbook already knows when something is wrong: an overdue service, an
expired MOT, tyres past their limit, a mistyped odometer, a fill-up that
doesn't add up. But each fact lives on its own tab. This phase gathers them
into a **Needs attention** list on each vehicle's overview and a dashboard
widget. Each item says what is wrong and links straight to where to fix it.

It is deliberately **not a health score**. A number like "82% healthy"
invents a weighting between an expired MOT and a stale valuation that means
nothing, and it hides the one thing that matters behind an average. The
list is facts the app already computes, in a fixed order. When nothing is
wrong, it disappears.

Read [`CLAUDE.md`](../../CLAUDE.md) and [`spec.md`](../../spec.md) §7.2,
§7.3, §7.6, §7.8, §7.17 and §7.18 first. It depends on nothing after
Phase 19 except two optional hooks: Phase 21.2's first MOT (already
covered through *Coming up*) and Phase 22's trips check. It could be moved
earlier.

---

## Goals

1. One service that lists a vehicle's attention items from existing
   judgements. It computes nothing new, apart from one staleness check.
2. A *Needs attention* card at the top of the vehicle overview, hidden when
   empty.
3. A `needs_attention` dashboard widget across visible vehicles (and the
   vehicle chip).
4. A marker on garage cards.
5. Hiding for data checks that are genuinely fine. A hidden item comes back
   when its data changes.

## Not in scope

- A score, grade, percentage or traffic-light rating of a vehicle.
- New notifications. Reminders already notify about due work. Data checks
  are not sent (see *Open questions*).
- Things that are merely *due soon*. That is *Coming up* and the reminder
  list. This list is for what is wrong now.
- Automatic fixes.

---

## Spec addition (§7.24 Needs attention)

### 7.24 Needs attention (Phase 24)
Derived on every read (`Service\Attention\AttentionList`) from the same
services that own each fact, and never stored, apart from the user's hidden
items. Active vehicles only. A switched-off module's items leave the list.
The list is core.

**Items**, in this order:

*Now*: work or paperwork that is overdue.
1. **Overdue items:** *Coming up*'s *Overdue* group for the vehicle
   (§7.18). That is schedules past a limit, documents past expiry, tyres
   past a wear or age limit, manual reminders past their date (reminders
   on), and the first MOT (Phase 21.2). They are read from the sources, so
   the list works with the `reminders` module off. With reminders on, an
   item whose current reminder occurrence is dismissed or done is left
   out. Titles use each source's own wording ("MOT expired 3 days ago",
   "Annual service overdue by 400 mi", "Front tyres below 3 mm"), oldest
   first. The action is *Log it* (the entry form for that work, prefilled
   as the reminder's *Done* link is) and, with reminders on, *Dismiss*.

*Check*: the data looks wrong, so figures built on it may be too.
2. **Implausible readings:** readings the Mileage tab flags (§7.2:
   backwards, or a jump over 2,000 km a day), one item per reading:
   "Reading on 12 Aug 2026 (48,120 mi) is lower than the one before". The
   action is *Fix* (the reading's, or its owning entry's, edit form).
3. **Economy flags:** the fill-ups the Fuel tab's economy check flags and
   that are not confirmed (§7.3), as **one** item per vehicle: "3 fill-ups
   look unusual", linking to `?check=1`. They are confirmed there with
   *Looks right*, as now.
4. **Mileage not updated:** the vehicle has a distance-based schedule, or a
   fitted tyre with a wear estimate, and its latest reading is more than
   **60 days** old: "No mileage logged since 2 May 2026. Distance-based
   services can't be projected." The action is *Add reading*.
5. **Trips exceed mileage** (Phase 22, `trips` on): the split's notice for
   the current tax year, linking to the Trips tab.
6. **Stale valuation:** the vehicle has valuations, is not sold, and the
   latest is over 12 months old (§7.1's hint), linking to *Add valuation*.

**Hiding.** *Check* items 2, 4 and 6 have *Hide*. It is a POST with CSRF
that stores a row keyed by the item's kind and subject, with a
**fingerprint** of what it judged: the reading's value and its
neighbours; the latest reading's id; the latest valuation's id. The item
stays hidden only while the fingerprint matches, so an edit or a new entry
that changes the judgement brings it back. This is the same idea as
`economy_confirmed` (§6). Item 3 uses *Looks right* as today, and item 5
has no *Hide* (fix the readings instead). *Now* items are dismissed
through their reminder, never hidden here, so there is one place to
dismiss due work.

**Where it shows:**
- **Overview:** a *Needs attention* card first, above every other card,
  showing up to five items plus *Show all (8)* (a `<details>`, working
  without JS). Hidden entirely when there are no items. Each item has an
  icon, its *Now* or *Check* label as text (never colour alone), the
  title and its action links.
- **Dashboard widget** `needs_attention` (§7.8): the same items across the
  visible active vehicles, each naming its vehicle, *Now* items first,
  then *Check*. It shows up to eight, and follows the vehicle chip. When
  there are none it shows "Nothing needs attention" (a widget keeps its
  place). New layouts put it first. Existing layouts get it appended, as
  with every new widget.
- **Garage cards and the fleet widget's tiles:** beside "N due", a
  "Needs attention" marker (icon and text, with the count in its
  accessible label) when the vehicle has any item.
- Not in History, print, the sale pack, notifications or the API in this
  phase.

**Access** (Phase 19): users see the items of vehicles they can view.
*Check* items and *Hide* appear only to users who could fix them
(`Manage`, or `Log` for an item about their own entry). Hidden items are
per user.

**Cost:** the overview computes one vehicle's items. The widget computes
each visible vehicle's with the per-request memoisation that *Coming up*
and the fuel services already use. No item runs a query per row.

### §6 Data model

**AttentionHidden** (Phase 24): id, user_id (`ON DELETE CASCADE`),
vehicle_id (`ON DELETE CASCADE`), kind (`reading` | `mileage_stale` |
`valuation_stale`), subject_id (the reading or vehicle id), fingerprint
(SHA-256 hex of the judged state), hidden_at (UTC). `(user_id, kind,
subject_id)` is unique. It is in backups.

---

## Decisions (and why)

- **No score.** See the introduction. Facts in a fixed order are honest and
  actionable; a score is neither.
- **Overdue work comes from *Coming up*, not the reminders table.** It
  works with reminders off, shows the same dates as the forecast, and
  honours dismissals when reminders are on. Dismissing stays in one place.
- **Economy flags are one item.** A cold week can flag three tanks. One
  line with a count, linking to the check view, beats three.
- **The stale-mileage threshold of 60 days** applies only where projections
  need readings. A garaged classic with no distance-based schedule is never
  nagged.
- **Hiding uses fingerprints.** A hidden check reappears if the data it
  judged changes, so hiding never buries a new problem.

---

## Tasks

### Spec and docs
- [ ] §7.24, §6, §7.8 (widget list) and §7.1 (garage card marker) in
      `spec.md`; the Phase 24 line in §13; remove the *Needs attention* line
      from the roadmap's *After 1.0* list.
- [ ] README status paragraph.

### Migration
- [ ] `attention_hidden`. Every engine, reversible; moves the schema
      version; backups include it.

### Services
- [ ] `Domain\Attention\AttentionItem` (kind, severity `now` | `check`,
      vehicle, title parameters, actions, fingerprint) and `AttentionKind`
      enum.
- [ ] `Service\Attention\AttentionList`: gathers from `ComingUp` (the
      overdue group), odometer plausibility, the economy check,
      `TyreJudgement` (through *Coming up*), the valuation staleness rule,
      `MileageSplit` (Phase 22) and the new stale-mileage check. Applies
      dismissals, hidden rows, access and module toggles, and sorts.
- [ ] `Service\Attention\StaleMileage`: the 60-day rule, only for vehicles
      with a distance-based schedule or a wear-estimated fitted tyre.
- [ ] Fingerprint builders per kind; `AttentionHiddenRepository`.

### Actions, templates
- [ ] Overview card partial; the widget (registered in the widget list,
      defaults to first in new layouts); garage card and fleet tile marker.
- [ ] `Action\Attention\Hide` (POST, CSRF, access check, returns to where it
      came from; works in and out of modals).
- [ ] Translations (en, de) for every title, with ICU plurals and dates.

### Tests
- [ ] Each kind appears exactly when its source says so, and disappears
      when fixed: log the overdue service; edit the backwards reading;
      *Looks right* on the flagged fill-ups; add a reading; add a
      valuation.
- [ ] With reminders on, a dismissed reminder's item is gone; with
      reminders off, overdue items still show.
- [ ] Hiding: hidden while the fingerprint matches; back after an edit to
      the reading or its neighbours, a new reading, or a new valuation;
      per user.
- [ ] Stale mileage: not raised without distance-based schedules or wear
      estimates; raised at 61 days and not at 59, counted in the owner's
      time zone.
- [ ] Order: *Now* before *Check*, oldest overdue first; the overview shows
      five and *Show all*; the widget follows the chip and shows "Nothing
      needs attention" when empty.
- [ ] Module toggles remove their items; archived vehicles never appear.
- [ ] Access matrix (Phase 19): view-only users see *Now* items only; Log
      users see checks on their own entries; hidden rows are per user.
- [ ] No text-only-by-colour: every item carries its label as text.
- [ ] Query count for the dashboard with ten vehicles is bounded (a test
      that fails if queries grow with the number of readings or fill-ups).
- [ ] Integration suite green on every engine.

### Sample data
- [ ] `DemoDataSeeder` already has a mistyped odometer and a flagged tank.
      Add an overdue service on the motorbike and an 18-month-old valuation
      on one car, so the demo list shows *Now* and *Check* items.

### Release
- [ ] `CHANGELOG.md` **2.4.0**: Needs attention. Upgrade notes: one
      migration; the widget is appended to existing dashboards and can be
      moved.
- [ ] Bump `VERSION`, rebuild assets, update the README status.

---

## Acceptance criteria

1. A vehicle with an expired MOT, a backwards reading and two unusual
   fill-ups shows three items on its overview, the MOT first, each linking
   to its fix.
2. Fixing each one removes it without any other action.
3. A reading that really is right can be hidden, and comes back only if
   that reading or its neighbours change.
4. A vehicle with nothing wrong shows no card at all.
5. There is no score, percentage or rating anywhere.
6. Definition of done (CLAUDE.md §11) holds.

## Open questions

- **Due items as well as overdue?** This draft keeps due-soon work in
  *Coming up* and reminders. Should items within their lead time also
  appear, under *Now*?
- **The monthly digest:** add a short "Needs attention" section listing
  *Check* items, so data problems reach owners who rarely open the app?
- **Thresholds:** 60 days for stale mileage and 12 months for valuations as
  constants (drafted), or user settings?
