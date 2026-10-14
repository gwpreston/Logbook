# Phase 48.1 — Show the working: "as of" dates

*Every figure built on an old input says how old.*

Status: ✅ built (released with [48.2](phase-48.2.md)) · file lives in `docs/phases/`

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

## The inventory (checked against the code in 48.1)

The same table is in [`spec.md`](../../spec.md) §8; 48.2 uses it too.

| Figure | Dated input | Before 48.1 | Now |
|---|---|---|---|
| *Latest value*, depreciation, per year and per distance (overview card) | latest valuation or sale | dated ✓ | unchanged |
| Depreciation part of true cost (§7.35), widget, report | the value point measured to | labelled when before today ✓ | unchanged |
| Depreciation in the cost of ownership (overview card, tab, Ownership report table and cards) | latest valuation | not dated on the tab and cards, no stale | `AsOf` beside it (short form on cards and the report) |
| Equity and negative equity (§7.32) | the valuation used (within 12 months) | dated only on the agreement page | `AsOf` on the card, the dashboard widget and the agreement page |
| Settlement figure | the lender's quote date, or "estimated today" | dated ✓ | unchanged |
| Tyre wear estimate | the last tread measurement | "checked" date ✓ | unchanged |
| *Coming up* item costs ("about £240, last time") | the day of last time | not dated | `AsOf` (kind `last_time`) beside the cost |
| *Coming up* fuel estimate | the last 12 months of driving and prices | basis named on the page ✓ | unchanged |
| Listed fuel prices, *Cheapest near me* | the provider's price time | time and "may be out of date" ✓ | unchanged |
| *Next 3 months* and 12-month totals | their items | inherit | the items are dated; a total is not |
| Ask, API, CSV | as the figure | not carried | API `as_of`, `cost_as_of`; Ask `value_as_of`, `last_cost_as_of`; CSV *As of* on *Coming up* (the Ownership CSV already had *Depreciation to*) |

Garage cards show no figure that rests on a valuation, and the digest
quotes none of these figures, so neither needed a change.

---

## Tasks

### 48.1.0 Spec first
- [x] The inventory completed against the code, and added to §8 as a
      cross-cutting rule with the table (48.2 uses the same inventory).
- [x] Open questions A–D decided by the owner and logged (#403–#406);
      §13; `ROADMAP.md` row.

### 48.1.1 The value and the macro
- [x] `Support\AsOf` (date, kind: `valuation` | `quote` | `measurement`
      | `last_time` | `prices` | `window`, and an optional basis
      string), with stale states from the existing thresholds.
- [x] `ui.as_of()`: text, never colour alone; print styles.

### 48.1.2 Applied
- [x] Each service in the inventory returns `AsOf` with its figure
      (`Depreciation`, `Equity`, `ForecastItem`).
- [x] Every page, widget and print view in the inventory uses the macro.
- [x] API: an `as_of` object beside each such figure (additive; the API
      stays `v1`). CSV: an *As of* column on *Coming up*
      (the Ownership report already has *Depreciation to*).
- [x] Ask tools pass the date with the figure (the digest quotes none
      of these figures). Ask's grounding check reads numbers, not these
      objects, and the Ask tests pass unchanged.
- [x] Translations (every shipped locale).

### 48.1.3 Tests
- [x] Each inventory row: date shown when the input isn't today, hidden
      when it is, stale style past its threshold, the owner's time zone
      at the edges.
- [x] A test that fails when a result in the inventory loses its
      `AsOf` (`AsOfCoverageTest`).
- [x] Page budgets (§8) unchanged.
- [ ] Design review (375, 768, 1280 px; light and dark; JS off; print), still pending:
      run before the 48.2 release.
- [x] `ROADMAP.md` row ✅ for 48.1 (released with 48.2).

---

## Acceptance criteria

1. No figure that rests on an old valuation, quote, measurement or price
   reads as current. Its date is beside it, on screen, in print, in the
   API and in Ask.
2. Every estimate names what it is projected from.
3. Definition of done (CLAUDE.md §11) holds.

## Open questions

Decided by the owner on 2026-10-13, before the phase was built; logged as
#403–#406 in [`open-questions.md`](open-questions.md).

- **A. Which figures get a date?** *Decided (#403):* (1) every figure in
  the inventory, whenever its input isn't from today.
- **B. Absolute or relative dates?** *Decided (#404):* (1) absolute
  ("as of 14 Sep 2026"), with the relative form in a tooltip.
- **C. Several dated inputs.** *Decided (#405):* (1) show the oldest, the
  others on the figure's own page.
- **D. The garage cards and dashboard tiles**, where space is tight.
  *Decided (#406):* (1) a short date ("Sep 2026") as text.
