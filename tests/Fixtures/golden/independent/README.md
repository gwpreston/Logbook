# Independent script for the golden household (#393)

`golden_script.py` works out every `expected.csv` row whose `source` is
`spreadsheet+script` (finance and depreciation, 114 rows of the 890-row file) a second time, so
they can be compared with the owner's spreadsheet. It writes
`script.csv`, which has the columns `id, figure, viewer, vehicle, period,
script, working`.

## Independence

The script is Python 3 with the standard library only. Money is
`decimal.Decimal`, never a float. It shares no code with the PHP app.

It was written from these sources and nothing else:

- `spec.md`: §7.1, §7.2, §7.7, §7.22, §7.29, §7.32, §7.35 and §8 *Money edge cases*;
- `docs/finance.md` and `docs/reports.md`;
- `../README.md`, `../inputs/*.csv` and `../expected.csv`;
- the decision rows #386–#407 of `docs/phases/open-questions.md`, and the
  owner's decisions #408–#413 (2026-10-12) as written into spec.md and the
  golden README.

Nothing under `src/`, `tests/Support/`, `tests/Unit`, `tests/Integration`,
`templates/` or `config/` was opened. Neither was
`golden-household-example.xlsx` or `build-inputs.php`.

## Running it

    python3 -I tests/Fixtures/golden/independent/golden_script.py

It finds its files relative to its own path. It prints its self-checks:

- each agreement's schedule, discounted at the monthly rate, comes back
  to its amount of credit within pennies, which confirms the reading of
  APR → rate;
- each vehicle's calendar years' depreciation adds up exactly to price −
  current value;
- golf's finance lines add up to its exact cost of credit.

It also prints the number of rows with a value and with `?`.

## Formats

The rules are those of `../README.md`:

- money is rounded once, half away from zero, to 0.01;
- money per distance goes to 0.001, and per mile = per km × 1.609344;
- counts are integers;
- `—` means the app should show no figure.

A minus sign is ASCII `-`. When the script can't choose between readings
that give different figures, the row's `script` is `?` and `working`
lists each candidate.

## Interpretations the script made (each rests on the spec text quoted)

