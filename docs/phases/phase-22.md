# Phase 22 — Trips and business mileage claims + v2.2 release

*Log the journeys you claim for; Logbook works out the rest.*

Status: ✅ complete · released as **v2.2.0**

This is the first item in `spec.md` §12, "Trip/journey log (business vs
personal for mileage claims)", given a phase.

Owners log the **business** trips they may claim: date, vehicle, from, to,
distance, purpose. Logbook then does four things with them:

- **Private mileage** is the rest of the odometer's distance, so private
  journeys never need logging.
- **A claim report** shows each tax year's trips, valued at dated mileage
  rates. HMRC's are provided; employers' and other countries' can be set.
  It is printed through the browser or exported as CSV.
- **The Mileage tab and Reports** gain a business and private split.
- **Repeat journeys take two taps**, through saved journeys and *Log again*.

Read [`CLAUDE.md`](../../CLAUDE.md) (including §12, *Phases and open
questions*) and [`spec.md`](../../spec.md) §5, §6, §7.2, §7.7, §7.10, §7.13,
§7.16, §7.20 and §7.21 first. Check `open-questions.md` before starting.

---

## Goals

1. A switchable `trips` module: a *Trips* tab per vehicle, *Log trip* in the
   *Log entry* chooser, and the phone app's quick actions.
2. Trips with optional start and end odometer, return journeys,
   passengers, and saved journeys.
3. Business and private distance for any period: business from trips,
   private from the mileage log's distance driven, minus business.
4. **Mileage rates:** a dated, editable table per user, with HMRC's
   approved rates provided for GB owners.
5. **The claim report** by tax year or any range: trips, rates, tiers,
   totals, employer payments and the difference. Printable and exportable
   as CSV.
6. CSV import and export of trips, API endpoints, backup, and a dashboard
   widget.

## Not in scope

- GPS tracking, automatic trip detection, or live telematics (spec §2
  non-goals).
- Looking up distances or addresses from a map service. The app makes no
  third-party requests.
- Submitting claims to HMRC or an employer's expense system.
- Company-car advisory fuel rates, VAT on fuel, and benefit-in-kind.
- A native Excel (.xlsx) export (see *Open questions*). CSV opens in Excel.
- Tax advice. The report states figures and rates, not what anyone may
  claim.

---

## Spec additions

### §6 Data model

**Trip** (Phase 22)
- id, vehicle_id (`ON DELETE CASCADE`), created_by (user, Phase 19; the
  driver and claimant), travelled_on (calendar date, never converted
  through a time zone), from_place and to_place (free text, up to 100
  characters each, trimmed, both required), is_return (bool: there and
  back; the distance stored is the whole round trip), distance_km
  (`decimal(12,3)`, 0; the whole trip), odometer_start_km and
  odometer_end_km (optional `decimal(12,3)`; when both are given, the end
  is after the start and the distance is end − start), is_business (bool,
  default true), purpose (up to 200 characters; required when business),
  passengers (0–8, default 0; business passengers for the passenger
  rate), notes (optional, up to 500), created/updated (UTC). Index
  `(vehicle_id, travelled_on)` and `(created_by, travelled_on)`.
- A trip **writes no odometer reading**. Its odometer values are kept as
  evidence on the trip. The mileage log stays the only distance series,
  so readings, plausibility and every existing figure are unchanged.
- Trips take attachments (owner type `trip`: a parking or toll receipt
  for the journey).

**SavedJourney** (Phase 22)
- id, user_id (`ON DELETE CASCADE`), from_place, to_place, distance_km
  (one way), is_return_default (bool), purpose_default (optional),
  is_business_default (bool), sort_order, created/updated (UTC). A
  journey belongs to a user, not a vehicle. Deleting it leaves the trips
  logged from it.

