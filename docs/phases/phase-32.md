# Phase 32 — True cost per mile, its breakdown and its trend + v2.16 release

*One number for what a car costs to run, what it is made of, and why it
changed.*

Status: ✅ complete · released as **v2.16.0** · file lives in `docs/phases/`

Phase 14.2 already works out a vehicle's **cost of ownership per distance**:
running costs plus depreciation, shown on the overview card and in the
Ownership report. But it is one figure with two parts. It doesn't show
fuel, maintenance, documents and other costs separately, it isn't on the
dashboard, and it can't be seen year by year.

This phase makes it the headline it deserves:

- the **true cost per mile or km** with a **breakdown** (fuel, maintenance,
  documents, other, depreciation);
- a **dashboard widget** that compares vehicles;
- a **year-by-year trend** with a plain explanation of what drove each
  change, worked out by Logbook. Ask Logbook (Phase 26.2) can then talk it
  through, but never computes it.

Read [`CLAUDE.md`](../../CLAUDE.md), [`spec.md`](../../spec.md) §7.1
(valuations and depreciation), §7.7 (cost ledger, groups, distance
driven), Phase 14.2's cost of ownership, Phase 29's finance lines, §7.8 and
§7.26 first.

---

## Goals

1. **Breakdown per distance** by ledger group (*fuel*, *maintenance*,
   *documents*, *other*) plus *depreciation*, for the ownership period and
   for the last 12 months.
2. **Depreciation for any period**, from the dated values Logbook has
   (purchase price, valuations, sale price), so 12-month and yearly figures
   include it honestly.
3. **A `true_cost` dashboard widget:** each vehicle's headline and
   breakdown, ranked, answering "which car costs me the most per mile?".
4. **A trend:** cost per distance by calendar year, stacked by part, per
   vehicle.
5. **What changed:** for each year against the one before, the change split
   into its causes: each group, fuel price against fuel economy, and fewer
   or more miles.
6. An Ask Logbook tool that returns these figures and causes.

## Not in scope

- Adjusting for inflation (spec §7.7: no inflation or price trend
  adjustment).
- Forecasting future cost per mile (*Coming up* covers known costs).
- Comparing with other people's cars or published averages.
- Changing Phase 14.2's figures. The lifetime numbers stay exactly as they
  are; this phase splits them and adds periods.

---

## Spec additions

