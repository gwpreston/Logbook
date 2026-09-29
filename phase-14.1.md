# Phase 14.1 — Valuations and depreciation

**Goal:** know what each vehicle is worth and what it has lost. The vehicle
already records what the owner paid and, once sold, what it fetched. Add a
small **valuation log** in between (a dealer's part-exchange offer, an
online valuation, an insurer's figure), and derive **depreciation** from
the purchase price to the latest value: the amount, the percentage, per
year and per distance. (Purchase and sale paperwork, first planned here,
already shipped with Phase 12 as the `purchase` and `sale` owner types.)

Read `spec.md` (§6 Vehicle, Attachment; §7.1, §7.7, §7.12, §7.13, §7.16;
§8) and `CLAUDE.md` (§6, §8, §9, §11) before starting.

**Prerequisites:** [Phase 13](phase-13.md) complete and green; v1.5.0
tagged.

**Followed by:** [Phase 14.2](phase-14.2.md) (total cost of ownership),
which cuts v1.6.0. This phase has no release of its own.

---

## Scope

**In:**
- A `vehicle_valuations` table with add / edit / delete, and attachments
  on each valuation.
- A derived depreciation figure and a value-over-time chart on the
  overview.
- Valuations in History and *Recent activity*; CSV export; backups;
  translations; demo seed.

**Out:**
- **Automatic valuation** from any online service. There is no free,
  reliable valuation API, the commercial ones (CAP HPI, Glass's, Auto
  Trader) need contracts and keys, and every lookup would send the
  owner's registration to a third party. Logbook keeps data local.
- **Depreciation curves or extrapolation** ("about 15% a year"). A value is
  something someone quoted, never something Logbook invents.
- An odometer on valuations (the valuation's mileage is whatever the
  owner typed into a website; it is not a reading).
- CSV import of valuations (a handful of rows a year).
- Total cost of ownership and the fleet view (Phase 14.2).

---

## Design decisions (record in `spec.md` before building)

### Valuations

- **Table `vehicle_valuations`:** id, vehicle_id (`ON DELETE CASCADE`),
  valued_on (`date`, a calendar date like `performed_on`, never converted
  through a time zone), amount (`decimal(14,3)`, in the vehicle's
  currency; **0 is valid**: a write-off or scrap value), source (optional,
  up to 100 characters: "Part-exchange offer, Arnold Clark", "Auto Trader
  valuation"), notes (optional, up to 500), created/updated (UTC). Index
  `(vehicle_id, valued_on)`.
- **Validation (block):** a date and an amount are required; not after
  today in the owner's time zone; not before the purchase date when one is
  set; not after the sale date when one is set ("This vehicle was sold on
  12 Mar 2026; its sale price is its final value."). Amount ≥ 0.
- **Free-text source**, no picklist: valuation services differ by country
  and change their names.
- **Attachments:** new owner type `valuation`, several files per save
  (§7.12). A screenshot of a quote is the usual receipt.
- **Where they are managed:** `/vehicles/{id}/valuations` (list newest
  first, add, edit, delete; each form a modal on desktop and its own page
  without JS), linked from the overview's *Ownership* card. Not a tab of
  its own and not in the *Log entry* chooser: it is a once-or-twice-a-year
  action. Archived vehicles can still take one (a scrapped car's scrap
  value) unless the sale-date rule refuses it.

### Purchase and sale paperwork

- **Already delivered by Phase 12** (spec §6 Attachment, §7.1): owner types
  `purchase` and `sale` with `owner_id` = the vehicle's id, the vehicle
  form's two file inputs, and the paperclips on *Bought*, *Sold* and the
  *Ownership* card. Phase 12 chose to refuse clearing a date while its files
  are attached (rather than keep orphaned files); that stays. Nothing to
  build here beyond keeping those paperclips on the extended card.

### The value series

- **Points,** in date order: *Bought* (purchase date and price, both
  needed), every valuation, *Sold* (sale date and price, both needed). On
  the same day *Bought* comes first and *Sold* last; valuations on one day
  keep the order they were added.
- **Current value:** the sale price when sold; else the latest valuation;
  else none.

### Depreciation (derived, never stored)

`Service\Vehicle\Depreciation` computes it on every read and returns a
typed result, like `VehicleAge`.

- **Needs a purchase price and a current value.** Otherwise the state is
  *no purchase price* ("Add what you paid to see depreciation") or *no
  value yet* ("Add a valuation to see what it has lost").
- **Change** = current value − purchase price, as an amount and a
  percentage of the purchase price. A loss reads "Down £6,200 (−37%)"; a
  gain (classics, used-car price spikes) reads "Up £1,100 (+9%)".
- **Per year** = the loss ÷ years from the purchase date to the **value's
  date** (not today: that is when the value was true). Shown once those two
  dates are at least 90 days apart.
- **Per distance** = the loss ÷ the distance driven from the purchase date
  to the value's date, measured as a report's *distance driven* (§7.7),
  in the owner's distance unit ("£0.26 per mile"). Shown under the same 90
  days rule and only when some distance was driven. Not shown for a gain
  ("per mile" of appreciation is meaningless).
- **Stale value:** when the vehicle is not sold and the latest valuation is
  more than 12 months old, a hint: "Valued 14 months ago; add a new
  valuation for an up-to-date figure." The figures still show; nothing is
  extrapolated.
- **Currency:** the vehicle's; amounts are never converted.

### Display

- **Overview *Ownership* card** (it already shows the purchase date;
  extend it rather than adding a second card with a similar name): *Bought*
  (date, price), *Latest value* (date, amount, source) or *Sold* (date,
  price), *Change*, *Per year*, *Per distance*, the stale-value hint, and
  *Valuations →* / *Add valuation*. Rows that are not set are left out; the
  card is hidden when neither a purchase date, a price, a sale nor a
  valuation exists (the vehicle form's purchase section then carries the
  *Valuations* link). *Currency* and *Added*, which were on this card, move
  to the *Details* card so the hidden card takes nothing else with it.
- A purchase price of **0** shows the change as an amount without a
  percentage. The change needs only the price; per year and per distance
  also need the purchase date.
- **Value over time:** with two or more points, a small Chart.js line of
  the points (dated x-axis, the vehicle's currency). Without JS the same
  points are a table.
- **History:** a new kind *Valuation*, dated `valued_on`, summary "Valued
  at £9,800 · Auto Trader valuation", with its paperclip. Like the
  milestones' prices, the amount goes in the summary, never in the amount
  column, which is for costs. Listed under *Everything* only (no new chip)
  and in *Recent activity*.
- **Print view: valuations are never printed.** A service history handed to
  a buyer should not carry the seller's own valuations. *Show costs* keeps
  controlling the purchase and sale prices, as today.
- The cost ledger, reports and every existing figure are unchanged:
  valuations and depreciation are not costs.

### Module

- **Core**, like the garage and history: no toggle. It adds nothing to a
  vehicle until the owner enters something.

---

## Tasks

### 14.1.0 Spec first
Per `CLAUDE.md` §12, update `spec.md` before building:
- [ ] §6: `VehicleValuation`; Attachment owner type `valuation`
      (`purchase` and `sale` exist since Phase 12).
- [ ] §7.1: the *Ownership* card, valuations page, depreciation and its
      rules, and why nothing is extrapolated or fetched.
- [ ] §7.12: the new owner types.
- [ ] §7.13: valuations CSV export; backup note.
- [ ] §7.16: the *Valuation* kind; milestone paperclips; not printed.
- [ ] §13: a Phase 14.1 entry.
- [ ] `ROADMAP.md` Phase 14.1 row 🚧; `CHANGELOG.md` `[Unreleased]` entry.

### 14.1.1 Migration + domain
- [ ] Migration: `vehicle_valuations` as above, with the FK and index.
      Reversible; applies and rolls back on SQLite, PostgreSQL, MySQL and
      MariaDB.
- [ ] `VehicleValuation` entity, `ValuationRepository` (DBAL), `Row`
      mapping (the date read without time-zone conversion, as for
      `first_registered_on`).
- [ ] `ValuationService` (create / update / delete with the validation
      above; deleting deletes its files).
- [ ] Attachment owner types added to the enum and to the authenticated
      file handler's owner checks. Reuse the one upload path.

### 14.1.2 Depreciation
- [ ] `Depreciation` service and typed result (state, points, current
      value, change amount and percentage, per year, per distance, value
      age), reusing the reports' distance-driven calculation and the money
      value object (integer micro-units, no floats).

### 14.1.3 Forms + pages
- [ ] Valuations page with add / edit / delete (page and modal; CSRF;
      errors keep typed values; attachments input).
- [ ] Vehicle form: the purchase section links to *Valuations* on edit
      (its file inputs exist since Phase 12).

### 14.1.4 Display
- [ ] Overview *Ownership* card and value chart (table without JS).
- [ ] History and *Recent activity*: the *Valuation* kind through
      `ActivityFeed`; print view excludes valuations (milestone paperclips
      exist since Phase 12).

### 14.1.5 CSV + backup
- [ ] `/vehicles/{id}/export/valuations.csv`: Date, Amount, Currency,
      Source, Notes, with the usual quoting and formula guard. The
      valuations page carries *Export CSV*.
- [ ] Backups: add `vehicle_valuations` if the table list is explicit, in
      FK-safe restore order; the new attachment owner types restore with
      their files. The schema version moves.

### 14.1.6 Demo seed
- [ ] The Golf gets a purchase price, a part-exchange offer and an online
      valuation a year apart, with a screenshot attachment; the sold,
      archived vehicle keeps its purchase and sale prices and gets one
      valuation before the sale.

### 14.1.7 i18n
- [ ] English and German: *Bewertung*, *Aktueller Wert*, *Wertverlust*,
      *Wertzuwachs*, *pro Jahr*, *Quelle*, the hints and messages above
      (the purchase and sale paperwork strings exist since Phase 12).

### 14.1.8 Tests
- [ ] **Unit (validation):** future date (owner's time zone, around
      midnight), before purchase, after sale refused; 0 accepted; source
      101 characters refused.
- [ ] **Unit (depreciation):** worked example: bought £15,000 on 1 Mar
      2023, valued £9,800 on 1 Mar 2026, 36,000 mi driven in between →
      down £5,200 (−34.67%), £1,733.33 per year, £0.144 per mile; sold
      overrides the latest valuation; a gain shows no per-distance figure;
      89 days → no per-year or per-distance figure; 90 → shown; no purchase
      price and no value states; the stale hint at 12 months and one day;
      currency never converted.
- [ ] **Unit (series order):** same-day purchase, valuation and sale.
- [ ] **Integration:** valuations CRUD (page and modal, with and without
      JS), attachments on valuations, authenticated
      serving; the overview card and chart; History rows and milestone
      paperclips still shown; print excludes valuations; CSV export; deleting the
      vehicle removes valuations and files; backup → restore round-trip;
      migration up and down; every existing figure unchanged.
- [ ] Pass on **both** MySQL and Postgres (plus MariaDB and SQLite via
      `bin/test-all-dbs.sh`); smoke test at a subpath.

---

## Deliverables
A valuation log per vehicle and a
depreciation figure (amount, percentage, per year, per distance) with a
value-over-time chart. Released with Phase 14.2 as Logbook v1.6.0.

## Acceptance criteria
- [ ] Valuations are validated against today, the purchase and the sale,
      and take attachments.
- [ ] Depreciation appears only when it can be computed, is measured to the
      value's own date, and never extrapolates.
- [ ] A sold vehicle's depreciation uses its sale price.
- [ ] Valuations appear in History and *Recent activity*, never in the
      print view or the cost ledger.
- [ ] Existing data untouched; every existing figure unchanged.
- [ ] Suite green on both DBs; translatable (en + de); works behind a
      subpath with deep-link refresh; Docker and bare-PHP paths both work.

## Gotchas
- **Measure to the value's date, not today.** A valuation from last March
  divided over the time to today understates the loss per year.
- **`date`, not `datetimetz`,** for `valued_on`, as for every calendar
  date in the app.
- **Values are not costs.** Keep them out of the ledger, the amount column
  and every report total, or running costs would be counted with capital.
- **No invented values.** A curve would put a made-up figure next to real
  ones and read as just as trustworthy.

## Open questions
- **Insurer's agreed value.** An insurance document could carry the value
  it insures for and offer it as a valuation. Small, but it touches
  compliance; wait for demand.
- **A valuation's mileage** as context (not a reading). Left out to keep
  one mileage series.
