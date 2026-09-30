# Trips and mileage claims

Log the business journeys you may claim for, and Logbook works out the
rest: your private mileage, the claim's value at the approved rates, what
your employer paid, and whether the allowance covers what the car costs to
run.

The figures come from your own trips and the rates you keep. They are not
tax advice about what you may claim.

- [Switching it on](#switching-it-on)
- [Logging a trip](#logging-a-trip)
- [Saved journeys and *Log again*](#saved-journeys-and-log-again)
- [Business and private mileage](#business-and-private-mileage)
- [Mileage rates](#mileage-rates)
- [The claim report](#the-claim-report)
- [Several people on one car](#several-people-on-one-car)
- [Import, export, API and backups](#import-export-api-and-backups)
- [What the figures do and don't mean](#what-the-figures-do-and-dont-mean)

## Switching it on

Trips are **off until you switch them on**, because most owners never claim
mileage. An admin switches them on in **Settings → Modules** (or with
`FEATURES_TRIPS=true`, see [configuration.md](configuration.md)). Each
vehicle then gets a **Trips** tab after *Mileage*, and trips appear in:
- *Log entry*;
- the phone app's quick actions;
- a *Business mileage* dashboard widget;
- a *Business mileage* section in Reports;
- the history's *Trips* chip;
- **Settings → Trips**.

Switching the module off hides all of that and keeps every trip, journey
and rate. Switching it back on restores them.

## Logging a trip

**Log trip** (on the Trips tab, in *Log entry*, or from the phone's home
screen) takes one form:

| Field | |
|---|---|
| Date | Today by default. A trip is dated by day, never in the future. |
| Saved journey | Fills in everything below except the date (see next section). |
| From, To | Free text, up to 100 characters each. |
| Return journey | There and back. Type the distance one way; Logbook doubles it. |
| Distance | In your distance unit. 0 is allowed. |
| Odometer at the start and end | Instead of the distance: the distance is the end minus the start. With both a distance and the odometers, they must agree to within 0.5. |
| Business trip | Ticked by default. A business trip needs a purpose. |
| Purpose | Who or what it was for. |
| Passengers | Business passengers you carried, 0 to 8, for the passenger rate. |
| Notes, files | A parking or toll receipt, for example. |
| Save as a journey | Keeps the places and distance for next time. |

A trip **adds no reading to the mileage log**. Trips are dated by day,
often several a day, and typed from memory, so the mileage log stays the
only distance series. The odometer values you type are kept on the trip as
evidence for the claim.

If a trip is longer than the mileage log says the car drove that day (when
there are readings on both sides of the day), Logbook warns you after
saving. It never refuses the trip.

In the UK (and in German) the form reminds you that travel between home
and your usual workplace is normally commuting, not business mileage.
Logbook never judges which trips count.

Archived vehicles take no new trips.

## Saved journeys and *Log again*

A journey you make often can be saved: tick **Save as a journey** when you
log it, or add it in **Settings → Trips → Saved journeys**. A saved journey
has its places, the distance one way, whether it is usually a return, and
its usual purpose. Saved journeys are yours, not the car's. Deleting one
leaves the trips logged from it as they are.

On the trip form, choosing a saved journey fills the form in place. Without
JS, the *Use* button reloads the form filled in (`?journey=<id>`).

**Log again** on any of your trips opens the form with everything copied
except the date (today) and the odometers. Logging a regular journey takes
two taps.

The phone app keeps the trip form and your saved journeys for offline use,
like a fill-up. A trip saved offline is sent when you're back online.

## Business and private mileage

You never need to log private journeys:

- **business** is the sum of the business trips;
- the **total** is the mileage log's distance driven in the period (the
  same figure as Reports);
- **private** is the total minus business.

The Trips tab and the Mileage tab show both for the current tax year.

When your trips add up to more than the mileage log shows, the readings are
too far apart to tell. Private then shows "—" with a note: add an odometer
reading to fix it.

You may log private trips if you like (a day out, for your own records).
They are listed on the Trips tab, but never change the split, which always
comes from the mileage log.

## Mileage rates

**Settings → Trips → Mileage rates** keeps your rates. Each set is in
effect from a date until the next set starts, and each trip uses the set in
effect on its day. That is why a claim for last year keeps last year's
rates when the approved rates change.

A set has:
- a distance unit and a currency;
- a **car rate**, with an optional **threshold** per tax year and the
  **rate after** it;
- optional **bike** and **passenger** rates;
- optional **employer** rates (what your employer pays you);
- a **source**, which is printed on the claim.

**UK users are given HMRC's approved rates** the first time they open
Settings → Trips, the claim report or the Trips tab with no rates of their
own:

| From | Cars (first 10,000 mi) | Cars (after) | Bikes | Passengers |
|---|---|---|---|---|
| 6 Apr 2011 | 45p | 25p | 24p | 5p |
| 6 Apr 2026 | 55p | 25p | 24p | 5p |

These are ordinary rows you can edit or delete. They are given once: delete
them and they don't come back. No update to Logbook ever changes your rates.
When HMRC announces new rates, add a set from the date they apply (the
changelog will mention it). Everyone else starts with no rates and adds
their country's or their employer's.

How trips are valued, each time you open a claim (nothing is stored):
- Only **business** trips are valued, and only the **claimant's own**.
- Each trip uses the set in effect on its date, in that set's unit
  (kilometres convert exactly) and currency.
- **The threshold** counts your business miles across all your cars through
  the tax year, in date order. The trip that crosses it is split: the miles
  below at the car rate, the rest at the rate after. The count restarts
  each tax year, and a rate change mid-year keeps the year's count.
- **Bikes** use the bike rate (or the car rate when there is none), with no
  threshold, and don't count towards the car threshold.
- **Passengers:** passengers × distance × passenger rate.
- **Employer payments:** the employer's car rate × distance for cars, and
  the employer's bike rate for bikes (the car rate when there is none).
- Every line is rounded to the penny, as a claim form would be: each rate
  of a trip (two for a split trip) and its passengers. A trip's amount is
  the sum of its lines, and the totals are sums of those, so everything on
  the claim adds up.

The **tax year** starts on 6 April for UK users and 1 January for everyone
else; change it in Settings → Trips. A UK tax year is labelled "2026/27",
and a calendar year "2026".

## The claim report

**Trips → Claim** (or `/trips/claim`) shows your own business trips for a
tax year (the current one by default), or dates you choose, on all your
vehicles or the ones you tick:
- each trip, oldest first, with its date, registration, journey, purpose,
  distance, passengers, rate and amount (a split trip shows both rates);
- the totals: distance at each rate, the passenger amount and the **total
  approved amount**;
- with employer rates, **Paid by employer** and the difference:
  - *Approved amount not paid (you may be able to claim tax relief on
    this)* when your employer paid less;
  - *Paid above the approved amount* when they paid more.

  The difference compares the approved mileage amount without passenger
  payments, because unpaid passenger payments get no tax relief.
- beside each vehicle, **what the vehicle costs to run** per mile (its cost
  of ownership for the period: running costs plus depreciation, or running
  costs alone when it has no value) next to the claim value per business
  mile. It shows whether the allowance covers the car. It is left out
  ("—") when either figure can't be worked out.

Trips before your earliest rate set have no value, and the report says how
many there are. Rates in different currencies are totalled separately.
Private trips never appear.

**Print** it from the browser. The printed copy starts with your name, the
vehicles and registrations, the period, the rate sets used and their
source, and the date printed. It ends with your declaration (set it in
Settings → Trips) and *Signed* and *Date* lines. **Export CSV** gives the
same rows as `mileage-claim-2026-27.csv`, with amounts as plain decimals.

## Several people on one car

A trip belongs to its driver, who is the person claiming it. Where someone
went is personal, so:
- **drivers see their own trips**. A driver with *Log* or *View* access
  never sees another driver's destinations, and can't open their receipts.
- **the owner and anyone with *Manage*** see everyone's trips on the car.
- **a claim includes only your own trips**, whoever else drove the car.
- the business and private split counts everyone's business trips. A
  driver who can't see some of them sees only the distance driven.
- trips are never listed under the history's *Everything*, in *Recent
  activity*, in the printed history or in the sale pack. They show only
  under the *Trips* chip, to those allowed to see them.

## Import, export, API and backups

- **CSV:** the Trips tab exports every trip on the car (*Manage*), and
  imports the same columns (see [import.md](import.md#trips)). The
  distance is the whole trip, so a return is never doubled twice. A trip
  with the same date, places and distance is already there, and is
  skipped. Imported trips are the importing user's.
- **API:** `GET/POST /api/v1/vehicles/{id}/trips` and
  `GET /api/v1/trips/claim`, with an iPhone Shortcut example that logs a
  saved journey in one tap (see [api.md](api.md#trips)).
- **Backups** carry trips, saved journeys, mileage rates, trip settings and
  receipts. `bin/export-user.php` moves a user's own trips, journeys and
  rates with them.

## What the figures do and don't mean

- The **approved amount** is your trips at the rates you keep. It is what
  those rates allow, not what you are entitled to: that depends on your
  employment and how you travel. The commuting hint is a reminder, not a
  rule.
- **One threshold per person.** The approved rates' 10,000-mile threshold
  applies per employment. Logbook counts one threshold across all your cars.
  With more than one employment, check the figures yourself.
- **Vans** are logged as cars, which have the same approved rates.
- **Company cars**, advisory fuel rates, VAT on fuel and benefit-in-kind
  are not covered.
- Logbook never looks up distances or addresses with a map service, and
  never sends a claim anywhere. Your trips stay on your server.
