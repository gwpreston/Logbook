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
   tab; each tab's own title stays its `<h1>`, visually hidden (#180).
   Tabs in the prototype's order (#179).
2. **Finance as a tab** between Incidents and Expenses that is the active
   agreement's page in the prototype's cards (#173, #181), with a
   Purchase card fed by two new vehicle fields: *Bought from* and
   *Mileage when bought* (#182).
3. **Insights** as a dashboard widget computed from existing figures
   (#178); the Insights page and AI insights are Phase 33.4's (#174).
4. **Trips:** a *Business and private* card on the trips tab; the
   dashboard's *Your vehicles* three to a row on wide screens (#177).
5. **Incidents** laid out as the prototype: cards, a stat strip, a
   restyled incident page, a *Breakdown* type, the claims history's stats
   and *Copy for insurance quote* (#185).
6. **Tyres:** *Current tyres* replaces *On the vehicle*, with a tread bar
   (#184), *Fitted {month}* and a thresholds note (#185).

## Not in scope

- New figures or checks beyond what the owner decided above. The new
  stored data is exactly: `vehicles.purchase_seller`, the `purchase`
  odometer reading source and the `breakdown` incident type (one
  migration).
- A period picker on *Business and private* (parked, spec §12).
- The Insights page, AI insights, Ask, Fuel stations and *Cost of
  ownership* ([33.4](phase-33.4.md)).

---

## Spec additions

Written into [`spec.md`](../../spec.md) on 2026-10-05 (task 33.3.0), after
the audit and the owner's decisions:

- §6 Vehicle `purchase_seller`; OdometerReading source `purchase`;
  Incident type `breakdown`.
- §7.1 *Bought from and mileage when bought*; the Ownership card.
- §7.2 tab order.
- §7.8 *Your vehicles layout* and the **Insights** widget (four computed
  kinds: shopping around, business mileage, cheapest to run, equity; two
  shown).
- §7.17 *Current tyres*.
- §7.22 the trips tab's tiles and the *Business and private* card.
- §7.26 *AI insights* (built in 33.4).
- §7.29 the incidents layout, *Breakdown*, the claims history's stats and
  *Copy for insurance quote*; the incident page's layout.
- §7.32 the *Finance tab*.
- §8 *Vehicle header*.
- §12 the period picker; §13 this phase.

---

## Tasks

### 33.3.0 Spec first
- [x] `spec.md` as above; §13 entry.

### 33.3.1 Prototype audit
- [x] *Prototype notes* for: the vehicle header on two tabs, overview
      *Insights*, trips (*Business and personal*, *Your vehicles*),
      incidents (tab and incident page), finance, tyres *Current tyres*.
- [x] For *Insights*: map each example in the prototype to an existing
      figure, or list it as an open question.
- [x] For finance: confirm which prototype content the brief means
      (#173).

### 33.3.2 Vehicle header, tab order and Finance tab
- [x] One `vehicle-hero__name` style; tab titles visually hidden `<h1>`s;
      tab order and icons.
- [x] Finance in the tab list, header button removed; the tab is the
      active agreement's page in the prototype's cards (*Paid so far*,
      Purchase, Value & equity, the PCP end note); earlier agreements
      below; every finance page within the tab frame.

### 33.3.3 Purchase fields
- [x] Migration: `vehicles.purchase_seller`; the `purchase` reading
      source (rollback turns them `manual`).
- [x] Vehicle form *Bought from* and *Mileage when bought*, validation,
      the Ownership card; Mileage tab label; API and export where vehicle
      fields are listed.

### 33.3.4 Insights widget
- [ ] `InsightsService` returning typed insights from existing services;
      `insights` widget (two shown).
- [ ] Translations with ICU plurals, units and currency.

### 33.3.5 Trips and Your vehicles
- [ ] Trips tab tiles and the *Business and private* card.
- [ ] *Your vehicles* widget tiles 3 / 2 / 1 per row.

### 33.3.6 Incidents
- [ ] Tab as cards with the stat strip; incident page layout; `breakdown`
      type; claims history stats, list and *Copy for insurance quote*.

### 33.3.7 Tyres
- [ ] *Current tyres* replacing *On the vehicle*: pill, depth, bar,
      *Fitted*, the note, *Check tread* button.

### 33.3.8 Tests
- [x] Every vehicle tab: the name has the same class; exactly one `<h1>`.
- [x] Finance tab shown to Manage with costs, absent for View, Log and
      no-costs shares; old finance URLs still answer; header button gone.
- [x] Purchase seller and mileage: saved, moved, removed; date rules;
      migration rolls back.
- [ ] *Business and private*: worked example (12,400 driven, 3,100
      business → 25% / 75%); business over driven shows the warning; no
      destinations in the HTML.
- [ ] *Insights*: each kind appears from its fixture and never without
      `ViewCosts` for a cost insight; module off removes its insights;
      nothing when there's nothing to say.
- [ ] *Current tyres* shows each fitted position with estimate labels, car
      and motorbike; the bar only with two measurements.
- [ ] Incidents: breakdown type; stats respect detail and cost access.
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
3. The dashboard has an *Insights* widget whose every line links to the
   figure behind it.
4. Trips shows a *Business and private* split that adds up to the
   distance driven, and the dashboard's *Your vehicles* is three to a row
   on a desktop.
5. Incidents and *Current tyres* look like the prototype.
6. Definition of done (CLAUDE.md §11) holds.

## Open questions

Numbers are the log's ([`open-questions.md`](open-questions.md)).

- **#173 Finance bullet:** the brief's line under "Move finance" repeats
  the incidents wording ("Review the content of incidents content…").
  Drafted as meaning the prototype's **finance** content. Correct?
  *Decided 2026-10-05:* yes, the finance content.
- **#174 Insights the app can't back:** *economy up*, a 3-month cost
  outlook and a yearly fuel saving against the cheapest nearby station
  have no figure today; the draft's fuel by grade, *What changed*,
  mileage allowance and *Shopping around* have no prototype example.
  Build the figures in a later phase, or leave them out? *Decided
  2026-10-05:* built in [33.4](phase-33.4.md) as AI insights: the model
  works them out from the *Ask* tools, daily per user, cached, with
  *Refresh*, only when AI is on, with the grounding check (spec §7.26
  *AI insights*).
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
  Which did the brief mean, or drop it? *Decided 2026-10-05:* the dashboard widget, 3 / 2 / 1 per row (spec §7.8). (found by the
  audit)
- **#178 Where Insights lives:** the prototype has no card on the vehicle
  overview; it has a fleet-wide dashboard widget (2 items) and an
  Insights page with *Ask* above the cards. *Decided 2026-10-05:* a computed dashboard widget now; the page with *Ask* in 33.4 (spec §7.8). (found by
  the audit)
- **#179 Tab order:** the prototype's … Documents, Incidents, Finance,
  Expenses, or the draft's Finance after Expenses? *Decided 2026-10-05:* the prototype's order (spec §7.2).
  (found by the audit)
- **#180 Name above the tab's title:** at 28 px on every tab the name sits
  above each tab's 26 px `<h1>`; the prototype has no tab title.
  *Decided 2026-10-05:* the tab titles stay `<h1>`s, visually hidden
  (spec §8). (found by the audit)
- **#181 Finance tab with several agreements, and the overview card:** the
  prototype has one record. *Decided 2026-10-05:* the tab is the active agreement's page in the prototype's cards, earlier agreements below; the overview card stays (spec §7.32). (found by the audit)
- **#182 Purchase card:** seller and mileage when bought aren't stored.
  *Decided 2026-10-05:* stored: *Bought from* on the vehicle, and the mileage as a dated `purchase` reading (spec §6, §7.1). (found by the audit)
- **#183 PCP end note** listing keep, hand back or part-exchange, against
  §7.32's "nothing recommends". *Decided 2026-10-05:* kept, neutral (spec §7.32). (found by the audit)
- **#184 Tyre bar scale:** the prototype divides by an assumed new depth.
  *Decided 2026-10-05:* from the first measured depth to the legal minimum, only with two measurements (spec §7.17). (found by the audit)
- **#185 Other prototype extras:** a *Breakdown* incident type, *Copy for
  insurance quote*, a period picker on *Business and private*, "Fitted
  {month}" on tyre cards, a tyre thresholds note. *Decided 2026-10-05:* build all four extras (*Breakdown*, *Copy for insurance quote*, *Fitted {month}*, the thresholds note); the period picker is parked (spec §12).
  (found by the audit)
