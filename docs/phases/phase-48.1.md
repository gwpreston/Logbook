# Phase 48.1 — Show the working: "as of" dates

*Every figure built on an old input says how old.*

Status: 📋 planned · file lives in `docs/phases/`

Logbook already dates some of its figures. Depreciation is labelled with
the date it is measured to (§7.1, §7.35). The *Ownership* card shows the
latest value's date. A valuation older than the owner's threshold gets a
stale hint and a *Needs attention* item (§7.24). Tyre wear, *Coming up*
fuel, finance settlement and repair estimates are labelled as estimates.

What is uneven is **where** the date appears. A figure that depends on a
valuation from 14 months ago reads just as current as one from today
when it shows up somewhere other than the *Ownership* card. That
includes the garage, the dashboard's *True cost* widget, the Ownership
report, the cost of ownership cards, equity on the Finance tab, Ask's
answers and printouts. The same goes for other estimates: their basis
(the last tread check, "last time" a service was done, the fuel rate's
window) is often unnamed.

This phase sets one rule, and applies it everywhere through one value
object and one macro:

> When a figure depends on a dated input that isn't today, the date of
> that input is shown beside the figure.

An estimate also names what it is projected from.

Phase 48 makes the numbers easy to understand and verify, in two parts:

- **48.1 (this part):** the dates.
- **[48.2](phase-48.2.md):** *How it's worked out* on every headline
  figure, then the release.

Both check themselves the same way: a date or a sum shown in the working
must match the figure it explains.

Read [`CLAUDE.md`](../../CLAUDE.md) and [`spec.md`](../../spec.md) §7.1
(valuations, depreciation, stale value), §7.7 (cost of ownership), §7.17
(wear estimate), §7.18 (*Coming up* costs and fuel), §7.32 (settlement,
equity), §7.34 (listed prices), §7.35 (true cost), §7.26 (Ask) and §7.20
(API) first.

**Prerequisites:** none.
Independent of Phases 45 to 47. Phase 50's warranty and build figures
follow the same rule when they are built.

---

## Goals

1. An inventory of every figure that depends on a dated input or is an
   estimate, recorded in the spec (48.0).
2. A `Support\AsOf` value (the input's date, its kind, and for estimates
   the basis) carried by those services' results, so pages, print, CSV,
   the API and Ask all get the same date.
3. One Twig macro, `ui.as_of()`, that renders it the same way
   everywhere, with the stale style once the input passes its threshold.
4. Every figure in the inventory shows it. Figures from today's data
   don't.
5. Release comes with [48.2](phase-48.2.md).

## Not in scope

- Changing any figure, or any rule for when a valuation is stale.
- Fetching fresh values or prices.
- Dates on figures whose inputs are all from today. That would add
  noise and no information (open question A).

---

## The inventory (to verify in 48.0)

| Figure | Dated input | Today |
|---|---|---|
| *Latest value*, depreciation, per year and per distance (overview card) | latest valuation or sale | dated ✓ |
| Depreciation part of true cost (§7.35) | the value point measured to | labelled when before today ✓ |
| Cost of ownership total, per distance and per month (cards, tab, Ownership report) | latest valuation | to check |
| *True cost* widget, *Insights* widget, garage cards | latest valuation | to check |
| Equity and negative equity (§7.32) | the valuation used (within 12 months) | to check |
| Settlement figure | the lender's quote date, or "estimated today" | to check |
| Tyre wear estimate | the last tread measurement | to check |
| *Coming up* item costs ("about £240, last time") | the date of last time | not dated |
| *Coming up* fuel estimate | the window of driving and prices it uses | to check |
| Listed fuel prices, *Cheapest near me*, *Was it worth it?* | the provider's price time | to check |
| *Next 3 months* and 12-month totals (Phase 44) | inherits from their items | to check |
| Ask answers and the digest quoting any of these | as the figure | not carried |
| API and CSV for any of these | as the figure | not carried |

---

## Tasks

### 49.1.0 Spec first
- [ ] The inventory completed against the code, and added to §8 as a
      cross-cutting rule with the table (48.2 uses the same inventory).
- [ ] Open questions A–D decided by the owner and logged; §13;
      `ROADMAP.md` row.

### 49.1.1 The value and the macro
- [ ] `Support\AsOf` (date, kind: `valuation` | `quote` | `measurement`
      | `last_time` | `prices` | `window`, and an optional basis
      string), with stale states from the existing thresholds.
- [ ] `ui.as_of()`: text, never colour alone; print styles.

### 49.1.2 Applied
- [ ] Each service in the inventory returns `AsOf` with its figure.
- [ ] Every page, widget and print view in the inventory uses the macro.
- [ ] API: an `as_of` object beside each such figure (additive; the API
      stays `v1`). CSV: an *As of* column on the Ownership report and
      *Coming up*.
- [ ] Ask tools and the digest pass the date with the figure. Ask's
      grounding check accepts it.
- [ ] Translations (every shipped locale).

### 49.1.3 Tests
- [ ] Each inventory row: date shown when the input isn't today, hidden
      when it is, stale style past its threshold, the owner's time zone
      at the edges.
- [ ] A test that fails when a service in the inventory returns a figure
      without its `AsOf`.
- [ ] Page budgets (§8) unchanged.
- [ ] Design review (375, 768, 1280 px; light and dark; JS off; print).
- [ ] `ROADMAP.md` row ✅ for 48.1 (released with 48.2).

---

## Acceptance criteria

1. No figure that rests on an old valuation, quote, measurement or price
   reads as current. Its date is beside it, on screen, in print, in the
   API and in Ask.
2. Every estimate names what it is projected from.
3. Definition of done (CLAUDE.md §11) holds.

## Open questions

Numbered in `open-questions.md` when logged. Not built until decided.

- **A. Which figures get a date?**
  - (1) Every figure in the inventory, whenever its input isn't from
    today.
  - (2) Every figure in the inventory, always.
  - (3) Only once the input is stale.

  *Recommendation:* (1). (2) would date figures computed from this
  morning's fill-up. (3) hides a 10-month-old valuation that is about to
  matter.

- **B. Absolute or relative dates?**
  - (1) Absolute ("as of 14 Sep 2026"), with the relative form in a
    tooltip.
  - (2) Relative ("3 months ago").

  *Recommendation:* (1). Printouts and screenshots stay true after the
  day they were made.

- **C. Several dated inputs.** For example, true cost depends on both a
  valuation and the last reading.
  - (1) Show the oldest.
  - (2) Show each.

  *Recommendation:* (1), with the others on the figure's own page.

- **D. The garage cards and dashboard tiles**, where space is tight.
  - (1) A short date ("Sep 2026").
  - (2) An icon with the date for screen readers and in a tooltip.

  *Recommendation:* (1). An icon alone is easy to miss, and it is the
  date that matters.
