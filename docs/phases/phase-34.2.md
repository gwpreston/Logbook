# Phase 34.2 — Expense breakdown and monthly spend widgets

*Where the money went, and how it moved month to month, without leaving
the dashboard.*

Status: 📋 planned · no release of its own (ships with Phase 34.3 as
**v3.1.0**) · file lives in `docs/phases/`

Reports already answer two questions: *where did the money go* (the
breakdown by group) and *how did spend move* (spend per month, stacked by
group). The dashboard shows neither beyond *Spend this month*. This phase
adds one widget for each, built from the figures Reports already
computes.

Read [`CLAUDE.md`](../../CLAUDE.md), [`spec.md`](../../spec.md) §7.7 (cost
ledger, Reports, months, currencies, costs filter), §7.8 (dashboard,
layout rule, vehicle filter, pinned card), §7.10 (what `reports` off
removes), §7.35 (true cost, which has its own widget) and §8, and
[Phase 18.1](phase-18.1.md) (`ViewCosts`) first.

**Prerequisites:** [Phase 33.4](phase-33.4.md) released as v3.0.0.

---

## Goals

1. An **Expense breakdown** widget (`expense_breakdown`): the period's
   spend split by Reports' groups, with a choice of period.
2. A **Monthly spend** widget (`monthly_expenses`): the last 12 months,
   one stacked bar per month, with a table as the no-JavaScript form.
3. Both follow the vehicle chips and the pinned vehicle, count only
   vehicles the viewer may see costs for, never convert currencies, and go
   away with the `reports` module.
4. No new figure: every number is Reports' own.

## Not in scope

- New ledger groups, new periods beyond those below, budgets or targets.
- A per-vehicle split inside the widgets (Reports has *Spend per vehicle*).
- The true-cost parts (fuel, maintenance, insurance and tax, other,
  depreciation). They answer a different question and have their own
  widget (§7.35, `true_cost`).
- New Ask tools, API endpoints or CSV exports. Reports already has them.
- Changing *Spend this month* (see the first open question).

---

## Spec additions

### §7.8 Dashboard (changed)

