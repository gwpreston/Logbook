# Phase 42 — Fuel saving and economy up as computed insights + release

*Sums done by Logbook; the model keeps only what no single service can
see.*

Status: 🚧 in progress (started 2026-10-09) · file lives in `docs/phases/`

Phase 33.4 (#174) handed three of the prototype's insights to the model,
because the app had no figure for them: *save about £x a year on fuel*,
*economy up*, and *about £x due in the next 3 months*. The model is told
to "work the figures out itself". That contradicts Ask's own system text
(§7.26: never convert or add up numbers; a tool does sums), and Logbook
now has every input needed:

- **Fuel saving** is a sum over figures §7.34 already computes: the
  vehicle's yearly volume × (the price at the usual station − the cheapest
  nearby *effective* price per unit).
- **Economy up** is Phase 25's drift (§7.24 item 7) with the sign
  flipped: the same segments, windows, weighting, threshold and seasonal
  test, judged for an improvement.

Both become **computed insights** (§7.8 *Insights*, never written by a
model). The AI insights keep the slot for patterns no single service can
see, and are held to the no-arithmetic rule. This partly replaces #174.

Read [`CLAUDE.md`](../../CLAUDE.md), [`spec.md`](../../spec.md) §7.3
(segments, reference grade), §7.8 *Insights*, §7.24 item 7, §7.26
(system text, *AI insights*), §7.33 and §7.34 (*Cheapest near me*,
*effective cost*, *usual station*), and [Phase 38](phase-38.md) (insight
topics) first.

