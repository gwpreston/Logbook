# Phase 14.2 — Total cost of ownership + v1.6.0

**Goal:** answer "what has this car really cost me?" The app already knows
the running costs (the cost ledger) and, since Phase 14.1, what the vehicle
has lost in value. Put the two together over the time it has been owned:
the total so far, per distance and per month, per vehicle and across the
fleet, with sold vehicles giving exact lifetime figures. Add a *Finance and
lease* expense category so leased and financed cars can be costed too.
Then cut **v1.6.0**.

Read `spec.md` (§6 ExpenseEntry; §7.1, §7.7, §7.10, §7.13; §8) and
`CLAUDE.md` (§6, §8, §11) before starting.

**Prerequisites:** [Phase 14.1](phase-14.1.md) complete and green.

---

## Scope

**In:**
- A derived **cost of ownership** per vehicle: running costs, depreciation,
  total, per distance and per month.
- A *Cost of ownership* card on the overview.
- An *Ownership* view in Reports comparing vehicles, with CSV export.
- A `finance` expense category.
- Translations and the v1.6.0 release (Phases 14.1 and 14.2).

**Out:**
- Changes to the existing reports, the pinned card's *Running cost* tile or
  any existing figure. Running cost (last 12 months) and cost of ownership
  (since bought) answer different questions and both stay.
- Opportunity cost, interest on the money tied up, or inflation.
- Splitting finance payments into capital and interest.
- Forecasting future costs (Phase 15).

---

## Design decisions (record in `spec.md` before building)

### The ownership period

- **Starts** on the purchase date. Without one, it starts at the earlier of
  the vehicle's first ledger line and first odometer reading, and the card
  says so: "Since first logged, 4 May 2024".
- **Ends** on the sale date when sold; otherwise today in the owner's time
  zone.
