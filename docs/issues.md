# Issues

Keep a log of the faults you've noticed and haven't fixed yet: "knock from
front left under braking", "slow leak, rear right", "advisory: brake pipes
corroded". Each issue has the date you noticed it, the mileage, your
description, any photos, and a status: **open**, **watching** or **fixed**.
It closes by linking to the service record that fixed it, so the trail from
symptom to repair stays with the car.

**Logbook never suggests what a fault is.** It records your words and links
the fix. A confident wrong guess about brakes or steering could hurt
someone; ask a qualified mechanic.

- [Switching it on or off](#switching-it-on-or-off)
- [Logging an issue](#logging-an-issue)
- [Open, watching and fixed](#open-watching-and-fixed)
- [Fixing an issue](#fixing-an-issue)
- [Updates](#updates)
- [Needs attention and reminders](#needs-attention-and-reminders)
- [History, print and the sale pack](#history-print-and-the-sale-pack)
- [Recommended work](#recommended-work)
- [Shared and archived vehicles](#shared-and-archived-vehicles)
- [Export, API, Ask and MCP](#export-api-ask-and-mcp)
- [Backups](#backups)

## Switching it on or off

Issues are **on by default**. An admin can switch them off in **Settings →
Modules** (or with `FEATURES_ISSUES=false`, see
[configuration.md](configuration.md)). That hides the Issues tab, *Log entry*'s
*Issue*, the *Fixes* checklist on service records, the *Needs attention*
items, the look-again reminders and everything below, and keeps every issue.
Links on service records are left as they are.

## Logging an issue

**Add issue** is on each vehicle's **Issues** tab (after Maintenance) and in
*Log entry*. The form asks:

- **What did you notice?** Up to 120 characters, as you'd tell a mechanic.
- **Noticed on** (not in the future) and the **odometer**. The odometer
  adds a reading to the mileage log, as a service record's does, with the
  usual warning if it looks wrong. If the vehicle already has a reading
  that day at the same mileage, no second one is added.
- **Details**, an **Area** (the maintenance categories, so the repair can be
  prefilled) and **photos or PDFs**.
- **Affects safety:** your judgement, never Logbook's. A safety issue is
  listed first, in red, with the words "Affects safety".
- **Status:** open, or watching with an optional look-again point.

The overview shows an **Issues** card with up to five open and watching
issues. **/issues** lists every open and watched issue across your
vehicles; the *Needs attention* widget links to it.

## Open, watching and fixed

- **Open:** noticed, not dealt with.
- **Watching:** you've decided to keep an eye on it (an advisory, a noise
  that comes and goes). Give it a **look-again** date, a mileage, both or
  neither. *Watch* on an open issue sets it; *Watch again* sets a new point;
  *Stop watching* puts it back to open.
- **Fixed:** linked to the service record(s) that fixed it, or fixed
  without one.

Every status change is written to the issue's timeline.

## Fixing an issue

From the **service record**: its form has a **Fixes** checklist of the
vehicle's open and watching issues. Saving marks the ticked ones fixed on
the record's date. One brake job can fix several issues.

From the **issue**, **Mark fixed** offers, in this order:

1. **Log the repair:** a new service record, prefilled with the issue's
   area and title, the issue ticked under *Fixes*.
2. **Link an existing record:** one of the vehicle's records since the
   issue was noticed.
3. **Fixed without a record:** a date and an optional note ("Went away on
   its own"). Some faults just stop.

Unticking an issue on the record, or deleting the record, takes the link
away; with no link left, the issue goes back to the status it had (a
look-again point is not restored), and the timeline says why. An issue
fixed without a record stays fixed.

**It's back** reopens a fixed issue. Its earlier fix stays in its history.

## Updates

**Add update** puts a dated note on the timeline ("Still knocking, worse
when cold"), with an optional odometer (which joins the mileage log) and an
optional change to open or watching. Your notes can be edited or deleted;
the automatic status lines can't.

## Needs attention and reminders

*Needs attention* lists each **open issue** ("Knock from front left under
braking · noticed 12 Aug, 3 weeks ago") with *Log the repair* and *Watch*,
and each **watching issue whose look-again date has passed or mileage has
been reached** as *Look again*, with *Log the repair*, *Watch again* and
*Reopen*. Safety issues come first. There is no *Hide*: *Watch* is how an
issue is set aside.

With the reminders module on, a look-again point also raises a **reminder**
("Look again: Brake pipes corroded") that reaches your notification
channels like any other. It follows the point: changing it moves the
reminder, fixing or un-watching the issue removes it. **Done** on it means
*Looked at it*: the point is cleared and the issue stays watched.

## History, print and the sale pack

History lists **Issue noticed** and **Issue fixed** under an *Issues* chip;
updates are not listed. The printable service history includes fixed issues
with what fixed them, never open ones. The sale pack has **Include open
issues**, off by default: honest disclosure is your choice.

## Recommended work

When you save a service invoice or an MOT certificate read from a photo or
PDF, its recommended work (or advisories) is offered line by line. Beside
*Add reminder*, each line has **Add as issue** (open) and **Watch**
(watching, with the line's own date or mileage, if it gave one, to look
again), and **Add all as issues** adds every line open. Each issue is
noticed on the invoice's date at its mileage. A line is added once, and
then says whether it became a reminder or an issue.

You see the issue buttons if you can add issues to the vehicle (*Log*,
issues on) and the reminder buttons if you can add reminders (*Manage*,
reminders on).

## Shared and archived vehicles

Anyone who can view a vehicle sees its issues. *Log* lets someone add
issues, notes and fixes, and watch or reopen them; editing or deleting
someone else's issue or note needs *Manage*. An archived vehicle keeps its
issues read-only, and its open ones raise nothing.

## Export, API, Ask and MCP

- **Export CSV** on the Issues tab (*Manage*) downloads every issue: date
  noticed, mileage, title, description, area, status, affects safety,
  fixed on and what fixed it. Updates are not included.
- The [API](api.md#issues) lists, reads, logs, edits, deletes, fixes and
  reopens issues and adds updates and files; webhooks of kind `issue` tell
  you when one changes.
- [Ask](ai.md) reads your issues and drafts one from a sentence ("noticed a
  knock from the front left when braking"). Asked what causes a fault, it
  says Logbook only records what you noted and suggests a qualified
  mechanic. The [MCP server](mcp.md) has the same tools.

## Backups

Backups and `bin/export-user.php` carry issues, their updates and their
fixes.
