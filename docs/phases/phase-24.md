# Phase 24 — Needs attention + v2.4 release

*What is wrong right now, on one short list, with the fix one tap away.*

Status: ✅ complete · released as **v2.4.0** · file lives in `docs/phases/`

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
6. A *Needs attention* section in the monthly digest, listing the *Check*
   items, so data problems reach owners who rarely open the app (decided
   2026-10-01).
7. The two staleness thresholds as user settings (decided 2026-10-01).

## Not in scope

- A score, grade, percentage or traffic-light rating of a vehicle.
- New notifications of their own. Reminders already notify about due work.
  Data checks reach people only through the monthly digest's section
  (decided 2026-10-01), never as a message of their own.
- Things that are merely *due soon*. That is *Coming up* and the reminder
  list. This list is for what is wrong now.
- Automatic fixes.

---

## Spec addition (§7.24 Needs attention)

The text that went into `spec.md` §6, §7.1, §7.8, §7.11 and §7.24 is the
authoritative one; this section is the draft it came from.

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
   **60 days** old (the owner's setting, default 60; decided 2026-10-01): "No mileage logged since 2 May 2026. Distance-based
   services can't be projected." The action is *Add reading*.
5. **Trips exceed mileage** (Phase 22, `trips` on): the split's notice for
   the current tax year, linking to the Trips tab.
6. **Stale valuation:** the vehicle has valuations, is not sold, and the
   latest is over 12 months old (the owner's setting, default 12, which
   §7.1's hint then follows too; decided 2026-10-01), linking to *Add
   valuation*.

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
- **Monthly digest** (§7.11, decided 2026-10-01): after the due
  reminders, a *Needs attention* section listing the recipient's *Check*
  items on the vehicles they receive reminders for, as they would see them
  (their access, their hidden items). A month with checks but nothing due
  still sends a digest. *Now* items are not repeated there: they are the
  digest's due reminders already.
- Not in History, print, the sale pack, other notifications or the API in
  this phase.

**Access** (Phase 19): users see the items of vehicles they can view.
*Check* items and *Hide* appear only to users who could fix them
(`Manage`, or `Log` for an item about their own entry). Hidden items are
per user.

**Cost:** the overview computes one vehicle's items. The dashboard
computes every visible vehicle's in one pass (*Coming up* loads them
together), shared by the widget and the fleet tiles. Each source is loaded
once per vehicle; no item runs a query per row.

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
- **The stale-mileage threshold** (default 60 days) applies only where
  projections need readings. A garaged classic with no distance-based
  schedule is never nagged.
- **Thresholds are the owner's settings** (decided 2026-10-01): *Mileage
  not updated after* (days, 7–365, default 60) and *Valuation is stale
  after* (months, 1–60, default 12), on Settings → Reminders in a *Needs
  attention* card that shows with `reminders` off too. Stored as their own
  user setting (`attention.thresholds`). As with lead times (Phase 19), a
  shared vehicle is judged by its owner's thresholds and today, whoever
  looks. The §7.1 stale-value hint reads the same setting, so the
  Ownership card and the list never disagree. The economy bands and the
  2,000 km a day rule stay fixed (log #17).
- **Data checks go in the digest** (decided 2026-10-01), not in a
  notification of their own. The digest is already monthly and already
  opt-in, so this adds no new noise. Its webhook payload gains an
  `attention` list beside `items`, which keeps its shape.
- **Due-soon work stays out** (decided 2026-10-01): the list is what is
  wrong now. *Coming up* and the reminders cover what is due soon.
- **Hiding uses fingerprints.** A hidden check reappears if the data it
  judged changes, so hiding never buries a new problem.

---

## Tasks

### Spec and docs
- [x] §7.24, §6, §7.8 (widget list) and §7.1 (garage card marker) in
      `spec.md`; the Phase 24 line in §13; remove the *Needs attention* line
      from the roadmap's *After 1.0* list (there was none left to remove).
- [x] README status paragraph.

### Migration
- [x] `attention_hidden`. Every engine, reversible; moves the schema
      version; backups include it.

### Settings
- [x] `AttentionThresholds` and its store; the *Needs attention* card on
      Settings → Reminders (with and without the `reminders` module);
      `Depreciation`'s stale hint follows the owner's setting.

### Digest
- [x] `ReminderNotifier::sendDigest` adds the recipient's *Check* items;
      a month with checks and nothing due still sends; the composer's
      section (en, de); the webhook's `attention` list; the digest hint
      on Settings → Reminders.

### Services
- [x] `Domain\Attention\AttentionItem` (kind, severity `now` | `check`,
      vehicle, title parameters, actions, fingerprint) and `AttentionKind`
      enum.
- [x] `Service\Attention\AttentionList`: gathers from `ComingUp` (the
      overdue group), odometer plausibility, the economy check,
      `TyreJudgement` (through *Coming up*), the valuation staleness rule,
      `MileageSplit` (Phase 22) and the new stale-mileage check. Applies
      dismissals, hidden rows, access and module toggles, and sorts.
- [x] `Service\Attention\StaleMileage`: the 60-day rule, only for vehicles
      with a distance-based schedule or a wear-estimated fitted tyre.
- [x] Fingerprint builders per kind; `AttentionHiddenRepository`.

### Actions, templates
- [x] Overview card partial; the widget (registered in the widget list,
      defaults to first in new layouts); garage card and fleet tile marker.
- [x] `Action\Attention\Hide` (POST, CSRF, access check, returns to where it
      came from; works in and out of modals).
- [x] Translations (en, de) for every title, with ICU plurals and dates.

### Tests
- [x] Each kind appears exactly when its source says so, and disappears
      when fixed: log the overdue service; edit the backwards reading;
      *Looks right* on the flagged fill-ups; add a reading; add a
      valuation.
- [x] With reminders on, a dismissed reminder's item is gone; with
      reminders off, overdue items still show.
- [x] Hiding: hidden while the fingerprint matches; back after an edit to
      the reading or its neighbours, a new reading, or a new valuation;
      per user.
- [x] Stale mileage: not raised without distance-based schedules or wear
      estimates; raised at 61 days and not at 59, counted in the owner's
      time zone; follows the owner's setting (and so does the valuation
      check, and the overview's stale-value hint).
- [x] Digest: checks listed after the due reminders; sent with checks and
      nothing due; nothing sent with neither; a View recipient gets no
      checks; hidden items left out; the webhook's `attention` list.
- [x] Order: *Now* before *Check*, oldest overdue first; the overview shows
      five and *Show all*; the widget follows the chip and shows "Nothing
      needs attention" when empty.
- [x] Module toggles remove their items; archived vehicles never appear.
- [x] Access matrix (Phase 19): view-only users see *Now* items only; Log
      users see checks on their own entries; hidden rows are per user.
- [x] No text-only-by-colour: every item carries its label as text.
- [x] Query count for the dashboard with ten vehicles is bounded (a test
      that fails if queries grow with the number of readings or fill-ups).
- [x] Integration suite green on every engine.

### Sample data
- [x] `DemoDataSeeder` already has a mistyped odometer and a flagged tank.
      Add an overdue service on the motorbike and an 18-month-old valuation
      on one car, so the demo list shows *Now* and *Check* items.

### Release
- [x] `CHANGELOG.md` **2.4.0**: Needs attention. Upgrade notes: one
      migration; the widget is appended to existing dashboards and can be
      moved.
- [x] Bump `VERSION`, rebuild assets, update the README status.
- [x] Tag `v2.4.0` once merged.

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
  *Decided 2026-10-01: no, overdue only (drafted).*
- **The monthly digest:** add a short "Needs attention" section listing
  *Check* items, so data problems reach owners who rarely open the app?
  *Decided 2026-10-01: yes, in this phase. A month with checks and nothing
  due still sends a digest. See* Decisions.
- **Thresholds:** 60 days for stale mileage and 12 months for valuations as
  constants (drafted), or user settings?
  *Decided 2026-10-01: user settings, the vehicle owner's, defaulting to
  60 days and 12 months. See* Decisions.

## Changed while building it

- **`AttentionItem` is in `Service\Attention`**, not `Domain`: it carries
  the *Coming up* item and the odometer warning it was built from, which
  are service types. The two enums (`AttentionKind`, `AttentionSeverity`)
  are in `Domain\Attention`. Wording lives in one place,
  `AttentionWording`, shared by the card, the widget and the digest.
- **Dismissals need a current reminder.** With `reminders` on, the list
  brings the reminders up to date first (§7.6 *Sync*), so a dismissal from
  an earlier occurrence can never hide a new overdue item (tested: dismiss,
  log a service that is still overdue, and it comes back). The dashboard
  passes on the sync it already did and its *Coming up*, so nothing is
  worked out twice. There is no per-request memoisation in *Coming up* or
  the fuel services, as the draft assumed; the dashboard makes one pass
  over the filter for the widget and the tiles instead, and the spec says
  so.
- ***Log it* targets:** a service record prefilled for the schedule, a
  renewal of the document's type, a new MOT certificate for the first MOT,
  and *Fit tyres*. A manual reminder has no entry form, so its action is
  *Done* (and *Dismiss*). *Done* and *Dismiss* now return to the page they
  were pressed on (`return`), not always to the reminder list.
- **Trips exceeding mileage links to the Mileage tab**, where the notice
  and the readings are, rather than the Trips tab.
- **Access, worked out:** a derived reading's author is its entry's
  (`created_by` is set only on manual readings), so a Log user's own
  readings come from one query joining the four owning tables
  (`OdometerReadingRepository::authorsForVehicle`), run only for Log users
  with flagged readings. A Log user's economy item counts only their own
  fill-ups. Stale mileage needs `Log`, a stale valuation `Manage`
  (*Add valuation* does).
- **No reading at all** on a vehicle that needs readings raises stale
  mileage too, as "No mileage logged yet".
- **The stale-valuation item needs no purchase price.** The overview's
  stale-value hint is part of the depreciation figures, which do; both use
  the owner's threshold (`Depreciation::staleMonths()`).
- **Hiding checks the fingerprint the page showed** and stores the one
  computed on the server. If the item changed in between, nothing is
  hidden and the page says so.
- **Thresholds left blank** on the form save the defaults; out of range
  is refused (7–365 days, 1–60 months).
- **The garage card's badges** sit together in `.vehicle-card__flags`
  ("N due" and the marker); the old absolute `.vehicle-card__due` rule is
  gone.
- **A decimal overflow fixed:** a vehicle whose fill-ups all cost 0 made
  *Coming up*, and so the overview and dashboard, fail (a fuel cost of 0
  per km multiplied at 21 decimal places divided by 10^19, beyond a PHP
  int). `Decimal::rescale()` now gives zero for zero and the float
  fallback otherwise (`DecimalArithmeticTest`). Found by this phase's
  tests, whose fill-ups cost 0.
- **The demo's mistyped odometer** (300 km too high) is flagged by the
  economy check, not the plausibility rule, so the demo's *Check* items
  are the unusual fill-ups and the Corolla's valuation (28 Mar 2025, 18
  months old); its *Now* items include the motorbike's annual service.
- **Tests:** `NeedsAttentionTest` (the acceptance criteria, each kind, the
  thresholds, hiding, dismissals, toggles, archived vehicles, the access
  matrix, the widget and the marker), `AttentionDigestTest`,
  `AttentionQueryCountTest` (ten vehicles, four times the readings and
  fill-ups, the same queries, with a DBAL counting middleware in
  `tests/Support/QueryCounter.php`) and unit tests for the rule, the
  fingerprints and the settings.
- **Checked in Chrome** on the demo data: the dashboard widget, the
  overview card and the garage markers, at desktop width and at 390 px.
  The suite passes on SQLite, PostgreSQL, MySQL and MariaDB
  (`bin/test-all-dbs.sh`, with migrate, full rollback and migrate first).
