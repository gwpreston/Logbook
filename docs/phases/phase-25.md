# Phase 25 — Trend and cost checks + v2.5 release

*Spot a car getting thirstier, or a price typed wrong, without any AI.*

Status: 📋 planned · releases **v2.5.0** · file lives in `docs/phases/`

The economy check (Phase 13) flags **one** tank that is far from the usual.
It cannot see a slow drift: five tanks each a little worse, together 15%
down on the year. It also doesn't check prices. A fill-up at £13.90 a litre
or a service entered as £6,400 instead of £640 passes quietly and skews
every cost figure.

This phase adds three deterministic checks as *Check* items in Phase 24's
*Needs attention* list:

- **economy drift**, for sustained change;
- **fuel price outliers**, for a price per unit far from what the vehicle
  paid around that time;
- **cost outliers**, for a service or repair far above the vehicle's usual
  for its category.

They are plain statistics: exact, testable, and available to every owner
whether or not AI is ever switched on. Phases 26.x may explain them in
words, but never compute them.

Read [`CLAUDE.md`](../../CLAUDE.md), [`spec.md`](../../spec.md) §7.3
(economy checks, grades, fuel insights), §7.4 and §7.24 (Needs attention)
first.

---

## Goals

1. **Economy drift:** the recent average against the vehicle's baseline,
   per series, allowing for the season when a year of data exists.
2. **Price outliers:** a fill-up whose price per unit is far from nearby
   fill-ups of the same grade.
3. **Cost outliers:** a maintenance record whose cost is far above the
   vehicle's previous records in the same category.
4. Each check has a clear title, fixed and translated likely causes that
   use what the app knows (a grade change, new tyres, the time of year), a
   link to the data, and *Hide* with a fingerprint.

## Not in scope

- AI, generated text, or anything a model decides.
- New notifications. The checks appear in *Needs attention* and, if Phase
  24's digest question is answered yes, in the digest.
- Changing any figure. Flagged entries count everywhere, as economy checks
  already do (§7.3).
- Comparing against other people's vehicles or published figures.

---

## Spec addition (§7.24, new *Check* items)

> **7. Economy drift** (per series: liquid fuel and electricity separately).
> - *Recent* = the last **5** closed segments. All must have ended within
>   the last 120 days; with fewer than 3 in that time the check is skipped.
> - *Baseline* = the closed segments that ended in the 12 months before the
>   first recent one. At least **8** are needed.
> - Consumption is compared as fuel used per distance (canonical L/100 km or
>   kWh/100 km), weighted as the averages are.
> - **Flagged** when recent is at least **10%** worse than baseline, **and**,
>   when the same calendar months a year earlier hold at least 3 segments
>   (Phase 16's month split), also at least 10% worse than those months.
>   The second test stops every winter being flagged against a summer
>   baseline. Without a year's data, the title says the comparison could
>   include the season.
> - An improvement is never flagged.
> - **Title:** "Economy is about 15% worse over the last 5 tanks than your
>   12-month average (38.2 against 45.1 mpg)", in the owner's unit.
> - **Likely causes**, fixed translated sentences, each shown only when it
>   applies:
>   - the recent segments ran on a different grade from most of the
>     baseline: "You've switched from E10 95 to E5 98";
>   - a tyre change is within the recent window: "New tyres were fitted on
>     3 Aug";
>   - the recent window is in November–February and the baseline is not
>     all winter: "Winter usually costs 5–15%";
>   - a service is overdue (from *Coming up*): "A service is overdue";
>   - the recent segments are much shorter than usual (distance per segment
>     under half the baseline median): "Shorter tanks than usual often mean
>     more short journeys".
>   Always shown: "Also worth checking: tyre pressures, load and roof
>   boxes."
> - **Links:** the Fuel tab's economy trend.
> - **Hide:** the fingerprint is the ids of the recent segments' closing
>   fill-ups, so a new tank re-judges.
>
> **8. Fuel price outlier** (per fill-up).
> - Compared with the vehicle's other fill-ups of the **same grade** within
>   **30 days** either side. At least **3** are needed; otherwise it is
>   compared with the owner's fill-ups of that grade on any vehicle in that
>   window.
> - **Flagged** when the price per unit is more than **35%** above or below
>   the median of those.
> - Electricity is checked per charging type (home and rapid prices differ
>   by design), with a free charge (cost 0) never flagged.
> - **Title:** "Fill-up on 12 Sep: £13.90/L, about 10× your usual £1.39/L.
>   Check the price or the volume." A ratio near 10× or 0.1× gets the "an
>   extra or missing digit?" wording.
> - **Link:** the fill-up's edit form. **Hide:** the fingerprint is the
>   fill-up's price, volume and total.
>
> **9. Cost outlier** (per maintenance record).
> - Compared with the vehicle's earlier records in the **same category**
>   with a cost above 0. At least **3** are needed.
> - **Flagged** when the cost is more than **3×** their median and at least
>   the owner's currency equivalent of 100 above it (so a £30 wiper job
>   after three £9 ones is not flagged). Only in the vehicle's currency;
>   records in another currency are not compared.
> - Only records from the last 12 months are raised, so old history doesn't
>   flood the list.
> - **Title:** "Service on 14 Mar cost £6,400, about 30× your usual £212.
>   Check the amount." A genuine big job (a gearbox, a clutch) is hidden
>   with *Looks right*.
> - **Link:** the record's edit form. **Hide:** the fingerprint is the
>   record's cost and category.
>
> Thresholds are constants on each check's service, not settings (see
> *Open questions*). Every check follows the module toggles (`fuel`,
> `maintenance`) and the access rules of §7.24.

