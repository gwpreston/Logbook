# Phase 16 — Fuel insights + v1.8 release

*Is the dearer fuel worth it, what does a mile really cost, and how much does
winter take?*

Status: ✅ complete

Logbook already records every figure this phase needs: full-to-full
segments, grades, prices and costs. Owners still have to do the sums
themselves, though. The *By grade* card shows E5's price and E5's economy
side by side, but not whether E5 is cheaper to drive on. This phase works
out the answer from data the app already stores, and stores nothing new.

Read [`CLAUDE.md`](CLAUDE.md) and [`spec.md`](spec.md) §7.3 first. The spec
text below goes into §7.3 **before** any code (CLAUDE.md §12).

---

## Goals

1. **Grade verdict.** For each liquid grade with enough data, state how much
   more or less it costs per mile or km than the vehicle's usual grade. The
   verdict combines a price premium, taken from fills bought close together
   in time, with the existing economy by grade.
2. **Cost per distance by charging type** for electricity: what a mile or km
   costs on home, AC, DC and rapid charging.
3. **Cost per distance trend.** A second mode on the Fuel tab's *Economy
   trend* chart: the fuel cost of each tank per mile or km, plus a running
   average.
4. **Economy by month.** Economy for each calendar month, per year and
   averaged across years. This shows the seasonal effect and answers
   "monthly mpg".

## Not in scope

- **Temperature or weather.** It would need either a location on every
  fill-up plus a third-party lookup (the app makes no third-party requests)
  or a manual field on the fast fill-up path. *Economy by month* shows most
  of the same effect.
- Correcting grade comparisons for season, driving style or route. The
  verdict is labelled as an indication and says what it rests on.
- A fleet-level insights page, a dashboard widget, changes to Reports, a CSV
  column, settings or thresholds as preferences, notifications.
- Any change to existing figures. The headline *cost per distance*, the
  averages, the economy trend and economy checks are all unchanged.
- A generic "MPG". Figures stay in the owner's unit, as today.

---

## Spec addition (§7.3, after *Fuel grades*)