- Ledger lines before the purchase date are outside the period and not
  counted (a deposit logged the day before is the owner's to move).

### What is counted

- **Running costs** = every cost-ledger line in the period (fuel,
  maintenance, documents, other), read through the existing ledger with its
  rules unchanged: exact sums, tyre costs once under maintenance, and a
  switched-off module's costs left out, as reports do.
- **Depreciation** = Phase 14.1's figure, measured to its own value date.
- **Distance owned** = the distance driven over the period, as a report's
  *distance driven* (§7.7).

### Rates add, each over its own period

The latest valuation is rarely dated today, so running costs (to today) and
depreciation (to the value's date) cover different spans. Rather than cut
either one short:

- **Per distance** = running costs ÷ distance owned + depreciation per
  distance (Phase 14.1). Each part is shown too.
- **Per month** = running costs ÷ months owned + depreciation per year ÷ 12.
  Months owned counts calendar months in the owner's time zone as reports
  do (the current month counts).
- **Total so far** = running costs + depreciation, labelled with the value's
  date: "£14,820 (depreciation to 1 Mar 2026)".
- For a **sold** vehicle both periods end on the sale date, so every figure
  is exact and the label says so: "Lifetime, sold 12 Mar 2026".

### When a part is missing

- **No purchase price or no value:** running costs only, clearly titled
  *Running costs since …*, with the 14.1 prompt ("Add what you paid …").
  The total and rates are never shown as if they were complete.
- **No distance driven:** per-distance figures hidden, as in reports.
- **Under 90 days owned:** per-month and per-distance figures hidden (too
  short to mean anything); totals still shown.
- **A gain in value** reduces the total (it is money back); per-distance
  depreciation is not shown (Phase 14.1), so per distance is running costs
  only and says so.

### Finance and leases

- New expense category **`finance`** (*Finance and lease*): a code like the
  others, so no migration. Hint on the expense form: "Loan interest, lease
  or PCP payments. If you entered a purchase price, log only the interest
  and fees, not the payments that pay off that price, or it is counted
  twice."
- A leased car has no purchase price: its cost of ownership is its running
  costs, lease payments included, which is what it cost.

### Where it shows

- **Overview *Cost of ownership* card** beside *Ownership*: owned for (as
  the vehicle's age is written, "3 yrs 2 mo"), distance owned, running
  costs by group, depreciation, total, per distance and per month. Core:
  shown whatever modules are on, like the Expenses tab.
- **Reports → *Ownership*** (`/reports/ownership`, linked from the Reports
  page header): one row per vehicle with owned from / to, distance,
  running costs, depreciation, total, per distance and per month.
  - Filters are the reports' plain GET form: vehicle, *include archived*
    (off by default, as every fleet report; the page hints that sold
    vehicles have exact lifetime figures).
  - Grouped by currency, each with its own totals; amounts never converted.
  - A fleet row per currency: sums, and per distance = total ÷ distance of
    vehicles that have both.
  - Part of the `reports` module: with it off the view is gone (404) and
    the overview card stays.
  - `/reports/ownership.csv` with the same filters, one row per vehicle,
    the reports' CSV rules.
- Not on the dashboard in this phase (see open questions).

---

## Tasks

### 14.2.0 Spec first
Per `CLAUDE.md` §12, update `spec.md` before building:
- [x] §6 ExpenseEntry: category `finance`.
- [x] §7.7: cost of ownership (period, what is counted, rates add, missing
      parts), the *Ownership* report and its CSV.
- [x] §7.1: the *Cost of ownership* card.
- [x] §7.10: the ownership report under `reports`.
- [x] §13: a Phase 14.2 entry.
- [x] `ROADMAP.md` Phase 14.2 row 🚧; `CHANGELOG.md` `[1.6.0]` entry.

### 14.2.1 Services
- [x] `Service\Report\OwnershipCost` returns a typed result per vehicle
      (period and how it started, distance, running costs by group,
      depreciation result, total, rates, what is missing), built on the
      ledger, the distance-driven calculation and `Depreciation`.
- [x] A fleet method that groups by currency, as the reports service does.
- [x] `ExpenseCategory::Finance` with its label and hint; CSV import
      accepts its code and labels.

### 14.2.2 Display
- [x] Overview *Cost of ownership* card.
- [x] `/reports/ownership` page and CSV; link from Reports.
- [x] Expense form: the category and its hint.

### 14.2.3 Demo seed
- [x] The sold, archived vehicle shows exact lifetime figures; the Golf
      shows a total with depreciation to its latest valuation; the EV gets
      monthly `finance` lease payments and no purchase price.

### 14.2.4 i18n
- [x] English and German: *Gesamtkosten*, *Betriebskosten*, *Besitzdauer*,
      *Finanzierung und Leasing*, *pro Monat*, the labels and hints above.

### 14.2.5 Release v1.6.0
- [x] `VERSION` → `1.6.0`; sidebar, Settings and `/health` show it.
- [x] `CHANGELOG.md` `[1.6.0]` gathers Phases 14.1 and 14.2. Upgrade notes:
      one new table and one attachment owner type, `valuation` (14.1; the
      purchase and sale owners are Phase 12's); a new expense
      category, no migration (14.2); the backup schema rule; no config
      changes; nothing in existing data or figures changes.
- [x] `ROADMAP.md`: Phase 14.1 and 14.2 rows ✅.
- [x] Tag `v1.6.0`; image published as `1.6.0`, `1.6`, `1` and `latest`
      (after the merge; pushing the tag publishes the image).

### 14.2.6 Tests
- [x] **Unit (worked example):** bought £15,000 on 1 Mar 2023; valued
      £9,800 on 1 Mar 2026 (36,000 mi in between); £11,700 of running
      costs and 39,000 mi from purchase to 1 Sep 2026 → running £0.30 per
      mile + depreciation £0.144 per mile = £0.444 per mile; total £16,900
      labelled "depreciation to 1 Mar 2026".
- [x] **Unit (period):** starts at the purchase date; without one at the
      first ledger line or reading, whichever is earlier; ends at the sale
      date; ledger lines before purchase excluded; the month count matches
      reports' across a DST change.
- [x] **Unit (missing parts):** no purchase price → running only; no
      distance → no per-distance; 89 days → no rates; a gain reduces the
      total and hides per-distance depreciation.
- [x] **Unit (modules):** `fuel` off removes fill-up costs from running
      costs; tyre costs counted once.
- [x] **Integration:** overview card; ownership report and CSV with
      filters, archived excluded by default and included on request,
      several currencies kept apart; `reports` off → 404 and the card
      stays; the `finance` category through the form and CSV import; every
      existing report figure unchanged.
- [x] Pass on **both** MySQL and Postgres (plus MariaDB and SQLite via
      `bin/test-all-dbs.sh`); smoke test at a subpath.

---

## Deliverables
Cost of ownership per vehicle and across the fleet, running costs plus
depreciation, per distance and per month, exact for sold vehicles, with
finance and lease costs recordable. Released with Phase 14.1 as Logbook
v1.6.0.

## Acceptance criteria
- [x] The total and rates appear only when both parts are known; otherwise
      running costs are shown alone and titled as such.
- [x] Rates add each part over its own period; a sold vehicle's figures are
      exact to the sale date.
- [x] The ownership report groups by currency, excludes archived by
      default, and exports to CSV.
- [x] Every existing report, tile and card figure is unchanged.
- [x] `/health`, sidebar and Settings show v1.6.0; changelog and roadmap
      updated.
- [x] Suite green on both DBs; translatable (en + de); works behind a
      subpath with deep-link refresh; Docker and bare-PHP paths both work.

## Gotchas
- **Don't cut running costs to the valuation date** to make the periods
  match: the owner would see last summer's total while this month's MOT
  sits on the Expenses tab.
- **Don't let finance double-count.** A purchase price plus every PCP
  payment counts the car twice; the hint says so.
- **Keep ownership separate from running cost.** The pinned card's 12-month
  running cost is a different, still useful, number. Don't replace it.
- **Never convert currencies**, even for the fleet row.

## As built

Decided while building and recorded in `spec.md` §7.7:
- **Distance owned needs the mileage log to reach back** to the start of
  the period, the same rule as depreciation per distance (the check is
  shared, `PeriodDistance::reachesBack()`). Without it, the demo's leased
  EV read €4.10/mi: two years of payments over eight months of mileage.
  The card says when the log starts and what to add.
- **No rates before any cost is logged:** nothing logged is not nothing
  spent.
- **A rate without its depreciation part** (no price or value, a gain, no
  purchase date, the value under 90 days after the purchase) is the running
  part alone, marked "running costs only", including on the running-only
  card of a leased car.
- **A sale date without a price** ends the period there, but depreciation
  runs to the latest valuation and the figures are not called "Lifetime".
- **The fleet row** sums distance, running costs and depreciation; its
  total covers only the vehicles that have one ("2 of 5 vehicles"), and its
  per distance divides those vehicles' totals by their distance. No per
  month.
- The CSV's per-distance columns are in the owner's unit ("Total per
  distance (per mi)"), and money per month keeps 3 places.

## Open questions
- **Dashboard tile or widget** for cost of ownership. The pinned card has
  four tiles already; a fifth, or a switch on *Running cost*, needs a
  design look first.
  *Parked 2026-09-30: recorded in spec §12, pending a design pass.*
- **Business mileage.** Once a trip log exists (§12), cost per business
  mile would be the obvious next figure.
  *Decided 2026-09-30: trips arrive in Phase 22, and cost per business mile is
  added to it.*
