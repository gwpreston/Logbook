# The golden household (Phase 45.1)

> **Provisional until the loader passes on every engine in CI.** The ids
> in `expected.csv` change whenever the inputs do, so don't start the
> spreadsheet before then.

A realistic household whose money figures are worked out **outside the
code**, then checked against the app to the penny on every database engine
([`docs/phases/phase-45.1.md`](../../../docs/phases/phase-45.1.md), spec.md
§8 *Money edge cases*).

- `inputs/*.csv` are the household: every entry, as a person would type
  it. Your spreadsheet and the golden test both start from these files and
  from nothing else.
- `expected.csv` holds the figures to check, one row each. Your spreadsheet
  fills its `expected` column (#393). For finance and depreciation, an
  independent script also fills it, and the two are compared.
- `inputs/attachments.csv` lists the few entries that carry a receipt or
  certificate, kept in `uploads/`. They change no figure; they are there
  so a backup and restore (Phase 45.2) carries files too.
- `build-inputs.php` regenerates `inputs/` from
  `tests/Support/Golden/InputBuilder.php`, which is seeded so it gives the
  same files every time. The CSVs are the fixture and are committed. After
  changing the builder, review the diff before touching the spreadsheet.

## Today

**Today is 15 September 2026, 08:00 UTC** (09:00 in London and Dublin).
The golden test pins the app's clock to that instant. Every "so far",
*Last 12 months*, *Coming up*, settlement and digest figure is as of then.

- *Last 12 months* is October 2025 to September 2026 (this month and the
  11 before).
- The digest's *Last month* is August 2026.
- The current tax year is 2026–27.

## People

| user | locale | zone | currency | shows distance in | tax year starts |
|---|---|---|---|---|---|
| alex | en_GB | Europe/London | GBP | miles | 6 April |
| sam | en_IE | Europe/Dublin | EUR | km | 1 January |

**Alex** owns the Golf, the EV and the Focus, and has a *View* share of the
Corolla with costs.

**Sam** owns the Corolla, and has a *Log* share of the Golf **without
costs**. Both shares have *Send me its reminders* ticked, so a shared car
is in its member's digest and *Coming up*. Sam adds some of the Golf's fill-ups. Sam must never get an amount
for the Golf (the `hidden.*` row).

## Vehicles

- **golf**: VW Golf 1.5 TSI, petrol, bought used on 7 Oct 2023 for
  £17,995, on PCP at 6.9% APR: 36 payments of £284.85 and a £7,650
  balloon. Each agreement's payment is worked out from its APR, so the
  terms agree with each other.
  - The PCP was settled early on 15 May 2026 for the lender's quote of
    £8,812.62, balloon included.
  - Three valuations. The latest, 30 Jun 2025, is stale by today.
  - Two services and a pair of tyres.
  - On 18 Sep 2025 the MOT failed (£54.85). The brakes were repaired the
    next day and the retest was free: a £0 document, which is not a ledger
    line.
  - Alex's business trips cross the 10,000-mile band in 2024–25 and again
    in 2025–26. The second time is after a rate change on 1 Oct 2025.
    There are trips on 5 and 6 April 2025.
  - Alex fills up at 23:30 on 31 Aug 2025.
- **ev**: Kia Niro EV, bought 10 Feb 2024 for £27,500 on HP at 5.9% APR,
  48 payments of £549.17.
  - The HP is still running today.
  - The payment due 10 Jan 2025 was missed, then paid late on 24 Jan.
  - Home charging at 24.5p/kWh, then 7.5p/kWh from 1 Apr 2025.
  - Public AC at 52p and DC at 79p, all partial.
  - A free charge on 14 Jun 2025 (£0).
  - Vehicle tax was £0 until April 2025. That £0 expense counts.
- **focus**: Ford Focus diesel, bought 20 Sep 2023 for £6,450.
  - A valuation dated on the purchase day, £6,200. It is that day's
    closing value, so the £250 drop falls in 2023 (#389).
  - A collision on 8 Nov 2024: repair £1,430 (maintenance), insurance
    payout £1,180, excess £250.
  - Sold on 19 Aug 2026 for £3,350, above its last valuation of £3,150
    (1 Mar 2026). August 2026 is the digest's month, so the sold car is
    in *Last month* (#374).
- **corolla**: Toyota Corolla hybrid, Irish-registered, bought by Sam on
  4 Nov 2023 for €21,400.
  - Everything is in euros and is never converted.
  - Sam fills up at 23:30 on 31 Mar 2024, the day summer time started.

## Conventions in the inputs

- **Distances and odometers** are km to 3 places.
  - A trip's `distance_km` is the whole trip (a return is already
    doubled), converted exactly from the miles Alex typed
    (1 mi = 1.609344 km).
  - Per-mile figures divide by km ÷ 1.609344.
  - **`distance_km` is the trip's distance.** `typed_miles_one_way` is
    only what Alex typed. 236 mi becomes 379.805 km, which is 235.9999
    mi back, so work claims from `distance_km`, as the app does.
- **Volumes** are litres, or kWh for the EV, to 3 places.
- **Prices per unit** are to 3 places.
- **Amounts** are in the vehicle's currency to 2 places. A fill-up's
  `total_cost` is what the pump printed (volume × price, rounded half up to
  the penny), not to be recomputed.
- **`at`** is a local wall-clock time in the zone of the user in `by`. A
  **date** is a calendar date, never shifted.
- **Maintenance** is entered with its odometer and is recorded at 12:00
  local.
- **Insurance** runs a year from `start_on`. Its cost counts on `start_on`
  in Reports and the ledger, and is spread over its cover by day in true
  cost (spec §7.35).
- **Finance events:**
  - `settled`: the agreement was settled early on `date` for `amount`.
    This is one event, the settlement payment and the end together, as
    *End agreement* records it.
  - `missed`: the payment due on `due_on` was not made.
  - `paid_late`: that payment was paid on `date`.

## The rules your spreadsheet follows

These are the spec as decided on 2026-10-11 (spec §8 *Money edge cases*).
Where they differ from what the app did before Phase 45.1, the app will be
fixed to match.

- **Rounding.** Sum exactly. Round once, half away from zero, to the
  currency's minor unit, only for the figure in `expected.csv`.
  Per-distance figures keep one more place: £0.344/mi. Each row is the
  exact figure rounded once, even where the app shows it as a part
  beside a total: on screen such parts are nudged by a minor unit so they
  add up to the total shown (#403, #406), and a property test checks
  that, not this spreadsheet.
- **Months and years** follow the viewer's own time zone (#386). Alex and
  Sam share the same offset, so this changes nothing here.
- **Cost per distance needs readings that reach back** to the period's
  start, or to the vehicle's purchase date when that is later (#405), on
  Reports and the digest as well (#376, #398, #399). Otherwise the figure
  is blank: leave `expected` as `—`. A fleet's figure adds up only the
  vehicles whose readings reach back (#398).
- **The digest's *Last month*** keeps a vehicle archived during that month
  (#374). The fleet spend covers only vehicles whose costs the viewer sees
  (#375).
- **Depreciation for a period** is the value at its start minus the value
  at its end, interpolated by day between the price, the valuations and the
  sale. There is no extrapolation past the latest point. The purchase day
  opens at the price: a valuation (or a sale, #404) that day is its
  closing value (#389).
- **Finance** rounds to the currency's minor unit (#390). A settled
  agreement's interest lines are adjusted on the end date to the exact cost
  of credit, and that adjustment may be negative (#387). A group below
  zero has no share; the others' shares are of the groups above zero.
- **Insurance payouts** reduce cost of ownership and true cost, and may
  make a total negative (#400).

## The example spreadsheet

`golden-household-example.xlsx` is a starting layout, not the answers.
It has every input as its own tab (blue), a Ledger and a Reports tab
worked from them, helper columns for economy segments and tax years, and
an `expected` tab with all the rows of `expected.csv`. A few rows carry an
example formula, written by Claude Code to show the pattern: they share
Claude's reading of the spec, so check or rewrite each one before you
trust it (#393). The rest are yours (yellow). When done, save the
`expected` tab alone as CSV (UTF-8), keeping its header, over
`expected.csv`. If the inputs change, rebuild its input tabs.

## expected.csv

| column | meaning |
|---|---|
| `id` | row number, kept stable once the spreadsheet uses it |
| `figure` | what is checked, e.g. `true_cost.depreciation`, `claim.amount` |
| `viewer` | whose view: alex or sam |
| `vehicle` | golf, ev, focus, corolla, `fleet` or `all` |
| `period` | `since_bought`, `last_12_months`, a year, `tax_year 2024-25`, `2026-08`, `today`, `segment ending <at>` |
| `currency` | the figure's currency, blank for distances and economy |
| `unit` | `money`, `money/mi`, `money/km`, `money/month`, `mi`, `km`, `count`, `l/100km`, `kWh/100km` |
| `expected` | your figure; `—` when the app should show none |
| `source` | `spreadsheet`, or `spreadsheet+script` (finance and depreciation, compared) |
| `notes` | anything the figure needs, e.g. `at_least` for a *Coming up* total with unknown costs |

Precision in `expected`:

- money to the currency's minor unit;
- money per distance to one more place;
- distance to the whole unit;
- economy to 2 places.

The golden test (`GoldenHouseholdTest`) reports every mismatch at once, naming the
id, figure, viewer, vehicle and period with both values. Numbers compare as
numbers (`12.5` equals `12.50`, `1,234.50` equals `1234.50`); a *Coming up*
total with an unknown cost is written `at_least 123.45`. A row whose
`expected` is empty never counts as a pass: the test ends incomplete
("N of 864 expected figures are not filled in yet"). `php
tests/Fixtures/golden/app-figures.php > app-figures.csv` prints the app's own
figure for every row (`id,actual`) to compare with the spreadsheet; it never
writes `expected.csv`. A figure no service exposes reads as `(no reader)` (see
`GoldenFigures::UNREADABLE_FIGURES`) and counts as a mismatch once its row is
filled. A mismatch is settled in
`docs/phases/open-questions.md`: the spreadsheet, the code or the spec was
wrong (45.1.4).
