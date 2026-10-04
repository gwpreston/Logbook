# Phase 33.3 — The vehicle pages: Finance as a tab, Insights, trips, incidents and tyres

*Every tab of a vehicle looks like the same page, and the cards the
prototype added are there.*

Status: 📋 planned · no release of its own (ships with Phase 33.4 as
**v3.0.0**) · file lives in `docs/phases/`

A vehicle's page has tabs (Overview, History, Mileage, Trips, Fuel,
Maintenance, Tyres, Documents, Expenses, Incidents), but Finance is a
button in the header that leads to a separate page, and the vehicle's
name is a large heading on Overview and a smaller line on every other tab.
The prototype in `design-import/` also has cards the app doesn't:
*Insights* on the overview, *Business and personal* and *Your vehicles* on
trips, a new incidents layout and *Current tyres*. This phase brings them
in.

Read [`CLAUDE.md`](../../CLAUDE.md), [`spec.md`](../../spec.md) §7.1,
§7.17 (tyres), §7.22–7.23 (trips and claims), §7.24–7.25 (Needs attention
and its checks), §7.29 (incidents), §7.32 (finance), §7.35 (true cost)
and §8 first, and the *Working from the prototype* section of
[Phase 33.2](phase-33.2.md), which this phase follows screen by screen.

**Prerequisites:** [Phase 33.2](phase-33.2.md) built.

---

## Goals

1. **One vehicle header:** the vehicle's name is the same size on every
   tab.
2. **Finance as a tab** beside the others, laid out as the prototype's
   finance content.
3. **Insights** on the overview, if the prototype's card can be built
   from figures Logbook already works out.
4. **Trips:** a *Business and personal* card, and *Your vehicles* three
   to a row on wide screens.
5. **Incidents** laid out, and doing what, the prototype's incidents
   content does (within what is decided below).
6. **Tyres:** *Current tyres* replaces *On the vehicle*.

## Not in scope

- New stored data, new figures or new checks. Where a prototype card
  needs one, it is an open question, not a task.
- Ask, Fuel stations and *Cost of ownership* ([33.4](phase-33.4.md)).

---

## Spec additions

### §8 Vehicle header (changed)

> The vehicle's name looks the same on every tab: one style,
> `vehicle-hero__name`, taken from the prototype (recorded in *Prototype
> notes*). On Overview it is the page's `<h1>`; on other tabs the tab's own
> title is the `<h1>` and the name keeps the same look as a `<p>`, so the
> heading order stays right and the page doesn't jump between tabs. A
> rule in `DesignAlignmentTest` compares the computed class on every tab.

### §7.32 Finance (changed)

> **Finance tab:** `/vehicles/{id}/finance` becomes a tab (icon
> `account_balance`) after *Expenses*, shown to those `finance_menu()`
> allows today (Manage with costs) when the vehicle has an agreement or
> can have one. With no agreement the tab shows the empty state with
> *Add agreement*. The header's *Finance* button goes. The tab's content
> is laid out as the prototype's finance content; the figures are Phase
> 29's, unchanged. Its add, edit, end and show pages keep their URLs and
> open in the tab's frame.

### §7.22 Trips (changed)