**MileageRateSet** (Phase 22)
- id, user_id (`ON DELETE CASCADE`), effective_from (calendar date),
  distance_unit (`mi`|`km`), currency (ISO 4217), car_rate (per unit),
  car_threshold (optional: units per tax year at car_rate), car_rate_after
  (optional; needed when a threshold is set), bike_rate (optional; null =
  bikes use car_rate with no threshold), passenger_rate (optional, per
  passenger per unit), employer_car_rate and employer_bike_rate (optional:
  what the user's employer pays), source (optional free text, "HMRC
  approved mileage allowance payments"), created/updated (UTC). All rates
  are `decimal(10,4)`. `(user_id, effective_from)` is unique.
- The set in effect on a trip's date is the latest `effective_from` on or
  before it. A trip before the earliest set has no value.

**User** gains trip settings, stored as a user-scope setting `trips`:
tax year start (`MM-DD`; default `04-06` when the user's locale region is
GB, else `01-01`) and the claim report's declaration text (optional).

### §7.22 Trips (new)

- **Module** `trips`, switchable like the others (§7.10) and **off by
  default** (`FEATURES_TRIPS`, default `false`). Most owners never claim
  mileage, and a new tab on every vehicle would be clutter. Switching it
  off hides the tab, chooser item, widget, report sections and API routes
  (404), and keeps the data.
- **Trips tab** (`/vehicles/{id}/trips`), after Mileage: this tax year's
  business and private distance and claim value at the top, then trips
  newest first (25 per page) with date, journey ("Ballymena → Belfast",
  "Ballymena → Belfast → Ballymena" for a return), distance, purpose, a
  business or private badge, a paperclip, and *Log again*. The shared
  toolbar has *Export CSV*, *Import CSV* and *Log trip*.
- **Trip form** (a page and a desktop modal, §5): date (default today),
  *Saved journey* (a select that fills from, to, distance, return, purpose
  and business; without JS, `?journey=<id>` pre-fills the page), from,
  to, *Return journey*, distance **or** start and end odometer (both in
  the owner's distance unit), *Business trip* (ticked by default),
  purpose, passengers, notes, attachments, and *Save as a journey*.
  - With return ticked, the distance field is labelled "one way" and the
    stored distance is doubled. The list shows the round trip.
  - Validation: from and to are required; distance 0; with both
    odometers, the end must be greater than the start, and a typed
    distance that disagrees by more than 0.5 is refused ("The odometer
    says 54.2 mi; the distance says 60 mi"); a business trip needs a
    purpose; the date is not in the future; archived vehicles take no new
    trips.
  - **Hint** under *Business trip*: "Travel between home and your usual
    workplace is normally commuting, not business mileage." It is shown
    for GB, and in German with the equivalent wording. The app never
    judges it.
  - **Warning** (never blocking) when a trip's distance is more than the
    vehicle's distance driven that day, if readings on both sides of the
    day exist.
- **Log again** opens the form with every field but the date and odometers
  copied.
- **Saved journeys** are managed under Settings → Trips: rename, reorder,
  delete. They are also created by *Save as a journey*.
- **Business and private split** for a vehicle and period:
  - business = the sum of business trips;
  - total = the period's *distance driven* (§7.7), per vehicle;
  - private = total − business, and never below 0.
  When business is more than the total (readings too sparse), private
  shows "—" with "Your trips add up to more than the mileage log shows
  for this period. Add an odometer reading to fix it." Logged private
  trips are listed but never change the split, which always comes from
  the mileage log.
- **Mileage tab:** the summary gains *Business* and *Private* for this tax
  year (module on).
- **Reports** (§7.7): *Business mileage*: distance, claim value, and
  business share of the total per vehicle for the period, and the fleet
  total.
- **Cost per business mile** (decided 2026-09-30, from Phase 14.2's open
  question): the *Business mileage* report and the claim report show,
  per vehicle, the vehicle's cost of ownership *Per distance* for the
  period (§7.7, running costs plus depreciation, or running costs alone
  when there is no value) beside the claim value per business mile, so
  the owner can see whether the allowance covers what the car costs to
  run. It is a figure, not advice, and is left out ("—") when either part
  cannot be worked out.
- **Dashboard widget** *Business mileage*: this tax year's business
  distance, the value so far, and distance to the rate threshold ("6,418
  mi until the 25p rate").
- **History** (§7.16): a *Trips* chip. Trips show under that chip only,
  never under *Everything*, in *Recent activity*, the print view or the
  sale pack. Frequent drivers would otherwise flood the history, and
  trips are location history.
- **Access** (Phase 19): logging needs `Log`. A user always sees their
  own trips. Other people's trips on a vehicle are visible only with
  `Manage` or `Own` (a new `ViewOthersTrips` ability in the Phase 18.1
  policy), because destinations are personal. The split's business
  figure counts every trip, and a user who cannot see some of them sees
  the total only.

### §7.23 Mileage rates and the claim report (new)

- **Rates** (Settings → Trips → *Mileage rates*): the user's rate sets,
  newest first, with add, edit and delete. The add form pre-fills from the
  set in effect today.
- **Provided for GB users.** When a user with a GB locale region first
  switches trips on or opens the rates page with none, they get two sets
  (`source` "HMRC approved mileage allowance payments", miles, GBP):
  - from 6 Apr 2011: cars 0.45 for the first 10,000 miles in the tax year,
    then 0.25; bikes 0.24; passengers 0.05;
  - from 6 Apr 2026: cars 0.55 for the first 10,000, then 0.25; bikes
    0.24; passengers 0.05.
  These are ordinary rows the user can edit. No release ever changes a
  user's rates silently; new official rates are added by the user or
  announced in the changelog. Other regions start with none and the page
  explains how to add one.
- **Tax year:** from the user's tax year start. A GB tax year runs from
  6 April to 5 April and is labelled "2026/27"; others are labelled by
  their calendar years.
- **Valuing trips** (derived on every read, never stored):
  - Only business trips are valued, and only the claimant's own
    (`created_by`).
  - Each trip uses the rate set in effect on its date, in that set's unit
    (km converted exactly, never through floats) and currency.
  - **Threshold:** for `car` vehicles, the claimant's business distance is
    accumulated across all their cars through the tax year, by date then
    by when logged. The trip that crosses the threshold is split: the part
    below at `car_rate`, the rest at `car_rate_after`. The accumulation
    restarts each tax year. A rate change mid-year (as on 6 Apr 2026)
    keeps the year's running total.
  - `bike` vehicles use `bike_rate` with no threshold, and do not count
    towards the car threshold.
  - Passengers: `passengers × distance × passenger_rate`.
  - Amounts are rounded to the minor unit per trip, as a claim form would
    be, and totals are the sums of those.
- **Claim report** (`/trips/claim`, module on): filters for tax year
  (default the current one), or a custom date range, and vehicles (all by
  default), as a plain GET form.
  - Rows, oldest first: date, vehicle registration, journey, purpose,
    distance, passengers, rate (two lines for a split trip), and amount.
  - Totals: distance at each rate, passenger amount, total approved
    amount. With employer rates set: *Paid by employer* (employer rate ×
    distance) and *Difference*. Where the approved amount is higher, the
    difference is labelled "Approved amount not paid (you may be able to
    claim tax relief on this)". Where the employer pays more, "Paid above
    the approved amount". The report shows figures, not advice.
  - Private trips never appear.
  - Mixed currencies (rate sets in different currencies) are totalled
    separately, as reports already do.
  - **Print** (Phase 17.2 conventions): the header gives the claimant's
    display name, the vehicles with registrations, the period, the rate
    sets used and their source, and the date printed. The optional
    declaration text and a *Signed* / *Date* line print at the end.
  - **CSV:** the same rows and columns, amounts as plain decimals, with a
    header row in the user's language. The file name is
    `mileage-claim-<tax year>.csv`.

### §7.13 Import and export, §7.20 API, backup

- Trips join CSV export and import (`/vehicles/{id}/import/trips`), read
  by the same parser as the form. The duplicate key is date, from, to and
  distance. Import sets `created_by` to the importing user.
- API: `GET/POST /api/v1/vehicles/{id}/trips`, `GET /api/v1/trips/claim`
  (the report's figures). A POST's duplicate key matches the import's, so
  retries are safe. An iPhone Shortcut can log "Ballymena → Belfast" from
  a saved journey (`journey_id`).
- Backups carry `trips`, `saved_journeys`, `mileage_rate_sets` and `trip`
  attachments. The schema version moves.

Update §12: remove the trip-log line.

---

## Decisions (and why)

- **Business is logged; private is derived.** Nobody records every private
  journey. The mileage log already knows the total, so the split is always
  complete and never depends on discipline.
- **Trips write no readings.** Trips are dated by day, often several a day,
  and typed from memory. As readings they would fight the mileage log's
  ordering and plausibility checks. Kept on the trip, the odometer values
  are still evidence on the claim.
- **Rates are dated rows the user owns.** The approved car rate went from
  45p to 55p on 6 April 2026, after 15 years unchanged, and employers pay
  their own rates. A hard-coded table would be wrong the next time either
  changes, and every claim before the change must keep its old rate.
- **The threshold counts per claimant across all their cars.** That matches
  how the approved rates apply. It is an open question for people with more
  than one employment.
- **Off by default.** It is a specialist module. People who need it switch
  it on once, and nobody else sees a new tab.
- **Destinations are private.** Other users see trips only at Manage or
  Own, and trips never reach the history's *Everything*, the print view or
  the sale pack.

---

## Tasks

### Spec and docs
- [x] §6, §7.22, §7.23, §7.13, §7.20 and §12 in `spec.md`; the Phase 22
      line in §13.
- [x] `docs/trips.md`: logging, saved journeys, the split, rates, the claim
      report, and what the figures do and don't mean.
- [x] `.env.example` and `docs/configuration.md`: `FEATURES_TRIPS` (default
      `false`).

### Migrations (every engine, each reversible)
- [x] `trips`, `saved_journeys`, `mileage_rate_sets`; the `trip` attachment
      owner type. Rollback drops them and the `trip` attachment rows (files
      stay under `UPLOAD_PATH`, as earlier rollbacks do).

### Domain / Support
- [x] `Domain\Trip\Trip`, `SavedJourney`, `MileageRateSet`, `TaxYear` (value
      object from a start `MM-DD` and a date).
- [x] `Support\Money` and distance conversion reused for per-unit rates
      (km ↔ mi exact decimals).

### Services
- [x] `Service\Trip\TripService`: create and edit (same parser for form,
      import and API), validation, return doubling, odometer distance,
      *Log again*.
- [x] `Service\Trip\MileageSplit`: business, total and private per vehicle
      and period, reusing the reports' distance driven.
- [x] `Service\Trip\ClaimCalculator`: rate lookup, threshold accumulation
      and splitting, bikes, passengers, rounding, employer difference, and
      currency grouping.
- [x] `Service\Trip\RateProvider`: seeds the GB sets once per user when
      they first need them.
- [x] Access: `VehicleAbility::ViewOthersTrips` in the policy (Manage and
      Own).

### Actions, templates, assets
- [x] Trips tab, trip form (page and modal), saved journeys and rates under
      Settings → Trips, claim report page, print and CSV.
- [x] Mileage tab split, Reports section, dashboard widget, the History
      chip, and the chooser item.
- [x] Phone app: *Log trip* in the quick actions, with saved journeys
      available offline (the fill-up queue's mechanism).
- [x] Translations (en, de): the GB wording for the commute hint and tax
      year labels; German with generic tax-year wording and no HMRC text.

### Tests
- [x] **Claim maths:** a year of trips crossing 10,000 miles, where the
      crossing trip is split exactly; a rate change on 6 Apr 2026 keeping
      the year's running total; a trip on 5 Apr and one on 6 Apr in
      different tax years (dates are calendar dates, not converted); bikes
      at 24p outside the threshold; passengers; km-denominated rates with
      mile trips; rounding per trip; employer higher and lower; a trip
      before the earliest rate set (no value, and the report says so);
      mixed currencies.
- [x] **Cost per business mile:** the period's cost per distance beside
      the claim value per business mile; "—" without a distance or with
      no running costs; km vehicles show per km.
- [x] **Split:** private = total − business; sparse readings → "—" with
      the notice; logged private trips don't change it; module off → gone.
- [x] **Form:** return doubling; odometer distance; mismatch refused; a
      business trip without a purpose refused; saved journey pre-fill with
      and without JS; *Save as a journey*; *Log again*; archived vehicle
      refused.
- [x] **Access:** the owner sees everyone's trips; a Log-level user sees
      only theirs; claims only ever include the claimant's own; a View user
      sees totals but no destinations.
- [x] **Privacy:** trips never appear under *Everything*, *Recent
      activity*, the print view, the sale pack or its ZIP.
- [x] Import and API duplicates; attachments; backup round-trip.
- [x] The GB rates are provided once and never overwritten; non-GB users
      get none.
- [x] Print header and CSV columns; translations suite (en, de).
- [x] Integration suite green on every engine; migrations roll back on every
      engine.

### Sample data
- [x] `DemoDataSeeder`: the demo owner switches trips on, with saved
      journeys (for example "Office → Client site, 27 mi") and about 60
      business trips on the Golf across 2025/26 and 2026/27, so the rate
      change shows. Add a few trips with passengers, one with a toll
      receipt, and an employer rate of 0.35 so the difference shows.

### Release
- [x] `CHANGELOG.md` **2.2.0**: trips, business mileage and claims. Upgrade
      notes: migrations; the module is off until switched on; GB users get
      HMRC's rates to check and edit.
- [x] Bump `VERSION`, rebuild assets, update the README status and
      documentation table.

---

## Acceptance criteria

1. With the module on, logging "Ballymena → Belfast, 54 mi, business,
   client meeting" takes one form, and logging it again takes two taps.
2. The claim report for 2026/27 lists each business trip at 55p up to
   10,000 miles and 25p after, with passengers and employer payments, and
   prints and exports as CSV.
3. The Mileage tab shows business and private for the tax year, with
   private coming from the mileage log without logging private trips.
4. Destinations are visible only to their driver and the vehicle's
   managers and owner, and never reach the history, print view or sale
   pack.
5. With the module off, nothing about trips appears anywhere, and switching
   it back on restores everything.
6. Definition of done (CLAUDE.md §11) holds.

## Open questions

- **More than one employment:** the 10,000-mile threshold applies per
  employment. Should a trip carry an optional *Employer* (with its own rate
  and threshold), or is one threshold per person enough for now?
  *Decided 2026-09-30: one threshold per person, across all their cars.
  Employers with their own threshold are parked in spec §12.*
- **Excel:** is CSV enough, or should the claim export a real .xlsx
  (PhpSpreadsheet, pure PHP but large)?
  *Decided 2026-09-30: CSV only. A native .xlsx is parked in spec §12.*
- **Default off:** should `trips` be on by default for GB-locale users
  only, instead of off for everyone?
  *Decided 2026-09-30: off for everyone (`FEATURES_TRIPS=false`).*
- **Van type:** vans share the car rates. Is a `van` vehicle type wanted,
  or do vans stay as `car`?
  *Decided 2026-09-30: vans stay as `car`. A `van` type is parked in spec
  §12.*
- **Cost per business mile:** cost of ownership per distance (drafted:
  running costs plus depreciation), or running costs only?
  *Answered 2026-09-30: cost of ownership per distance, as decided for
  Phase 14.2's question (log #23). Spec §7.7 already falls back to running
  costs alone when a vehicle has no value.*
- **Saved journeys in the API** (found while building): an iPhone Shortcut
  logs a saved journey by `journey_id`, but no endpoint lists the journeys,
  so the id comes from the journey's edit link. Add
  `GET /api/v1/journeys`?
  *Decided 2026-10-01: yes, built in [Phase 23.1](phase-23.1.md) (spec
  §7.20).*

## Changed while building it

- **Employer payments for bikes** fall back to the employer's car rate when
  a set has no employer bike rate, as `bike_rate` falls back to `car_rate`.
  **The difference** compares the approved mileage amount *without*
  passengers with what the employer paid: unpaid passenger payments get no
  tax relief. Both are written into spec §7.23. Checked against HMRC on
  2026-09-30: passenger payments are "an exemption only", with "no
  corresponding relief or deduction" when the employer pays less than 5p a
  mile per passenger or nothing (EIM31410); Mileage Allowance Relief is on
  "the unused balance of the approved amount" for the vehicle
  (gov.uk/expenses-and-benefits-business-travel-mileage/rules-for-tax,
  which also confirms 55p from 6 April 2026, 45p before, 25p above
  10,000 miles and 24p for motorcycles).
- **The threshold count is one accumulation** (`ClaimValuation`), in each
  rate set's own unit, from each trip's exact converted distance. The claim
  report and the dashboard widget read the same count, and summing
  kilometres cannot move the 10,000-mile boundary. A claim is always valued
  on every vehicle the claimant can see from the start of the tax year its
  period starts in, then filtered by vehicle and range, so a filtered or
  mid-year report shows the same split as the full year.
- **Import and the API read the whole trip's distance**, as it is stored and
  exported (`TripForm::parse(..., wholeDistance: true)`); only the form
  doubles a one-way distance. The export writes distances to 3 places in
  the owner's unit, so a file exported from Logbook imports back unchanged
  and is recognised as duplicates.
- **The GB rates are provided once per user**, with a `rates_provided` flag
  in the user's `trips` setting. The module is instance-wide and the rates
  are per user, so they are provided when each GB user first opens Settings
  → Trips, the claim, the Trips tab or the widget, not when an admin
  switches the module on. A user who deletes them does not get them back.
- **"Rename" a saved journey is editing it**: a saved journey has no name of
  its own beyond its places. Reordering is with up and down buttons, which
  work without JS.
- **A claim's vehicles are the ones the claimant can View.** A driver whose
  share is removed no longer sees that vehicle's trips in their claim.
- **The longer-than-driven warning** compares each trip with the gap between
  the last reading before its day and the first after it, trip by trip.
- **Trip files are guarded** (`TripFileGuard`): a receipt is served and
  deleted only for those who may see its trip, and not at all with the
  module off.
- **The phone app's offline "now"** also moves a date-only field (a trip's
  date) to today, so a trip form cached yesterday is dated today.
- **API** (spec §7.20): a POST's retry matches the key user's own trips only,
  so a driver never gets another driver's hidden trip back; a bad claim
  filter is a 400 and an unknown vehicle a 404, like every API parameter;
  the first claim read provides the GB rates like the web page. Amounts are
  3-place decimals, as elsewhere in the API.
- **Every line is rounded**, not just each trip: a split trip's two rate
  lines are each rounded to the penny, and the trip's amount is their sum.
  Rounding only the trip left the claim's per-rate totals up to half a
  penny a trip away from the total approved amount on a signed document;
  now every figure on the claim adds up (spec §7.23).
- **The *Business mileage* widget is last** in the default dashboard order,
  so existing layouts keep theirs.
- **Icons:** `route` and `replay` join the vendored sprite.