> **Fuel insights** (Phase 16). Derived on every read from the segments
> above, with no second walk of the fill-ups. Nothing is stored and no other
> figure changes. With the `fuel` module off nothing about insights appears.
>
> - **Segment cost.** The fuel cost of a closed full-to-full segment is its
>   volume (the same volume its consumption uses) × the *burned unit price*.
>   The burned unit price is the volume-weighted price per unit of the
>   opening full fill and every partial inside the segment: the fuel that
>   was in the tank, the same attribution as *Economy by grade*. Free
>   charges at cost 0 count. Every fill-up has a price (the form derives
>   whichever of volume, price and total was left blank, and all three are
>   stored), so every closed segment has a cost. Segment cost per
>   distance = segment cost ÷ segment distance, in the vehicle's currency.
>   Liquid fuel and electricity are separate series, as everywhere.
>
> - **Grade verdict** (liquid families only, petrol and diesel):
>   - *Reference grade:* the family's most-used grade on the vehicle by
>     volume over the last 12 months up to today (ties go to the grade used
>     most recently; with no graded fill of the family in that window, over
>     all time). A vehicle with only one grade gets no verdict.
>   - *Economy ratio* = grade's consumption ÷ reference's consumption. Both
>     are the existing *Economy by grade* figures, in canonical L/100 km, and
>     each needs its own two single-grade segments (§ above).
>   - *Price premium:* each fill-up of the grade is paired with the nearest
>     fill-up of the reference grade, on the same vehicle, within **30 days**
>     either side. Dates are compared in the owner's time zone, and a
>     reference fill may pair more than once. The ratio for each pair is
>     price per canonical litre ÷ price per canonical litre, and the premium
>     is the **median** ratio over at least **3** pairs. Averages taken
>     across all time are never used, because they measure fuel price
>     inflation as much as grade.
>   - *Cost ratio* = price premium × economy ratio. Shown as a whole
>     percentage. Under 1% either way reads "about the same".
>   - *Wording*, in the *By grade* card, one line per compared grade:
>     "E5 98 costs about 10% more per mile than E10 95", with a second line
>     "7% more per litre, 3% more fuel used". Fuel used is worded as in
>     economy checks, so the percentage is the same in every unit. Under it
>     is the basis: "From 4 tanks of E5 98 and 11 of E10 95; prices from 5
>     fill-ups within a month of each other. An indication: season and
>     driving also change economy."
>   - *Not enough data:* when the economy ratio is missing, the card shows
>     the existing "Not enough fills yet". When fewer than 3 price pairs
>     exist, it shows "Not enough fill-ups near each other in time to
>     compare prices" and gives no verdict.
>   - Constants (30 days, 3 pairs, 1%) live on the service. There is no
>     setting.
>
> - **Cost per distance by charging type** (electricity): each type's cost
>   per kWh (the existing figure) × the vehicle's average electricity
>   consumption in kWh per canonical km. The charging method does not change
>   consumption at the wheel, and single-type segments are rare. It is a new
>   column in the charging card, labelled "per mile" or "per km".
>
> - **Cost per distance trend:** the *Economy trend* card gets a two-way
>   switch, *Economy* | *Cost per mile/km*. The switch is plain links
>   (`?trend=cost`) that work without JS, survive a refresh and respect the
>   base path; with JS they swap the chart without reloading. Cost mode plots
>   each segment's cost per distance and a weighted running average (Σ cost
>   ÷ Σ distance so far). The tooltip reads "Fuel used in this tank". Without
>   JS the same points are a table. The headline cost per distance (spend ÷
>   distance) is unchanged. It is a different measure and the chart does not
>   replace it.
>
> - **Economy by month** (full-width card below the trend charts, one per
>   series):
>   - A segment's distance and volume are split across the calendar months
>     it spans, in proportion to elapsed time between its opening and
>     closing fill-ups. Month boundaries are in the owner's time zone,
>     stored instants are UTC, and DST is handled by `DateTimeImmutable`
>     arithmetic, never by counting hours.
>   - Segments longer than **92 days** are left out of this card only, as
>     they are too long to place in a season.
>   - A month's figure = Σ volume share ÷ Σ distance share, worked in
>     canonical units and converted at the edge. mpg is never averaged.
>     A month with under **200 km** of distance shows "—".
>   - The table is one row per month (January to December, ICU month names)
>     and one column per calendar year with data (the last five at most),
>     plus *Average*, weighted across every year. The chart shows *Average*
>     as bars, with the current and previous year as lines. Without JS only
>     the table is shown.
>   - Hidden until at least one month has a figure.
>
> - **Not in scope:** temperature or weather, insights by grade for
>   electricity beyond cost per distance, fleet insights, a dashboard
>   widget, CSV or backup changes, settings.

Add to §13: **Phase 16 — Fuel insights + v1.8.0.** Grade verdict, cost per
distance by charging type, cost per distance trend and economy by month
(§7.3). No schema change.

---

## Decisions (and why)

- **The verdict rests on a time-matched premium, not average prices.** The
  existing *By grade* average price is correct as a record of what was paid,
  but it is misleading for comparing grades. If E5 was bought mostly in 2022
  and E10 mostly in 2025, the gap between the averages is fuel prices
  changing, not the grade. Pairing fills within 30 days removes most of
  that. The median resists one-off forecourt outliers.
- **There is no "cost per mile" column per liquid grade.** Its figures would
  carry the same time bias and could contradict the verdict beside them.
  The verdict is the answer, and the parts shown under it (per litre, fuel
  used) let the owner check it.
- **Segment cost uses the burned price, not the refill price.** It must
  match *Economy by grade*, which attributes a segment to the fuel that went
  in at its opening fill. Using the closing fill's price would credit E10
  with the tank of E5 that came before it.
- **Month splits are pro rata by time.** Distance within a segment is
  unknown, so time is the only fair basis. Assigning a whole segment to its
  closing month would push a late-January tank into February and blur the
  seasons the card exists to show.
