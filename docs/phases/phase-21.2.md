# Phase 21.2 — First MOT due + v2.1 release

*A new car's first MOT is the one reminder nobody has paperwork for yet.*

Status: ✅ complete · released as **v2.1.0** with Phases 20 and 21.1

Today, MOT reminders come from an `inspection` document's expiry (§7.5). A
car under three years old has no MOT certificate yet, so nothing reminds
the owner of its first test, even though its first registration date
(Phase 9.1) says exactly when that is. This phase adds an optional *First
MOT due* date to the vehicle. The form suggests it from the first
registration date, and it drives a reminder until the first MOT
certificate is logged. From then on, the certificate's expiry takes over,
as now.

Read [`CLAUDE.md`](../../CLAUDE.md) and [`spec.md`](../../spec.md) §6, §7.1,
§7.5, §7.6 and §7.18 first. Check `docs/phases/open-questions.md`.

---

## Why a stored date, not a rule

The first test's timing depends on where the vehicle is registered, and it
changes:

- **Great Britain:** 3 years after first registration, for cars and
  motorbikes.
- **Northern Ireland:** 4 years for cars and motorbikes (3 for light goods
  vehicles).
- **Germany:** the first HU at 3 years. France, Ireland, Italy and Spain
  test first at 4 years.
- GB has consulted more than once on moving to 4 years.

An owner's locale (`en_GB`) cannot tell Northern Ireland from the rest of
the UK. A hard-coded rule would be wrong for some owners, and wrong for
everyone the day the law changes. So the app **suggests** a date from a
small table of rules and **stores** what the owner accepts. The owner can
always change it.

## Goals

1. An optional *First MOT due* date on the vehicle (add and edit forms),
   suggested from the first registration date.
2. A reminder, a *Coming up* item and an overview line from that date,
   until an MOT certificate (an `inspection` document) is logged.
3. Handing over cleanly to the certificate's expiry after the first test.

## Not in scope

- Looking up MOT due dates from DVSA or any other service.
- Rules for vans, taxis or commercial vehicles (the app's types are `car`
  and `bike`).
- Subsequent MOTs. They already come from each certificate's expiry.
- Historic-vehicle exemptions.

---

## Spec addition

### §6 Vehicle

- first_inspection_due_on (optional calendar date, Phase 21.2; never
  converted through a time zone): when the vehicle's first MOT, or the
  local equivalent, is due. It is used only while the vehicle has no
  `inspection` document. Upgrading to 2.1.0 adds the column empty (see
  *Existing vehicles*); rolling it back drops it and the reminders it
  raised.

### §7.1 Garage: the vehicle form

