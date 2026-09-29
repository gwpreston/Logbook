# Phase 15 — Coming up: maintenance and cost forecast + v1.7.0

**Goal:** look forward the way History looks back. Everything the app
already knows is coming (services and intervals, document renewals, tyres
wearing out or ageing, manual reminders) laid out over the **next 12
months**, each with what it cost last time, plus an estimate of fuel at the
current rate of driving. The owner sees what is due and roughly what the
year will cost, per vehicle and for the fleet. Then cut **v1.7.0**.

Read `spec.md` (§7.2, §7.4, §7.5, §7.6, §7.7, §7.8, §7.10, §7.16, §7.17;
§8) and `CLAUDE.md` (§5, §8, §11) before starting.

**Prerequisites:** [Phase 14.2](phase-14.2.md) complete and green; v1.6.0
tagged.

---

## Scope

**In:**
- A *Coming up* service projecting the next 12 months from the existing
  sources, including repeats of recurring work.
- An expected cost per item from its last occurrence, and a fuel estimate.
- A fleet page `/upcoming` (with vehicle chips), a *Coming up* card on the
  overview and a *Coming up* dashboard widget.
- CSV export, translations and the v1.7.0 release.

**Out:**
- Notifications. Reminders already tell the owner when something is due;
  this is a plan, not an alert.
- Changes to the reminder engine, its statuses or lead times.
- Inflation, price trends or any cost the owner has never paid.
- Recurring ad-hoc expenses (road tax, parking permits); see open
  questions.
- A horizon other than 12 months.
- Stored forecasts.

---

## Design decisions (record in `spec.md` before building)

### One service, derived on every read

- `Service\Forecast\ComingUp` takes vehicles and returns typed items for
  the horizon: from today to the end of the 11th calendar month after this
  one, in the owner's time zone (this month and the 11 after, as the
  reports' *last 12 months* in reverse).
- **Computed from the sources, not from the reminders table.** It reuses
  the same due-point calculations (schedule next due and distance
  projection §7.4, document expiry §7.5, `TyreWear` §7.17), so it works
  with the `reminders` module off, and a dismissed reminder's work still
  appears: dismissing a nudge does not cancel the service.
- Never stored; nothing to go stale.

### Items

| Source | Item | Dated | Repeats |
|---|---|---|---|
| Schedule | its title | the sooner of its date limit and its projected distance limit (§7.4) | yes: each next occurrence = the previous + the interval (months and/or projected distance, sooner first), within the horizon |
| Document | "Renew {title or type}" | its current document's expiry | yes, at the current document's own term (expiry − start) when both are set and at least 28 days apart |
| Tyres | as the tyre reminder's title groups them ("Front tyres worn out") | wear-out date or age-limit date | no: a new tyre's wear is unknown |
| Manual reminder | its title | its due date | no (only open ones; only with `reminders` on) |

- **Already overdue** items are listed first under *Overdue*, once each,
  with no repeats: the next occurrence counts from when the work is
  actually done, which is not known yet.
- **Date not known yet:** a distance-only due point that cannot be placed
  on the calendar (under a week of mileage history) is listed under
  *Date not known yet* with its distance ("at about 48,000 mi").
- **Repeats are capped** at 24 per schedule, so an interval typed as
  "every 100 km" cannot loop the page.
- Archived vehicles raise nothing. A switched-off module's items are left
  out, as everywhere.

### Expected costs

- **At last time's price**, from the owner's own records:
  - schedule: the cost of the latest entry that completed it, when above 0;
  - document: the current document's cost, when above 0;
  - tyres: the cost of the service record(s) linked to fitting the tyres
    now due (each record once);
  - manual reminders: none.
- Otherwise the cost is *not known*, shown as "—", and counted: "3 items
  without a known cost". Never a guess, never a category average.
- Labelled everywhere: "about £240 (last time)".

### Fuel estimate

- **Projected distance** per month = the average daily distance (§7.4's
  projection, needs a week of history) × the days of that month inside the
  horizon.
- **Fuel cost per distance** = the vehicle's fuel-group ledger spend over
  the last 12 months ÷ the distance driven in the same period (§7.7).
  Needs at least 90 days between the first fill-up in that window and
  today; otherwise *not enough fill-ups yet*.
- **Estimate** = projected distance × fuel cost per distance, per month.
  Labelled "about £160 on fuel (at the last 12 months' cost per mile)".
  A plug-in hybrid's petrol and electricity are both in the fuel group, so
  one estimate covers both.
