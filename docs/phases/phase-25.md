# Phase 25 — Trend and cost checks + v2.5 release

*Spot a car getting thirstier, or a price typed wrong, without any AI.*

Status: 🚧 in progress · releases **v2.5.0** · file lives in `docs/phases/`

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
> Thresholds were drafted as constants; the owner chose settings (see
> *Open questions*). Every check follows the module toggles (`fuel`,
> `maintenance`) and the access rules of §7.24.
>
> *As decided on 2026-10-01, spec.md §7.24 is the source of truth and
> differs from this draft in: the thresholds are the owner's settings;
> electricity drift defaults to 15%; a price of 0 is never flagged for
> any fuel and never counted in the median; the drift title's percentage
> is worked out in the unit shown; the cost floor is 100 in the vehicle's
> currency's major unit; access follows the existing items.*

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
- [x] The three items in §7.24; the Phase 25 line in §13.

### Services
- [x] `Service\Attention\EconomyDrift`: windows, weighting, the seasonal
      test through Phase 16's month split, and the cause detectors (grade
      from Phase 8, tyre changes from Phase 11.1, overdue from *Coming up*,
      segment length).
- [x] `Service\Attention\PriceOutlier` and `Service\Attention\CostOutlier`.
- [x] Register the kinds with `AttentionList` (`drift_liquid`,
      `drift_electric`, `fuel_price`, `maintenance_cost`: two drift kinds
      because a plug-in hybrid can raise both and `attention_hidden` is
      unique per kind and subject), with fingerprints and *Hide*.
- [x] No new tables and no migration: `attention_hidden.kind` is a string.

### Settings
- [x] Five more fields on the *Needs attention* card (Settings →
      Reminders), stored in `attention.thresholds`: drift % (liquid,
      electricity), price %, cost multiple and cost floor. Blank saves the
      default; a row missing a key reads its default.

### Templates, translations
- [x] Titles and cause sentences in English and German, with ICU plurals,
      units and currency.

### Tests
- [x] **Drift:** flagged at 10% with 8 baseline segments; not at 9%; not
      with 7; not when recent tanks are older than 120 days; winter against a
      summer baseline flagged without a year of data (with the season
      wording), and not flagged when last winter was just as bad;
      improvements never flagged; plug-in hybrid series kept apart; each
      cause sentence appears exactly when its fact is true; a new tank
      re-judges a hidden drift.
- [x] **Price:** 10× and 0.1× typos flagged with the digit wording; 36%
      flagged; 34% not; fewer than 3 neighbours falls back to the owner's
      other vehicles; home and rapid charging judged separately; free
      charges never flagged; the fingerprint changes on edit.
- [x] **Cost:** 3× and at least 100 above flagged; a cheap category never
      flagged under the absolute floor; fewer than 3 earlier records
      skipped; only the last 12 months raised. (A record has no currency
      of its own, so there are no other currencies to skip; the price
      fallback skips other-currency vehicles instead.)
- [x] Module toggles and access rules as §7.24.
- [x] The owner's thresholds apply whoever looks; out-of-range values fall
      back to the defaults; changing a threshold changes the verdict.
- [x] Free fill-ups (price 0) never flagged and never in the median.
- [x] Query count bounded for the dashboard.
- [ ] Integration suite green on every engine.

### Sample data
- [x] `DemoDataSeeder`: a slow economy drift on the self-charging hybrid
      over its last five tanks (with a grade switch), one fill-up at 10× the
      price, and one service with an extra digit, so each check shows.

### Release
- [x] `CHANGELOG.md` **2.5.0**: trend and cost checks. Upgrade notes: no
      migration (or one enum change), no configuration.
- [x] Bump `VERSION`, rebuild assets, update the README status.

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
  *Decided 2026-10-01: the owner's settings, on the* Needs attention
  *card with Phase 24's two, defaulting to the drafted values.*
- **Electricity drift:** EV efficiency swings more with temperature than
  liquid fuel does. Use a wider threshold (15%) for the electricity series?
  *Decided 2026-10-01: yes, 15% by default (its own setting).*

Found while starting it:

