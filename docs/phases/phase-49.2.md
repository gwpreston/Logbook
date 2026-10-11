# Phase 49.2 — Show the working: *How it's worked out* + release

*Every headline figure can be opened to show its inputs and its sum in
plain words.*

Status: 📋 planned · file lives in `docs/phases/`

Two figures already show their working:

- **Cost of ownership** has *How it's worked out*: "Depreciation
  compares the price you paid with its latest value…".
- **Was it worth it?** opens each result to its sum: "Fuel saving £2.00
  (50 L at 4p less) · Extra distance… · **Actual saving −£0.34**".

Owners trust these figures because they can check them. Every other
headline figure asks to be taken on trust. That includes true cost and
*What changed*, depreciation for a period, the finance settlement
estimate, equity, interest so far, cost per distance, economy for a
segment, the mileage claim and *Coming up* totals.

This part gives each of them the same pattern. The working is one
collapsed `<details>` under the figure, and it holds:

- **the inputs**, each with its date (49.1) and a link to the row it
  came from;
- **the sum in words**, with the real numbers;
- **what Logbook did not assume.**

The last point matters most for depreciation. Logbook doesn't estimate
values or apply a depreciation rate. It draws a straight line between
values the owner entered (§7.1), and the working says so plainly.

Read [`CLAUDE.md`](../../CLAUDE.md), [`spec.md`](../../spec.md) §7.1,
§7.7, §7.18, §7.23, §7.32, §7.34 (*Was it worth it?*, the existing
pattern) and §7.35 first, and [Phase 49.1](phase-49.1.md)'s inventory.

**Prerequisites:** [Phase 49.1](phase-49.1.md) complete and green.

---

## Goals

1. **A `Working` result** (inputs, steps and notes) that the inventory's
   services return beside each figure, built from the same values the
   figure was computed from.
2. **One `ui.working()` macro**, used under every figure in the
   inventory.
3. **Wording for each figure** (below), checked against the golden
   household.
4. The working in **print** (expanded), the **API** (`working` on
   request) and **Ask** (its tools pass the working, so answers can quote
   it).
5. Release.

## Not in scope

- Changing any figure or method. A figure whose working reveals a bug
  goes back through Phase 45.1's mismatch process.
- Interactive "what if" calculators.

---

## Working, per figure (proposed wording)

- **Depreciation for a period:** "Value on 1 Jan £14,200 (straight line
  between your valuations of 3 Oct 2025, £14,900, and 2 Mar 2026,
  £13,600) − value on 31 Mar £13,640 = £560. Logbook doesn't estimate
  values or apply a depreciation rate; it only draws a line between
  values you entered."
- **True cost for a month:** each part (fuel, maintenance, documents,
  other, depreciation, finance charges), its amount and a link to its
  entries, and their sum. Payouts are shown with their sign, and the
  build line is shown apart once Phase 51 lands.
- **What changed:** the two periods side by side, part by part, with the
  difference.
- **Settlement estimate:** "Your 14 remaining payments of £289.50,
  each discounted at the agreement's monthly rate of 0.54%, come to
  £3,890.12 today. Your lender's quote will differ: it may add fees or
  interest that the agreement allows."
- **Equity:** "Value £13,600 (2 Mar 2026) − settlement £3,890.12
  (estimated today) = £9,709.88."
- **Interest so far:** the balance and the split for the last three
  payments, and the rule for the rest.
- **Cost per distance:** "£2,184.40 running costs ÷ 9,320 mi between
  readings of 1 Jan (41,200) and 31 Dec (50,520)". Build spend and
  depreciation are left out, and the working says so.
- **Economy for a segment:** the full fill-ups at each end, the litres
  between them and the distance.
- **Mileage claim:** business miles in each rate band × the rate =
  the claim, per tax year.
- ***Coming up* total:** each item with its "last time" date or planned
  cost, and the fuel estimate's window.

---

## Tasks

### 49.2.0 Spec first
- [ ] Open questions A–C decided by the owner and logged.
- [ ] §8: the *Show the working* rule. Each inventory row gets its
      wording in its own section. §13; `ROADMAP.md` row.

### 49.2.1 Working
- [ ] `Support\Working` and its construction in each inventory service,
      from the values actually used (never recomputed separately).
- [ ] `ui.working()`: a `<details>` that works without JS, with print
      expanded and links to the rows.
- [ ] The existing *How it's worked out* and *Was it worth it?* moved
      onto the macro, with their wording unchanged.

### 49.2.2 Everywhere
- [ ] Every page, card and widget in the inventory. Widgets link to the
      page that shows the working rather than embedding it (open
      question B).
- [ ] API `?include=working`; Ask's read tools pass it; MCP likewise.
- [ ] Translations (every shipped locale).

### 49.2.3 Tests and release
- [ ] For every golden figure, the working's sum equals the figure
      shown, and every input links to a row that exists.
- [ ] `ViewCosts`: the working hides with its figure. The leak sweep
      (Phase 45.2) covers it.
- [ ] Design review (375, 768, 1280 px; light and dark; JS off; print;
      a screen reader opening a working).
- [ ] `VERSION`, `CHANGELOG.md`, README; `ROADMAP.md` rows ✅ for 49.1
      and 49.2; tag once merged.

---

## Acceptance criteria

1. Every headline figure in the inventory can be opened to show its
   dated inputs, linked to their rows, and its sum in words.
2. The working always agrees with the figure. The golden household
   proves it.
3. Depreciation says plainly that it uses only values the owner entered.
4. Definition of done (CLAUDE.md §11) holds.

## Open questions

Numbered in `open-questions.md` when logged. Not built until decided.

- **A. Open or closed by default?**
  - (1) Closed everywhere on screen, open in print.
  - (2) Open on the figure's own page.

  *Recommendation:* (1). It is consistent, and pages stay short.

- **B. On dashboard widgets?**
  - (1) A *How?* link to the page that shows the working.
  - (2) The working inside the widget.

  *Recommendation:* (1). Widgets are small, and the page already exists.

- **C. Ask quoting the working.**
  - (1) Ask's tools pass the working, and answers may quote its sum.
  - (2) Figures only.

  *Recommendation:* (1). "How did you get that?" then has a checkable
  answer, held to the same grounding check.