- Needs the `fuel` module on.

### Totals

- **Per month and for the 12 months, per currency:** known planned costs
  plus the fuel estimate, each shown apart and then together. Amounts are
  never converted.
- Everything is an estimate and says so; a total with items of unknown cost
  says "at least".

### Where it shows

- **Fleet page `/upcoming`** (core, like `/history`): *Overdue*, then one
  section per month (each item with vehicle, date or "around {month}" for
  projected dates, source icon, expected cost, link to its source), then
  *Date not known yet*; a 12-month summary at the top (planned, fuel,
  total, per currency) and a stacked bar chart of planned vs fuel per
  month (a table without JS). With two or more active vehicles, the
  dashboard's vehicle chips (`?vehicle=`); an unknown or archived id falls
  back to all. *Export CSV* in the toolbar.
- **Overview *Coming up* card:** the next five items and the vehicle's
  12-month estimate, linking to `/upcoming?vehicle={id}`. Not a new tab.
- **Dashboard widget `coming_up`:** the next five items across the
  dashboard's vehicle filter and the 12-month total. Appended to saved
  layouts by the existing rule; in the default order after *Upcoming
  reminders*.
- **CSV** `/upcoming.csv` with the page's filter: one row per item (date or
  blank, vehicle, source, title, expected cost, currency, "estimate" flag)
  plus one row per vehicle per month for fuel.
- Not in History, print or reports.

---

## Tasks

### 15.0 Spec first
Per `CLAUDE.md` §12, update `spec.md` before building:
- [x] A new §7 section *Coming up*: horizon, sources and repeats, expected
      costs, fuel estimate, totals, pages, and why it reads sources rather
      than reminders.
- [x] §7.8: the `coming_up` widget and default order.
- [x] §7.10: how each module's items leave.
- [x] §7.13: the CSV export (it lives in §7.7's *CSV export* bullet with
      the other exports).
- [x] §13: a Phase 15 entry.
- [x] `ROADMAP.md` Phase 15 row 🚧; `CHANGELOG.md` `[1.7.0]` entry.

### 15.1 Refactor first, if needed
- [x] If schedule, document or tyre due-point logic lives inside
      `ReminderGenerator`, extract it into services both can call, with no
      change in behaviour (the reminder suite must pass unchanged before
      anything new is added).
      *Nothing to extract: the due points already live in
      `ScheduleCalculator` / `DueState` (§7.4), `DocumentState` (§7.5) and
      `TyreJudgement` (§7.17); `ReminderGenerator` only maps their states to
      rows. The one shared piece made public is the "latest completing
      entry" order (`ScheduleCalculator::latest()`), so the schedule's
      last-time cost reads the same entry that sets *last done*.*

### 15.2 Service
- [x] `ComingUp` with typed items (source, vehicle, title, date or
      distance, projected flag, expected cost or unknown, link) and typed
      month totals per currency.
- [x] Schedule repeats with the sooner-first rule and the 24 cap; document
      repeats by term; tyre grouping through the tyre reminder's title
      helper; manual reminders; overdue and date-unknown groups.
- [x] Fuel estimate from the projection and the ledger.

### 15.3 Display
- [x] `/upcoming` page, chart (table without JS), chips, CSV.
- [x] Overview card; dashboard widget and layout registration.
- [x] Accessible: sections as headings, dates in `<time>`, estimates
      marked in text, not only by style.

### 15.4 Demo seed
- [x] Check the seed gives each source at least one item in the horizon,
      with and without a known cost (an interval never completed; an
      insurance renewal with last year's premium; the Golf's fronts).

### 15.5 i18n
- [x] English and German: *Demnächst*, *Überfällig*, *Datum noch nicht
      bekannt*, *etwa {amount} (letztes Mal)*, *etwa {amount} für
      Kraftstoff*, *mindestens*, month headings through ICU dates.

### 15.6 Release v1.7.0
- [x] `VERSION` → `1.7.0`; sidebar, Settings and `/health` show it.
- [x] `CHANGELOG.md` `[1.7.0]`: *Added* — *Coming up*. Upgrade notes: no
      migrations, no configuration changes, no change to the backup
      format; a new dashboard widget is appended to saved layouts.
- [x] `ROADMAP.md`: Phase 15 row ✅.
- [ ] Tag `v1.7.0`; image published as `1.7.0`, `1.7`, `1` and `latest`.