- **Access:** who sees the new items and *Hide*?
  *Decided 2026-10-01: as the existing items. Drift needs `Log`; a price
  or cost outlier `Manage`, or `Log` for an entry they added.*
- **Cost floor without exchange rates:** what is "the currency
  equivalent of 100"?
  *Decided 2026-10-01: 100 in the vehicle's currency's major unit.*
- **Zero price:** only free charges were exempt, so a free liquid
  fill-up would be flagged as a missing digit.
  *Decided 2026-10-01: a price of 0 is never flagged and never counted,
  for every fuel.*
- **The drift title's percentage:** consumption change, or the change in
  the unit shown (they differ in mpg)?
  *Decided 2026-10-01: worked out from the two figures shown.*

## Changed while building it

- **Two drift kinds** (`drift_liquid`, `drift_electric`), not one:
  `attention_hidden` is unique per user, kind and subject, and a plug-in
  hybrid can drift on both series at once.
- **The checks live in `TrendChecks`**, which loads what they need once
  per vehicle (the fuel history is now shared with the economy flags, the
  maintenance records, and tyre fittings only when a drift is found) and
  decides who sees each item. The arithmetic is pure: `EconomyDrift`,
  `PriceOutlier`, `CostOutlier` and `Outlier` (median, digit band).
- **The price check's wider comparison** loads the owner's fill-ups on
  their vehicles in the same currency once per list (`PriceBook`), only
  when a fill-up has fewer than three neighbours: three queries for the
  whole dashboard, however many vehicles need it (tested). The median it
  shows may come from the owner's other vehicles, which a sharer may not
  see; only the median is shown.
- **"No grade" is its own group** for the price check, and **zero-price
  fill-ups are left out** of the median as well as never flagged (#63).
- **The digit wording** applies within ×/÷ 1.25 of 10, 100 or 1,000 (or
  their inverses); the ratio reads "about N×" from 2× up and "about N%
  above/below" under it. Cost outliers use the same wording.
- **The drift percentage** is worked out from the two figures as shown in
  the viewer's unit (#64), so the demo reads "about 10% worse … (61.9 mpg
  against 69.0 mpg)" for the owner and "12% … (4.6 L/100 km against
  4.1 L/100 km)" for the partner, on the same tanks.
- **The tyre cause** counts *Fit* changes from the first recent segment's
  opening fill-up to the last one's close; **winter** means every recent
  segment ended in November–February and not every baseline one did.
- **Price and cost items need `ViewCosts`.** Their titles show the usual
  amount, a figure made from other entries, so a Log share without costs
  gets neither, even for an entry they added (`EntryAccess::canSeeAmount`
  allows only that entry's own amount). The drift shows no money and needs
  only `Log`. The digest follows the same list. Tested.
- **Tyre fittings are calendar dates** (midnight UTC), compared and shown
  as they are, so an owner west of UTC sees the right day (tested with
  New York).
- **Price and cost items use *Looks right*** for *Hide*, and open their
  edit form in a modal; a drift has *View economy* (the Fuel tab's trend
  chart) and *Hide*.
- **Demo data:** the Corolla's history was only six partner fill-ups. The
  owner now fills it on the 10th and 20th of each month, the partner on
  the 15th of the last six, at realistic volumes for its 43 L tank, with
  E5 98 and 12% more fuel from 10 Jul: the last five tanks drift with no
  single tank unusual. The Golf's price typo is the latest full E10 tank
  with three E10 fill-ups around it (5 Jun 2026, £14.33/L), and an
  interim service is entered as £1,780 for £178.
- **Tests:** `EconomyDriftTest`, `PriceOutlierTest`, `CostOutlierTest`
  (unit), `TrendChecksTest` (end to end: the acceptance criteria, hiding,
  the owner's thresholds, toggles, access), a digest line in
  `AttentionDigestTest`, and `AttentionQueryCountTest` now with records,
  a cost check per vehicle and the price fallback's single load.
- **Checked in Chrome** on the demo data: the Corolla overview with the
  drift and its causes, and the settings card, at desktop width and at
  390 px.