> - **Expense breakdown** (id `expense_breakdown`; with `reports` on;
>   appended to saved layouts by the layout rule). For the vehicles in
>   view that the viewer may see costs of:
>   - **Period switch**: *This month*, *Last 12 months* (default) and
>     *This year*, as plain links (`?expenses=this_month|last_12_months|this_year`,
>     keeping `?vehicle=`). It is a URL choice, not a stored setting, so
>     it can be bookmarked and works without JavaScript. An unknown value
>     falls back to the default.
>   - **Per currency**, as Reports does (amounts are never converted): the
>     total, then one row per group in **Reports' own order and labels**
>     with its amount and its share of the total. Shares are rounded by
>     the largest-remainder method so they add to exactly 100%.
>   - **A bar** of the groups' shares above the rows, drawn with CSS (no
>     chart library) in design-token colours. Every row carries its label,
>     amount and percentage as text, so colour is never the only cue. A
>     group whose total is negative is left out of the bar and listed.
>   - Each row links to Reports for the same vehicle selection, the
>     matching period and that group (`group=`, §7.7 *Costs filter*). The
>     widget's title row links to Reports for the period.
>   - **Empty:** "No costs in this period." The widget keeps its place.
> - **Monthly spend** (id `monthly_expenses`; with `reports` on; appended
>   to saved layouts): the last 12 calendar months in the viewer's time
>   zone (this month and the 11 before), every month listed including
>   those with nothing spent. Per currency, a stacked bar chart by group
>   (the same series, colours and order as the Expenses tab's chart, §7.7)
>   and the average per month, divided as Reports divides it (the current
>   month counts). Without JavaScript, and for assistive technology, the
>   same figures are a table (months as rows, groups as columns, as the
>   Mileage widget does); the chart is the enhancement and is hidden from
>   assistive technology. The title row links to Reports for the last 12
>   months.
> - **Both widgets:** vehicle filter and pinned card as every other widget
>   (one vehicle selected shows that vehicle only). Archived vehicles are
>   left out. A viewer who may see costs on none of the vehicles in view
>   does not get the widget; *Customise* lists it as "No vehicles whose
>   costs you can see". Every figure comes from the report service (§7.7),
>   so the rules for fill-ups, maintenance, documents, tyres, ad-hoc
>   expenses and incident payouts are the ledger's, not re-implemented
>   here.
> - **Default order** (fresh dashboards and *Reset layout*): …, *spend this
>   month*, **expense breakdown**, **monthly spend**, *recent fuel*, … (the
>   rest unchanged). Saved layouts get both appended at the end, as every
>   widget added in a later release does.
> - **Cost:** each widget makes one call to the report service for the
>   whole vehicle set, not one per vehicle, and months are grouped in the
>   same pass that groups the ledger.

### §7.10 Feature toggles (changed)

> `reports` off removes the spend widgets: *Spend this month*, *Expense
> breakdown* and *Monthly spend*.

---

## Decisions (and why)

- **Reports' groups, not the true-cost parts.** *Where did the money go*
  is the ledger's question and Reports and the Expenses tab already use
  its groups. Mixing in the true-cost parts would give the same word two
  meanings on one page.
- **A bar and rows, not a donut.** A list with amounts carries more than a
  donut can, works without JavaScript, matches the *Cost of ownership*
  bar of Phase 33.4, and needs no chart code.
- **Period as a URL choice.** The dashboard already keeps its filter in
  the URL (`?vehicle=`). A stored per-widget setting would be a new
  preference to migrate and explain.
- **Monthly spend is fixed to 12 months.** It matches the Expenses tab,
  which is always the last 12 months whatever period is chosen.

---

## Tasks

### 34.2.0 Spec first
- [ ] `spec.md` §7.8 and §7.10 as above; §13 entry; `ROADMAP.md` row and
      section.

### 34.2.1 Code
- [ ] Register `expense_breakdown` and `monthly_expenses` where the other
      widgets are registered, with the default order above and the
      append-to-saved-layouts rule.
- [ ] A dashboard service method for each, reading the report service once
      for the vehicle set (filtered by `ViewCosts` and archived status).
      Largest-remainder rounding in one small, tested helper.
- [ ] The period map from `?expenses=` to Reports' presets in one place,
      used by the widget's links as well as its figures.

### 34.2.2 Templates, CSS, JavaScript
- [ ] Widget templates for both, with the *Customise* entries and empty
      states. The breakdown bar is CSS only.
- [ ] Reuse the Expenses tab's chart code for *Monthly spend* rather than
      writing a second stacked-bar configuration. The table is rendered
      server-side in the widget.
- [ ] Segment colours come from tokens that read in both themes and all
      four accents (reuse the Reports palette).

### 34.2.3 Translations
- [ ] English and German strings: widget titles, period labels, empty
      state, the *Customise* note.

### 34.2.4 Tests
- [ ] Unit: largest-remainder shares (totals 100 for awkward splits, zero
      total, one group).
- [ ] Unit: period parsing (valid values, unknown, repeated).
- [ ] Integration: the breakdown matches Reports for the same vehicles and
      period, group by group (assert equality with the report service, not
      hard-coded numbers); two currencies give two blocks, never summed;
      archived vehicles excluded; a vehicle the viewer may not see costs of
      is excluded and a viewer with none gets no widget; one vehicle
      selected shows that vehicle only; links carry the vehicle, period and
      group.
- [ ] Integration: *Monthly spend* lists 12 months including empty ones;
      the table and the chart data agree; the average per month equals
      Reports'; the month boundary at local midnight in a time zone with
      daylight saving (a fill-up at 00:30 BST on 1 April counts in April).
- [ ] Integration: `reports` off removes both widgets from the dashboard,
      *Customise* and saved layouts without losing the layout; turning it
      back on restores them in place.
- [ ] Integration: an old saved layout gains both widgets at the end; an
      unknown id is still dropped.
- [ ] Query count: the dashboard makes no more than a fixed number of extra
      queries for the two widgets however many vehicles there are.
- [ ] Without JavaScript: the table is present and the bar and rows render.

### 34.2.5 Checks
- [ ] `design-reviewer` agent at 375, 768 and 1280 px, light and dark, all
      four accents.

### Sample data
- [ ] `DemoDataSeeder`: confirm the demo owner has costs in every group
      Reports shows across the last 12 months. Add a few ad-hoc expenses if
      a group is empty, so both widgets show something worth looking at.

### Release
- [ ] Ships with Phase 34.3 as **v3.1.0**.

---

## Acceptance criteria

- The dashboard can show where the last 12 months' money went and a bar
  chart of the 12 months, from the same numbers as Reports.
- Choosing *This month* or *This year* changes the breakdown and survives a
  refresh, with no JavaScript.
- Nothing is converted between currencies and nothing is shown for a
  vehicle whose costs the viewer may not see.
- Switching `reports` off hides both and switching it on brings them back.

## Open questions

- **Overlap with *Spend this month*.** It already shows this month's spend
  by group with last month beside it. The breakdown's *This month* option
  repeats that. Keep all three (drafted: nothing the owner has arranged
  breaks), or retire *Spend this month* once the breakdown has it?
  Recommendation: keep all three for now and look again after a release.
- **Periods offered.** *This month*, *Last 12 months*, *This year*
  (drafted). Add *Last 3 months* or *All time* to match Reports' presets?
- **Fleet view by vehicle.** A *By vehicle* option on the breakdown, or
  leave that to Reports (drafted)?
- **Clicking a bar.** To Reports for that month (drafted: not built).
