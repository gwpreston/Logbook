# Independent script for the golden household (#393)

`golden_script.py` works out every `expected.csv` row whose `source` is
`spreadsheet+script` (finance and depreciation, 96 rows) a second time, so
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
- the decision rows #386–#407 of `docs/phases/open-questions.md`.

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

1. **The value curve's days.** A value point is the closing value of its
   day, and the purchase day opens at the price. A period's depreciation
   is the opening value of its first day minus the closing value of its
   last day. The opening value is the previous day's close, or the price
   on the purchase day.
   - §7.1: "the purchase day opens at the purchase price, so a valuation
     (or a sale, #404) dated on the purchase day is that day's closing
     value"
   - §7.1: "the periods' depreciation always adds up to *Since bought*"
   - Reading "value at its start" as the start date's own closing value
     would lose a day's drop at every boundary.
2. **Curve values are rounded to the micro-unit, half up, before
   subtracting.**
   - §7.35: "rounded to the micro-unit (half up) before anything is
     subtracted from it" (#402)
   - The exact curve gives the same figures in every row; the script
     checks this.
3. **Periods are cut to the ownership period.** That period ends today,
   15 Sep 2026, or at the sale. So *Last 12 months* and *2026* stop at
   15 Sep or 19 Aug, and a document's days of cover after that day don't
   count.
   - §7.35: "Last 12 months (… this month and the 11 before, cut to the
     ownership period …)"
   - §7.7: the ownership period "ends on the sale date when one is set,
     else today"
4. **Every document with a start and an expiry is spread by day in true
   cost**, MOT and NCT included, not only insurance as `../README.md`
   says.
   - §7.35: "a document with both a start and an expiry date is spread
     evenly over its cover by day"
5. **How a day's share is rounded.** Each day's share is
   floor(cost in micro-units ÷ days), and the last day takes the
   remainder. Rounding the share half up instead changes no row.
   - §7.35: "The shares are worked out in micro-units and the last day
     takes the remainder"
   - An exact proportion (cost × days ÷ cover) is *not* the spec's rule.
     It would move row 172 (corolla 2025 true cost) from 4332.52 to
     4332.53, and `working` says so. A spreadsheet that spreads by exact
     proportion will differ there.
6. **Insurance payouts are dated by the incident's date** (`occurred_on`,
   8 Nov 2024), not by the date it was settled or closed.
   - §7.7: "net of the payouts of incidents dated in the period"
7. **Depreciation per distance is measured over its own dates.**
   - For ownership it is the loss ÷ the distance from purchase to the
     value's date. §7.1: "the loss ÷ the distance driven between the same
     two dates"
   - For a true cost period it is the loss ÷ the distance from the
     period's start to the date it is measured to. §7.35: "the
     depreciation part ÷ the distance driven up to the date it is measured
     to"
   - The running part divides by the whole period's distance.
8. **The mileage log** is built from `readings.csv`, fill-up odometers and
   maintenance odometers at 12:00 local. Trips have no odometers and add
   none.
   - Distance driven is the last reading in the period minus the last
     reading before it, or minus the first reading in it when there is
     none before (§7.7).
   - "Reaches back" means a reading on or before the later of the
     period's first day and the purchase date (§7.7, #405).