### 15.7 Tests
- [x] **Unit (schedules):** today 1 Oct 2026, every 6 months, last done
      15 Aug 2026 → 15 Feb and 15 Aug 2027 in the horizon; every 10,000 mi
      at 1,000 mi a month, last done at 40,000 → about 10 months out;
      both limits → the sooner each time; 31 Aug + 6 months clamps to
      28/29 Feb; overdue listed once; under a week of history → *Date not
      known yet*; the 24 cap.
- [x] **Unit (documents):** annual insurance expiring in 2 months → one
      item; a 6-month policy → two; a replaced document raises nothing; no
      start date → no repeat.
- [x] **Unit (tyres and manual):** wear-out and age items grouped as the
      reminder title; a dismissed reminder's source still appears; manual
      reminders hidden with `reminders` off.
- [x] **Unit (costs):** last completing entry's cost; 0 → unknown; tyre
      cost from the linked fitting record, each record once; "at least"
      when any is unknown.
- [x] **Unit (fuel):** worked example: 30 mi a day, £0.15 per mile over the
      last 12 months → about £139.50 for a 31-day month; under 90 days of
      fill-ups → not enough yet; `fuel` off → no estimate.
- [x] **Unit (horizon):** starts today in the owner's time zone (a user
      ahead of UTC just after midnight); ends at the last day of the 11th
      month after this one.
- [x] **Integration:** `/upcoming` with and without JS, chips, CSV;
      overview card; widget appended to an old saved layout; each module
      off removes its items; archived vehicles excluded; several currencies
      kept apart; the reminder list and notifications unchanged.
- [x] Pass on **both** MySQL and Postgres (plus MariaDB and SQLite via
      `bin/test-all-dbs.sh`); smoke test at a subpath.

---

## Departures from the plan (spec updated)
- **Tyre costs are each tyre's share** of the service record that fitted
  it (the record's cost split across the tyres it fitted), not the whole
  record per item. Two fronts fitted together wear out at different points
  and are two items; the whole record on each would count it twice.
- **A set's age limits are one item**, at the soonest, so the title names
  the set ("Tyres: Winter wheels 6 years old on 7 Dec 2026") instead of
  listing the same model three times.
- **A schedule overdue by distance** shows the odometer it was due at
  ("due at 48,587 mi"), not a projected day in the past.
- **Totals:** overdue items count in this month; *Date not known yet* items
  are in no total. A month with only unknown costs shows "—" as planned.
- **CSV columns:** Date, Vehicle, Registration, Source, Title, Expected cost,
  Currency, Projected, Overdue; fuel rows are marked *Projected*.
- **The empty page** ("Nothing planned yet") shows while there are no items
  and no fuel estimate, even if fill-ups have been logged for under 90 days.

## Deliverables
A 12-month forward view of everything due, with each item's cost last
time, a fuel estimate and monthly totals, per vehicle and for the fleet,
on a page, the overview and the dashboard. Released as Logbook v1.7.0.

## Acceptance criteria
- [x] Every schedule, document, tyre and manual due point in the horizon is
      listed, with repeats where the source recurs.
- [x] Costs come only from the owner's own previous records; unknown costs
      are shown as unknown and counted.
- [x] The fuel estimate uses the existing projection and the last 12 months'
      fuel cost per distance, and needs 90 days of fill-ups.
- [x] Works with the `reminders` module off; changes nothing about
      reminders or notifications.
- [x] `/health`, sidebar and Settings show v1.7.0; changelog and roadmap
      updated.
- [x] Suite green on both DBs; translatable (en + de); works behind a
      subpath with deep-link refresh; Docker and bare-PHP paths both work.

## Gotchas
- **One due-point calculation.** If the forecast and the reminders compute
  a schedule's next due differently, the owner will see two dates for the
  same service. Extract, don't copy.
- **Read sources, not reminders.** Reminder status is about nudging; the
  forecast is about what will happen.
- **Cap the repeats.** A tiny interval must not produce hundreds of rows.
- **Never guess a cost.** "£240 last time" is a fact the owner can check;
  "about £300 for a typical service" is not.

## Open questions
- **Recurring expenses.** Road tax and permits are ad-hoc expenses today.
  An optional "repeats every N months" on an expense (or logging road tax
  as a document) would bring them in.
- **Needs attention.** With economy flags (Phase 13), tyre flags and
  overdue items all in place, a short *Needs attention* list on the
  overview, each line linking to its cause. Deliberately not a score.
- **Price drift.** Scaling last time's cost by elapsed time or fuel-price
  trend would be more realistic and less checkable; leave it out unless
  owners ask.