- **First MOT due** (label from the `inspection` document type: "First MOT
  due" in English, "Erste HU fällig" in German), under *First registered*,
  optional, a date.
  - **Suggestion:** from a rule table on `Support\InspectionRules`, keyed
    by the owner's locale region: `GB` → 36 months after first
    registration, `DE` → 36 months, `FR`, `IE`, `IT` and `ES` → 48 months
    (decided 2026-09-30). The table holds nothing else, and a locale with
    no region (`en`, `de`) gets no suggestion. Months
    are added with end-of-month clamping, as maintenance intervals are, so
    29 Feb 2024 gives 28 Feb 2027.
  - With JS, entering or changing *First registered* fills *First MOT due*
    while the owner hasn't typed in it. Once they edit it, it is theirs.
  - Without JS, on **add** only: when the field is blank, *First
    registered* is set, a rule exists and the suggested date is today or
    later, the saved vehicle gets the suggestion. The flash says so ("First
    MOT reminder set for 14 Jun 2027. Change it on the vehicle's edit
    page."). On **edit**, a blank field stays blank, so clearing it
    sticks.
  - A suggestion in the past is never filled in: that vehicle has had its
    first MOT.
  - **Hint**, GB: "Usually 3 years after first registration in England,
    Scotland and Wales; 4 years in Northern Ireland." DE: "Usually 3 years
    after first registration." FR, IE, IT, ES: "Usually 4 years after
    first registration." Others, and a locale with no region: "Check when
    the first inspection is due where the vehicle is registered. Choose a
    language with a country in Settings for a suggestion."
  - Once the vehicle has an `inspection` document, the field shows as
    read-only text ("Done: the MOT certificate from 12 Jun 2027 now sets
    the next one") and is not submitted.
  - Validation: not before *First registered* when both are set (message:
    "The first MOT can't be due before the vehicle was first
    registered").

### §7.6 Reminders

- **First MOT** (Phase 21.2): source `first_inspection`, `source_id` = the
  **vehicle's own id** (one per vehicle, like `tyre`). It is raised while
  `first_inspection_due_on` is set, the vehicle is active and has no
  `inspection` document, and the `compliance` module is on. Its due date is
  that date, and its lead time is the owner's document lead time. It is
  titled "First MOT" (the type's label).
- It is **done** automatically when an `inspection` document is saved for
  the vehicle. It is removed when the date is cleared, and it moves when
  the date changes. After that, the certificate's own expiry reminder
  takes over (§7.5).
- Dismiss works as for any reminder. Notifications, digest and calendar
  feed treat it like the others (and in Phase 19, go to the owner and to
  shares with `notify`).

### §7.18 Coming up, §7.2 Overview, §7.19 Sale pack, §7.20 API

- *Coming up*: a **First MOT** item on the date, with no repeats and no
  "last time" cost.
- Overview, current documents: "First MOT due 14 Jun 2027", with the due
  badge rules of documents.
- The sale pack's *Inspection* line: "First MOT due 14 Jun 2027", when
  there is no certificate yet. *Due next* includes it through *Coming up*.
- API: `first_inspection_due_on` on the vehicle; the summary's next due
  item can be it.

---

## Existing vehicles

The column arrives empty. Vehicles already in the garage get option A
(decided 2026-09-30):

- **A (chosen): a one-time prompt.** Each vehicle that has *First
  registered* set, no `inspection` document, and a suggestion today or
  later shows a dismissible card on its overview: "Set a reminder for the
  first MOT? Suggested: 14 Jun 2027", with *Set it* (a POST with CSRF) and
  *Not needed*. Nothing is set without the owner.
- **B (not chosen): backfill.** The migration would have set the
  suggestion for those vehicles.

---

## Tasks

### Spec and docs
- [x] §6, §7.1, §7.6, §7.18, §7.2, §7.19 and §7.20 in `spec.md`; the Phase
      21.2 line in §13.
- [x] `docs/configuration.md` needs nothing (no new variable). Mention the
      field in the README status paragraph.

### Migration
- [x] `vehicles.first_inspection_due_on` (date, nullable). Applies and rolls
      back on every engine. The rollback deletes reminders with source
      `first_inspection`, then drops the column. Moves the schema version.
- [x] ~~A setting (user scope) that records dismissed prompts per vehicle
      (option A).~~ Not a migration: a row in `settings`; see *Changed while
      building it*.

### Code
- [x] `Support\InspectionRules`: region → months, and the hint key.
      Constants only, with a test per row.
- [x] Vehicle form parser and validation; the add-only no-JS fill;
      read-only state once a certificate exists.
- [x] `assets/js/first-inspection.js`: fills the date from *First
      registered* until the owner types in it.
- [x] Reminder sync: the `first_inspection` source, raised, moved, removed
      and done as above. The unique key `(vehicle_id, source, source_id)`
      already fits.
- [x] Compliance document save: mark the vehicle's `first_inspection`
      reminder done when an `inspection` document is saved. Built in
      reminder sync rather than the save; see *Changed while building it*.
- [x] `ComingUp` item, overview line, sale pack line, API serializer field.
- [x] The prompt card and its Action (option A).
- [x] Translations (en, de).

### Tests
- [x] Suggestion: GB and DE give 36 months, FR, IE, IT and ES 48; 29 Feb
      clamps; a locale with no region gives none; other regions
      give none; a past suggestion is never filled.
- [x] No JS: add with a blank field fills it and flashes; edit with a blank
      field stays blank; an explicit date is kept.
- [x] Validation: before *First registered* is refused.
- [x] Reminder: raised; lead time applied; status upcoming → due → overdue;
      moved when the date changes; removed when cleared; done when an
      `inspection` document is saved; not raised when one already exists,
      when archived, or with `compliance` off.
- [x] After the first certificate, only the certificate's reminder exists,
      never two.
- [x] *Coming up*, overview, sale pack and API show it; the digest and
      calendar feed include it.
- [x] Existing vehicles: option A's prompt appears only when it should and
      never returns after *Not needed*.
- [x] Rollback removes the reminders and the column on every engine.
- [x] Integration suite green on every engine.

### Sample data
- [x] `DemoDataSeeder`: the electric car (leased, so young) gets a first
      registration two and a half years ago and its first MOT due date, so
      the demo shows the reminder and the *Coming up* item.

### Release (with Phase 21.1)
- [x] `CHANGELOG.md` **2.1.0**: tyre modals, drag-and-drop files, the digest
      default for new users, the sale pack cover page, *First MOT due*, and
      the phase files moved to `docs/phases/` (Phase 20). Upgrade notes: one
      migration; existing users keep their digest choice; existing vehicles
      (per the decision above).
- [x] Bump `VERSION`, rebuild assets, update the README status paragraph.

---

## Acceptance criteria

1. Adding a two-year-old car in a GB locale suggests a first MOT on its
   third registration anniversary, with or without JS. The owner can change
   or clear it.
2. The reminder comes due at the document lead time, is notified like any
   other, and shows in *Coming up*, the overview and the sale pack.
3. Logging the first MOT certificate closes it, and from then on only the
   certificate's expiry drives MOT reminders.
4. A vehicle whose first MOT is already past never gets a suggestion.
5. Definition of done (CLAUDE.md §11) holds, including the migration on
   every engine.

## Open questions

- **Existing vehicles:** a one-time prompt per vehicle (option A, drafted)
  or a backfill (option B)?
  *Decided 2026-09-30: option A, the one-time prompt.*
- **Northern Ireland:** GB-locale owners get the 3-year suggestion with an
  NI hint. Is that enough, or should the form offer a GB / NI choice when
  the locale is `en_GB`?
  *Decided 2026-09-30: the hint is enough; the date can always be changed.*
- **Other regions:** add more rules now (France, Ireland, Italy and Spain
  at 4 years), or keep GB and DE only until someone asks?
  *Decided 2026-09-30: add them: `FR`, `IE`, `IT` and `ES` → 48 months. A locale
  with no region (`en`, `de`) gets no suggestion, and the hint says so.*

## Changed while building it

- **"Done" lives in reminder sync, not in the document save.** Sync deletes
  any generated reminder its source no longer calls for, so a reminder
  marked done by the save would have been deleted on the next page view.
  Instead, when a vehicle has an `inspection` document, sync marks its
  `first_inspection` reminder done, keeps it, and never raises a new one.
  That also covers certificates that arrive through CSV import, the API
  or a restore, which a save hook would miss. One helper,
  `Service\Compliance\FirstInspection`, decides "has an inspection
  document" (any, replaced and expired included) for sync, *Coming up*,
  the overview, the Documents tab, the sale pack, the form and the prompt.
- **The prompt setting needs no migration.** It is a user-scoped row of
  the vehicle's owner in `settings` (`vehicles.first_inspection_prompted`,
  a list of vehicle ids), so *Not needed* from a manager settles it for
  the owner too. The migration's rollback deletes it.
- **Saving the vehicle form settles the prompt.** Otherwise a date cleared
  on the edit form (or cleared on the add form with JS on) would bring the
  card straight back. So only vehicles from before 2.1.0 that nobody has
  saved since get the card.
- **The script leaves a marker.** The no-JS fallback fills a blank field
  on add, so a date the owner cleared with JS on would have been filled in
  again. `js/first-inspection.js` adds `first_inspection_js=1`, and the
  server fills only without it.
- **Off the form, the stored date is kept.** With `compliance` off the
  field is hidden. After the first certificate it is read-only text. In
  both cases an edit keeps the stored date whatever is posted.
- **The suggestion follows the vehicle owner's locale.** A manager's own
  locale is not used. It matters only for shared vehicles.
- **The Documents tab lists it too**, with the overview's row. The
  reminder and the *Coming up* item link to the vehicle's overview, which
  a View or Log share can open. The row links to the edit form for those
  who can manage the vehicle.
- **Validation also refuses a date before 1885**, as *First registered*
  does. A past date the owner types is accepted: it is theirs, and it
  shows as overdue.
- **Two older migration tests no longer assume they are the newest.**
  `PlugInHybridMigrationTest` reads the fuel type with plain SQL after its
  rollback, since `VehicleRepository` now selects the new column.
  `UsersAndSharingMigrationTest` rolls back to its own target.
- **The demo Kia** is first registered on 9 Feb 2024, the day before its
  lease started, with its first MOT due on 9 Feb 2027.
- **Known edge:** if the only certificate is deleted, the first MOT
  reminder stays *done*, because its occurrence (the date) hasn't changed.
  *Reopen* brings it back.
