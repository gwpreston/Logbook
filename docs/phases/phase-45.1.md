# Phase 45.1 — Hardening: proving the money

*Every headline figure checked against a source that isn't the code that
produced it.*

Status: 📋 planned · file lives in `docs/phases/`

Forty-four phases in, Logbook works out a lot of money:

- cost of ownership;
- true cost by period, with depreciation interpolated between
  valuations;
- finance interest, settlement estimates and equity;
- mileage claims by tax-year band;
- *Coming up* totals;
- economy by full-to-full segment.

Each figure has unit tests, but those tests were written alongside the
code. When the code and the test share a mistaken idea, both pass.

Phase 45 is a **hardening milestone**. No feature phase starts until it
passes. It has three parts:

- **45.1 (this part):** the money.
- **[45.2](phase-45.2.md):** households and backups.
- **[45.3](phase-45.3.md):** phones and real people, then the release.

This part builds two things:

- **A golden household.** A realistic fixture whose expected figures are
  worked out by the owner in a spreadsheet, independently of the code.
- **Property tests.** These hold the money rules true for any data, not
  just for chosen examples.

Read [`CLAUDE.md`](../../CLAUDE.md) and [`spec.md`](../../spec.md) §7.1,
§7.7, §7.11, §7.18, §7.23, §7.32 and §7.35 first.

**Prerequisites:** [Phase 44](phase-44.md) complete and green.

---

## Goals

1. **The golden household**, built from scripted inputs and kept in
   `tests/Fixtures/golden/`:
   - two users;
   - four vehicles over three years;
   - expected figures in `expected.csv` from the owner's spreadsheet.

   It is loaded the same way on every engine.
2. **A golden test.** It compares every expected figure to the penny,
   through the same services the pages use, on every engine.
3. **Property tests** for the ledger's invariants.
4. **An edge-case catalogue** in the spec. Each case has a test, and
   each case's answer is decided, not inherited from the code.
5. Every mismatch found is settled. Either the spreadsheet or the code is
   wrong, the answer is logged, and the code or the spec is fixed.

## Not in scope

- New figures, or new ways of working them out. A fix brings code and
  spec back into line; it doesn't redesign.
- Performance work, beyond keeping §8's budgets.

---

## The golden household (proposed)

Settled in 45.1.0 (open question A). The four vehicles:

- **Petrol car, bought used on PCP.**
  - Three valuations, one of them stale.
  - A balloon payment, settled early in year three.
  - Tyres, two services, and an MOT fail then pass.
  - Business trips across two tax years and a rate band change.
- **EV.**
  - Home charging on two tariffs, plus public AC and DC.
  - A free charge (cost 0).
  - A refund.
- **Sold car.**
  - An accident with an insurance payout and an excess.
  - Sold at a gain over its last valuation.
- **Car bought in euros, kept in Ireland.**
  - Every figure stays in its own currency and is never converted.
  - The owner is in `Europe/Dublin`, so it also covers a summer-time
    change.

The second user has a Log share without costs, and adds fill-ups to the
first car. The fixture is only data: the same inputs go in through the
services (not raw SQL), so every derived row is created as the app would
create it.

**Figures checked** (one row each in `expected.csv`, with the period and
vehicle):

- cost of ownership total, per distance and per month;
- true cost and its parts per month and per year, and *What changed*;
- depreciation per period;
- finance payments made, interest so far, the settlement estimate,
  equity and the payments left;
- the mileage claim per tax year;
- *Coming up* totals for the next 3 and 12 months;
- economy per segment and lifetime;
- Reports totals per group and per category;
- the digest's *Last month* figures.

---

## Properties (proposed)

Generated vehicles and entries from a seeded generator, so any failure
replays from its seed (open question B). Each property must hold:

- **Totals add up.** A total equals the sum of its parts in every report,
  card and widget, to the decimal place it is shown.
- **Rounded once.** No figure moves when the same data is entered in a
  different order, split into more entries, or summed in a different
  grouping.
- **Undo restores.** Deleting an entry and adding it back gives
  identical figures.
- **Never mixed.** No figure ever combines two currencies.
- **Sign is kept.** Refunds, payouts and gains in value reduce totals,
  and never turn a per-distance figure negative without saying so.
- **Owner's time zone.** An entry at 23:30 on the last day of a month is
  counted in that month for the owner, whatever the server's zone.
  Summer-time days and 29 February are both generated.
- **Hidden means hidden.** A user without `ViewCosts` gets nothing from
  any service that returns a derived amount.

---

## Tasks

### 45.1.0 Spec first
- [ ] Open questions A–D decided by the owner and logged.
- [ ] The edge-case catalogue added to §8 (below): each case with its
      decided answer.
- [ ] §13; `ROADMAP.md` rows for 45.1, 45.2 and 45.3, with the feature
      phases renumbered after them.

### 45.1.1 The golden household
- [ ] The input script (services, not SQL) and its uploads.
- [ ] The owner's spreadsheet in `tests/Fixtures/golden/`, and
      `expected.csv` exported from it.
- [ ] `GoldenHouseholdTest` on every engine: one assertion per row, and a
      failure message that names the figure, period and vehicle.

### 45.1.2 Properties
- [ ] The generator and the seven properties; a CI job that runs them
      with a fresh seed each night, as well as fixed seeds on every push.

### 45.1.3 Edge cases
The catalogue, each case with a test:
- [ ] Cost 0, and every figure at 0 distance.
- [ ] A valuation on the purchase day, two valuations on one day, and a
      sale below or above the last valuation.
- [ ] Finance settled early, a missed payment, a balloon refinanced, and
      a 0% agreement.
- [ ] A vehicle with no readings, one reading, or a backwards reading.
- [ ] A plug-in hybrid with both litres and kWh.
- [ ] A tax year spanning a rate change, and a trip on 5 and 6 April.
- [ ] A refund larger than the month's spend.
- [ ] Rounding at half a penny in each currency's minor unit.

### 45.1.4 Mismatches
- [ ] Each mismatch logged in `open-questions.md` with what was wrong
      (spreadsheet, code or spec) and the fix. Code fixes carry a
      regression test. Spec fixes are noted in §13.

---

## Acceptance criteria

1. The golden test passes to the penny on every engine.
2. The properties pass on fixed seeds in CI, and the nightly run is
   green for a week.
3. Every edge case in the catalogue has a decided answer and a test.
4. Definition of done (CLAUDE.md §11) holds.

## Open questions

Numbered in `open-questions.md` when logged. Not built until decided.

- **A. Who works out the expected figures?**
  - (1) The owner, in a spreadsheet, from the input script alone.
  - (2) Claude Code, in a separate script that shares no code with
    `src/`.
  - (3) Both, compared.

  *Recommendation:* (3) for finance and depreciation, which are the
  hardest to get right; (1) for everything else. A second implementation
  by the same author as the first shares its blind spots.

- **B. A property-testing library?**
  - (1) None: PHPUnit data providers over a small seeded generator in
    `tests/Support/`.
  - (2) A library such as Eris.

  *Recommendation:* (1). The properties are few, and a library is one
  more dependency to keep current.

- **C. How large a household?**
  - (1) The four vehicles above, about 1,500 entries.
  - (2) Also a "heavy" variant, 20 vehicles over ten years, for budgets.

  *Recommendation:* (1) here, with (2) only if §8's query budgets
  are doubted.

- **D. What if the spec is ambiguous?** When the spreadsheet and the code
  differ because the spec allows both readings:
  - (1) The owner decides, and the spec is made exact.
  - (2) The code's reading stands.

  *Recommendation:* (1). Settling these ambiguities is the point of the
  exercise.
