# Phase 12 — Buyer-first print, ownership paperwork, dated starting mileage + v1.4.0

**Goal:** answer the open questions from Phases 9.1 and 10 that need no new
concepts. The print view defaults to the copy a buyer should see (no costs).
The purchase invoice and sale receipt get somewhere to live, on the *Bought*
and *Sold* rows. A vehicle added months after it was bought can have its
starting mileage dated when it was read. The overview drops a list the
History card now covers. Then cut **v1.4.0**.

Read `spec.md` (§6 Vehicle, OdometerReading and Attachment, §7.1, §7.2,
§7.3, §7.12, §7.16) and `CLAUDE.md` (§6, §8, §11, §12) before starting.

**Prerequisites:** [Phase 11.2](phase-11.2.md) complete and green; v1.3.0
tagged.

---

## Scope

**In:**
- *Show costs* off by default on the print view.
- Attachments on a vehicle's purchase and its sale, shown on the *Bought*
  and *Sold* milestones, the overview and the print view.
- An optional *As of* date for the add form's *Current odometer*, and the
  lifetime average measured to the date of the reading it uses.
- The overview's latest fill-ups list removed.
- Spec, translations, tests and the v1.4.0 release.

**Out:**
- A region preference, the first-MOT rule and tyre threshold presets
  (the next phase: they share the new region concept).
- Registration lookup and DVSA MOT history import (§12).
- An *As of* date on the edit form (a reading's date is edited on the
  Mileage tab, as today).
- A gallery of vehicle photos, or attachments on the vehicle itself. The
  paperwork belongs to the purchase and the sale, not the car.
- Separate *Print for a buyer* / *Print with costs* buttons. One tick stays.
- Tyre follow-ups (moving sets between vehicles, rims, tyre CSV import).

---

## Design decisions (record in `spec.md` before building)

### Print view: costs off by default
- **The default is the copy you can hand over.** The print view exists "to
  hand to a buyer or a garage". Printing your own copy without costs costs
  a second print; handing a buyer the price you paid costs you in the
  negotiation. So the safe copy is the default.
- *Show costs* is an unticked checkbox; `costs=1` shows them, anything else
  (absent included) hides them. Hidden costs still hide the purchase and
  sale prices on the milestones, as today.
- Hint under the tick: "Leave off for a copy you give to a buyer. Purchase
  and sale prices are hidden too."
- **Old links fail safe.** Check how 1.3.0 encodes the option. Whatever it
  is, a link that hid costs must still hide them, and a link that showed
  them by omission now hides them. Never the other way round.

### Purchase and sale paperwork
- **Two owner types, not a vehicle gallery.** `attachments.owner_type`
  gains `purchase` and `sale`, with `owner_id` = the vehicle's id (and
  `vehicle_id` the same, which already scopes every lookup). The purchase
  and the sale are events in the vehicle's life, so their paperwork belongs
  to them, in keeping with §7.12's rule that paperwork belongs to the entry
  it proves. The vehicle keeps its single photo.
- **On the vehicle form's purchase and sale fields**, add and edit, each
  with the shared attachment input and the list of files already attached
  (with delete links, as on every other form). Two inputs on one form:
  the shared parser takes the field name as a parameter
  (`purchase_attachments[]`, `sale_attachments[]`; `attachments[]` stays
  the default), so there is still one upload path.
- **One limit per save, across both inputs.** PHP's `max_file_uploads`
  counts every file in the request, so the 10-file limit (or the lower PHP
  value) applies to purchase and sale files together. The hint says so.
  All or nothing, as everywhere: one rejected file fails the save, nothing
  is written, typed values are kept.
- **Files need their date.** Paperwork only shows on a milestone, and a
  milestone exists only when its date is set. So:
  - sale files without a sale date are refused: "Add the sale date to
    attach the sale paperwork" (likewise for the purchase);
  - clearing a date while files are attached is refused: "Remove the sale
    paperwork first, or keep the sale date." The same shape as the tyre
    type-change refusal (§7.1). No file is ever left with nowhere to show.
- **Shown** as a paperclip with the count on the *Bought* and *Sold*
  milestone rows (History tab, fleet history), beside the purchase and sale
  dates on the overview's *Ownership* card, and as file names under the
  milestones in the print view. Milestone counts join the page's one grouped
  paperclip query; never one query per row.
- **Core, not a module.** The vehicle is core, so this has no toggle.
  Deleting the vehicle deletes the files (the existing cascade and file
  clean-up); archiving keeps them.

### Dated starting mileage
- **An optional *As of* beside *Current odometer*** on the add form: a
  native date input defaulting to today in the owner's time zone. Hint:
  "When the figure was read, for example on the MOT certificate or at the
  sale." Ignored when *Current odometer* is blank.
