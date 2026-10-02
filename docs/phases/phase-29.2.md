# Phase 29.2 — Mileage, ending and finance everywhere + v2.12 release

*Whether you'll go over the miles, what happens at the end, and finance
wherever the app plans ahead.*

Status: ✅ complete · released as **v2.12.0** (Phases 29.1 and 29.2) · file
lives in `docs/phases/`

[Phase 29.1](phase-29.1.md) adds finance agreements, their figures and
their cost lines. This phase connects them to the rest of Logbook:

- **the odometer**, for mileage against a PCP or lease allowance and the
  excess charge it is heading for;
- **archiving**, for ending an agreement: settling early, completing,
  handing back, ending a lease, and selling with finance owing;
- *Coming up*, reminders, *Needs attention*, the dashboard, the API and
  Ask.

Phase 29 was split when it started (decided 2026-10-02,
[open questions](open-questions.md) #124).

Read [`CLAUDE.md`](../../CLAUDE.md), [`spec.md`](../../spec.md) §6
(Vehicle disposal, Reminder), §7.1, §7.4 (distance projection), §7.6,
§7.7, §7.8, §7.18, §7.20, §7.21, §7.24, §7.26, §7.29 *Total loss* and
§7.32 first, and [Phase 29.1](phase-29.1.md).

---

## Goals

1. **Mileage allowance** tracking with a projected excess charge, for PCP
   and leases.
2. **Ending:** settling early, paying the final payment, handing back, and
   selling with finance owing, each closing the agreement correctly, with
   exact costs afterwards.
3. *Coming up* lines, reminders, a *Needs attention* check, a dashboard
   widget, the API endpoint and the Ask tool.
4. Sample data and the v2.12.0 release.

## Not in scope

- As [Phase 29.1](phase-29.1.md): advice, lender connections, other
  payment frequencies, business VAT, balloon refinancing, exact
  settlement figures.

---

## Spec

In [`spec.md`](../../spec.md) §7.32 (*Mileage*, *Ending*, *Selling with
finance owing*, *Coming up*, *Reminders*, *Needs attention*, *Dashboard
widget*, *API*, *Ask*), §6 (Vehicle disposal `returned_lender` and
`returned_lessor`; Reminder source `finance`), §7.6, §7.18, §7.20, §7.24
item 11 and §7.26.

---

## Decisions (and why)

- **Handing back is a sale at the final payment.** It makes lifetime cost
  of ownership correct without a special case: the owner paid the cash
  price less the final payment they didn't pay (#123).
- **Mileage is where Logbook adds most.** The lender knows the allowance;
  only Logbook knows the odometer and the usual daily distance. The
  projected excess charge, a year early, is the figure owners can act on.
- **No reminders for regular payments.** They are direct debits; only the
  final payment and the end of a PCP or lease need a decision.

---

## Tasks

### Spec and docs
- [x] §7.32 and the cross-references (written with Phase 29.1).
- [x] `docs/finance.md`: mileage and ending an agreement.

### Migration
- [x] Widen `vehicles.disposal` to 16 for `returned_lender` and
      `returned_lessor`. Reversible on every engine (rollback clears the
      new values to `sold`, keeping the sale price).

### Services
- [x] `Service\Finance\MileageAllowance` (pro rata allowance, projection
      through §7.4's average daily distance, excess charge).
- [x] Ending flows, including the archive integration (*Returned to the
      lender*, *Returned to the lessor*) and *Settled from the sale*;
      exact cost lines after a hand back.
- [x] *Coming up* lines, `finance` reminders, the *Needs attention* items,
      the API endpoint and the Ask tool.

### Templates
- [x] *End agreement*, the archive dialog changes, the mileage section on
      the page and card, the `finance` widget.
- [x] Translations (en, de).

### Tests
- [x] **Mileage:** pro rata allowance; projection over and under; no
      projection without enough readings; km and miles; the *Needs
      attention* item at more than 2%, hidden by fingerprint.
- [x] **Ending:** settled early stops the schedule; completed; handed back
      archives with the sale price at the final payment, and lifetime cost
      equals cash price − final payment + costs of credit and running;
      lease ended; sold with finance owing warns and settles.
- [x] *Coming up* lines and totals; reminders raised and done.
- [x] Missed payment as a *Now* item.
- [x] Access: no `ViewCosts` or below `Manage` → no finance in the widget,
      API or Ask.
- [x] Module off: widget, reminders, *Coming up* lines and attention items
      gone; data kept.
- [x] Integration suite green on every engine; migrations roll back on every
      engine.

### Sample data
- [x] `DemoDataSeeder`: convert the leased EV's monthly expenses to a lease
      agreement, removing those expenses so nothing counts twice. Put the
      self-charging hybrid on a 48-month PCP with a final payment, 8,000 mi a
      year and 9p excess, heading about 1,200 mi over, with a recent
      valuation for equity. Give the archived car an HP agreement settled
      early, with a settlement quote.

### Release
- [x] `CHANGELOG.md` **2.12.0**: finance and lease agreements. Upgrade
      notes: migrations; existing *Finance and lease* expenses are unchanged,
      and the overlap warning helps move to an agreement.
- [x] Bump `VERSION`, rebuild assets, update the README status and
      documentation table.
- [x] Tag `v2.12.0` once merged.

---

## Acceptance criteria

1. A PCP heading over its mileage shows the projected excess charge on the
   card and in *Needs attention*.
2. Lifetime cost of ownership is exact after the agreement ends, however it
   ends.
3. Only people who can see the vehicle's costs and manage it ever see its
   finance, in HTML, API or Ask.
4. Definition of done (CLAUDE.md §11) holds.

## What changed while building

- **Two reminder sources** (#130): `finance` for the final payment and
  `finance_end` for *Agreement ends*, as a reminder row is unique per
  vehicle, source and source id (spec §6 Reminder).
- **The end date for mileage** is the final payment's date for PCP and, for
  a lease, a month after the last rental, when the car goes back (the
  final payment's default date rule), so #129's 36-month lease comes out
  at 36 (spec §7.32 *Mileage*). The projection runs from the latest
  reading's date.
- **The archive page** (#126) offers *Sold* only for credit agreements (a
  driver can't sell a leased car); *Returned to the lender* always takes
  the optional final payment as the sale price (#123 needs that exact
  figure); *Just archive* leaves the agreement active (spec §7.32 *Archive
  page*, §7.29).
- **The *End agreement* form** (#127): *Completed* is not before the last
  payment; an end date is not after the owner's today nor before the
  agreement; the fields the chosen outcome doesn't use hide with CSS
  `:has()`, and all show without it (spec §7.32 *Ending*).
- ***Coming up*** keeps one line per vehicle but counts each payment in its
  own month, so the monthly totals stay right; a plain line names no
  agreement in the API either (`source_id` null, #128).
- **The dashboard widget** stays off the dashboard until a vehicle in view
  has an active agreement the viewer may see; customising lists it (spec
  §7.8, §7.32 *Module*).
- **Hiding item 11** keys on the agreement and the projected excess rounded
  to 100 in the agreement's unit.
- **Reminder titles** are cut to the column's 150 characters for a long
  lender's name.
- **Sample data:** the stale valuation the Corolla carried for *Needs
  attention* (Phase 24) moved to the bike, so the Corolla's recent one
  gives its PCP's equity; the Corolla's purchase moved to the PCP's start
  (1 Apr 2024, its cash price).
- **OpenAPI 1.17.0:** `GET /vehicles/{id}/finance`; *Coming up* items and
  reminders carry the new sources.

## Open questions

Phase 29's questions (#118–#125) are in [Phase 29.1](phase-29.1.md).
Found while starting this phase, all answered before it was built:

- **#126 Where do *Selling with finance owing* and the hand-back choices
  go?** *Decided 2026-10-02:* the archive page. With an active agreement
  the viewer may see, *Archive* opens it with *Sold* (with the warning and
  *Settled from the sale* for HP and PCP), *Returned to the lender*,
  *Returned to the lessor*, *Written off* when offered, and *Just
  archive*. The vehicle form is unchanged (spec §7.32 *Archive page*).
- **#127 Excess mileage and damage charges, and the overlap warning.**
  *Decided 2026-10-02:* two optional amounts on the *End agreement* form,
  saved as *Finance and lease* expenses on the end date; an ended
  agreement's months stop the day before its end date, so they never warn.
- **#128 *Coming up* for `ViewCosts` below `Manage`.** *Decided
  2026-10-02:* plain lines with no link or lender, so planned totals match.
- **#129 The agreement's length for the allowance.** *Decided 2026-10-02:*
  calendar months from started_on to the end date.

Found while building it:

- **#130 Two finance reminders, one row per vehicle, source and
  source_id.** *Decided 2026-10-02:* two sources, `finance` for the final
  payment and `finance_end` for *Agreement ends*, both with the agreement
  as source_id.
