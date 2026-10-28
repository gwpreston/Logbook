# True cost per mile or km

*What a vehicle really costs to run per mile or km, what that figure is
made of, and why it changed from one year to the next.* Added in v2.16.0
(Phase 32; the full rules are in [`spec.md`](../spec.md) §7.35).

**For:** anyone reading the true cost figures on the dashboard, the overview or Reports.

Logbook already works out each vehicle's **cost of ownership**: its running
costs plus what it has lost in value since you bought it (the overview's
*Cost of ownership* card and **Reports → Cost of ownership**). True cost
splits that figure per mile or km into its parts and works it out for
other periods too.

- [The parts](#the-parts)
- [Distance starts at the purchase date](#distance-starts-at-the-purchase-date)
- [The periods](#the-periods)
- [Where it shows](#where-it-shows)
- [How it's worked out](#how-its-worked-out)
- [What changed](#what-changed)
- [Not included](#not-included)

## The parts

| Part | What it counts |
|---|---|
| **Fuel** | Fill-ups and charging. |
| **Maintenance** | Service records, tyres included. |
| **Insurance, tax and MOT** | Documents with a cost: insurance, road tax, MOT, registration. |
| **Other** | Ad-hoc expenses (parking, tolls, cleaning…) and finance lines (interest and fees, or a lease's rentals). |
| **Depreciation** | What the vehicle lost in value over the period. A gain in value is negative: money back. |

Insurance payouts from a claim are listed on their own line and taken off
the total. A switched-off module's costs are left out, as everywhere else.
The parts are the same groups every report uses, so true cost never
disagrees with the rest of Logbook.

Each part is shown per distance in your unit. The parts and the payouts
always **add up exactly** to the total. Each figure is rounded to the penny
or cent for display only.

## Distance starts at the purchase date

Every distance in Logbook's reports (cost per distance, true cost, cost of
ownership, depreciation per distance, the dashboard's mileage, the fuel
forecast, trips' business share and the API's reports) is measured from
the vehicle's **purchase date**. Readings dated before it, such as the
mileages MOT history brings in from an earlier owner, stay in History and
on the odometer list, but they are not your driving, so they are left out
of every distance. A reading on the purchase day counts. A vehicle with no
purchase date uses all its readings. The averages (per month and per year)
are a different figure, the Mileage tab's, and still use every reading.

## The periods

- **Since bought**: from the purchase date (or the first thing logged) to
  today or the sale date. It is exactly the cost of ownership card's *Per
  distance* figure, split into its parts.
- **Last 12 months**: this month and the 11 before, as in Reports, cut to
  the time you have owned the vehicle. Under 90 days there is no figure.
- **Each calendar year**, in your time zone. The current year, the year
  you bought the vehicle and the year you sold it are partial and say so:
  "2026 so far", "2023 from 14 Mar", "2026 to 12 Mar".

In *Last 12 months* and the yearly figures, a document with a start and an
expiry date is **spread over its cover by day**. A policy renewed 13
months ago still counts for the months it covers, and a year never counts
two renewals. *Since bought* counts each document on its date, as the cost
of ownership card does.

Cover outside the time you owned the vehicle counts in no year: the days
of a policy before you bought it or after you sold it are left out, and
the working under the first year or the year of the sale says how much
("£40 of cover before you bought it isn't counted"). So the years can
differ from *Since bought* either way: a policy that starts before the
purchase date isn't in *Since bought* at all, while one still running at
the sale is in it whole.

### Depreciation for a period

Logbook knows the vehicle's value on a few dates: what you paid, each
valuation you added, and the sale price. Between two of those dates the
value is taken to fall (or rise) in a straight line. A period's
depreciation is the value at its start minus the value at its end.

Nothing is guessed beyond the latest value. A period that runs past it is
measured only up to it ("depreciation to 1 Mar 2026"). A period entirely
after it has no depreciation and shows *running costs only*, with a prompt
to add a valuation. Without a purchase price (a leased car, for instance)
there is no depreciation: the rentals are what the car costs.

**Tip:** add a valuation each year (a part-exchange quote or an online
valuation is enough) and every year of the trend includes depreciation.

## Where it shows

The depreciation in the cost of ownership shows the date of the valuation it rests on ("as of Mar 2026"), and "out of date" once it is older than your *Needs attention* valuation threshold.

- **The vehicle's overview**, on the *Cost of ownership* card: the five
  parts as a bar and a list, with a switch between *Since bought* and *Last
  12 months*.
- **The dashboard widget** *True cost per distance*: every active vehicle
  ranked by cost per mile or km, highest first, with the change against
  the 12 months before ("↑ £0.03/mi"). Vehicles in different currencies are
  ranked separately and never converted. Vehicles without enough mileage
  logged are listed last. It follows the vehicle chips and switches between
  *Last 12 months* (the default) and *Since bought*.
- **Reports → True cost** (`/reports/true-cost`): each vehicle's cost per
  distance by year, stacked by part (partial years hatched), with the table
  beside it, *What changed*, a fleet chart with one line per vehicle, CSV
  export and a printout.
- **The vehicle's Expenses tab**: the same trend and *What changed*.
- **Reports → Cost of ownership** (`/reports/ownership`): four summary
  cards for the vehicles shown (*Total cost*; *Per month*, the vehicles you
  still own added up; *Depreciation*; *Finance interest*, the interest and
  fees of HP, PCP and loan agreements paid so far, never lease rentals),
  then a card per vehicle, highest total first, with the five *Since
  bought* parts as a bar and a legend with each amount and its share. A
  gain in value and insurance payouts are listed under the bar, not drawn.
  Vehicles in another currency get their own set; nothing is converted.
  It always covers the time since each vehicle was bought. Print and CSV
  give the table.
- **The vehicle's Cost of ownership tab** (`/vehicles/{id}/ownership`): the
  same figures for one vehicle as tiles (total, per month, per mile or km,
  how long you have owned it), a row per part with its share, and how it
  is worked out.
- **Ask Logbook** ("Why has my car got more expensive?") and the [REST
  API](api.md) (`GET /api/v1/vehicles/{id}/true-cost`).

A year with under 500 km (311 mi) of driving is shown in the table only:
per-mile figures over a few hundred miles swing too much to compare.

Everything here needs access to the vehicle's costs. A vehicle shared with
you without its costs is left out.

## How it's worked out

Every figure on this page can be opened. Under the cost of ownership, the
true cost of a period and *What changed* there is a *How it's worked out*
line (closed on screen, open when you print). It lists what the figure
rests on, each input with its date and a link to the entry it came from,
then the sum in words with the real numbers, and what Logbook did not
assume.

For depreciation that last part matters: Logbook never estimates what a
vehicle is worth or applies a depreciation rate. It subtracts values you
entered (the price you paid, your valuations and the sale price), and for a
period it draws a straight line between two of them. The working shows
which two, and says so when a period runs past your latest value and is
measured only up to it. The dashboard's *True cost* widget links to the
vehicle's card (*How?*) rather than carry the working itself. On the True cost
report each vehicle opens the working for its latest three years, *Since
bought* and its latest two changes; older years show their figures only.

## What changed

Under the trend, each year is compared with the year before, as long as
both have at least 500 km of driving. The change in cost per distance is
split into contributions that **add up exactly** to it:

- **Each part's change per distance.**
- **Fuel, split into price and economy**, for each kind of energy (a
  plug-in hybrid gets both petrol and electricity):
  - *price*: the change in the average price per litre (or kWh, or kg) ×
    last year's fuel used per distance;
  - *economy*: the change in fuel used per distance × this year's average
    price.

  The two add up exactly to the fuel change, because price × fuel used per
  distance is the fuel cost per distance.
- **Distance**, for the costs that do not depend on how far you drive
  (insurance, tax and MOT, and depreciation): the same amount spread over
  more or fewer miles. "Insurance, tax and MOT +£0.02/mi, because you
  drove 1,185 mi less" is this year's amount ÷ this year's distance minus
  the same amount ÷ last year's distance. The rest of that part's change is
  shown as a change in the amount itself ("£88.46 more spent").
- **Insurance payouts**, when either year had some.

Each line is a fixed sentence, largest first:

> Fuel +£0.003/mi: fuel cost 5% more per litre (+£0.007/mi); economy
> improved by 3% (−£0.005/mi)

Lines under 0.2p a mile (0.2 of the currency's smallest unit per mile or
km) are grouped as *Other small changes*. The total is the exact sum of the
lines; only the display of each line is rounded.

Ask Logbook reads these lines from Logbook and words them. It never works
the causes out itself, and the usual grounding check applies to its answer.

## Not included

- Inflation: amounts are taken as they were paid.
- A forecast of future cost per mile. *Coming up* lists known costs ahead.
- Comparisons with other people's cars or published averages.
- UK tax years beside calendar years, and treating depreciation as
  mileage-based. Both are noted for later (`spec.md` §12).

---

[Back to the docs index](README.md)
