# Phase 45.1 — Hardening: proving the money

*Every headline figure checked against a source that isn't the code that
produced it.*

Status: 🚧 in progress (started 2026-10-11) · file lives in `docs/phases/`

Forty-four phases in, Logbook works out a lot of money:

- cost of ownership;
- true cost by period, with depreciation interpolated between
  valuations;
- finance interest, settlement estimates and equity;
- mileage claims by tax-year band;
- *Coming up* totals;
- economy by full-to-full segment.

Each figure has unit tests, but those tests were written alongside the
code. When the code and the test share a mistaken idea, both pass.

Phase 45 is a **hardening milestone**. No feature phase starts until it
passes. It has three parts:

- **45.1 (this part):** the money.
- **[45.2](phase-45.2.md):** households and backups.
- **[45.3](phase-45.3.md):** phones and real people, then the release.

This part builds two things:

- **A golden household.** A realistic fixture whose expected figures are
  worked out by the owner in a spreadsheet, independently of the code.
- **Property tests.** These hold the money rules true for any data, not
  just for chosen examples.

Read [`CLAUDE.md`](../../CLAUDE.md) and [`spec.md`](../../spec.md) §7.1,
§7.7, §7.11, §7.18, §7.23, §7.32 and §7.35 first.

**Prerequisites:** [Phase 44](phase-44.md) complete and green.

---

## Goals

1. **The golden household**, built from scripted inputs and kept in
   `tests/Fixtures/golden/`:
   - two users;
   - four vehicles over three years;
   - expected figures in `expected.csv` from the owner's spreadsheet.

   It is loaded the same way on every engine.
2. **A golden test.** It compares every expected figure to the penny,
   through the same services the pages use, on every engine.
3. **Property tests** for the ledger's invariants.
4. **An edge-case catalogue** in the spec. Each case has a test, and
   each case's answer is decided, not inherited from the code.
5. Every mismatch found is settled. Either the spreadsheet or the code is
   wrong, the answer is logged, and the code or the spec is fixed.

## Not in scope

- New figures, or new ways of working them out. A fix brings code and
  spec back into line; it doesn't redesign.
- Performance work, beyond keeping §8's budgets.

---

## The golden household