9. **The monthly rate** is (1 + APR)^(1/12) − 1, rounded half up to 10
   places (§7.32). Half-even changes no row. Each interest share is
   balance × rate, rounded half away from zero to 0.01 (#390). Every gap
   between payments here is exactly one month, so simple and compound
   interest over a gap can't differ.
10. **The golf PCP, settled early on 15 May 2026:**
    - *Deposits* include the dealer contribution. §7.32 defines "the
      deposits (the customer's and the dealer's contribution, as UK
      paperwork counts them)", and cash price = deposits + credit.
    - The £10 option-to-purchase fee was **not paid**. §7.32 puts it "with
      the final one", and "After a `settlement`, later payments leave the
      schedule". Counting it would give a cost of credit of 2157.97.
    - *Payments made* = 31, the scheduled payments due on or before the
      end date. The settlement is an event, not one of the 36. §7.32:
      "*Payments made* and *Payments remaining* ("18 of 48 remaining")".
      *Payments remaining* = 0.
    - *Cost of credit* = everything paid − cash price = 2147.97. §7.32:
      "Once ended it is **exact**: for `settled` …, everything paid
      (deposits, payments, extras, settlement, fees) − cash price"
    - *Interest so far* is the same 2147.97. The lines are "adjusted so
      their total equals the exact cost of credit, the difference falling
      on the end date". The interest shares came to 2134.89, so the
      adjustment is **+13.08**, not negative.
    - *Amount paid* is the *Paid so far* tile: "deposits, payments made,
      extras, settlement and fees".
11. **The EV HP:**
    - *Total amount payable* isn't entered, so it is worked out:
      deposits + payments + final + fees. docs/finance.md: "Leave the
      total amount payable blank to have it worked out from the other
      figures"
    - The missed payment of 10 Jan 2025 was paid late, so it counts as
      paid, with its interest on its due date. §7.32: "a paid-late one on
      its due date"
    - *Settlement estimate*: each of the 17 remaining payments is
      discounted by k = 1…17 whole months, rounding a part month up
      ("one due tomorrow, or in exactly a month, is one month away"). The
      sum is rounded once. Rounding each discounted payment instead
      changes no row.
    - *Equity* = the 10 Feb 2026 valuation − the rounded estimate. That
      valuation is within 12 months, as equity needs.
12. **`true_cost.what_changed.*` rows are amounts, not rates per
    distance.** Their `unit` is `money`. Each is (last 12 months) −
    (the 12 months before, 1 Oct 2024 – 30 Sep 2025), for the total or
    the depreciation part. §7.35's sentences give the change in amount
    ("£88.46 more spent"; "lost £187.23 more in value"). Its *What
    changed* lines are otherwise per distance and for calendar years, so
    this is a reading of the `unit` column.
13. **Time zones.** A timed entry is dated in the viewer's zone, and
    finance in the owner's. Europe/London and Europe/Dublin share their
    offsets, so no row depends on this (#386, #397).
14. **There is no minimum span for a period's depreciation part per
    distance.** §7.35 says "the depreciation part ÷ the distance driven up
    to the date it is measured to" and states no minimum span. §7.1's
    90-day condition is for the lifetime figures. Two parts are measured
    over short spans:
    - EV 2026, to 10 Feb: 41 days;
    - corolla's last 12 months, to 1 Nov 2025: 32 days.
    The script divides by those spans as written.

## Open questions (rows left as `?`)

Each of these changes a figure, and the spec doesn't settle it.

### Q1. Ownership per month: which formula, and how many years? (rows 5, 10, 15, 20, 24)

Two passages give different formulas:

- §7.7: "**Per month** = running costs ÷ months owned + depreciation per
  year ÷ 12".
- §7.7, on the *Cost of ownership* page: "each vehicle's total ÷ its own
  owned months, as the table".

Depreciation per year is, in §7.1, "the loss ÷ the years from the purchase
date to the **value's date** … counted in calendar years, months and
days". The spec never says how years, months and days become one number.

The candidates for golf are:

| Reading | Golf per month |
|---|---|
| running ÷ 36 + loss ÷ (days ÷ 365.2425) ÷ 12 | 569.19 |
| running ÷ 36 + loss ÷ (days ÷ 365.25) ÷ 12 | 569.20 |
| running ÷ 36 + loss ÷ (days ÷ 365) ÷ 12 | 569.05 |
| running ÷ 36 + loss ÷ (y + m/12 + d/365.2425) ÷ 12 | 569.29 |
| total ÷ 36 | 475.54 |

The `working` column gives every candidate for every vehicle.

**Recommendation:** use §7.7's formula, with days ÷ 365.2425. That is the
year §7.2 uses for "age in years (days ÷ 365.2425)". Then fix the
*Cost of ownership* page sentence, or the formula, so the two agree.

### Q2. Does the 90-day rule apply to a calendar year's per distance? (rows 38, 159)

§7.35 states it only for *Last 12 months*: "cut to the ownership period;
under 90 days, no per-distance figure, as §7.7". For years, the trend
instead keeps "a year with under 500 km … in the table only".

Two years are affected:

- golf 2023, 86 days: 0.624 /mi, or `—`;
- corolla 2023, 58 days: 0.622 /mi, or `—`.

**Recommendation:** decide whether a short first year shows a rate. The
spec's wording, read literally, gives the figure.

### Q3. What changed for golf, when the last 12 months have no depreciation (rows 64, 65)

Golf's latest value point is 30 Jun 2025. The last 12 months (from 1 Oct
2025) lie wholly after it, so their depreciation is `—` and their total is
"running costs only". The 12 months before do have depreciation (1,506.90).

- **Row 64 (depreciation):** `—`, or −1506.90 (counting the missing part
  as 0).
- **Row 65 (total):** `—`, or −3169.59 (3,356.92 running only, minus
  6,526.51).

The widget's rule is "nothing when either period has no figure", but that
is about the rate per distance.

**Recommendation:** `—` for both, since one side isn't a complete figure.

## Not a question, but worth knowing

The script also runs two readings that the spec rules out. When either
one moves a row, it adds a `NOTE:` to that row's `working`.

- **Exact proportion.** A spreadsheet that spreads documents by exact
  proportion gets 4332.53 in row 172, where the spec's micro-unit shares
  give 4332.52 (item 5).
- **Insurance only.** A spreadsheet that spreads only insurance would
  differ in 24 rows: 44, 45, 51, 52, 71, 72, 92, 93, 99, 105, 107, 121,
  123, 129, 137, 139, 145, 151, 152, 172, 173, 179, 180 and 186. It would
  count the MOT and NCT on their start dates, as `../README.md`'s
  *Insurance* bullet suggests. §7.35 spreads every document with both
  dates (item 4).
- **The golf settlement checks out.** After 31 payments the golf
  balance is 8,799.54. Eight days' interest to 15 May adds 13.06, giving
  8,812.60, which is the lender's 8,812.62 to within 2p. That is why the
  end *Adjustment* is +13.08 here, not negative.
