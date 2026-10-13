# Phase 50.1 — Parts and modifications

*What was fitted, what came off, and what the build has cost, kept apart
from what the car costs to run.*

Status: 📋 planned · file lives in `docs/phases/`

Logbook records the work done to a car ("Clutch replaced, £780") but not
the **part**: which clutch, its part number, where it was bought, when it
went on and at what mileage, what came off, and whether it is still under
warranty. It also has nowhere for **modifications**: coilovers, a remap, a
cat-back exhaust, a set of wheels. Today these are logged as maintenance
`other` or the expense category `accessories`, so a £2,400 suspension
build lands in the running costs, and the car's cost per mile jumps for
the year it was bought. §7.35's *What changed* then explains a rise in
maintenance that was really a choice, not the cost of running the car.

Two records, one module:

- A **part** is the physical item: name, brand, part number, supplier,
  price, condition, receipt and warranty. It is fitted and removed at a
  date and mileage, more than once if need be (the standard exhaust goes
  back on before a sale), and its distance on the vehicle is derived as a
  tyre's is.
- A **modification** is the build work: a title, category, date, mileage,
  cost, who fitted it, the parts it fitted and what it took off.

**Costs stay on the record that fitted the part**, as tyre costs stay on
their service record (§7.17), so nothing is counted twice. A service
record's cost stays *Maintenance*. A modification's cost is a new ledger
group, ***Modifications*** (`build`): it counts in what the car has cost,
and **never in any running cost per distance** (requested by the owner,
2026-10-11).

Phase 50 is planned as one and split in two (open question A): this part
builds parts, modifications, the cost group and everything on Logbook's
own pages; [Phase 50.2](phase-50.2.md) adds warranty reminders, the list
for an insurer, the sale pack, scanning, the API, Ask, MCP, CSV and
webhooks, and releases.

Read [`CLAUDE.md`](../../CLAUDE.md) and [`spec.md`](../../spec.md) §7.17
first (tyres: the nearest pattern for fitted, removed and distance), then
§7.7 (the cost ledger and cost of ownership), §7.35 (true cost), §7.4,
§7.12, §7.16, §7.10, §7.21, §7.24 (Phase 25's cost checks) and §7.37 (the
issue pages are the nearest page pattern).

**Prerequisites:** none. Built after [Phase 48.2](phase-48.2.md), so its build figures show their working from the start.

---

## Goals

1. **Parts:** add, edit, delete; fitted and removed at a date and
   mileage, more than once; what happened to it when it came off; receipt
   and warranty terms; distance on the vehicle.
2. **Modifications:** add, edit, delete and *Revert*; fit parts and
   record what came off in the same form. A service record can fit parts
   too.
3. **The *Modifications* cost group:** in spend, the Expenses tab,
   Reports and cost of ownership; out of every running cost per distance.
4. A *Parts* tab, an overview card, part and modification pages,
   History and print; moving an existing record into modifications;
   backups; the demo seed.

## Not in scope

- **Tyres.** §7.17 keeps them. Wheels are parts; the tyres on them are
  not.