Decided 2026-10-11 (#393, #395). The four vehicles:

- **Petrol car, bought used on PCP.**
  - Three valuations, one of them stale.
  - A balloon payment, settled early in year three.
  - Tyres, two services, and an MOT fail then pass.
  - Business trips across two tax years and a rate band change.
- **EV.**
  - Home charging on two tariffs, plus public AC and DC.
  - A free charge (cost 0).
  - (No refund: the app has none, #388.)
- **Sold car.**
  - An accident with an insurance payout and an excess.
  - Sold at a gain over its last valuation.
- **Car bought in euros, kept in Ireland.**
  - Every figure stays in its own currency and is never converted.
  - Its owner is in `Europe/Dublin`, so it also covers a summer-time
    change.

The people (#401): **user 1** (en_GB, Europe/London, UK tax year from
6 April) owns the petrol car, the EV and the sold car, and makes the
business trips. **User 2** (en_IE, Europe/Dublin, EUR) owns the Irish
car, and has a Log share without costs on the petrol car, adding
fill-ups to it. No vehicle has a reading dated before its purchase: that rule
(#346) is built in Phase 41.8, which writes its own test (#391). The fixture is only data: the same inputs go in through the
services (not raw SQL), so every derived row is created as the app would
create it.

**Figures checked** (one row each in `expected.csv`, with the period and
vehicle):

- cost of ownership total, per distance and per month;
- true cost and its parts per month and per year, and *What changed*;
- depreciation per period;
- finance payments made, interest so far, the settlement estimate,
  equity and the payments left;
- the mileage claim per tax year;
- *Coming up* totals for the next 3 and 12 months;
- economy per segment and lifetime;
- Reports totals per group and per category;
- the digest's *Last month* figures.

---

## Properties

Generated vehicles and entries from a seeded generator in
`tests/Support/`, with no library (#394), so any failure replays from its
seed. Each property must hold:

- **Totals add up.** A total equals the sum of its parts in every report,
  card and widget, to the decimal place it is shown.
- **Rounded once.** No figure moves when the same data is entered in a
  different order, split into more entries, or summed in a different
  grouping.
- **Undo restores.** Deleting an entry and adding it back gives
  identical figures.
- **Never mixed.** No figure ever combines two currencies.
- **Sign is kept.** Insurance payouts, negative finance adjustments and
  gains in value reduce totals, and a negative figure always shows its
  minus sign (#387).
- **The viewer's time zone.** An entry at 23:30 on the last day of a
  month is counted in that month for the user viewing it, whatever the
  server's zone (#386). The server runs in UTC and America/Los_Angeles,
  users in zones such as Australia/Sydney and Europe/London (#397).
  Summer-time days and 29 February are both generated.
- **Hidden means hidden.** A user without `ViewCosts` gets nothing from
  any service that returns a derived amount.

---

## Tasks

### 45.1.0 Spec first
- [x] Open questions A–D decided by the owner and logged (#393–#396),
      with #386–#392 and #397 found while starting, and the carried
      #374–#376 (2026-10-11).
- [x] The edge-case catalogue added to §8 *Money edge cases*: each case
      with its decided answer.
- [x] §13 rows for 45.1, 45.2 and 45.3; `ROADMAP.md` already had them,
      with the feature phases renumbered after them.

### 45.1.1 The golden household
- [x] The inputs: `tests/Fixtures/golden/inputs/*.csv` (users Alex and
      Sam, 4 vehicles, 577 fill-ups, 143 expenses, 143 trips, finance,
      valuations, documents, an incident), written by the seeded
      `tests/Support/Golden/InputBuilder`, with today pinned at
      2026-09-15 08:00 UTC; `tests/Fixtures/golden/README.md` for the
      spreadsheet; `GoldenInputsTest` keeps the CSVs and the builder in
      step.
- [x] The loader (`tests/Support/Golden/GoldenHousehold`) that enters
      them through the services, not SQL, acting as the user in `by`;
      `GoldenHouseholdLoadTest` checks every row is stored once.
- [ ] The uploads (receipts on a few entries).
- [ ] The owner's spreadsheet in `tests/Fixtures/golden/`, and
      `expected.csv` exported from it (the skeleton, 812 rows with
      `expected` empty, is committed).
- [ ] `GoldenHouseholdTest` on every engine: one assertion per row, and a
      failure message that names the figure, period and vehicle.

### 45.1.2 Properties
- [x] The generator (`tests/Support/Property/HouseholdGenerator`, 1–3
      vehicles in GBP, EUR or JPY, an owner and a member in different
      zones, entered through the services) and the seven properties in
      `tests/Integration/Property/` (`#[Group('property')]`): fixed seeds
      on every push in `composer test`; `.github/workflows/properties.yml`
      runs 25 fresh seeds a night on SQLite, PostgreSQL and MySQL and
      prints them; `PROPERTY_SEEDS` replays one. The time-zone property
      fails when the ledger dates by the server's zone, and the sign and
      hidden-costs properties reach negative figures and hidden vehicles'
      items on most seeds.
- [x] The properties that wait on a 45.1.5 fix are added with it:
      negative groups (#387), reach-back (#376), the digest (#374, #375),
      the purchase-day valuation (#389), finance rounding (#390) and shown
      parts adding up (#403). All are in, #403 as an eighth property,
      *shown parts add up*. The generator now
      values one vehicle in four on its purchase day, buys one unsold
      vehicle in two on HP, and starts one log in five at the first
      fill-up.
- [x] *Hidden means hidden* covers the services that work out amounts
      for a page: Reports, cost of ownership, true cost, *Coming up*, the
      digest's *Last month*, finance and incidents. The fuel tab's cost per
      distance, station stats, the API, exports, Ask and MCP are carried to
      [45.2](phase-45.2.md)'s leak sweep, which renders every GET route,
      the API, CSV and every Ask and MCP read tool as a member without
      costs.

### 45.1.3 Edge cases
The catalogue, each case with a test:
- [ ] Cost 0, and every figure at 0 distance.
- [ ] A valuation on the purchase day, two valuations on one day, and a
      sale below or above the last valuation.
- [ ] Finance settled early, a missed payment, a balloon refinanced, and
      a 0% agreement.
- [ ] A vehicle with no readings, one reading, or a backwards reading.
- [ ] A plug-in hybrid with both litres and kWh.
- [ ] A tax year spanning a rate change, and a trip on 5 and 6 April.
- [ ] A payout or negative finance adjustment larger than the month's
      spend (#388: there are no refunds).
- [ ] Rounding at half a penny in each currency's minor unit.

### 45.1.4 Mismatches
- [x] #402 (found by *totals add up*): the years' depreciation missed
      *Since bought* by a micro-unit; the code was wrong, fixed in
      `ValueCurve` with a regression test (spec §7.35).
- [ ] Each mismatch logged in `open-questions.md` with what was wrong
      (spreadsheet, code or spec) and the fix. Code fixes carry a
      regression test. Spec fixes are noted in §13.

### 45.1.5 Fixes decided while starting
Each with a regression test, spec already updated:
- [x] #376, #398, #399: Reports' and the digest's cost per distance
      need the mileage log to reach back to the period's start (§7.7,
      §7.11), or to the purchase when later (#405, decided 2026-10-12).
      Insights' *cheapest to run* and Ask's *vehicle summary* read the
      same figure, so they follow.
- [x] #400: a test that a payout above the period's costs gives
      negative ownership and true cost figures with a minus sign
      (`TrueCostTest`; *sign is kept* checks the minus sign shown).
- [x] #374: the digest's *Last month* keeps vehicles archived, sold or
      written off during that month (§7.11).
- [x] #375: the digest's fleet spend is labelled when any listed
      vehicle's costs are hidden (§7.11).
- [x] #387: a negative finance adjustment shown with its minus sign;
      shares over the groups above zero, a negative group listed after
      them (§7.7, §7.8). The generator now buys one unsold vehicle in two
      on HP (some at 0%, half settled early, sometimes for far less than
      the payments left); *sign is kept* holds the groups' order and
      shares, and *rounded once* holds every finance line and interest
      share to the currency's minor unit (#390). Seed 11 joins the fixed
      seeds, for yen on HP and a group below zero.
- [x] #389: the value curve opens the purchase day at the price, so a
      purchase-day valuation is depreciation in that period (§7.1,
      §7.35). A sale on the purchase day is its closing value too (#404,
      decided 2026-10-12). The generator values one vehicle in four on its
      purchase day, so *totals add up* holds the years to *Since bought*.
- [x] #390: finance rounds to the agreement currency's minor unit
      (§7.32), with the forms accepting that many decimals (the agreement,
      extra payment, quote and end forms, on the page and the API; the
      inputs step by `currency_step()`).
- [x] #403: shown parts rounded by largest remainder wherever a total
      is shown with them (Reports, the ownership card, true cost, the
      expense widgets, CSV), so they add up to the shown total (§8).
      Reports' months × groups table by controlled rounding (#406,
      decided 2026-10-12); a vehicle's cost of ownership at each level;
      the CSV total rows at their 3 places. True cost's amounts are shown
      only on the ownership card and in the CSV, both covered.

---

## Acceptance criteria

1. The golden test passes to the penny on every engine.
2. The properties pass on fixed seeds in CI, and the nightly run is
   green for a week.
3. Every edge case in the catalogue has a decided answer and a test.
4. Definition of done (CLAUDE.md §11) holds.

## Open questions

All decided by the owner on 2026-10-11, before the phase was built.

- **A. Who works out the expected figures?** *Decided (#393):* both for
  finance and depreciation (the owner's spreadsheet and an independent
  script by Claude Code sharing no code with `src/`, compared); the
  owner's spreadsheet alone for everything else.
- **B. A property-testing library?** *Decided (#394):* none; PHPUnit data
  providers over a small seeded generator in `tests/Support/`.
- **C. How large a household?** *Decided (#395):* the four vehicles,
  about 1,500 entries; no heavy variant.
- **D. What if the spec is ambiguous?** *Decided (#396):* the owner
  decides, and the spec is made exact.

Found while starting (the edge-case review compared spec and code):

- **#386 Which zone dates a shared vehicle's costs?** *Decided:* the
  viewing user's (as the code does); §7.7's "owner's" made exact.
- **#387 A negative finance adjustment against "costs are never
  negative".** *Decided:* kept and shown with a minus; shares over the
  groups above zero.
- **#388 Refunds don't exist.** *Decided:* none in 45.1; the edge case is
  a payout larger than the month's spend; refunds parked in §12.
- **#389 A valuation on the purchase day.** *Decided:* that day's
  closing value, after the price, so the drop falls in the period
  holding the purchase.
- **#390 Finance always at 2 decimals.** *Decided:* the currency's minor
  unit.
- **#391 Pre-purchase readings (#346) not built yet.** *Decided:* the
  golden household has none; Phase 41.8 tests it.
- **#392 The cases where spec and code agree.** *Decided:* confirmed as
  listed in spec §8 *Money edge cases*.
- **#398 A fleet vehicle whose log doesn't reach back.** *Decided:* its
  spend counts in the totals, but it is left out of the fleet's cost per
  distance.
- **#399 The digest's 12-month average with a short log.** *Decided:*
  the comparison is left out.
- **#400 A payout larger than the period's costs.** *Decided:* ownership
  and true cost go negative with a minus sign; the incident card keeps
  its 0.
- **#401 The golden household's people.** *Decided:* split owners, as
  above.
- **#404 A sale on the purchase day** (found while building #389).
  *Decided 2026-10-12:* as #389, that day's closing value.
- **#405 A vehicle bought during the period** (found by *totals add up*
  while building #376). *Decided 2026-10-12:* the log reaches back to the
  later of the period's first day and the purchase date.
- **#406 Reports' months table** (found while building #403). *Decided
  2026-10-12:* controlled rounding, so every month, group and the grand
  total add up.
- **#407 Other totals shown with their parts** (found while building
  #403): the Cost of ownership summary, the ownership print table and
  *Coming up*'s table. *Needs a decision* (open-questions log).
- **#397 Europe/Dublin shares London's offset.** *Decided:* keep Dublin;
  the time-zone property runs the server in UTC and America/Los_Angeles
  with users in far zones.

Carried from Phase 43 and decided now, since the golden household's
digest figures depend on them: #374 (include vehicles archived during
the month), #375 (label the fleet spend), #376 (require readings that
reach back, on Reports too). #377 touches no figure and stays open.