**Prerequisites:** [Phase 38](phase-38.md) complete and green.
Independent of Phases 39–41. (Phase 38 added no insight topics or
dismissals; this phase adds the topics, #358.)

---

## Goals

1. **Fuel saving** computed insight.
2. **Economy up** computed insight.
3. **AI insights** no longer asked for these, never asked to work out a
   figure, and kept from repeating a computed insight.
4. An Ask tool for the computed insights. Release.

## Not in scope

- New *Needs attention* items. Insights stay observations, never tasks.
- Electricity prices. No feed lists charging prices (§7.34), so an EV, or
  a plug-in hybrid's electric series, gets no fuel-saving insight.
- The **3-month outlook** as an insight (open question D).
- Any change to *Cheapest near me*, the usual station or the drift check
  themselves.

---

## Spec changes

Written into `spec.md` on 2026-10-09 (42.0), with the decisions below
(#352–#358, #280); where this section and the spec differ, the spec wins.

### §7.8 *Insights*: two new computed insights

Appended to the list in this order (open question E), each only when its
figure exists and the viewer may see it, for the vehicles in view.

**5. Fuel saving** (`fuel_saving`; `ViewCosts`; Fuel stations on and a
price provider enabled; liquid fuel only). Per vehicle:

- **Inputs, all existing:**
  - the **grade**: the vehicle's reference grade (§7.3);
  - **yearly volume**: the vehicle's litres of that grade over the last 12
    months (from fill-ups; needs at least 6 fill-ups and 90 days between
    the first and last, so a new car isn't extrapolated from a week);
    scaled to a year when the vehicle's fill-ups start less than 12
    months ago (#357);
  - the **usual station**: as §7.34 *After a fill-up* defines it, the most
    visited linked station in the last 12 months; and its **listed price**
    for the grade, fresh (no older than 48 hours, §7.34); without one,
    the vehicle's average price paid for the grade over the last 30 days
    (#352); with neither, no insight;
  - the **cheapest nearby**: §7.34's *Cheapest near me* for this vehicle
    and grade around the user's first place (normally *Home*), at the
    default radius, ordered by effective cost; its **effective price per
    unit** = its effective cost ÷ the usual fill (so the detour is
    counted).
- **Figure:** yearly saving = yearly volume × (usual station's listed
  price − cheapest effective price per unit), decimal arithmetic, rounded
  for display only.
- **Shown when** the cheapest is not the usual station and the yearly
  saving is at least 20 in the currency's major unit (fixed, #353), and the usual station and the
  cheapest are in the vehicle's currency (never converted).
- **Wording:** "Could save about £46 a year on fuel" — "Filling the Golf
  at Asda Antrim instead of your usual Tesco Antrim: £1.329/L counting the
  drive there, against £1.369/L, at your 1,150 L a year. Today's prices."
  → *Cheapest near me* for that vehicle and grade. When the usual fill is
  assumed (§7.34's 40 L) or the detour can't be costed, the body says so,
  as *Cheapest near me* does.
- **Why the usual station's listed price, not the average paid** (open
  question A, #352): both sides are today's prices, so a year of price movement
  doesn't count as a saving.

**6. Economy up** (`economy_up`; `fuel` on). Per vehicle and per series
(liquid, electric), exactly §7.24 item 7's test **with the sign flipped**:

- the same *recent* (last 5 checkable segments ending within 120 days, at
  least 3) and *baseline* (the 12 months before, at least 8) windows,
  weighted the same way and compared exactly;
- **flagged** when recent is at least the owner's drift threshold
  (10% liquid, 15% electricity by default) **better** than baseline and,
  when the same months a year earlier hold at least 3 segments, also at
  least that much better than those months. Without them, the body adds
  "This may include the time of year: there's no data for these months
  last year." (the drift item's sentence);
- **wording:** "Economy is up about 12%" — "50.1 mpg over the last 5
  tanks, against your 12-month average of 44.7 mpg." with the **likely
  causes** that apply, from the drift item's fixed sentences that make
  sense for an improvement: a grade switch, new tyres fitted in the
  window, and "Longer tanks than usual often mean more motorway driving"
  when the recent mean distance is over twice the baseline median.
  → the Fuel tab's economy trend;
- it uses the same code path as the drift item (one judgement, two
  outcomes), so the two can never disagree, and a vehicle can never show
  both.

### §7.26 *AI insights*

- The paragraph listing the prototype's *economy up*, *3-month outlook*
  and *yearly fuel saving* is replaced: those are computed (§7.8) or not
  shown (D).
- **No arithmetic:** the request's instructions follow Ask's system text:
  use only figures tools return, using their display strings unchanged,
  and never add, subtract, average, convert or project a number. "It
  works the figures out itself" is removed.
- **No repeats:** the computed insights for the user's vehicles are given
  to the model (their titles and vehicles) with an instruction not to
  repeat them, and are **enforced on reading**: the model tags each AI
  insight with a topic (`fuel_cost`, `economy`, `other`) and its vehicles
  (#358), and an AI insight with topic `fuel_cost` for a vehicle showing *Fuel
  saving*, or `economy` for a vehicle showing *Economy up*, is dropped.
- **What it's for**, in the instructions: patterns across services or
  vehicles that no single computed insight covers.
- **Unmatched figures** (#354): an AI insight with a figure no tool
  returned is dropped on reading; Ask's answers keep the highlight.

### §8 *Page budgets*

- The Insights page joins the dashboard and the overview: at most 60
  queries, the same for 1 vehicle as for 10 (#280). *Fuel saving* reads
  the stations and prices around the place once for every vehicle, not a
  search per vehicle, so the dashboard stays within its 60 too.

### §7.26 *Tools*

- `computed_insights(vehicles?)`: the §7.8 insights the user would see,
  with their figures as raw values and display strings. Ask can answer
  "How much could I save on fuel?" from it, grounded, and the AI insights
  job uses it for the no-repeats list. Also an MCP read tool.

---

## Decisions (and why)

- **Logbook does sums; the model doesn't.** A figure the model computes
  can only be marked "not provided" by the grounding check; a figure
  Logbook computes is exact, tested and the same everywhere.
- **Reuse, don't rebuild.** Effective cost, the usual station and the
  drift judgement are tested, and their wording is reviewed. New code is
  the two sums and the sentences.
- **Today against today.** Comparing a year's average paid with today's
  cheapest would count general price changes as savings.
- **Enforce the no-repeat rule on reading.** As with dismissals, the
  instruction saves a slot; the filter is the guarantee.

---

## Tasks

### 42.0 Spec first
- [x] §7.8, §7.26 (*AI insights*, *Tools*), §8, §13; #174 marked as
      partly replaced by Phase 42; open questions A–E decided (#352–#356)
      with #357–#358 found while starting, and #280; `ROADMAP.md` row;
      [Phase 44](phase-44.md) written for the 3-month outlook (#355).

### 42.1 Computed insights
- [ ] `Service\Insights\FuelSaving` on the existing *Cheapest near me*,
      usual-station and volume services; the 30-day average fallback
      (#352); the yearly volume scaled under 12 months (#357); the
      nearby stations and prices read once per page for every vehicle.
- [ ] The drift judgement returns both outcomes; `economy_up` reads the
      improvement; the *Needs attention* item is unchanged.
- [ ] Widget and Insights page cards, icons, tones, links; translations
      (every shipped locale, ICU plurals and currency).

### 42.2 AI insights
- [ ] Instructions: no arithmetic, the computed-insight list, the new
      purpose, `topic` and `vehicles` in the asked shape (#358); the old
      three removed.
- [ ] Reading filters: computed-insight repeats (#358) and unmatched
      figures (#354), on the page and in the widget.
- [ ] `computed_insights` tool for Ask and MCP.
- [ ] `bin/ai-eval.php`: fixtures checking AI insights carry no figure
      absent from their tool results.

### 42.3 Tests
- [ ] **Fuel saving:** the worked example above to the penny; not shown
      when the cheapest is the usual station, below the threshold, with a
      stale usual price, under 6 fill-ups or 90 days, without a place,
      without `ViewCosts`, for an EV, with currencies differing, with
      the provider off; assumed fill and uncostable detour wording.
- [ ] **Economy up:** mirrors every drift test with the sign flipped; a
      vehicle never shows both; the seasonal sentence; causes.
- [ ] **Fuel saving extras:** the 30-day-average fallback and its
      wording; scaling under 12 months.
- [ ] **AI:** a returned `fuel_cost` or `economy` insight for a vehicle
      with the computed one is dropped; one with an unmatched figure is
      dropped; an untagged (older) one is kept as `other`; the
      instructions contain the no-arithmetic text; `computed_insights`
      matches the widget.
- [ ] **Budgets:** the Insights page within 60 queries, the same for 1
      and 10 vehicles; the dashboard still within 60 with *Fuel saving*
      on.
- [ ] Suite green on every engine; coverage at or above the floor.

### 42.4 Release
- [ ] `VERSION` → next minor; `CHANGELOG.md` (*Added* — fuel saving and
      economy up insights; *Changed* — AI insights no longer work out
      figures or repeat computed insights). No migration.
- [ ] README, `docs/ai.md`; `ROADMAP.md` row ✅. Tag once merged.

---

## Acceptance criteria

1. *Fuel saving* and *Economy up* appear from Logbook's own figures, with
   or without AI.
2. No AI insight repeats them, and AI insights are not asked to compute
   any figure.
3. Ask answers "how much could I save" from the computed figure.
4. Definition of done (CLAUDE.md §11) holds.

## Open questions

All decided by the owner on 2026-10-09, before the phase was built;
logged in [`open-questions.md`](open-questions.md).

- **A (#352) — Which "price you usually pay"?** *Decided:* the usual
  station's current listed price (1); when it has no fresh listed price,
  the vehicle's average paid for the grade over the last 30 days (3),
  said in the body.
- **B (#353) — The threshold.** *Decided:* fixed, at least 20 major
  currency units a year, recorded in §7.8.
- **C (#354) — Unmatched figures in AI insights.** *Decided:* (2), drop
  that insight. Ask's answers keep the highlight.
- **D (#355) — The 3-month outlook.** *Decided:* (1), not an insight; a
  *Next 3 months* total on the *Coming up* page and widget, in its own
  later phase, [Phase 44](phase-44.md).
- **E (#356) — Order in the widget.** *Decided:* *Fuel saving* second,
  after *Shopping around*; *Economy up* last.
- **#357 — Yearly volume under 12 months** (found while starting).
  *Decided:* scaled to a year (litres after the first fill-up ÷ the days
  from the first to the last × 365), and the body says so.
- **#358 — AI insight topics** (found while starting: Phase 38 added no
  topics or dismissals). *Decided:* the model tags each insight with a
  `topic` and its `vehicles`, stored with the set; the no-repeat filter
  matches on them; untagged ones count as `other`.
- **#280 — An Insights page budget** (Phase 38). *Decided:* the page
  joins §8's budgets at 60 queries, built here.
- **Carried:** #221, #279 and #346–#349 change nothing in this phase.