- **A parts inventory** away from any vehicle (#79 stays). A part belongs
  to one vehicle; a spare bought ahead is a stored part on it. Moving a
  part to another vehicle is out of scope, as for tyre sets.
- **Planned modifications** or a wish list.
- **Value.** Build spend never changes the purchase price, a valuation or
  depreciation (open question D).
- Catalogue lookups, part-number decoding, or any AI suggestion of a part
  or its fit.
- Warranty reminders, the insurer's list, the sale pack, scanning, API,
  Ask, MCP, CSV and webhooks: [Phase 50.2](phase-50.2.md).

---

## Spec (proposed)

A new [`spec.md`](../../spec.md) §7.39 *Parts and modifications*, with §6
Part, PartFitting and Modification. Written in 51.1.0 once the open
questions below are decided; in outline:

- **Modification** (§6): vehicle, title (up to 120), category (open
  question I), `done_on` (calendar date), odometer (optional), cost
  (`decimal(14,3)`, **0 allowed**), fitted by (optional, up to 120:
  a garage, or "Self"), notes (up to 2,000), `declared_on` (optional:
  when the insurer was told; used by 50.2), `reverted_on` and its
  odometer and note (optional), created/updated (UTC), attachments.
- **Part** (§6): vehicle, name (up to 120), brand, part number and serial
  (optional, up to 80 each), supplier (optional), `bought_on` and price
  (optional; open question B), condition (`new` | `used` |
  `reconditioned`), origin (`original`: it came with the car; `fitted`:
  everything else), warranty (below), notes, attachments.
- **PartFitting** (§6): part, `fitted_on`, fitted odometer, the record
  that fitted it (a service record, a modification or none), and once it
  comes off `removed_on`, removed odometer, the record that removed it,
  and on the last one the **outcome**: `kept` (stored), `sold` (with a
  price), `returned` (under warranty, optionally naming its replacement),
  `scrapped` or `other`. A part's status (*Fitted*, *Stored*, *Gone*) is
  read from its fittings and stored on the part, as a tyre's is; two
  fittings of one part may not overlap, and a refused save names the part
  and the dates.
- **Fitting parts.** The modification form and the service record form
  get a *Parts fitted* list (a new part, or a stored one fitted again) and
  a *Parts removed* list (a fitted part, or *Record what came off*, which
  creates an `original` part fitted from the purchase date and removed
  now, usually *Kept*). A part's own page has *Fit*, *Remove* and *Fit
  again* without a record (open question C).
- **One form for a one-part modification.** Each *Parts fitted* row is
  inline on the modification form: name, brand, part number, supplier
  and price, with the part's warranty and files behind *More*. Fitting an
  exhaust is one save; the Part and its fitting are created with the
  modification in the same transaction. A modification may have no parts
  at all (an ECU tune, a wrap), and its supplier is then *Fitted by*.
- **Distance** is derived, never stored: the sum of each fitting's
  odometer span, an open fitting running to the vehicle's current reading
  (§7.2); a negative span counts as 0 and is flagged, as for tyres.
- **Warranty** on the part: months and/or a distance, starting from the
  purchase date or the fitting (open question L), the provider and terms
  (up to 500). *In warranty until 3 Mar 2028 or 85,400 mi* while either
  limit holds; *Warranty ended* after. Reminders are 50.2's.
- **Reverting a modification** (a remap taken off, a car put back to
  standard) sets `reverted_on`, offers to remove its fitted parts in the
  same save and to fit stored `original` parts again; the modification
  stays in the record with a *Reverted* badge.
- **The ledger** (§7.7): a modification with a cost above 0 is one line
  in group `build` (*Modifications*), dated `done_on`. A part's price is
  never a ledger line (open question B). The proceeds of a part sold are
  a negative line (open question E).
- **Running cost per distance leaves `build` out everywhere** (open
  question D): Reports' and the Expenses tab's cost per distance, cost of
  ownership's *Per distance* and *Per month*, true cost's parts and *What
  changed* (§7.35), the dashboard's *True cost* widget, the digest's
  *Last month* cost per distance (§7.11), and the trips report's cost per
  business mile (§7.23). Totals of spend include it, as its own group,
  with "not in cost per mile" beside it.
- **Cost checks** (§7.24, Phase 25) leave `build` lines out of their
  baselines and never flag one (open question J).
- **Odometer:** a modification's, a fitting's and a removal's mileage add
  readings (sources `modification` and `part`; open question F).
- **Pages:** a *Parts* tab (open question K) with *Modifications* (current
  first, reverted after) and *Parts* (*Fitted*, *Stored*, *Gone* filters);
  an overview card (current modifications, total build spend, parts in
  warranty); a modification page and a part page with its fittings as a
  timeline; add/edit modals and pages; a *Log entry* chooser entry
  *Modification*.
- **Photos:** a modification's or part's image attachments (§7.12) show
  as a gallery on its page, before its other files, and the first image
  is the thumbnail on its row on the *Parts* tab and on the overview
  card. No new data: the first image is the earliest attached; a later
  *Make cover* is not in this phase.
- **History** kinds *Modification*, *Modification reverted*, *Part
  fitted* and *Part removed* (a part fitted by a service record or
  modification folds into that record's row), a *Parts* chip; the print
  view lists modifications and, with *Show costs*, their cost.
- **Module** `parts` (open question G), `FEATURES_PARTS`.

---

## Decisions (and why)

- **The cost lives on the record, not the part.** A garage invoice says
  "Clutch kit £320, labour £460"; the service record already holds the
  £780. Counting the part's £320 as well would count it twice, as a
  tyre's price would have (§7.17). The part keeps its price for reference
  and for a warranty claim.
- **Build spend is a ledger group, not an expense category.** The rule
  "not a running cost" has to hold everywhere the ledger is read
  (Reports, cost of ownership, true cost, the digest, the trips report,
  the cost checks, Ask). A group is the one thing all of them already
  split by; an expense category is not.
- **Spent is still spent.** Build spend stays in total spend and in what
  the car has cost, as its own group, so the Expenses tab still adds up
  to what left the owner's account. Only the figures that answer "what
  does it cost to run?" leave it out.
- **A part can go back on.** Owners keep the standard parts and refit
  them to sell, to pass an MOT or for winter; one fitting per part would
  lose the second spell and its distance.
- **What came off is worth recording.** *Record what came off* is the
  owner's own note that the original exhaust is in the garage, which a
  buyer will ask about.

---

## Tasks

### 51.1.0 Spec first
- [ ] Open questions A–L decided by the owner and logged in
      `open-questions.md`.
- [ ] §7.39; §6 Modification, Part, PartFitting, the reading sources
      `modification` and `part`, the attachment owner types; §7.7 (the
      `build` group, running cost per distance); §7.35, §7.11, §7.23
      and §7.24 (where `build` is left out); §7.4 (*Parts fitted*,
      *Parts removed*); §7.10 module; §7.16 kinds; §13; `ROADMAP.md`
      rows for 50.1 and 51.2.

### 51.1.1 Migration and services
- [ ] `modifications`, `parts`, `part_fittings`;
      `odometer_readings.modification_id` and `part_fitting_id`;
      reversible on every engine (rollback turns `modification` and
      `part` readings `manual`, removes their attachments, and moves
      `build` ledger lines nowhere: their records go with the tables).
- [ ] Domain: `Modification`, `ModificationCategory`, `Part`,
      `PartCondition`, `PartOrigin`, `PartStatus`, `PartFitting`,
      `RemovalOutcome`; repositories for each.
- [ ] `Service\Parts\PartService` (create, edit, delete, fit, remove,
      fit again, status replay with overlap refusal, distance) and
      `Service\Parts\ModificationService` (create, edit, delete with its
      fittings, revert, un-revert); readings written in the same
      transaction; the service record's save and delete call fit/remove.
- [ ] Ledger group `build` in `CostLedger`; *running* reads exclude it in
      `ReportService`, `OwnershipCost`, `TrueCost`, `CostChange`, the
      digest's *Last month* and the claim report; the cost checks skip it.
- [ ] Module `parts` (`Feature`, Settings → Modules, `FEATURES_PARTS` in
      `.env.example` and the configuration docs); off removes the
      `build` lines from reports as any module's.

### 51.1.2 Pages
- [ ] *Parts* tab, overview card, modification page, part page with its
      fittings and warranty, add/edit modals and pages, delete
      confirmations (a modification's names the fittings it removes),
      *Revert*, *Fit*, *Remove*, *Fit again*.
- [ ] *Parts fitted* and *Parts removed* on the modification and service
      record forms, page and modal, with and without JS; *Record what
      came off*; the inline part row (name, brand, part number, supplier,
      price; warranty and files behind *More*), so a one-part
      modification is one save.
- [ ] *Make this a modification* on a service record and an expense
      (open question H).
- [ ] Expenses tab, Reports, the *Expense breakdown* and *Monthly spend*
      widgets and cost of ownership show *Modifications* as a group, with
      "not in cost per mile" where a per-distance figure sits beside it;
      `?group=build` on Reports shows no cost per distance.
- [ ] *Log entry* chooser; Mileage tab source labels.
- [ ] Attachments with owner types `modification` and `part`; the photo
      gallery on modification and part pages, and the thumbnail on the
      *Parts* tab rows and overview card.
- [ ] Backups and `bin/export-user.php` carry the three tables.

### 51.1.3 Integrations
- [ ] History kinds and *Parts* chip; print view section.
- [ ] Demo seed: the Golf with a coilover modification (two parts fitted,
      the standard dampers recorded as *Kept*) and a clutch fitted by a
      service record with a two-year warranty.
- [ ] Translations (every shipped locale).

### 51.1.4 Tests
- [ ] Validation (dates not after today, a removal not before its
      fitting, overlapping fittings refused, a sold price only on *Sold*,
      warranty limits positive).
- [ ] Fitting from a modification, a service record and none; *Record
      what came off*; *Fit again*; reverting and un-reverting; deleting a
      record keeps its parts and reopens their fittings as the spec says.
- [ ] Distance per part across two spells; a backwards reading flagged.
- [ ] The ledger to the penny: `build` in totals and the Expenses tab,
      out of every per-distance and per-month running figure listed in
      51.1.1; *What changed* has no build cause; a sold part's proceeds;
      currencies never converted; `ViewCosts`.
- [ ] Readings written, moved and removed with their records.
- [ ] Access matrix for every route; module off is 404 everywhere and
      its lines leave reports.
- [ ] Migration up, down, up on SQLite, PostgreSQL, MySQL and MariaDB;
      suite green on every engine; coverage at or above the floor; the
      60-query budget on the overview and dashboard.
- [ ] Design review (375, 768, 1280 px; light and dark; JS off; German at
      375 px; subpath).

---

## Acceptance criteria

1. A part can be recorded with its number, supplier, price, receipt and
   warranty, fitted and removed more than once, and its distance on the
   vehicle is shown.
2. A modification records what was fitted and what came off, and can be
   reverted without losing the record.
3. Build spend appears in what the car has cost and never in any running
   cost per distance; nothing is counted twice.
4. Definition of done (CLAUDE.md §11) holds.

## Open questions

Numbered in `open-questions.md` when logged. Not built until decided.

- **A. One phase or two?** Options: (1) 50.1 the records, cost rule and
  pages; 50.2 warranty reminders, insurer list, sale pack, scanning, API,
  Ask, MCP, CSV, webhooks and the release; (2) one phase. *Recommendation:*
  (1), as Phases 40.1 and 40.2 were split.
- **B. Does a part's price count?** Options: (1) for reference only; the
  cost is on the service record or modification that fitted it (as tyres,
  §7.17); (2) a part fitted without a record counts its price, in a group
  the owner picks; (3) every part counts and its record's cost is reduced
  by it. *Recommendation:* (1). A part bought for a DIY job is costed by
  logging the job as a service record or modification with that cost.
- **C. Fitting without a record?** Options: (1) allowed with no cost,
  for *Already on the car* (as a tyre's `existing`) and a stored part
  fitted again; (2) every fitting needs a record. *Recommendation:* (1).
- **D. Which figures include build spend?** The owner's request decides
  that running cost per distance never does. Open: (1) total spend, the
  Expenses tab, Reports' totals and breakdown and cost of ownership's
  total include it as its own group; every per-distance and per-month
  running figure leaves it out; (2) it shows only on the *Parts* tab and
  in no total; (3) it is treated as capital, added to the purchase price
  for depreciation. *Recommendation:* (1). (3) would invent a value the
  owner never recorded, which §7.1 avoids.
- **E. A removed part that is sold.** Options: (1) a negative line in the
  group of the record that fitted it (`original` parts in `build`); (2)
  its own *Parts sold* line under the parts, taken off the total, as
  insurance payouts are (§7.35); (3) recorded on the part, never counted.
  *Recommendation:* (1): the build's net cost is what the owner wants.
- **F. Do the mileages add readings?** *Recommendation:* yes, as a
  service record's and an issue's do (#307): sources `modification` and
  `part`.
- **G. The module.** Options: (1) one module `parts` for both, on by
  default; (2) one module, off by default (as `trips`); (3) two modules.
  *Recommendation:* (1): parts with warranties suit every owner, not only
  those who modify.
- **H. Records already logged as modifications.** Options: (1) *Make
  this a modification* on a service record and an expense, one at a time,
  moving date, mileage, cost, note and files, the original deleted in the
  same transaction; (2) nothing: new ones only. *Recommendation:* (1),
  never automatic and never in bulk (an `accessories` expense may be a
  phone holder).
- **I. Modification categories.** A fixed list stored as codes (a new
  one needs no migration), in this order: `exhaust` *Exhaust*,
  `suspension` *Suspension*, `wheels` *Wheels*, `tune` *ECU tune*,
  `intake` *Intake*, `audio` *Audio*, `lighting` *Lighting*, `body`
  *Bodywork*, then `engine` *Engine internals*, `brakes`, `interior`,
  `electrical` *Electrical*, `security` *Security and tracking* and
  `other`. The first eight are the owner's list (2026-10-11).
  *Recommendation:* this list. `tune` is its own category because it is
  the modification with no part, the one most often reverted, and the
  one insurers ask about first (50.2's insurer list sorts it to the top).
- **J. The cost checks** (Phase 25). Options: (1) `build` lines are left
  out of the baselines and never flagged; (2) checked against a build
  baseline of their own. *Recommendation:* (1): a build is a choice, not
  an anomaly.
- **K. The tab.** Options: (1) one *Parts* tab after *Issues*, with
  *Modifications* above *Parts*; (2) two tabs; (3) a section on the
  Maintenance tab. *Recommendation:* (1).
- **L. When a warranty starts.** Options: (1) a choice per part,
  defaulting to the purchase date when set, else the first fitting; the
  distance limit always from the first fitting's mileage; (2) always the
  first fitting. *Recommendation:* (1): a supplier's warranty runs from
  the sale, a garage's from the fitting.
