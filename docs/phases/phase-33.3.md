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

> **Business and private** card (the prototype's *Business and
> personal*; *private* is the app's word throughout) (trips module on; the vehicle's trips
> tab): for the user's current tax year (their tax year start, spec §6
> *Trip settings*; as the claim report):
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
> readings this year". A viewer who can't see every driver's business
> trips sees the distance driven only, with no split (as the tab does
> today). Destinations never appear on it.
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
- [x] *Prototype notes* for: the vehicle header on two tabs, overview
      *Insights*, trips (*Business and personal*, *Your vehicles*),
      incidents (tab and incident page), finance, tyres *Current tyres*.
- [x] For *Insights*: map each example in the prototype to an existing
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

Audited 2026-10-05 against `design-import/Logbook.dc.html` (line numbers
are that file's). Sorted as in Phase 33.2: **L** layout and style (build),
**H** behaviour or data the app has (build from existing services), **N**
new (not built; see *Open questions*), **K** kept as the app does it
because an earlier decision or spec rule says so.

### Vehicle header (every tab; l.389–434)

The prototype draws the same header on every tab; nothing in it changes
with the tab.

- **L** Name: Outfit 28 px, 600, -.02em, margin 8 px 0 2 px (l.411), the
  app's global `h1`. The app shows that on Overview and `--fs-xl` (20 px)
  on every other tab (`.vehicle-hero__name`). Build: one style at
  `--fs-3xl`.
- **L** The prototype has **no per-tab title**: tab content starts with
  cards titled by 17 px `<h3>`s. The app's tabs have a 26 px
  `section-title` `<h1>`, which would then sit under a 28 px name (#180).
- **L** Tab order: … Documents, **Incidents, Finance, Expenses**, Cost of
  ownership, Sale pack (l.1677). The app has … Documents, Expenses,
  Incidents (#179).
- **L** Tab icons: Overview `dashboard` (app `space_dashboard`), Documents
  `description` (app `verified_user`), Finance `account_balance`.
- **K** Tab labels *History* and *Maintenance* (the prototype's
  *Timeline* and *Service*); header actions Sharing, Prepare for sale and
  Delete (the prototype has no sharing or permissions).
- **K** Sale pack and Cost of ownership as tabs are not in this phase
  (Cost of ownership is 33.4).
- **L** A four-tile stat strip under the tabs on Overview, Mileage, Fuel,
  Trips, Incidents (none on Finance). Built in this phase only for Trips
  and Incidents, from existing figures.

### Overview *Insights* (l.312–322, l.958–985, `insights()` l.1552–1569)

- **Not on the vehicle overview.** The prototype's vehicle Overview
  (l.436–464) has *Coming up* and *Recent activity* only. Insights appear
  as a **dashboard widget** (the first 2, fleet-wide, not filtered by the
  vehicle chip; "All insights") and an **Insights page** in the nav
  (`auto_awesome`, "Patterns and AI answers") with *Ask Logbook* above
  every insight card (#178).
- **K** Every insight is a computed template string; only *Ask* uses a
  model (#175 obsolete).
- **L** Widget row: whole-row button, 36 px tone tile, title 14 px bold,
  body 12.5 px muted clamped to 2 lines; empty "Nothing stands out right
  now." Page card: 40 px tile, 15/13.5 px, accent action link with
  `arrow_forward`; grid min 380 px.

The prototype's seven insights, in its order, against the app:

| # | Prototype | App | Sort |
|---|---|---|---|
| 1 | Tyre at x mm, reaches the legal limit in about y | `TyreWearEstimate` (to *replace at*, not legal) | **K**, duplicate of *Needs attention* / *Coming up*: left out |
| 2a | Economy down x % (3 months against 12, ≥ 4 %) | `EconomyDrift` (5 tanks, 10 %/15 %) | **K**, duplicate of *Needs attention*: left out |
| 2b | Economy up x % | none: an improvement is never flagged (§7.24) | **N** (#174) |
| 3 | About £x due in the next 3 months, converted | *Coming up* (12 months, per currency) | **K**, duplicate: left out |
| 4 | Save about £x a year on fuel at the cheapest nearby | none; *Shopping around* is realised, not projected | **N** (#174) |
| 5 | £x claimable in business mileage this tax year | `ClaimReportService::thisYear` | **H** (rates and tax year the app's, **K**) |
| 6 | {vehicle} is your cheapest to run, converted | `VehicleCost::costPerKm` | **H** among same-currency vehicles only (**K**); fleet only |
| 7 | {vehicle} has about £x of equity (15 %/yr estimate) | `Finance\Equity` with a valuation | **H**; **K** no estimate: no valuation, no insight |

The draft's other sources (fuel by grade, *What changed*, finance
mileage allowance, *Shopping around*) have no prototype example (#174).

### Finance (tab; l.671–701, sheet l.1122–1136)

- **L** A tab, `account_balance`, between Incidents and Expenses; no stat
  tiles. With the shared vehicle header (today `finance/index.twig` has
  its own back link and `<h1>`).
- **L** Empty: one card, 44 px accent-soft tile, "How did you buy it?",
  a lead, an accent-soft add button. **K** Wording to the app's scope (HP,
  PCP, loan, lease; no "purchase" record).
- **L** With an agreement, a grid (min 340 px) of cards:
  1. **Agreement:** type as title, lender · agreement number under it,
     *Edit*; *Monthly payment* as a 32 px figure; a 10 px progress bar
     "Payment k of n · Ends {Mon YYYY}"; two tiles *Paid so far* and
     *Still to pay*; hairline key/value rows (deposit, amount borrowed,
     APR, term, optional final payment, total interest, total payable); an
     end note.
  2. **Purchase:** price, date, mileage when bought, seller, how paid
     (#182).
  3. **Value & equity:** current value, settlement (est.), equity.
- **H** Monthly payment, payment k of n, end date, still to pay, deposit,
  amount of credit, APR, optional final payment, total payable,
  settlement, equity: all existing figures. *Paid so far* is
  `AgreementFigures::paidTotal()`, existing but not shown today.
- **K** *Still to pay* for PCP shows the optional final payment beside the
  remaining amount, not inside it (§7.32). *Total interest* is the app's
  *Cost of credit*. The payment is typed and checked, never computed from
  the APR as the prototype's sheet does. The value is the latest valuation:
  no 15 %-a-year estimate (§7.1 "There is no depreciation curve"). The
  prototype's combined *Add purchase & finance* sheet: the app keeps the
  vehicle form and the finance form. Finance stays out of History.
- **H** Kept, not drawn: loan and lease, mileage allowance, half-paid
  point, schedule with marks, extras, quotes, End, CSV, print, warnings,
  earlier agreements (#181).
- **N** Seller and mileage when bought are not stored (#182). The PCP end
  note "you can pay … and keep it, hand it back, or part-exchange" is new
  wording against §7.32's "nothing recommends" (#183).

### Trips tab (l.485–502, JS l.1699–1702)

- **K** The prototype's *Personal* is the sum of logged non-business
  trips; the app's private is distance driven minus business (Phase 22,
  `MileageSplit`), as drafted.
- **H** Period: the prototype hard-codes the UK tax year with no picker;
  the app already has each user's tax year start (§6 *Trip settings*,
  §7.23) and the tab uses it (#176 answered).
- **H** The app's *exceeds* warning and *total only* view (a viewer who
  can't see other drivers' trips sees distance driven only, no split)
  stay; the draft now says so.
- **L** Bar 14 px, business accent, private `--c-other`, legend with
  swatches; whole percents that add to 100 (round business, private =
  100 − business); bar `aria-hidden`, text carries the figures.
- **K** "Private", the app's word everywhere, not "Personal": the card is
  *Business and private*.
- **K** Claim value at the user's rate sets, not hard-coded HMRC; no
  hard-coded rate note.
- **H** Stat strip: Business, Private, Claim value, Trips (count this tax
  year).
- **L** *Log trip* and *Export CSV* may move into the card header.
- **"Your vehicles":** not on trips anywhere. The prototype has no fleet
  trips page and no vehicles card on the trips tab. *Your vehicles* is
  the dashboard widget (l.237–255, one scrolling row of 220 px tiles); the
  only "three to a row" grid is the garage (l.361), which the app's
  `.vehicle-grid` already matches (#177).

### Incidents (tab l.651–669, l.1711–1715; sheet l.1136–1190; claims l.907–920)

- **L** A card grid (min 320 px) in place of the list; whole card links to
  the incident page. An icon per type (mapping on the enum). A status pill
  from `claim_status` ("Claim open", "Claim settled", "No claim", …) with
  the app's other badges kept. A two-column meta grid: fault, insurer,
  insurer paid, net cost.
- **H** Location and description on the card, only where `IncidentView`
  gives them (detail fields). Stat strip: incidents, claims ("n at
  fault" when fault is visible), insurer paid and net cost (`ViewCosts`,
  per currency).
- **K** The prototype's *Your cost = repair cost + excess*: the app's cost
  is the net of linked records less payouts; the excess is a detail.
  No cost field on the incident. Claim on/off is `claim_status`; the
  description stays optional; open incidents first; the app's extra
  types, faults and statuses; damage, severity, *added by*, paperclip.
- **K** The prototype has **no incident page** (a card opens the edit
  sheet). The app's page (linked records, costs, photos, *Update from a
  letter*, reminder) stays and is laid out in the prototype's card
  language: a header with the type tile, title, "date · location", the
  pill; the description first; the meta grid in place of the `dl`.
- **H** Claims history: stat tiles (claims in 5 years, since last fault
  claim, paid by insurers, excess paid); rows as a list on screen, the
  table kept for print and CSV.
- **N** A *Breakdown* type; *Copy for insurance quote* (#185).

### Tyres *Current tyres* (l.602–619, `tyreState` l.1520–1524)

- **L** Title *Current tyres*; per position: uppercase muted label and
  status pill on one row, the depth large, a bar, brand over size, then a
  hairline and footer lines. Two columns; *Tread check* as a visible
  button beside *Fit tyres*.
- **H** Pill from `TyreJudgement`/legal flags, text and icon. Depth is the
  latest measurement with "Checked {date}". Distance covered.
- **K** No assumed depth when nothing is measured (the prototype shows 8 or
  6 mm); the estimate is the app's to *replace at*, labelled "about", not
  "≈ to legal limit". The spare, age from DOT and season stay. No
  hard-coded UK note (#11).
- **N** The bar's full scale: the prototype divides by an assumed new
  depth (#184). "Fitted {month}" (derived from changes but not in
  `TyreView`) and a thresholds note from the user's settings (#185).

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

Numbers are the log's ([`open-questions.md`](open-questions.md)).

- **#173 Finance bullet:** the brief's line under "Move finance" repeats
  the incidents wording ("Review the content of incidents content…").
  Drafted as meaning the prototype's **finance** content. Correct?
  *Needs a decision.*
- **#174 Insights the app can't back:** *economy up*, a 3-month cost
  outlook and a yearly fuel saving against the cheapest nearby station
  have no figure today; the draft's fuel by grade, *What changed*,
  mileage allowance and *Shopping around* have no prototype example.
  Build the figures in a later phase, or leave them out? *Needs a
  decision.*
- **#175 Insights from AI?** *Obsolete:* the prototype's insights are
  computed template strings; only *Ask* uses a model, and that is
  [33.4](phase-33.4.md).
- **#176 Business and personal period:** *Answered:* the user's tax year
  start (spec §6 *Trip settings*, §7.23), which the trips tab already uses;
  the prototype has no picker. The draft now says "the user's current
  tax year".
- **#177 "Your vehicles" on trips:** the prototype has it on neither a
  fleet trips page nor the trips tab: it is the dashboard widget, and the
  only three-to-a-row grid is the garage, which the app already matches.
  Which did the brief mean, or drop it? *Needs a decision.* (found by the
  audit)
- **#178 Where Insights lives:** the prototype has no card on the vehicle
  overview; it has a fleet-wide dashboard widget (2 items) and an
  Insights page with *Ask* above the cards. *Needs a decision.* (found by
  the audit)
- **#179 Tab order:** the prototype's … Documents, Incidents, Finance,
  Expenses, or the draft's Finance after Expenses? *Needs a decision.*
  (found by the audit)
- **#180 Name above the tab's title:** at 28 px on every tab the name sits
  above each tab's 26 px `<h1>`; the prototype has no tab title. *Needs a
  decision.* (found by the audit)
- **#181 Finance tab with several agreements, and the overview card:** the
  prototype has one record. *Needs a decision.* (found by the audit)
- **#182 Purchase card:** seller and mileage when bought aren't stored.
  *Needs a decision.* (found by the audit)
- **#183 PCP end note** listing keep, hand back or part-exchange, against
  §7.32's "nothing recommends". *Needs a decision.* (found by the audit)
- **#184 Tyre bar scale:** the prototype divides by an assumed new depth.
  *Needs a decision.* (found by the audit)
- **#185 Other prototype extras:** a *Breakdown* incident type, *Copy for
  insurance quote*, a period picker on *Business and private*, "Fitted
  {month}" on tyre cards, a tyre thresholds note. *Needs a decision.*
  (found by the audit)
