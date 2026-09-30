# Phase 21.2 — First MOT due + v2.1 release

*A new car's first MOT is the one reminder nobody has paperwork for yet.*

Status: 📋 planned · releases **v2.1.0** with Phase 21.1

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

> - first_inspection_due_on (optional calendar date, Phase 21.2; never
>   converted through a time zone): when the vehicle's first MOT, or the
>   local equivalent, is due. It is used only while the vehicle has no
>   `inspection` document. Upgrading to 2.1.0 adds the column empty (see
>   *Existing vehicles*); rolling it back drops it and the reminders it
>   raised.

### §7.1 Garage: the vehicle form

> - **First MOT due** (label from the `inspection` document type: "First MOT
>   due" in English, "Erste HU fällig" in German), under *First registered*,
>   optional, a date.
>   - **Suggestion:** from a rule table on `Support\InspectionRules`, keyed
>     by the owner's locale region: `GB` → 36 months after first
>     registration, `DE` → 36 months. The table holds nothing else. Months
>     are added with end-of-month clamping, as maintenance intervals are, so
>     29 Feb 2024 gives 28 Feb 2027.
>   - With JS, entering or changing *First registered* fills *First MOT due*
>     while the owner hasn't typed in it. Once they edit it, it is theirs.
>   - Without JS, on **add** only: when the field is blank, *First
>     registered* is set, a rule exists and the suggested date is today or
>     later, the saved vehicle gets the suggestion. The flash says so ("First
>     MOT reminder set for 14 Jun 2027. Change it on the vehicle's edit
>     page."). On **edit**, a blank field stays blank, so clearing it
>     sticks.
>   - A suggestion in the past is never filled in: that vehicle has had its
>     first MOT.
>   - **Hint**, GB: "Usually 3 years after first registration in England,
>     Scotland and Wales; 4 years in Northern Ireland." DE: "Usually 3 years
>     after first registration." Others: "Check when the first inspection is
>     due where the vehicle is registered."
>   - Once the vehicle has an `inspection` document, the field shows as
>     read-only text ("Done: the MOT certificate from 12 Jun 2027 now sets
>     the next one") and is not submitted.
>   - Validation: not before *First registered* when both are set (message:
>     "The first MOT can't be due before the vehicle was first
>     registered").

### §7.6 Reminders

> - **First MOT** (Phase 21.2): source `first_inspection`, `source_id` = the
>   **vehicle's own id** (one per vehicle, like `tyre`). It is raised while
>   `first_inspection_due_on` is set, the vehicle is active and has no
>   `inspection` document, and the `compliance` module is on. Its due date is
>   that date, and its lead time is the owner's document lead time. It is
>   titled "First MOT" (the type's label).
> - It is **done** automatically when an `inspection` document is saved for
>   the vehicle. It is removed when the date is cleared, and it moves when
>   the date changes. After that, the certificate's own expiry reminder
>   takes over (§7.5).
> - Dismiss works as for any reminder. Notifications, digest and calendar
>   feed treat it like the others (and in Phase 19, go to the owner and to
>   shares with `notify`).

### §7.18 Coming up, §7.2 Overview, §7.19 Sale pack, §7.20 API

> - *Coming up*: a **First MOT** item on the date, with no repeats and no
>   "last time" cost.
> - Overview, current documents: "First MOT due 14 Jun 2027", with the due
>   badge rules of documents.
> - The sale pack's *Inspection* line: "First MOT due 14 Jun 2027", when
>   there is no certificate yet. *Due next* includes it through *Coming up*.
> - API: `first_inspection_due_on` on the vehicle; the summary's next due
>   item can be it.

---

## Existing vehicles

The column arrives empty. Two options for vehicles already in the garage
(see *Open questions*):

- **A (drafted): a one-time prompt.** Each vehicle that has *First
  registered* set, no `inspection` document, and a suggestion today or
  later shows a dismissible card on its overview: "Set a reminder for the
  first MOT? Suggested: 14 Jun 2027", with *Set it* (a POST with CSRF) and
  *Not needed*. Nothing is set without the owner.
- **B: backfill.** The migration sets the suggestion for those vehicles,
  and the upgrade notes say so.

---

## Tasks

### Spec and docs
- [ ] §6, §7.1, §7.6, §7.18, §7.2, §7.19 and §7.20 in `spec.md`; the Phase
      21.2 line in §13.
- [ ] `docs/configuration.md` needs nothing (no new variable). Mention the
      field in the README status paragraph.

### Migration
- [ ] `vehicles.first_inspection_due_on` (date, nullable). Applies and rolls
      back on every engine. The rollback deletes reminders with source
      `first_inspection`, then drops the column. Moves the schema version.
- [ ] Option A: a setting (user scope) that records dismissed prompts per
      vehicle. Option B: the backfill in the migration.

### Code
- [ ] `Support\InspectionRules`: region → months, and the hint key.
      Constants only, with a test per row.
- [ ] Vehicle form parser and validation; the add-only no-JS fill;
      read-only state once a certificate exists.
- [ ] `assets/js/first-inspection.js`: fills the date from *First
      registered* until the owner types in it.
- [ ] Reminder sync: the `first_inspection` source, raised, moved, removed
      and done as above. The unique key `(vehicle_id, source, source_id)`
      already fits.
- [ ] Compliance document save: mark the vehicle's `first_inspection`
      reminder done when an `inspection` document is saved.
- [ ] `ComingUp` item, overview line, sale pack line, API serializer field.
- [ ] The prompt card and its Action (option A).
- [ ] Translations (en, de).

### Tests
- [ ] Suggestion: GB and DE give 36 months; 29 Feb clamps; other regions
      give none; a past suggestion is never filled.
- [ ] No JS: add with a blank field fills it and flashes; edit with a blank
      field stays blank; an explicit date is kept.
- [ ] Validation: before *First registered* is refused.
- [ ] Reminder: raised; lead time applied; status upcoming → due → overdue;
      moved when the date changes; removed when cleared; done when an
      `inspection` document is saved; not raised when one already exists,
      when archived, or with `compliance` off.
- [ ] After the first certificate, only the certificate's reminder exists,
      never two.
- [ ] *Coming up*, overview, sale pack and API show it; the digest and
      calendar feed include it.
- [ ] Existing vehicles: option A's prompt appears only when it should and
      never returns after *Not needed*; or option B's backfill sets only
      future dates and only for GB and DE owners.
- [ ] Rollback removes the reminders and the column on every engine.
- [ ] Integration suite green on every engine.

### Sample data
- [ ] `DemoDataSeeder`: the electric car (leased, so young) gets a first
      registration two and a half years ago and its first MOT due date, so
      the demo shows the reminder and the *Coming up* item.

### Release (with Phase 21.1)
- [ ] `CHANGELOG.md` **2.1.0**: tyre modals, drag-and-drop files, the digest
      default for new users, the sale pack cover page, *First MOT due*, and
      the phase files moved to `docs/phases/` (Phase 20). Upgrade notes: one
      migration; existing users keep their digest choice; existing vehicles
      (per the decision above).
- [ ] Bump `VERSION`, rebuild assets, update the README status paragraph.

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
- **Northern Ireland:** GB-locale owners get the 3-year suggestion with an
  NI hint. Is that enough, or should the form offer a GB / NI choice when
  the locale is `en_GB`?
- **Other regions:** add more rules now (France, Ireland, Italy and Spain
  at 4 years), or keep GB and DE only until someone asks?