1. **The value curve (#408).** A value point dated D is the value at the
   *start* of D. A period of days a..b loses the value at the start of a
   minus the value at the start of b + 1, cut to the first and latest
   points. A period wholly outside them has none (`—`).
   - §7.1: "A value point is the value at the **start** of its day, so a
     period ending on 31 Dec is measured to the start of 1 Jan, the next
     period's start"
   - The purchase day opens at the price. A valuation or sale dated on the
     purchase day is that day's closing value, so the script places it at
     the start of the next day (focus: 6,450 at the start of 20 Sep 2023,
     6,200 at the start of 21 Sep). §7.1: "the purchase day opens at the
     purchase price, so a valuation (or a sale, #404) dated on the
     purchase day is that day's closing value".
2. **Curve values are rounded to the micro-unit, half up, before
   subtracting.** §7.35: "rounded to the micro-unit (half up) before
   anything is subtracted from it" (#402). The exact curve changes no row.
3. **Periods are cut to the ownership period**, which ends today (15 Sep
   2026) or at the sale. So *Last 12 months* and *2026* stop at 15 Sep or
   19 Aug, and a document's cover after that day doesn't count.
   - §7.35: "Last 12 months (… this month and the 11 before, cut to the
     ownership period)"
   - §7.7: the ownership period "ends on the sale date when one is set,
     else today"
4. **Every document with a start and an expiry is spread by day in true
   cost**, MOT and NCT included. §7.35: "a document with both a start and
   an expiry date is spread evenly over its cover by day". *Since bought*
   counts each document on its start date.
5. **How a day's share is rounded.** Each day's share is floor(cost in
   micro-units ÷ days), and the last day takes the remainder. §7.35: "The
   shares are worked out in micro-units and the last day takes the
   remainder". Rounding the share half up instead, or using an exact
   proportion, changes no row.
6. **Insurance payouts are dated by `occurred_on`.** §7.7: "net of the
   payouts of incidents dated in the period"
7. **Depreciation per distance is measured over its own dates.**
   - For ownership: the loss ÷ the distance from purchase to the value's
     date, both days included (§7.1, whose "lifetime figures above are
     unchanged"). §7.1: "the loss ÷ the distance driven between the same
     two dates"
   - For a true-cost period: the loss ÷ the distance from the period's
     start to the date it is measured to, that day's driving included.
     §7.35: "the depreciation part ÷ the distance driven up to the date it
     is measured to, that day's driving included, even though a value
     point is the value at the start of its day" (#412).
8. **The mileage log** is built from `readings.csv`, fill-up odometers and
   maintenance odometers at 12:00 local. Trips add none.
   - Distance driven is the last reading in the period minus the last
     reading before it, or minus the first reading in it when there is
     none before.
   - "Reaches back" means a reading on or before the later of the
     period's start and the purchase date (§7.7, #405).
9. **Under 90 days in a period, no per-distance figure.** This covers a
   short first or last year (golf 2023 is 86 days, corolla 2023 is 58).
   §7.35: "any period under 90 days once cut to the ownership period, a
   first or last year included, has no per-distance figure" (#410).
10. **Ownership per month (#409)** = running costs ÷ months owned +
    depreciation per year ÷ 12.
    - Per year = loss × 12 ÷ (whole calendar months from purchase to the
      value's date + leftover days ÷ 30.436875).
    - Months owned counts the calendar months the period touches.
    - A gain would have no per year, but no vehicle here has one.
    - §7.1: "the loss × 12 ÷ (the whole calendar months between the dates
      + the days left over ÷ 30.436875 …)"
    - §7.7: "**Per month** = running costs ÷ months owned + depreciation
      per year ÷ 12"
11. **What changed (#411)** is per distance.
    - **For "Y vs Y−1":** the total change is total rate(Y) − total
      rate(Y−1), and the depreciation change is the depreciation part's
      rate(Y) − rate(Y−1). Either is `—` when either year has no rate (under
      90 days, no distance, a log that doesn't reach back) or under 500 km.
    - **For `last_12_months_vs_previous`:** the total change only, against
      1 Oct 2024 – 30 Sep 2025.
    - **A year whose rate is running costs only** (no depreciation part)
      still has a rate, so its total change is a number (golf and corolla
      "2026 vs 2025"; golf `last_12_months_vs_previous`).
    - **A part missing from one year counts as 0**, so the parts add up to
      the total change. Golf "2026 vs 2025" depreciation is −0.154, and
      corolla's is −0.217. §7.35: "a part missing from one year (no
      depreciation after the latest value point) counts as 0 there"
      (#413).
12. **The monthly rate** is (1 + APR)^(1/12) − 1, rounded half up to 10
    places (§7.32); half-even changes no row. Each interest share is
    balance × rate, rounded half away from zero to 0.01 (#390). Every gap
    between payments here is one month.
13. **The golf PCP, settled 15 May 2026:**
    - *Deposits* include the dealer contribution. §7.32: "the deposits
      (the customer's and the dealer's contribution, as UK paperwork
      counts them)"
    - The £10 option-to-purchase fee was not paid. It comes "with the
      final one", and "After a `settlement`, later payments leave the
      schedule". Counting it would give 2157.97.
    - *Payments made* = 31 and *payments remaining* = 0. The settlement is
      an event, not one of the 36 payments.
    - *Cost of credit* = everything paid − cash price = 2147.97. §7.32:
      "Once ended it is **exact**". *Interest so far* is the same, since
      the lines are adjusted to it.
    - The interest shares came to 2134.89, so the end *Adjustment* is
      **+13.08**.
    - This checks out: after 31 payments the balance is 8,799.54. Eight
      days' interest adds 13.06, giving 8,812.60, which is the lender's
      8,812.62 to within 2p.
    - *Amount paid* = deposits + payments made + settlement + fees paid.
14. **The EV HP:**
    - *Total amount payable* is worked out from the figures (30,360.16).
    - The missed 10 Jan 2025 payment was paid late, so it counts on its
      due date.
    - *Settlement*: the 17 remaining payments are discounted by k = 1…17
      whole months, rounding a part month up, and the sum is rounded once.
      Rounding each discounted payment instead changes no row.
    - *Equity* = the 10 Feb 2026 valuation − that settlement.
15. **Time zones.** London and Dublin share their offsets, so no row
    depends on the viewer's zone.

## Open questions

None. Every row has a value. The owner settled the earlier questions:
- *per month* (#409);
- 90 days (#410);
- what changed (#411, #413);
- the value point at the start of its day (#408);
- the depreciation part's distance (#412).