- **Every segment counts, flagged or not,** consistent with economy checks
  (§7.3: "every average, trend and cost figure still counts every segment").
  A mistyped odometer distorts insights until it is fixed, and the economy
  check already points the owner at it.

---

## Tasks

### Spec and docs first
- [x] Add the spec text above to `spec.md` §7.3 and the Phase 16 line to §13.
- [x] Add the Phase 16 row and section to `ROADMAP.md` (📋, then ✅).

### Domain / Support
- [x] `Domain\Fuel\SegmentCost`: a value object holding a segment reference,
      cost (money, DECIMAL-backed), distance (canonical km) and cost per km
      (a decimal with at least 6 places, rounded only for display).
- [x] `Domain\Fuel\GradeVerdict`: grade, reference grade, price premium,
      economy ratio, cost ratio, segment counts, pair count and a status
      enum (`ok`, `not_enough_economy`, `not_enough_price_pairs`,
      `single_grade`).
- [x] `Domain\Fuel\MonthlyEconomy`: rows of (year, month, volume, distance),
      plus a per-month average and a figure-or-null rule (200 km).
- [x] Percent-difference helper in `Support` (ratio → rounded whole
      percentage and direction; under 1% → same). It is shared with the
      economy-check wording if that helper does not already exist.
      (`Support\Number\PercentDifference`. The economy-check wording is
      left as it is: it words a flag band, not a rounded ratio.)

### Services (unit-tested without a DB)
- [x] `Service\Fuel\SegmentCostCalculator`: from the existing segments,
      computes each segment's burned unit price, cost and cost per
      distance (every fill-up has a price, so every segment has a cost).
      Keeps series separate.
- [x] `Service\Fuel\GradeComparison`: works out the reference grade, the
      economy ratio (reusing the existing *Economy by grade* numbers and not
      recomputing them), the price-pair premium and the cost ratio. It also
      returns the basis counts. Constants live on the class.
- [x] Charging-type cost per distance: extend the existing charging
      breakdown with cost per kWh × average kWh per km.
- [x] `Service\Fuel\SeasonalEconomy`: time-proportional month split in the
      owner's time zone, excludes segments over 92 days, and returns the last
      five years plus the weighted average.
- [x] Wire everything into PHP-DI, constructor-injected. No `new` on
      collaborators.

### Repository
- [x] No new queries if the segment builder already loads fill-up prices
      and grades. If it does not, extend the existing fuel repository query.
      DBAL query builder only, bound parameters, no engine-specific SQL.

### Actions and templates
- [x] Fuel tab Action: read `trend` (`economy` default, `cost`), validate it
      against an enum, and pass the insights view models. The Action stays
      thin.
- [x] *By grade* card: verdict lines, basis and not-enough states. For
      electricity, add the *per mile/km* column.
- [x] *Economy trend* card: the switch as links (`aria-current` on the
      active one), cost-mode dataset and a table fallback.
- [x] New *Economy by month* card: table always rendered; chart
      progressively enhanced; one card per series for plug-in hybrids.
- [x] Charts read colours from the chart tokens (light and dark). No colour
      alone carries meaning, and a legend names each series.
- [x] All new strings go through `|trans`, with no literal UI text in
      templates.

### Translations
- [x] English and German keys with ICU `select` for direction
      (more / less / same) and `plural` for tank and fill-up counts. Month
      names come from ICU, not the catalogue.
- [x] Unit words in the verdict ("per mile", "per litre", "per gallon",
      "per kWh") follow the owner's distance and volume units.

### Tests
- [x] **Worked example:** E10 at 42.1 mpg (UK) against E5 at 40.8 mpg, with
      E5 paired at +7% per litre, gives "about 10% more per mile", "7% more
      per litre, 3% more fuel used". The same data viewed with L/100 km and
      mpg US preferences gives identical percentages.