> **Business and personal** card (trips module on; the vehicle's trips
> tab): for the period chosen on the page (default the current UK tax
> year for GB users, otherwise the calendar year; as the claim report):
>
> - *Business* = the distance of the vehicle's business trips in the
>   period;
> - *Personal* = the distance driven in the period (§7.7, from the mileage
>   log) minus *Business*; never the sum of logged private trips (Phase
>   22's rule);
> - a two-part bar and the percentages, to whole percent.
>
> When business is more than the distance driven (readings missing), the
> card says so and links to the Mileage tab instead of showing a negative
> personal figure. Without distance driven in the period: "Not enough
> readings this year". Destinations never appear on it.
>
> **Your vehicles** on the trips page: cards three to a row from the
> sidebar breakpoint (≥ 960 px), two on tablets, one on phones, same card
> as the garage's.

### §7.29 Incidents (changed)

> The incidents tab and an incident's page are laid out as the
> prototype's incidents content (recorded in *Prototype notes*). What is
> recorded and who may see it is unchanged; anything the prototype shows
> that isn't recorded today is an open question.

### §7.17 Tyres (changed)

> The tyres tab's *On the vehicle* card is replaced by **Current tyres**,
> laid out as the prototype's card. It shows what *On the vehicle* showed
> (each fitted position, brand and model, size, season, age from DOT,
> tread and the wear estimate, labelled as an estimate) in the prototype's
> arrangement.

### §7.1 Overview — Insights (new; subject to the audit)

> An **Insights** card on the overview, laid out as the prototype's card,
> listing short statements about the vehicle. Each statement:
>
> - comes from a figure Logbook already computes (economy trend and drift
>   §7.24–7.25, true cost and *What changed* §7.35, fuel by grade §7.3,
>   tyre wear §7.17, mileage against a finance allowance §7.32,
>   *Shopping around* §7.34);
> - is worked out by Logbook, never written by a model, and links to
>   where the figure is shown;
> - respects modules, sharing levels and `ViewCosts`;
> - is not a duplicate of *Needs attention* (things to fix) or *Coming up*
>   (things due): insights are observations, not tasks.
>
> At most four, in a fixed priority order (recorded when the audit maps
> the prototype's examples). No card when there is nothing to say.

---

## Tasks

### 33.3.0 Spec first
- [ ] `spec.md` §7.1, §7.17, §7.22, §7.29, §7.32 and §8 as above, after the
      audit settles the details; §13 entry.

### 33.3.1 Prototype audit
- [ ] *Prototype notes* for: the vehicle header on two tabs, overview
      *Insights*, trips (*Business and personal*, *Your vehicles*),
      incidents (tab and incident page), finance, tyres *Current tyres*.
- [ ] For *Insights*: map each example in the prototype to an existing
      figure, or list it as an open question.
- [ ] For finance: confirm which prototype content the brief means (see
      open questions).

### 33.3.2 Vehicle header and Finance tab
- [ ] One `vehicle-hero__name` style; heading rules as above.
- [ ] Finance in the tab list, header button removed; tab content to the
      prototype; existing finance pages within the tab frame.

### 33.3.3 Overview Insights
- [ ] `InsightsService` returning typed insights from existing services;
      `insights` card on the overview.
- [ ] Translations with ICU plurals, units and currency.

### 33.3.4 Trips
- [ ] *Business and personal* card from `TripService` and the distance
      driven calculation.
- [ ] *Your vehicles* grid: 3 / 2 / 1 per row.

### 33.3.5 Incidents
- [ ] Tab and incident page to the prototype, from existing data.

### 33.3.6 Tyres
- [ ] *Current tyres* card replacing *On the vehicle*.

### 33.3.7 Tests
- [ ] Every vehicle tab: the name has the same class and computed size;
      exactly one `<h1>`.
- [ ] Finance tab shown to Manage with costs, absent for View, Log and
      no-costs shares; old finance URLs still answer; header button gone.
- [ ] *Business and personal*: worked example (12,400 driven, 3,100
      business → 25% / 75%); business over driven shows the warning; no
      destinations in the HTML.
- [ ] *Insights*: each kind appears from its fixture and never without
      `ViewCosts` for a cost insight; module off removes its insights;
      nothing when there's nothing to say.
- [ ] *Current tyres* shows each fitted position with estimate labels, car
      and motorbike.
- [ ] Integration suite green on every engine; design-reviewer clean of
      HIGH findings.

---

## Prototype notes

*Filled in by task 33.3.1.*

---

## Acceptance criteria

1. The vehicle's name is the same size on Overview, History, Mileage,
   Trips and every other tab.
2. Finance is a tab beside the others, laid out as the prototype.
3. The overview has an *Insights* card whose every line links to the
   figure behind it (or the owner has decided against it).
4. Trips shows a *Business and personal* split that adds up to the
   distance driven, and *Your vehicles* three to a row on a desktop.
5. Incidents and *Current tyres* look like the prototype.
6. Definition of done (CLAUDE.md §11) holds.

## Open questions

- **Finance bullet:** the brief's line under "Move finance" repeats the
  incidents wording ("Review the content of incidents content…").
  Drafted as meaning the prototype's **finance** content. Correct?
- **Insights the app can't back:** any prototype insight without an
  existing figure (listed by 33.3.1). Build the figure in a later phase,
  or leave it out?
- **Insights from AI?** If the prototype's insights read as generated
  text, the draft still computes them; a model-written version would sit
  behind the AI module (§7.25) and its grounding check. Wanted?
- **Business and personal period:** tax year for GB users and calendar
  year otherwise (drafted), or a period picker?
- **"Your vehicles" on trips:** the brief places it in the trip content.
  Is it the fleet trips page, or a card on each vehicle's trips tab?
