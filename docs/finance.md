# Finance and lease agreements

If your car is on hire purchase, PCP, a personal loan or a lease, you can
type the agreement into Logbook from its paperwork. From those figures
Logbook works out:

- the payment schedule;
- how many payments are left, and what remains to pay;
- what settling now would roughly cost, or your lender's own quote;
- what the credit costs you;
- for hire purchase and PCP, when you reach half the total amount payable;
- your equity: what the car is worth against what you owe.

The interest, fees or rentals then count in your costs automatically, once
each.

Logbook shows figures, **never financial advice**. Every estimate says it
is one. Your agreement and your lender have the final word.

- [Switching it on or off](#switching-it-on-or-off)
- [Entering an agreement from the paperwork](#entering-an-agreement-from-the-paperwork)
- [The schedule: payments assumed paid](#the-schedule-payments-assumed-paid)
- [What each figure means](#what-each-figure-means)
- [Estimates and your lender's quote](#estimates-and-your-lenders-quote)
- [Costs: counted once](#costs-counted-once)
- [Moving from manual finance expenses](#moving-from-manual-finance-expenses)
- [Who can see it](#who-can-see-it)
- [Export and backups](#export-and-backups)

Mileage allowances, ending an agreement (settling, handing back, selling
with finance owing) and finance in *Coming up*, reminders and *Needs
attention* are coming in Phase 29.2.

## Switching it on or off

Finance is **on by default**. Nothing shows until a vehicle has an
agreement. An admin can switch it off in **Settings → Modules** (or with
`FEATURES_FINANCE=false`, see [configuration.md](configuration.md)). That
hides every finance page, the overview card and the cost lines, and keeps
every agreement.

## Entering an agreement from the paperwork

**Add finance** is in the vehicle's header. Once an agreement exists, the
same place says **Finance** and opens the finance page: the active
agreement first, then earlier ones. A vehicle has at most one active
agreement.

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
anyone else the finance pages don't exist (404), the header has no
finance button, and there is no card.

Someone who sees the vehicle's costs without managing it still has the
agreement's lines in their totals, so everyone sees the same figures. To
them each line is a plain *Finance and lease* line, with no link, lender
or agreement details.

Finance is never in the sale pack, the printed History or *Recent
activity*.

## Export and backups

- **The agreement page** prints with its own header (*Print*), and its
  schedule exports as CSV.
- **Export CSV** on the finance page (and the vehicle's CSV export
  `/vehicles/{id}/export/finance.csv`) lists every agreement's payments,
  scheduled and extra. Neither includes the agreement number.
- **Backups** carry agreements, their payment marks and extra payments,
  and settlement quotes. `bin/export-user.php` carries those of your own
  vehicles.