- **Stored as the other date-only readings are.** Today → the moment of
  saving (today's behaviour, unchanged). An earlier date → local noon on
  that date, like service records, documents and tyre changes (§7.2). Still
  an ordinary `manual` reading written in the vehicle's transaction.
- **Validation:** block a date after today (owner's time zone) or before
  1 January 1885. **Warn, don't block** a date before `first_registered_on`
  (delivery mileage before registration exists), with the usual notice
  after saving.
- **Lifetime average to the reading's date.** Today, *average per year
  since first registered* is the current reading ÷ age to *today*. With a
  starting reading dated months back (or any vehicle not driven for a
  while), that understates. It becomes the current reading ÷ age at that
  reading's `recorded_at` (local date), and the 90-day floor applies to
  that age too. *Age* itself stays measured to today. This changes the
  figure for vehicles whose latest reading is not recent; the changelog
  says so.

### Overview
- **Remove the latest fill-ups list.** *Recent history* lists fill-ups
  alongside everything else, and the Fuel tab lists them all. Check first
  what else the card shows: any figure that appears nowhere else on the
  overview (economy, cost per distance) stays, in whichever existing card
  fits, not in a new one.

---

## Tasks

### 12.0 Spec first
Per `CLAUDE.md` §12, update `spec.md` before building:
- [x] §6 Attachment: owner types `purchase` and `sale` (owner_id = the
      vehicle); the date rule.
- [x] §6 OdometerReading / §7.1: the add form's *As of* and how it is
      stored.
- [x] §7.2: *average per year since first registered* measured to the
      reading's date.
- [x] §7.3: drop "the vehicle overview's latest fill-ups" from the badge
      list.
- [x] §7.12: purchase and sale take files; reword "a vehicle keeps a single
      photo" so it is clear the paperwork belongs to the events, not the
      vehicle.
- [x] §7.16: print *Show costs* default off, with the hint; paperclips on
      *Bought* and *Sold*; file names under milestones in print.
- [x] §13: a Phase 12 entry.
- [x] `ROADMAP.md` gains a Phase 12 row and section; `CHANGELOG.md`
      `[Unreleased]` entry.
- [x] Mark the answered open questions in `phase-9.1.md` (*Date the
      starting reading?*) and `phase-10.md` (*Overview*, *Purchase and sale
      paperwork*, *Print defaults*) as "Answered in Phase 12", leaving the
      others open.

### 12.1 Print view
- [x] *Show costs* unticked by default; `costs=1` shows them. Hint as above.
- [x] Links from the History toolbar open the print view with the new
      default.
- [x] Old option encoding checked; the fail-safe direction holds.

### 12.2 Purchase and sale paperwork: domain
- [x] Check how `attachments.owner_type` is stored now (Phase 10 checked it
      for `expense` and `odometer`). If there is a length limit, check
      constraint or native enum on any engine, widen it in a migration.
- [x] The owner-type enum gains `Purchase` and `Sale`; the attachment
      service resolves both to the vehicle.
- [x] **Backups and going back.** If no migration is needed, the schema
      version does not move, so a 1.4.0 backup would restore into 1.3.x.
      Check what 1.3.x does with an unknown owner type (an enum `from()`
      throwing on a History page, say). If anything breaks, add a
      migration so the backup rule blocks the restore; otherwise the upgrade
      note says the files are ignored by 1.3.x.
- [x] The shared parser takes a field name; every existing caller keeps
      `attachments[]` without change.
- [x] `VehicleService::create()` / `update()` write the files in the
      vehicle's transaction (files first, rows in the transaction, files
      deleted if it fails), with the date rules above.

### 12.3 Purchase and sale paperwork: forms and display
- [x] Vehicle form (page and modal, add and edit): the attachment input and
      file list under the purchase fields and under the sale fields. Works
      without JS; the JS limit check counts both inputs together.
- [x] Paperclips on *Bought* and *Sold* in History and fleet history, from
      the page's grouped query.
- [x] Overview *Ownership* card: a paperclip beside each date that has
      files, linking to the edit form.
- [x] Print view: file names under the milestones, as under entries.
- [x] Delete confirmation mentions the paperwork in its count of files, if
      it counts files.

### 12.4 Dated starting mileage
- [x] Add form: *As of* beside *Current odometer*, default today (owner's
      time zone), kept on a validation error.
- [x] `VehicleService::create()` takes the date with the reading; today →
      now, earlier → local noon; plausibility as for any reading.
- [x] The lifetime average (`VehicleAge` or wherever 9.1 put it) takes the
      reading's local date instead of today; no date maths in templates or
      Actions.

### 12.5 Overview
- [x] Remove the latest fill-ups list; keep any figure found only there.
- [x] Remove its now-unused template partial, translation keys and query.

### 12.6 Demo seed + backup
- [x] `bin/dev seed`: where cheap, give the sold, archived vehicle a small
      generated PDF as its sale paperwork; otherwise skip.
- [x] Backups include the new owner types automatically (they are rows and
      files like any other). Follow the rule decided in 12.2.

### 12.7 i18n
- [x] English and German for every new label, hint, error and notice:
      *Purchase paperwork* / *Kaufunterlagen*, *Sale paperwork* /
      *Verkaufsunterlagen*, *As of* / *Stand vom*, the print hint, the two
      date-rule errors, the before-registration warning.
- [x] Remove the overview list's keys from both catalogues.

### 12.8 Release v1.4.0
- [x] `VERSION` → `1.4.0`; sidebar, Settings and `/health` show it.
- [x] `CHANGELOG.md` `[1.4.0]`: *Added* (paperwork, *As of*), *Changed*
      (print costs off by default; lifetime average to the reading's date;
      overview list removed), upgrade notes (migration or not, per 12.2; the
      backup rule; no config changes).
- [x] `ROADMAP.md`: Phase 12 row ✅.
- [ ] Tag `v1.4.0`; image published as `1.4.0`, `1.4`, `1` and `latest`.

### 12.9 Tests
- [x] Unit: print option parsing (absent, `costs=1`, the 1.3.0 encoding);
      lifetime average to the reading's date (a reading 200 days old on a
      3-year-old vehicle; the 90-day floor measured at the reading);
      *As of* today → now, earlier → local noon in a zone ahead of and
      behind UTC; future and 1884 rejected; before first registration warns.
