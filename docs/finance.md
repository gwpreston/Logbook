# Finance and lease agreements

If your car is on hire purchase, PCP, a personal loan or a lease, you can
type the agreement into Logbook from its paperwork. From those figures
Logbook works out:

- the payment schedule;
- how many payments are left, and what remains to pay;
- what settling now would roughly cost, or your lender's own quote;
- what the credit costs you;
- for hire purchase and PCP, when you reach half the total amount payable;
- your equity: what the car is worth against what you owe;
- for PCP and leases, where your mileage stands against the allowance, and
  the excess charge it is heading for.

The interest, fees or rentals then count in your costs automatically, once
each.

Logbook shows figures, **never financial advice**. Every estimate says it
is one. Your agreement and your lender have the final word.

- [Switching it on or off](#switching-it-on-or-off)
- [Entering an agreement from the paperwork](#entering-an-agreement-from-the-paperwork)
- [The schedule: payments assumed paid](#the-schedule-payments-assumed-paid)
- [What each figure means](#what-each-figure-means)
- [Estimates and your lender's quote](#estimates-and-your-lenders-quote)
- [Mileage against the allowance](#mileage-against-the-allowance)
- [Ending an agreement](#ending-an-agreement)
- [Selling or archiving with finance](#selling-or-archiving-with-finance)
- [Coming up, reminders and Needs attention](#coming-up-reminders-and-needs-attention)
- [Costs: counted once](#costs-counted-once)
- [Moving from manual finance expenses](#moving-from-manual-finance-expenses)
- [Who can see it](#who-can-see-it)
- [The dashboard, the API and Ask](#the-dashboard-the-api-and-ask)
- [Export and backups](#export-and-backups)

## Switching it on or off

Finance is **on by default**. Nothing shows until a vehicle has an
agreement. An admin can switch it off in **Settings → Modules** (or with
`FEATURES_FINANCE=false`, see [configuration.md](configuration.md)). That
hides every finance page, the overview card, the dashboard widget, the
cost lines, the *Coming up* lines, the reminders and the *Needs
attention* items, and keeps every agreement.

## Entering an agreement from the paperwork

Finance is a **tab** on the vehicle's page, between *Incidents* and
*Expenses*. Before there is an agreement it shows one card, *How did you
buy it?*, with **Add finance**. A vehicle has at most one active
agreement.

Once there is one, the tab is that agreement's page:

- **The agreement card:** the type, the lender and the agreement number's
  last 4 characters, *Edit*; the monthly payment; a progress bar
  ("Payment 18 of 36", "Ends Jan 2028"); two tiles, **Paid so far** (the
  deposits, payments made, extra payments, any settlement and the fees
  paid) and **Still to pay** (with a PCP's optional final payment beside
  it); then the figures (see [below](#what-each-figure-means)). A PCP adds
  a note on what you can do at the end: pay the optional final payment
  and keep the car, hand it back, or part-exchange it.
- **Purchase:** the vehicle's purchase price and date, *Bought from*,
  *Mileage when bought* (all from the vehicle form) and how it was paid.
- **Value & equity** (not for a lease): the current value (the latest
  valuation, or the sale price once sold), the settlement figure and your
  equity.
- Then the schedule, extra payments, settlement quotes, *End agreement*,
  *Delete*, *Print* and the schedule's CSV.
- **Earlier agreements** below, each opening the same page for that
  agreement. With no active agreement, *How did you buy it?* sits above
  them.

The add, edit and end pages keep their addresses and open within the tab.
The overview's *Finance* card stays.

Choose the type first. Each type asks only for what its paperwork gives:

| Type | You enter |
|---|---|
| **Hire purchase** | Lender, agreement number, cash price, your deposit, any dealer deposit contribution, amount of credit, APR, number of monthly payments, first payment date, monthly payment, first payment if different, a final payment if there is one, fees, total amount payable |
| **PCP** | As hire purchase, with the **optional final payment (GFV)**, plus the annual mileage allowance, the excess mileage charge and the odometer at the start |
| **Personal loan** | Lender, amount of credit, APR, number of payments, first payment date, monthly payment, fees, total amount payable |
| **Lease** | Lessor, initial rental, number of monthly rentals after it, first rental date, monthly rental, fees, annual mileage, excess charge, odometer at the start |

A few things to know:

- **Monthly payments only.** Weekly and four-weekly agreements aren't
  supported.
- **0 is a valid amount** and a valid APR (interest-free deals).
- **Leave the amount of credit blank** to use the cash price less the
  deposits. **Leave the total amount payable blank** to have it worked out
  from the other figures.
- **The final payment date** defaults to one month after the last monthly
  payment.
- **The agreement number** is shown as its last 4 characters everywhere
  except the edit form. It never appears in exports, the API or Ask
  Logbook.
- **Purchase price.** With hire purchase or PCP, and no purchase price on
  the vehicle yet, the form offers to set it to the cash price. With a
  lease, if the vehicle has a purchase price, the form warns that a leased
  car has none and offers to clear it (its rentals are what it costs).

**The figures are checked.** If you enter the total amount payable and it
differs by more than 1.00 from what the other figures add up to (the
deposits, including any dealer contribution, + payments + final payment +
fees), the agreement page says so: "These figures add up to £18,412.40,
but the agreement says £18,512.40. Check the paperwork." The cash price
less the deposits is checked against the amount of credit in the same way.
The check is a warning only and never stops you saving.

## The schedule: payments assumed paid

The schedule is worked out from the agreement every time you look, never
stored:

- payment 1 on the first payment date (with the first payment's own
  amount if it differs);
- the rest monthly on the same day of the month, moved to the month's last
  day when the month is shorter (31 Jan, 28 Feb, 31 Mar);
- then the final payment, if there is one;
- for a lease, the initial rental falls on the agreement date.

Nearly every agreement is paid by direct debit, so **a payment counts as
paid once its date has passed**, judged by the vehicle owner's time zone.
You record only the exceptions, on the agreement page:

- **Mark missed** on a payment that didn't go out. It stays owed.
- **Mark paid late** on a missed payment when you pay it, with the date if
  you like.
- **Add extra payment** for anything you pay on top of the schedule.

*Undo* removes a mark.

## What each figure means

| Figure | What it is |
|---|---|
| **Payments remaining** | "18 of 48 remaining". A PCP's optional final payment is shown beside it: "plus the optional final payment of £9,450". |
| **Remaining to pay** | The scheduled payments still due (missed ones included). **Exact**, straight from the agreement. For PCP the optional final payment is shown beside it, not in it. |
| **Settlement** | What settling now would roughly cost (see [below](#estimates-and-your-lenders-quote)). Not for leases. |
| **Cost of credit** | Hire purchase and PCP: the total amount payable less the cash price. Loans: less the amount of credit. The interest so far is estimated while the agreement runs. |
| **Half-paid point** | Hire purchase and PCP: the date the deposits and payments reach half the total amount payable, or what is still needed to reach it. Your agreement explains your rights at this point. Check with your lender. |
| **Equity** | Your latest valuation less the settlement figure: "Positive equity £2,140" or "Negative equity £1,380". It needs a valuation from the last 12 months; add one from the Valuations page. |

**Remaining to pay and settlement answer different questions.** Remaining
to pay is everything left on the schedule. Settling early costs less,
because you no longer pay the interest for the months you skip. Mixing
the two up is the commonest mistake about car finance, so Logbook always
shows both.

## Estimates and your lender's quote

Logbook's settlement figure is an **estimate**. It is the present value of
what is still owed: each remaining payment discounted at the monthly rate
that comes from the APR, ((1 + APR)^(1/12) − 1), less any extra payments
you have made. It is labelled "Estimated. Your lender's settlement figure
will differ; ask them for a quote."

Lenders work the real figure out by their own rules, and some add extra
interest for settling early. When you get a quote, enter it under
**Settlement quotes** with its date and the date it is valid until. While
it is valid it **replaces the estimate** everywhere ("£7,612.08, quoted
3 Oct, valid until 31 Oct"). Once it expires, the estimate comes back.

## Mileage against the allowance

Your lender knows your allowance; only Logbook knows your odometer and how
far you usually drive. For a PCP or lease with an annual mileage
allowance, the agreement page and the overview card show:

- **the allowance over the whole agreement:** the annual allowance × the
  agreement's months ÷ 12, counting from the agreement date to the end
  date (a lease of an initial rental and 35 rentals runs 36 months: the
  car goes back a month after the last rental);
- **the distance so far:** your latest reading less the odometer at the
  start (as you entered it, else the reading nearest the agreement date),
  and how much of the allowance you would have used by now at an even
  pace;
- **where you are heading:** your latest reading plus your average daily
  distance (from the mileage log, as for maintenance) for the days left.
  Over the allowance: "On track for 31,200 mi against 30,000. About £108
  in excess mileage at £0.09 a mile." Under it: "On track to finish 2,400
  mi under the allowance."

The projection needs a week of readings; until then you see the distance
so far only. Distances are in the agreement's own unit (miles or km),
whatever your display unit. The projection is an estimate, and says so.

## Ending an agreement

**End agreement** on the agreement page asks how it ended and when:

| Outcome | For | What happens |
|---|---|---|
| **Settled early** | HP, PCP, loans | Enter what you paid (prefilled from your lender's quote, or the estimate). Later payments leave the schedule. |
| **Completed** | HP, PCP, loans | Every payment was made (for PCP, the optional final payment too); the date is on or after the last payment. The vehicle is yours. |
| **Handed back** | PCP | You returned the car instead of paying the optional final payment. |
| **Lease ended** | Leases | The car went back to the lessor. |

Handing back and ending a lease take two optional amounts, the **excess
mileage charge** (prefilled from your mileage when you are over) and
**damage charges**. Each is logged as a *Finance and lease* expense on the
end date. Then, if you own the vehicle, Logbook takes you to **Archive**
with *Returned to the lender* or *Returned to the lessor* chosen.

Once ended, the agreement's costs are **exact**: everything you paid less
the cash price (less the amount of credit for a loan). A PCP handed back
is treated as **sold at its optional final payment**: you paid the cash
price less the final payment you didn't pay, so lifetime cost of ownership
comes out right without a special case. Ending marks the agreement's
reminders done.

## Selling or archiving with finance

While a vehicle has an agreement, **Archive** opens a short page instead
of archiving in one click:

- **Sold**, with the sale date and price. With an active hire purchase or
  PCP it warns: "This agreement is still active. The lender owns the car
  until it is settled." **Settled from the sale** (ticked) takes the
  settlement amount and ends the agreement as settled early on the sale
  date.
- **Returned to the lender** (PCP): the sale price is the optional final
  payment, and an active agreement ends as handed back on that date.
- **Returned to the lessor** (lease): no sale price; an active lease ends
  on that date.
- **Written off**, when the vehicle has a settled write-off (see
  [incidents.md](incidents.md)).
- **Just archive**, which leaves the agreement as it is.

*Restore* clears the disposal, as for any archived vehicle; the agreement
stays ended.

## Coming up, reminders and Needs attention

- ***Coming up*** shows the next 12 months' payments of each active
  agreement as one line per vehicle ("Finance payments, 12 × £312.40"),
  plus a final payment inside the 12 months as its own item. They count in
  the expected total, each payment in its own month.
- **Reminders:** the final payment, due on its date with your document
  lead time, and for PCP and leases *Agreement ends: decide what to do*,
  90 days before the end. None for regular payments, which go by direct
  debit. Ending the agreement marks them done.
- ***Needs attention*** lists a payment marked missed (and not paid late)
  as a *Now* item, and a PCP or lease heading more than 2% over its
  allowance as a check: "Heading for about 1,200 mi over your allowance:
  about £108". *Hide* keeps it hidden until the projection moves by about
  100.

## Costs: counted once

With **Count the credit charges in my costs** (for a lease, **Count the
rentals in my costs**) ticked, the default, the agreement adds lines
to the cost ledger, in the *Finance and lease* category. They come
straight from the agreement and are never stored:

- **Hire purchase, PCP and loans:** each payment's **interest share** and
  the fees, on their dates. **Never the capital**, the part of each
  payment that pays off the price, because the vehicle's purchase price
  already counts it. While the agreement runs, the interest is split from
  each payment at the APR's monthly rate, so it is an estimate. Once the
  agreement has ended, one adjustment on the end date makes the lines add
  up to the exact cost of credit.
- **Leases:** every rental and fee, on its date.

Only payments already made count, so next month's payment never shows in
this month's report. The lines count in Reports, the Expenses tab's
totals, cost of ownership and cost per mile, exactly as a finance expense
you logged would. In the Expenses tab they say "From the finance agreement"
and link to it.

Untick it to keep the agreement for its figures only.

## Moving from manual finance expenses

Before this, finance was a manual expense category, and you had to
remember not to log the payments that pay off a purchase price. Your
existing *Finance and lease* expenses are unchanged.

When you add an agreement, Logbook looks for manual *Finance and lease*
expenses in the months it covers. The agreement page and the Expenses tab
then say "Finance and lease expenses logged in these months may count
twice with this agreement", list them, and link to each. Either:

- **delete them**, and let the agreement count the interest or rentals;
  or
- **keep them**, and edit the agreement to stop counting it in your
  costs.

## Who can see it

Finance is about money and ownership, so only people who can **manage**
the vehicle **and** see its costs can see or change its agreements. For
anyone else the finance pages don't exist (404), there is no Finance tab,
and there is no card.

Someone who sees the vehicle's costs without managing it still has the
agreement's lines in their totals, so everyone sees the same figures. To
them each line is a plain *Finance and lease* line, with no link, lender
or agreement details.

Their *Coming up* lines are plain too: the same payments, with no link
or lender, so everyone's planned total matches. They get no finance
reminders, *Needs attention* items, widget, API data or Ask answers.

Finance is never in the sale pack, the printed History or *Recent
activity*.

## The dashboard, the API and Ask

- The **Finance** dashboard widget lists each vehicle in view with an
  active agreement: "18 payments remaining · £7,850 to pay · ends Mar
  2028", its mileage line and its equity. It appears once a vehicle has an
  agreement, and follows the vehicle chip.
- **`GET /api/v1/vehicles/{id}/finance`** returns the active agreement's
  figures and schedule (else the latest ended one's), every estimate
  marked as such and never the agreement number. See [api.md](api.md).
- **Ask** (and MCP clients) can read the same figures with the
  `finance(vehicle)` tool, and never recommend settling, handing back or
  refinancing.

## Export and backups

- **The agreement page** prints with its own header (*Print*), and its
  schedule exports as CSV.
- **Export all agreements** on the Finance tab (and the vehicle's CSV export
  `/vehicles/{id}/export/finance.csv`) lists every agreement's payments,
  scheduled and extra. Neither includes the agreement number.
- **Backups** carry agreements, their payment marks and extra payments,
  and settlement quotes. `bin/export-user.php` carries those of your own
  vehicles.
