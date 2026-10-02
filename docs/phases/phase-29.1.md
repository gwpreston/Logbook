# Phase 29.1 — Finance and lease agreements

*Payments left, what's still owed, and what the car is worth against what
you owe.*

Status: ✅ complete · no release of its own (**v2.12.0** ships with
[Phase 29.2](phase-29.2.md)) · file lives in `docs/phases/`

Since Phase 14.2, finance has been an expense category: the owner logs lease
payments, or the interest on a loan, by hand. They must also remember not
to log the payments that pay off a purchase price, or the car is counted
twice. Nothing knows the agreement itself.

This phase adds **finance agreements**: hire purchase, PCP, personal loans
and leases. They are typed in from the paperwork. From an agreement,
Logbook works out the payment schedule, payments left and what remains to
pay. It also gives an estimated settlement figure (or the lender's own
quote), the cost of credit, the date the agreement reaches half the total
amount payable, and equity against the latest valuation. Credit charges
and lease rentals are counted in costs automatically, with no double
counting.

Phase 29 was split when it started (decided 2026-10-02,
[open questions](open-questions.md) #124). This phase holds the agreements,
their figures, the agreement page, the overview card and the cost lines.
[Phase 29.2](phase-29.2.md) adds mileage, ending agreements, and finance in
*Coming up*, reminders, *Needs attention*, the widget, the API and Ask,
then releases v2.12.0.

Logbook shows figures, never financial advice. Every estimate says it is
one.

Read [`CLAUDE.md`](../../CLAUDE.md), [`spec.md`](../../spec.md) §6
(FinanceAgreement, FinancePaymentEvent, SettlementQuote), §7.1 (purchase,
sale, valuations), §7.7 (*Cost of ownership*, *Finance and leases*),
§7.10, §7.13, §7.21 and §7.32 first.

---

## Goals

1. Agreements per vehicle (`hp`, `pcp`, `loan`, `lease`), with the figures
   UK paperwork gives, under a `finance` module.
2. A **schedule** of monthly payments derived from the agreement, assumed
   paid on their due dates, with exceptions (missed, late, extra payments,
   a settlement) recorded when they happen.
3. Exact **remaining payments** and **remaining to pay**; an **estimated
   settlement** (or the lender's quote); **cost of credit**; the
   **half-paid point** for HP and PCP.
4. **Equity** from the latest valuation, for HP, PCP and loans.
5. **Costs** counted from the agreement in Reports, the Expenses tab and
   ownership, with a warning where manual *Finance and lease* expenses
   overlap.
6. The agreement page (printable, schedule CSV) and an overview card.

## Not in scope

- Everything in [Phase 29.2](phase-29.2.md): mileage allowance figures,
  ending agreements and the archive flows, *Coming up*, reminders, *Needs
  attention*, the dashboard widget, the API, Ask and sample data. The form
  already stores the mileage fields.
- Advice on whether to settle, hand back, refinance or terminate.
- Connecting to lenders, open banking or credit files.
- Payment frequencies other than monthly (#118).
- Business contract hire with VAT recovery (#121), and balloon refinancing
  ("refinance the GFV") as its own flow; it is entered as a new loan.
- Exact settlement figures. Only the lender's quote is exact; Logbook's is
  an estimate, with no "up to" line (#119).

---

## Spec

The full specification is in [`spec.md`](../../spec.md): §6
*FinanceAgreement*, *FinancePaymentEvent* and *SettlementQuote*, and §7.32.
The Phase 14.2 *Finance and leases* rule (§7.7), §7.10 (the module) and
§7.13 (export and backups) point to it.

---

## Decisions (and why)

- **The paperwork's figures are the source.** Lenders differ in how they
  build an agreement. What the owner types from the paperwork is the truth;
  Logbook derives the schedule from it and checks the sums add up.
- **Remaining to pay is exact; settlement is an estimate.** They answer
  different questions, and confusing them is the commonest mistake about
  car finance. The lender's quote overrides the estimate while valid.
- **Payments assumed paid.** Nearly all are direct debits. Asking owners to
  tick 48 boxes guarantees the data goes stale, so only the exceptions are
  recorded.
- **Derived cost lines replace the manual rule.** Phase 14.2 relied on
  owners remembering not to double count. Now the agreement adds exactly
  the cost of credit, or the rentals, and warns where manual lines overlap.
- **Figures, not advice.** The half-paid point, equity and settlement are
  shown with what they mean and a pointer to the lender, never a
  recommendation.

---

## Tasks

### Spec and docs
- [x] §6, §7.32, Phase 14.2's *Finance and leases* rule, §7.6, §7.7,
      §7.10, §7.13, §7.18, §7.20, §7.24 and §7.26 in `spec.md`; the
      Phase 29.1 and 29.2 lines in §13; the parked items in §12.
- [x] `docs/finance.md`: entering an agreement from the paperwork, what each
      figure means, estimates against quotes, and moving from manual
      finance expenses.

### Migration
- [x] `finance_agreements`, `finance_payment_events`, `settlement_quotes`.
      Reversible on every engine.

### Domain / Services
- [x] `Domain\Finance\*` (agreement, type, status and event-kind enums,
      events, quotes).
- [x] Repositories for agreements, events and quotes.
- [x] `Service\Finance\Schedule` (dates with end-of-month clamping, amounts,
      paid status from events).
- [x] `Service\Finance\AgreementFigures` (remaining, settlement estimate,
      cost of credit estimate and exact, half-paid point, equity), using
      decimal arithmetic with no floats for money. The monthly rate from
      the APR is computed to 10 decimal places.
- [x] `Service\Finance\FinanceLedger`: derived cost lines for the ledger
      and the overlap check.
- [x] The agreement form's parser and consistency checks; the purchase
      price offers.
- [x] Access: `Manage` and `ViewCosts`; the derived lines as plain
      *Finance and lease* lines for other cost viewers (#125).
- [x] Backups and `bin/export-user.php` carry the three tables; the
      schema version moves; the CSV export.

### Templates
- [x] Form by type (page and modal), the agreement page (print and CSV),
      the overview card, the vehicle header's *Add finance*, and the
      Expenses tab overlap notice.
- [x] Translations (en, de); German labels for UK-specific terms (PCP and
      GFV explained as *Ballonfinanzierung* and *Schlussrate*).

### Tests
- [x] **Schedule:** 48 payments from 31 Jan clamp to month ends; a different
      first payment; a PCP final payment one month after the last; a lease
      initial rental on the start date; missed, late, extra and settlement
      events.
- [x] **Figures**, against hand-worked examples kept in the test file: an
      HP agreement at 9.9% APR; a 0% PCP; a loan; a lease. Remaining to pay
      is exact; the settlement estimate matches the worked PV to the penny;
      the exact cost of credit after settlement; a loan's cost of credit
      against the amount of credit; the half-paid date; equity positive and
      negative; equity hidden without a recent valuation.
- [x] **Consistency check:** warns at 1.01 difference, not at 1.00; never
      blocks.
- [x] **Costs:** HP and PCP add only credit charges, never capital; a lease
      adds every rental; totals after settlement equal the exact cost of
      credit; `count_in_costs` off adds nothing; the overlap warning lists
      manual expenses in covered months only; no double counting in Reports
      and ownership.
- [x] Access: no `ViewCosts` or below `Manage` → no finance pages or card;
      a `ViewCosts` viewer below `Manage` sees the lines as plain finance
      lines; never in the sale pack or print view.
- [x] Module off: card, page and lines gone; data kept.
- [x] Backup round trip with the three tables.
- [x] Integration suite green on every engine; migrations roll back on every
      engine.

---

## Acceptance criteria

1. Entering a PCP from its paperwork shows "18 of 48 payments remaining",
   the exact amount still to pay, the optional final payment beside it, and
   an estimated settlement labelled as one.
2. With a lender's quote entered, the quote replaces the estimate until it
   expires.
3. Lifetime cost of ownership counts credit charges (or lease rentals) once,
   with nothing counted twice, and is exact after a settled agreement.
4. Only people who can see the vehicle's costs and manage it ever see its
   agreement.
5. Definition of done (CLAUDE.md §11) holds.

## What changed while building

- **Deposits as UK paperwork counts them.** The total amount payable
  includes the dealer's deposit contribution, so the derived total, the
  consistency check, everything paid and the half-paid walk count both
  deposits. Without it, a contribution made the cost of credit negative
  (spec §7.32).
- **`brick/math`** for the arithmetic (spec §4, CLAUDE.md §2): present
  values over 120 months at a 10-place rate overflow the scaled-integer
  `Decimal` helper. It raises only to whole powers, so the twelfth root
  of (1 + APR) is found by Newton's method from a float first guess.
- **Discounting:** each payment by the whole months until it falls due
  (a payment due tomorrow is a month away); a missed payment is owed now
  (spec §7.32 *Settlement*).
- **The owner's today** decides which payments are paid, for every viewer,
  so #125's "same totals for everyone" holds around midnight (spec §7.32
  *Schedule*).
- **A finance page** (`/vehicles/{id}/finance`) lists the agreements,
  active first; the header shows *Add finance* until one exists, then
  *Finance* (spec §7.32).
- **404, not 403:** the finance routes take `View` and the actions check
  `Manage` and `ViewCosts`, so anyone else finds nothing. The vehicle's
  finance CSV is an export like the others and answers 403 below
  `Manage` (a `Manage` share always sees costs).
- **The start odometer's default** is looked up when the mileage is worked
  out (Phase 29.2), so a reading added later still counts.
- **Remaining to pay** is the schedule's; extra payments reduce the
  settlement estimate, not it.
- **OpenAPI 1.16.0:** `GET /me`'s modules include `finance`.

## Open questions

All decided on 2026-10-02, before the phase started:

- **Payment frequency** (#118). *Decided 2026-10-02:* monthly only, as
  drafted. Weekly and four-weekly are in spec §12.
- **Settlement estimate method** (#119). *Decided 2026-10-02:* the present
  value at the APR's monthly rate only, labelled as an estimate that
  points to the lender's quote. An "up to" line for extra early-settlement
  interest is in spec §12.
- **Agreement number** (#120). *Decided 2026-10-02:* stored, optional,
  masked to its last 4 characters except on the edit form. It is never in
  the API, Ask, CSV export or sale pack, and it is in backups (spec §6).
- **Business leases** (#121). *Decided 2026-10-02:* out of scope. Rentals
  are entered as paid, VAT included. Business contract hire with VAT
  recovery is in spec §12.

Found while starting it:

- **Cost of credit for a loan** (#122). The draft's "total amount payable −
  cash price" doesn't fit a loan, which has no cash price. *Decided
  2026-10-02:* a loan uses total amount payable − amount of credit (and,
  once ended, everything paid − amount of credit). A lease shows no cost
  of credit; its cost is the rentals (spec §7.32).
- **Exact cost after handing back** (#123). The draft defined *exact* only
  for settled and completed agreements. *Decided 2026-10-02:* a
  handed-back PCP is exact too, at everything paid − (cash price − final
  payment), so lifetime cost is exact (spec §7.32; built in Phase 29.2).
- **Splitting the phase** (#124). *Decided 2026-10-02:* split into 29.1
  (this file) and [29.2](phase-29.2.md), as Phase 27 was.
- **Derived lines for cost viewers below `Manage`** (#125). *Decided
  2026-10-02:* they count in the totals that person sees, as plain
  *Finance and lease* lines with no link or agreement detail, so every
  viewer sees the same totals (spec §7.32 *Access*).
