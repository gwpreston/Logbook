# Incidents, damage and insurance claims

Log what happened to a vehicle (a scrape in a car park, a break-in, a
pothole, a breakdown) and keep the claim with it. Repairs, expenses and tyre changes stay
ordinary records that you **link** to the incident, so their cost is counted
once, where it always was.

The **claims history** puts every incident on every vehicle you have had in
one list, sold ones included. It answers the question every insurance quote
asks.

- [Switching it on or off](#switching-it-on-or-off)
- [Logging an incident](#logging-an-incident)
- [Photos keep when and where](#photos-keep-when-and-where)
- [Linking repairs and costs](#linking-repairs-and-costs)
- [The claim](#the-claim)
- [Claims history for insurance quotes](#claims-history-for-insurance-quotes)
- [History, Reports and cost of ownership](#history-reports-and-cost-of-ownership)
- [What the sale pack shows](#what-the-sale-pack-shows)
- [Shared vehicles](#shared-vehicles)
- [API, Ask Logbook, export and backups](#api-ask-logbook-export-and-backups)

## Switching it on or off

Incidents are **on by default**. An admin can switch them off in
**Settings → Modules** (or with `FEATURES_INCIDENTS=false`, see
[configuration.md](configuration.md)). That hides the Incidents tab, *Log
incident*, the *Part of an incident* select, the claims history and
everything below, and keeps every incident. Links on records are left as
they are.

## Logging an incident

**Log incident** is on each vehicle's **Incidents** tab and in *Log entry*.
The tab shows a card per incident (open ones first) with a strip of totals
above them: incidents, claims, what insurers paid and the net cost. Each
card opens the incident's page.
The form has four parts:

- **What happened:** the date (not in the future), the time if you know
  it, where, what kind of incident, a description, whose fault it was, the
  odometer (it goes into the mileage log), and the driver: someone the
  vehicle is shared with, or a name.
- **Damage:** the damaged areas, how bad it was, photos and files, and the
  write-off category if the insurer wrote the vehicle off (Cat N, S, B or A).
- **Other party:** folded away until you need it: their name, registration
  and insurer, and a police reference.
- **Insurance:** see [The claim](#the-claim).

Closing an incident sets *Closed on* to today unless you give a date.

## Photos keep when and where

Every other photo you upload to Logbook is turned upright and **stripped**
of its metadata (time, place, camera), so a receipt photographed on your
driveway never records your address. **Incident photos are kept exactly as
taken.** When and where a photo was taken is evidence an insurer may want.

They are cleaned only when a copy leaves Logbook in the sale pack's
paperwork ZIP (see [below](#what-the-sale-pack-shows)). The stored photo
does not change.

## Linking repairs and costs

The incident page lists its **linked records** and adds up their costs:

- **Add a repair**, **Add an expense** (the excess you paid, a hire car,
  recovery) and **Add a tyre change** open the usual forms with the
  incident already chosen.
- **Link a record** offers your records from the incident's date to 180
  days after it that aren't linked to another incident.
- Every repair, expense and tyre change form has **Part of an incident**.
- A tyre change linked to a service record follows that record. Its cost
  is the record's, so linking the record links both.

*Linked costs* is the sum of those records' costs, exactly as Reports counts
them. *Net cost to you* takes off any payout. When the insurer paid more
than the records add up to, it shows 0 and says so. Unlinking a record never
changes it, and deleting an incident unlinks its records but keeps them.

## The claim

Record where the claim stands (*Not claimed*, *Insurer told*, *Open*,
*Settled*, *Declined*, *Withdrawn*), the insurer and policy, the claim
number, the excess, any payout, whether your no-claims discount is affected,
and the date of the latest news.

- The **insurer** is filled in from the insurance document that was current
  on the incident's date. On a new incident, *Use the policy for this date*
  fills it in again after you change the date.
- Changing the claim status moves *Latest update* to today, unless you
  changed that date too.
- A claim that is *Insurer told* or *Open* with no news for more than 30
  days shows in **Needs attention**: "Claim 4417 with Aviva: no update for
  34 days". It goes when you record news or the claim is settled, and you
  can hide it.
- With reminders on, **Add reminder** on the incident page suggests "Chase
  claim 4417".
- A **repair estimate** has its own field. The incident page shows it as
  "Estimate, not counted in costs": it's what a repair *may* cost. The
  invoice, linked as a repair, is what it did cost.

## Reading insurer letters

Claim news arrives by post and email over weeks. With [reading
files](ai.md#reading-receipts-and-documents) set up, Logbook can read a
letter or an estimate into the incident it's about:

- **Update from a letter** on the incident page reads a letter or estimate
  straight into that incident.
- **Scan a receipt or document** (or *Fill from a file* on *Log incident*)
  recognises an insurer's or broker's letter. When its claim number
  matches one of the vehicle's incidents ("CLM 4417" matches "clm-4417"),
  that incident's edit form opens. Otherwise *Log incident* opens with
  the letter's details.
- The letter fills in the claim status, insurer, claim number, excess,
  payout, write-off category and *Latest update* (the letter's date). Only
  the fields it changes are marked *From the file, check*. The incident
  keeps its own date. The status is read from the letter's words:
  "settled" and "payment issued" mean *Settled*, "declined" and
  "rejected" mean *Declined*. Anything less clear, such as "settlement
  offer" or "under review", is left for you.
- A **repair estimate** fills the estimate and adds "Estimate from
  Coastline Body Repairs" to the notes. Estimates seldom quote a claim
  number, so it goes on the vehicle's most recent open incident, with a
  choice above the form to pick another or start a new one.
- Saving attaches the letter to the incident. Nothing is saved until you
  press **Save**.

## When the car is written off

When the insurer settles a claim as a write-off (*Cat N*, *Cat S*, …),
record the category and *Settled* on the incident. **Archive** then asks
how the car left:

- **Written off** shows the incident, with the sale date and price filled
  in from the settlement: the day the incident was closed (else the latest
  claim update) and the payout. Both can be changed. Saving archives the
  car with the settlement as its sale.
- **Just archive** archives it without a reason, as before.

A written-off car is labelled "Written off 14 Mar 2025" where a sold one
says "Sold": on its garage card and page, in the ownership report and its
CSV, and in History, where the *Written off* milestone names its incident.
In **cost of ownership** the settlement counts once, as the sale price.
It's left out of *Insurance payouts*, and the card says "Settlement counted
as the sale price". **Restore** brings the car back and clears *Written
off*. The sale date and price stay.

Without a settled write-off, *Archive* stays one click. Saving a sale date
on the vehicle's edit form marks it *Sold*, and clearing the date clears
that. A car archived as written off keeps its label even if the incidents
module is switched off later.

## Claims history for insurance quotes

**Claims history** (on the Incidents tab and the Reports page) lists every
incident on every vehicle you can see, **sold and archived ones included**:
date, vehicle, type, fault, driver, claim status, insurer, claim number,
payout and no-claims effect. Insurers usually ask about the last 5 years,
including incidents that weren't your fault and ones on vehicles you no
longer own, so that is the default.

Above the list are four tiles: the claims in the period and how many were
your fault, the time since your last at-fault claim, what insurers paid and
the excess (amounts only where you can see costs). **Copy for insurance
quote** copies the rows you are looking at as plain text, one line each
("12 Mar 2024 – Collision – Not at fault – Claim settled – £1,240.00 – 2019
BMW 320d"), ready to paste into a quote form; it needs JavaScript.

Filter by 3, 5 or 10 years or by dates, by vehicle or driver, by fault, or
to claims only. It prints cleanly (black on white, as a table) and downloads
as CSV. The other party is never included in either.

## History, Reports and cost of ownership

- **History** has an *Incidents* chip. An incident's row lists its linked
  records, and each linked record says which incident it was part of. The
  printed history leaves incidents out unless you tick *Incidents*.
- **Reports** show the same spend as before (a linked repair is still under
  maintenance), plus an *Incidents* section: how many there were,
  *Incident-related spend* and *Payouts received*.
- **Cost of ownership** is net of insurance payouts, with an *Insurance
  payouts* line so the figure is explained. A total loss's settlement is
  the sale price instead (see *When the car is written off*).

## What the sale pack shows

The sale pack has **Include incidents** (off by default). With it on, an
*Incidents* group lists each incident's date, type, damage and the repairs
that fixed it (date and garage), and the ZIP can include incident photos,
cleaned of when and where they were taken. The fault, the claim, payouts,
the driver, the location and the other party are **never** in the pack.

If a write-off category is recorded, the pack's summary says "Recorded as
Cat S (14 Mar 2025)" when incidents are included. When they aren't, a notice
on the screen (never printed) tells you: "This vehicle has a Cat S record. A
buyer's vehicle history check will show it." Logbook leaves the choice to
you, but won't let it look hidden.

## Shared vehicles

Anyone who can view the vehicle sees an incident's date, type, damage,
status, photos and linked records. The fault, driver, location, description,
notes, other party and claim are visible to those who **manage** or **own**
the vehicle, and to whoever logged the incident. Amounts follow *Can see
costs*. Logging an incident needs *Log*, and changing someone else's needs
*Manage*.

## API, Ask Logbook, export and backups

- **API:** `GET` and `POST /api/v1/vehicles/{id}/incidents`, and `GET
  /api/v1/incidents/history`, with the same access rules (see
  [api.md](api.md)). The claim's `repair_estimate` is read and written
  like its payout. A `POST` retried with the same date, type and claim
  number returns the incident already logged.
- **Ask Logbook** answers "Have I had any claims in the last five years?"
  from the claims history. It can also draft an incident for you to check
  and add. Over [MCP](mcp.md), read and write keys get the same draft.
- **Export:** *Export CSV* on the Incidents tab includes every field except
  the other party, the repair estimate included. There is no CSV import.
- **Backups** include incidents, their links, readings and photos, the
  estimate, and a vehicle's *Written off* or *Sold* with its incident.