- [x] Unit: the paperwork date rules (files without a date refused;
      clearing a date with files refused; clearing one without files
      allowed).
- [x] Integration: add and edit a vehicle with purchase and sale files
      (page and modal); 6 + 5 files refused as over the limit; one bad file
      fails the save and writes nothing; files served only to the owner;
      deleting the vehicle removes them; archiving keeps them.
- [x] Integration: paperclips on *Bought* / *Sold* in History, fleet
      history and the overview; file names in print; print hides costs and
      prices by default and shows them with `costs=1`.
- [x] Integration: add a vehicle with a dated starting reading; it sits in
      order with fill-ups before and after it; the Mileage tab, garage card
      and dashboard show it.
- [x] Integration: overview without the fill-ups list, with `fuel` on and
      off.
- [x] Pass on **both** MySQL and Postgres (plus MariaDB and SQLite via
      `bin/test-all-dbs.sh`); smoke test at a subpath.

---

## Deliverables
A print view that is safe to hand over by default, purchase and sale
paperwork kept with the vehicle's *Bought* and *Sold* milestones, a
starting mileage that can be dated when it was read, and a leaner overview;
released as Logbook v1.4.0.

## Acceptance criteria
- [x] The print view hides costs and purchase / sale prices unless *Show
      costs* is ticked; no old link shows costs it used to hide.
- [x] Purchase and sale files can be added, listed and deleted on the
      vehicle form, and show on the milestones, the overview and in print.
- [x] No file is ever attached to a purchase or sale without its date.
- [x] A vehicle added with a dated starting reading has exactly one manual
      reading, at local noon on that date (or now, for today).
- [x] The lifetime average uses the reading's date; every other figure is
      unchanged.
- [x] The overview no longer lists fill-ups and loses no figure.
- [x] `/health`, sidebar and Settings show v1.4.0; changelog and roadmap
      updated.
- [x] Suite green on both DBs; translatable (en + de); works behind a
      subpath with deep-link refresh; Docker and bare-PHP paths both work.

## Gotchas
- **Fail safe on the print default.** The only unacceptable regression is a
  link that now shows costs it used to hide.
- **`max_file_uploads` is per request, not per input.** Two inputs on one
  form share the limit; check both together, server-side and in JS.
- **No schema change can still be a backup change.** New owner-type values
  restore into an older version that may not know them. Decide in 12.2,
  don't discover it in a bug report.
- **Local noon, not midnight,** for a dated reading, like every other
  date-only reading. Midnight UTC moves the reading to the day before for
  anyone west of UTC.
- **Don't add attachments to the vehicle.** `owner_type = vehicle` would be
  the gallery §7.12 rules out, with nowhere in history to show it.

## Open questions
- **Remember the last print choice?** An owner who always prints their own
  copy ticks *Show costs* every time. A per-user setting would cover it, at
  the cost of the safe default. Wait and see whether anyone asks.
  *Decided 2026-09-30: no. *Show costs* stays a per-print choice, so every print
  starts as the copy a buyer can be handed.*
- **Paperwork on documents vs purchase.** Some owners will file the V5C as a
  *Registration* document and the purchase invoice under *Bought*. A hint
  may be enough; leave it until the form is in use.
  *Decided 2026-09-30: a hint on the purchase paperwork input, Phase 21.1.*
