# Parts and modifications

Parts and modifications: what was fitted and what came off, warranties, and build spend.

**For:** anyone tracking the parts on a vehicle or a modified car.

Keep track of what is on the car: the **parts** (which clutch, its part
number, where it was bought, when it went on and at what mileage, what came
off, and whether it is still under warranty) and the **modifications** (a
set of coilovers, a remap, a cat-back exhaust, a set of wheels).

A modification's cost counts in **what the car has cost**, as its own group,
***Modifications***. It never counts in a **running cost per mile**: a £2,400
suspension build is a choice, not the cost of running the car, so it doesn't
make the car look dearer to run for the year it was bought.

- [Switching it on or off](#switching-it-on-or-off)
- [Parts](#parts)
- [Modifications](#modifications)
- [Fitting parts from a service record or a modification](#fitting-parts-from-a-service-record-or-a-modification)
- [Fitting and removing a part on its own](#fitting-and-removing-a-part-on-its-own)
- [Reverting a modification](#reverting-a-modification)
- [What counts where](#what-counts-where)
- [Make this a modification](#make-this-a-modification)
- [Warranty reminders](#warranty-reminders)
- [For your insurer](#for-your-insurer)
- [Selling the car](#selling-the-car)
- [Scanning an invoice](#scanning-an-invoice)
- [History and print](#history-and-print)
- [API, Ask, MCP, CSV and webhooks](#api-ask-mcp-csv-and-webhooks)
- [Shared and archived vehicles](#shared-and-archived-vehicles)
- [Backups](#backups)

## Switching it on or off

Parts and modifications are **on by default on installs set up before
v3.15.0, and off on new ones**. An admin can switch them on or off in
**Settings → Modules** (or with `FEATURES_PARTS=false`, see
[configuration.md](configuration.md)). Off hides the *Parts* tab, the overview
card, *Log entry*'s *Modification*, the parts lists on service records and
the History kinds, and takes the *Modifications* lines out of every report, as
any switched-off module's costs are. Everything is kept, and mileage readings
already written stay in the mileage log.

## Parts

A part belongs to one vehicle. Each has a name and, optionally, a brand, part
number, serial number, supplier, the date bought and the price, its condition
(*New*, *Used*, *Reconditioned*), notes, and files: the receipt, photos and
the warranty papers.

**The price is for reference.** What you paid for the job is on the service
record or modification that fitted the part, so it is counted once. To cost a
part you fitted yourself, log the job as a service record or a modification
with that cost.

**Warranty:** months and/or a distance, the provider and the terms. The months
run from the purchase date or from the fitting (you choose; a new part,
or one you edit that has no warranty yet, starts with *Purchase date*
chosen, and with no purchase date it runs from the fitting; a supplier's warranty usually runs from the sale, a garage's
from the fitting). A distance always counts from the mileage when the part
was first fitted. The part page says "In warranty until 3 Mar 2028 or
85,400 mi" while every limit holds, "Warranty ended 3 Mar 2028" once the
date has passed, and "Warranty ended" when the distance ran out first.

Tick ***Remind me before it ends*** to be reminded before the warranty runs
out (see [Warranty reminders](#warranty-reminders)); the part page's
warranty card then says "Reminder before it ends".

A part is ***Fitted***, ***Stored*** (a spare bought ahead, or one you kept
when it came off) or ***Gone*** (sold, returned under warranty, scrapped).
It can go on and off more than once: the standard exhaust goes back on before
a sale. Its **distance on the vehicle** adds up each spell, from the mileage
it went on to the mileage it came off (or the latest reading while it is
still on). A spell without both mileages counts nothing, and the total says
"At least".

## Modifications

**Add modification** is on the *Parts* tab and in *Log entry*. It records
what was done (up to 120 characters), the category (*Exhaust*, *Suspension*,
*Wheels*, *ECU tune*, *Intake*, *Audio*, *Lighting*, *Bodywork*, *Engine
internals*, *Brakes*, *Interior*, *Electrical*, *Security and tracking*,
*Other*), the date and mileage, the cost (0 is fine), who fitted it (a
garage, or "Self"), notes and photos. A modification doesn't need parts: an
ECU tune or a wrap has none.

## Fitting parts from a service record or a modification

The service record form and the modification form have two lists:

- **Parts fitted:** a row per new part (name, brand, part number, supplier
  and price, with condition, warranty and notes under *More*), so fitting an
  exhaust is one save; and your stored parts, ticked to fit them again.
- **Parts removed:** your fitted parts, ticked to take them off, each with
  what happened to it (*Kept* by default; *Sold* takes a price); and
  **Record what came off**: a part that was on the car before you logged it,
  such as the standard dampers. It is recorded as on the car from the
  purchase date and taken off by this record, usually *Kept*.

The fittings follow the record's date and mileage when you edit it, and
unticking a part takes that fitting or removal back. **Deleting the record**
takes away the fittings it made (the parts stay) and fits again the parts it
took off; the confirmation names them. Without JavaScript, *Add another part*
shows the form again with one more row.

## Fitting and removing a part on its own

A part's page has **Fit**, **Fit again** and **Remove**, with no record and
no cost, for a part already on the car or a stored part going back on. The
date and mileage join the mileage log. *Add part* can also record a part as
*already on the car*. Two spells of one part may not overlap; a save that
would is refused, naming the part and the dates.

A removal made this way can't be edited or undone, in the app or the API:
its date, mileage, outcome and sale price stay as entered. To correct one,
delete the part and add it again. A sale of £0.00 stored before 3.16.3 (when
a blank price was stored as 0.000) stays the same way. When a removal came
from a record, correct it there: editing the record moves the removal, and
unticking the part reopens it.

## Reverting a modification

**Revert** (a remap taken off, a car put back to standard) asks for the date,
mileage and a note, and offers in the same save to take off the parts the
modification fitted and to fit again the original parts it took off. The
modification stays in the record marked *Reverted*, and its cost still
counts: it was spent. **Undo revert** puts everything back, unless a part it
put back has come off again since (sold, say): undo that first.

## What counts where

| Figure | Modifications |
| --- | --- |
| Expenses tab and Reports: total spend, breakdown, spend per month | Included, as *Modifications* |
| Cost of ownership: *Total so far* | Included, as its own line |
| Cost per mile (or km) everywhere, average per month, cost of ownership's per-distance and per-month rates, true cost and *What changed*, the digest's cost per distance, the trips report's cost per business mile | **Left out** |
| Cost checks in *Needs attention* | Never flagged, never part of a baseline |
| Purchase price, valuations, depreciation | Never changed |

A part sold as it came off is **money back**: a negative line, in the group
of the record that first fitted it (*Maintenance* for a service record,
otherwise *Modifications*), so a build's net cost is what you paid less what
you got back.

## Make this a modification

If you logged a build before this module existed (as maintenance *Other*, or
an *Accessories* expense), open that service record or expense and choose
**Make this a modification**. Its date, mileage, cost, notes and files move
to a new modification and the original is deleted, in one save. A title or
note longer than 120 characters becomes the title cut to 120, and the whole
text is kept in *Notes*, so nothing is lost. It is never
done automatically or in bulk (an accessories expense may be a phone holder).
A service record that completes a service interval, fixes an issue, fits
parts or is part of an incident can't be converted; take that link off first.

## Warranty reminders

Most parts' warranties need nothing doing, so a part reminds you only when
you tick **Remind me before it ends** on it (on the part form, or behind
*More* on a new part's row of a service record or modification). The
reminder is raised while the part isn't *Gone* and the warranty hasn't
ended, and is due when the warranty's date is within your **document lead
time** (Settings → Reminders), or when its mileage, projected from your
recent driving, is within the **schedule lead distance**. Its title is
"Warranty ends: Sachs Clutch kit". Press **Checked it** once you've had it
looked at: it stays done until you edit the warranty, which raises a new one.

It goes (nothing is lost) when you clear the tick, edit the warranty away,
sell or scrap the part, or the warranty ends. Ticking a time limit counted
from a purchase date that has already passed is refused; any other warranty
simply stops reminding once it ends. It is sent through your channels and shows
in the calendar like any reminder.

## For your insurer

The *Parts* tab's **For your insurer** lists every modification currently on
the car, **ECU tunes first**: what it is, when it was fitted, the parts it
fitted (name, brand, part number) and took off, and when you **declared it**
to your insurer or "Not declared yet". Print it, or read it out for a quote
or a renewal. It never shows a cost, and anyone who can see the vehicle can
open it. A reverted modification is left off: it is no longer on the car.

Logbook doesn't judge whether a modification must be declared or what it
would add to a premium; this is your own record. With *Manage* on the
vehicle, **Mark declared** records the day you told the insurer (today, or a
date you choose, not before the modification) for one modification or all
the undeclared ones; **Clear** puts one back to "Not declared yet". Each
current modification without a date shows a **Not declared** label on the tab
and its page.

## Selling the car

The [sale pack](sale-pack.md) has an option, **Include modifications**,
that lists what has been changed, what was put back to standard (with the
original parts fitted again) and the parts you keep with the car. It never
shows costs, prices or what a part sold for.

## Scanning an invoice

When you [scan a service invoice](ai.md), its **parts lines** are offered
under the prefilled form as **Add as parts**, each with a name, a part number
and a price read from the line as a guess to check. None are ticked for you:
oil and screen wash are parts lines too. Tick the ones that should be parts
fitted by that record; nothing is created until you save the record, and a
warranty is never read from a line.

## History and print

History lists modifications (and their revert) and parts fitted or removed
without a record under a **Parts** chip. A part fitted or removed by a
service record or modification shows on that record's line: "Fitted Bilstein
B14 coilover kit · removed Sachs Standard dampers". The printable service
history lists modifications, with their cost when *Show costs* is ticked.

## Shared and archived vehicles

Viewing needs *View*; adding, *Fit*, *Remove* and *Revert* need *Log*;
editing, deleting and *Undo revert* on your own need *Log*, on anyone's
*Manage*. Costs,
prices and sale prices need *Can see costs*, even on a part or
modification you added yourself: without it the forms don't ask for them,
and the owner's figures stay as they are when you edit. Making your own
expense or service record a modification hides its amount from you too.
An archived vehicle keeps its parts and modifications read-only.

## Backups

Backups and `bin/export-user.php` carry modifications, parts and their
fittings. The schema version moves: restore a backup from before v3.15.0
with its own version, then upgrade.

## API, Ask, MCP, CSV and webhooks

- **API:** modifications and parts can be read, added, edited, deleted,
  fitted, removed and reverted over the [REST API](api.md#parts-and-modifications)
  (a cost or price needs *Can see costs*, and is ignored without it).
- **Webhooks:** `modification` and `part` entries; every fitting and removal
  is an update of the part ([API](api.md#webhooks)).
- **Ask and MCP:** the `modifications` and `parts` tools, and
  `draft_modification` for a card you add yourself. `cost_per_distance` gives
  the build spend beside the rate, so an answer can say both: a modification
  is never in a cost per mile ([AI](ai.md), [MCP](mcp.md)).
- **CSV:** the *Parts* tab exports `modifications.csv`, `parts.csv` and
  `part-fittings.csv` (one row per spell on the car). They can't be imported.

---

[Back to the docs index](README.md)