---

## Decisions (and why)

- **No AI.** These are comparisons of numbers the app owns. A model would
  add cost, latency and error to an answer that arithmetic gives exactly.
- **Allowing for the season.** A drift check without it would flag every
  November. Requiring the same months a year earlier to be worse too keeps
  winter quiet once a year of data exists, and the title is honest before
  that.
- **Causes use what the app knows.** Grade switches, tyre changes, overdue
  services and short tanks are recorded facts. The generic list is one line,
  not a lecture.
- **Price and cost outliers are about typing.** Most will be a digit too
  many. The wording points at the digit, and *Looks right* handles the rare
  real one.

---

## Tasks

### Spec and docs
- [ ] The three items in §7.24; the Phase 25 line in §13.

### Services
- [ ] `Service\Attention\EconomyDrift`: windows, weighting, the seasonal
      test through Phase 16's month split, and the cause detectors (grade
      from Phase 8, tyre changes from Phase 11.1, overdue from *Coming up*,
      segment length).
- [ ] `Service\Attention\PriceOutlier` and `Service\Attention\CostOutlier`.
- [ ] Register the three kinds with `AttentionList`, with fingerprints and
      *Hide* (Phase 24's `attention_hidden`; add the kinds to its enum).
- [ ] No new tables. No migration unless the enum is stored as a DB enum.

### Templates, translations
- [ ] Titles and cause sentences in English and German, with ICU plurals,
      units and currency.

### Tests
- [ ] **Drift:** flagged at 10% with 8 baseline segments; not at 9%; not
      with 7; not when recent tanks are older than 120 days; winter against a
      summer baseline flagged without a year of data (with the season
      wording), and not flagged when last winter was just as bad;
      improvements never flagged; plug-in hybrid series kept apart; each
      cause sentence appears exactly when its fact is true; a new tank
      re-judges a hidden drift.
- [ ] **Price:** 10× and 0.1× typos flagged with the digit wording; 36%
      flagged; 34% not; fewer than 3 neighbours falls back to the owner's
      other vehicles; home and rapid charging judged separately; free
      charges never flagged; the fingerprint changes on edit.
- [ ] **Cost:** 3× and at least 100 above flagged; a cheap category never
      flagged under the absolute floor; fewer than 3 earlier records
      skipped; other currencies skipped; only the last 12 months raised.
- [ ] Module toggles and access rules as §7.24.
- [ ] Query count bounded for the dashboard.
- [ ] Integration suite green on every engine.

### Sample data
- [ ] `DemoDataSeeder`: a slow economy drift on the self-charging hybrid
      over its last five tanks (with a grade switch), one fill-up at 10× the
      price, and one service with an extra digit, so each check shows.

### Release
- [ ] `CHANGELOG.md` **2.5.0**: trend and cost checks. Upgrade notes: no
      migration (or one enum change), no configuration.
- [ ] Bump `VERSION`, rebuild assets, update the README status.

---

## Acceptance criteria

1. A car whose last five tanks average 15% worse than its year shows one
   *Needs attention* item naming both figures and the causes that apply.
2. A price or cost with an extra digit is flagged with wording that points
   at the digit, and fixing it removes the item.
3. A normal winter, once a year of data exists, raises nothing.
4. Nothing in this phase calls a model or the network.
5. Definition of done (CLAUDE.md §11) holds.

## Open questions

- **Thresholds** (10%, 35%, 3× and 100) as constants (drafted), or
  settings?
- **Electricity drift:** EV efficiency swings more with temperature than
  liquid fuel does. Use a wider threshold (15%) for the electricity series?