*The draft below was settled while building: the decisions (#150–#156)
and the details found then are in `spec.md` §7.35, which is the source.*

### §7.35 True cost (new)

> - **Parts:** *Fuel*, *Maintenance*, *Documents* and *Other* are the cost
>   ledger's groups (§7.7), read through the same ledger with its rules:
>   tyre costs under maintenance, Phase 29's finance lines under other, and
>   switched-off modules left out. *Depreciation* is the fifth part.
>   Labels and icons are fixed and translated (*Fuel*, *Maintenance*,
>   *Insurance, tax and MOT* for documents, *Other*, *Depreciation*).
> - **Periods:** *Since bought* (Phase 14.2's ownership period, unchanged),
>   *Last 12 months*, and each **calendar year** in the owner's time zone.
>   The current year and the first year of ownership are partial and say so
>   ("2026 so far", "2023 from 14 Mar").
> - **Per distance** for a period = each part's amount ÷ the period's
>   distance driven (§7.7). It is shown in the owner's unit, to the penny or
>   cent ("£0.34/mi"). Without distance in a period, it is not shown.
> - **Depreciation for a period** (new; Phase 14.2's lifetime rule is
>   unchanged):
>   - the vehicle's **value points** are the purchase price on the purchase
>     date, each valuation on its date, and the sale price on the sale date;
>   - the value on any day between two points is interpolated in a straight
>     line;
>   - a period's depreciation = value at its start − value at its end, and a
>     gain is negative, as in Phase 14.2;
>   - **no extrapolation:** a period that runs past the latest value point
>     is measured up to that point and labelled "depreciation to 1 Mar
>     2026"; a period entirely after it shows depreciation as "—" and the
>     total as *running costs only*, with the prompt to add a valuation.
>   For a lease without a purchase price there is no depreciation; the
>   rentals are the cost (Phase 29).
> - **Overview card** (cost of ownership): under *Per distance*, the
>   five-part breakdown as a stacked bar and a list ("Fuel 14p · Maintenance
>   5p · Insurance, tax and MOT 4p · Other 2p · Depreciation 9p = 34p"), with
>   a switch between *Since bought* and *Last 12 months*. Phase 14.2's other
>   figures are unchanged.
> - **Dashboard widget** `true_cost`:
>   - one row per visible active vehicle: the headline per distance for the
>     chosen period (*Last 12 months* by default, or *Since bought*), its
>     stacked bar, and the change against the previous 12 months ("↑ 3p");
>   - ranked highest first, grouped by currency, with amounts never
>     converted;
>   - vehicles without distance in the period are listed last, with "Not
>     enough mileage logged";
>   - it follows the vehicle chip. It is a new widget, appended to existing
>     dashboards as §7.8 does, so the pinned card's four tiles are
>     untouched.
> - **Trend** (a *True cost* tab in Reports, `/reports/true-cost`, and a
>   card on the vehicle's Expenses tab):
>   - cost per distance by calendar year, stacked by part, one chart per
>     vehicle (Chart.js, with the table as the no-JS and print fallback);
>   - partial years are drawn hatched and labelled;
>   - a year with under 500 km of distance driven is shown in the table
>     only, as too little to compare.
>   - Fleet view: one line per vehicle (total per distance by year) for
>     vehicles in the same currency.
>   - CSV export: vehicle, year, distance, each part's amount and per
>     distance, total.
> - **What changed** (under the trend, for each year against the one
>   before, both with at least 500 km): the change in total per distance is
>   split into contributions that **add up exactly** to it:
>   - **for each part,** its change per distance;
>   - **the fuel part is split further** into *price* (the change in average
>     price per unit × last year's consumption) and *economy* (the change in
>     consumption × this year's average price). The two sum exactly to the
>     fuel part's change;
>   - **distance:** for the fixed-cost parts (documents, and depreciation
>     when it is roughly time-based), the change caused by driving a
>     different distance with the same amount, shown as its own line
>     ("Insurance, tax and MOT: +1.2p, because you drove 2,140 mi less").
>     This is computed as the part's amount this year ÷ this year's distance
>     − the same amount ÷ last year's distance, with the rest of the part's
>     change shown as the change in the amount itself.
>   Each contribution is shown as a fixed, translated sentence, largest
>   first, with its sign: "Fuel +2.1p: fuel cost 7% more per litre (+2.6p);
>   economy improved 3% (−0.5p)". Contributions under 0.2p are grouped as
>   *Other small changes*. The figures are never rounded so that they stop
>   adding up: the total line is the exact sum, and rounding is per line for
>   display only.
> - **Ask Logbook** (§7.26): a `true_cost(vehicles?, period, by_year?)` tool
>   returning the breakdowns, the trend and the *What changed*
>   contributions with their sentences and display strings. So "Why has my
>   BMW got more expensive?" is answered from these figures, and the
>   grounding check applies as usual.
> - **API** (§7.20): `GET /api/v1/vehicles/{id}/true-cost?period=` with the
>   same figures; the summary endpoint gains the 12-month headline.
> - **Access:** everything here needs `ViewCosts` (Phase 19).

---

## Decisions (and why)

- **Use the ledger's groups.** They are already exact, tested and used by
  every report. A new set of categories would make the breakdown disagree
  with the rest of the app.
- **Interpolate depreciation, never extrapolate.** A value between two
  valuations is a fair straight line. A value beyond the last one is a
  guess, and Logbook says so instead of making it.
- **The widget is new, not a fifth tile.** Phase 14.2 deliberately kept the
  pinned card at four tiles. A ranked widget answers the cross-vehicle
  question the user actually asks.
- **Explanations are arithmetic.** "Fuel cost more per litre" and "you drove
  less, so insurance per mile rose" are exact splits of the numbers. AI can
  word them conversationally, but the causes and their sizes come from
  Logbook, consistent with Phase 26.2.
- **A minimum distance for comparisons.** Per-mile figures over a few
  hundred miles swing wildly. 500 km keeps the trend readable without
  hiding real data, which stays in the table.

---

## Tasks

### Spec and docs
- [x] §7.35 in `spec.md`; the depreciation-for-a-period rule in §7.1 beside
      Phase 14.2's; the widget in §7.8; the Phase 32 line in §13.
- [x] `docs/reports.md` (or the README's reports section): true cost, its
      parts, periods, the trend and how *What changed* is worked out.

### Code
- [x] `Service\Report\ValueCurve` (value points, interpolation, the
      no-extrapolation rule, leases).
- [x] `Service\Report\TrueCost` (per period: parts, per distance,
      partial-period labels) on the existing ledger and distance services.
- [x] `Service\Report\CostChange` (per-part change, fuel price and economy
      split, distance effect, exact sums with display rounding).
- [x] Overview card breakdown and period switch; the `true_cost` widget;
      Reports *True cost* tab with charts, table, CSV and print; the
      Expenses tab card.
- [x] Ask tool and API endpoint.
- [x] Translations (en, de), with ICU plurals, units and currency.

### Tests
- [x] **Breakdown:** the five parts add up exactly to Phase 14.2's lifetime
      total per distance for every demo vehicle; finance lines are under
      *Other*; tyre costs under *Maintenance*; module off removes its part.
- [x] **Value curve:** interpolation between two valuations; a period past
      the last point is labelled and stops there; a period wholly after it
      gives "—"; a sold vehicle is exact to the sale date; a lease has no
      depreciation; a gain is negative.
- [x] **Periods:** calendar years in the owner's time zone (a 31 Dec 23:30
      fill-up in the right year); partial-year labels; under 500 km only in
      the table.
- [x] **What changed:** the contributions sum exactly to the change for
      worked examples (a price rise with better economy; a year with less
      driving and the same insurance); the fuel split sums to the fuel part;
      small contributions grouped; display rounding never alters the total.
- [x] **Widget:** ranked, grouped by currency, vehicles without distance
      last, change against the previous 12 months, follows the chip.
- [x] Access: no `ViewCosts` → none of it, in HTML, API or Ask.
- [x] Ask tool returns figures that pass the grounding check.
- [x] Integration suite green on every engine.

### Sample data
- [x] `DemoDataSeeder`: the Golf gets valuations each spring, so its trend
      shows four years with depreciation. One year has a fuel price rise and
      less driving, so *What changed* shows the price, economy and distance
      lines.

### Release
- [x] `CHANGELOG.md` **2.16.0**: true cost per mile, its breakdown, the
      dashboard widget and the yearly trend. Upgrade notes: no migration; the
      widget is appended to existing dashboards.
- [x] Bump `VERSION`, rebuild assets, update the README status.
- [x] Tag `v2.16.0` once merged.

---

## Acceptance criteria

1. A vehicle's overview shows "£0.34/mi" with fuel, maintenance, insurance,
   tax and MOT, other and depreciation beside it, adding up exactly.
2. The dashboard ranks the user's vehicles by true cost per mile for the
   last 12 months, with the change against the year before.
3. The trend shows cost per mile by year, and *What changed* explains each
   year's change in parts that add up to it, such as fuel price, economy,
   distance driven and maintenance.
4. "Why has my car got more expensive?" in Ask Logbook is answered from
   those figures.
5. Definition of done (CLAUDE.md §11) holds.

## Open questions

Answered on 2026-10-05, before the phase was built (the full text is in
`spec.md` §7.1, §7.7, §7.8, §7.35 and §12):

- **#150 Tax years.** *Decided 2026-10-05:* calendar years only. UK tax
  years beside them are parked (spec §12).
- **#151 Depreciation as fixed or per mile.** *Decided 2026-10-05:*
  always time-based, so *What changed* gives it a distance line like
  documents. A per-vehicle mileage-based option is parked (spec §12).
- **#152 Dashboard default period.** *Decided 2026-10-05:* *Last 12
  months*, with *Since bought* one link away.

Found while starting this phase:

- **#153 Documents in short periods.** *Decided 2026-10-05:* the ledger
  dates a document on its start date, so *Last 12 months* could miss a
  renewal paid 13 months ago and a year could hold two. For *Last 12
  months* and the yearly trend, a document with a start and an expiry date
  is spread evenly over its cover by day; *Since bought* stays on the
  ledger date, exactly as Phase 14.2.
- **#154 A gain per distance.** *Decided 2026-10-05:* Phase 14.2 showed no
  depreciation per mile for a gain. It is now a negative part in every
  period, *Since bought* included, which changes Phase 14.2's per-mile
  figure only for vehicles that gained value (called out in the
  changelog).
- **#155 Plug-in hybrids in *What changed*.** *Decided 2026-10-05:* the
  fuel split into price and economy is per energy (litres, kWh, kg), so a
  plug-in hybrid gets a pair for each, still adding up exactly.
- **#156 Insurance payouts.** *Answered:* spec §7.7 already shows them as
  their own *Insurance payouts* line under the groups; the breakdown keeps
  that line instead of folding payouts into a part.