- [x] A cheaper grade that is thirstier, a pricier grade that is more
      economical and breaks even ("about the same"), and a pricier grade
      that is more economical and still costs more.
- [x] Price pairing: nearest fill within 30 days; a fill 31 days away is
      unpaired; fewer than 3 pairs → `not_enough_price_pairs`; a single
      outlier pair does not move the median.
- [x] Time bias guard: a history where E5 was bought only during a price
      spike and E10 only after it. The verdict uses paired fills only, and
      an all-time average would disagree.
- [x] Segment cost: a partial fill inside a segment changes the burned
      price; a free charge at cost 0 counts; the headline figures and each
      segment's volume and cost are unchanged. (There is no unknown-price
      case: price and total are NOT NULL and the form derives all three.)
- [x] Plug-in hybrid: petrol and electricity series never mix in any
      insight.
- [x] Month split: a segment from 20 January to 10 February is split in
      proportion to elapsed time; a segment over 92 days is excluded; a fill
      at 23:30 UTC on 31 January lands in February for an owner in
      Europe/Berlin; DST weeks split correctly.
- [x] A month under 200 km shows "—"; the average is weighted, not a mean
      of mpg values.
- [x] The fuel module off: no insights markup at all.
- [x] Rendering without JS: switch links, trend table and month table all
      render; `?trend=cost` survives a hard refresh under `APP_BASE_PATH`.
- [x] Translation suite: keys, placeholders and templates pass for en and de.
- [x] Integration suite green on SQLite, PostgreSQL 17, MySQL 8.4 and
      MariaDB 11.4 (`bin/test-all-dbs.sh`).

### Sample data
- [x] Extend `DemoDataSeeder` so the Golf alternates E10 and E5 over part
      of the year, with at least three pairs of fills within 30 days. The
      demo should then show a verdict, a winter dip in *Economy by month*
      and a cost trend. The EV's home and rapid charges should show
      distinct costs per mile.

### Release
- [x] `CHANGELOG.md` entry for **1.8.0** with upgrade notes: no migrations,
      no configuration, backup format unchanged, and 1.7.0 backups restore.
- [x] Bump `VERSION`; rebuild assets (`composer build-assets`) and commit
      the output.
- [x] Update the README status paragraph.
- [ ] Tag `v1.8.0`; image published as `1.8.0`, `1.8`, `1` and `latest`.

---

## Acceptance criteria

1. On a petrol vehicle with two or more single-grade segments of each of two
   grades and three or more fills of them within 30 days of each other, the
   *By grade* card states the cost difference per mile or km, its two parts
   and its basis. The percentages are the same in every unit preference.
2. With too little data, the card says exactly what is missing and shows no
   verdict.
3. An EV's charging card shows cost per mile or km for each charging type.
4. The *Economy trend* card switches to cost per distance, with or without
   JS, and the choice survives a refresh at a subpath.
5. *Economy by month* shows a table of months by year with a weighted
   average, and a chart when JS is on. Months with little driving show "—".
6. No existing figure (averages, headline cost per distance, economy checks,
   reports, *Coming up*) changes value.
7. Definition of done (CLAUDE.md §11): lint and analyse pass on PHP 8.4 and
   8.5, the suite is green on every engine, no migrations, strings are
   translated in en and de, the Docker image builds multi-arch and the
   bare-PHP path works.

---

## Open questions

Shipped as drafted in 1.8.0 (most used by volume; one rule for petrol and
diesel; no minimum number of months); revisit if owners ask.

- **Reference grade:** should it be the most used by volume, as drafted, or
  the vehicle's `default_grade` when one is set? Most-used matches what the
  owner actually buys; the default reflects what they intend.
- **Diesel blends:** B7 against HVO is a real comparison. B7 against B7
  premium may rarely reach three price pairs. Do we keep one rule for both
  families, as drafted, or relax it for diesel?
- **Economy by month for a vehicle under a year old:** the card shows only
  the months it has, which is honest but sparse. Is a minimum of three
  months with figures worth adding before the card appears?
