# Phase 29.2 — Mileage, ending and finance everywhere + v2.12 release

*Whether you'll go over the miles, what happens at the end, and finance
wherever the app plans ahead.*

Status: 📋 planned · releases **v2.12.0** (Phases 29.1 and 29.2) · file
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
- [ ] `docs/finance.md`: mileage and ending an agreement.

### Migration
- [ ] Widen `vehicles.disposal` to 16 for `returned_lender` and
      `returned_lessor`. Reversible on every engine (rollback clears the
      new values to `sold`, keeping the sale price).

### Services
- [ ] `Service\Finance\MileageAllowance` (pro rata allowance, projection
      through §7.4's average daily distance, excess charge).
- [ ] Ending flows, including the archive integration (*Returned to the
      lender*, *Returned to the lessor*) and *Settled from the sale*;
      exact cost lines after a hand back.
- [ ] *Coming up* lines, `finance` reminders, the *Needs attention* items,
      the API endpoint and the Ask tool.

### Templates
- [ ] *End agreement*, the archive dialog changes, the mileage section on
      the page and card, the `finance` widget.
- [ ] Translations (en, de).

### Tests
- [ ] **Mileage:** pro rata allowance; projection over and under; no
      projection without enough readings; km and miles; the *Needs
      attention* item at more than 2%, hidden by fingerprint.
- [ ] **Ending:** settled early stops the schedule; completed; handed back
      archives with the sale price at the final payment, and lifetime cost
      equals cash price − final payment + costs of credit and running;
      lease ended; sold with finance owing warns and settles.
- [ ] *Coming up* lines and totals; reminders raised and done.
- [ ] Missed payment as a *Now* item.
- [ ] Access: no `ViewCosts` or below `Manage` → no finance in the widget,
      API or Ask.
- [ ] Module off: widget, reminders, *Coming up* lines and attention items
      gone; data kept.
- [ ] Integration suite green on every engine; migrations roll back on every
      engine.

### Sample data
- [ ] `DemoDataSeeder`: convert the leased EV's monthly expenses to a lease
      agreement, removing those expenses so nothing counts twice. Put the
      self-charging hybrid on a 48-month PCP with a final payment, 8,000 mi a
      year and 9p excess, heading about 1,200 mi over, with a recent
      valuation for equity. Give the archived car an HP agreement settled
      early, with a settlement quote.

### Release
- [ ] `CHANGELOG.md` **2.12.0**: finance and lease agreements. Upgrade
      notes: migrations; existing *Finance and lease* expenses are unchanged,
      and the overlap warning helps move to an agreement.
- [ ] Bump `VERSION`, rebuild assets, update the README status and
      documentation table.

---

## Acceptance criteria

1. A PCP heading over its mileage shows the projected excess charge on the
   card and in *Needs attention*.
2. Lifetime cost of ownership is exact after the agreement ends, however it
   ends.
3. Only people who can see the vehicle's costs and manage it ever see its
   finance, in HTML, API or Ask.
4. Definition of done (CLAUDE.md §11) holds.

## Open questions

None open. Phase 29's questions (#118–#125) are in
[Phase 29.1](phase-29.1.md).
