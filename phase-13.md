# Phase 13 — Economy checks + v1.5.0

**Goal:** point out the fill-ups whose economy does not look right, before
they quietly bend every average, forecast and cost per distance built on
them. Most odd tanks are typing mistakes: an extra digit on the odometer, a
fill-up that was not really full, a missed fill-up that was never flagged.
Compare each full-to-full segment with the vehicle's own usual economy, flag
the ones far outside it, say what probably went wrong and link straight to
the fix. The owner can mark a genuine one as correct. Then cut **v1.5.0**.

Read `spec.md` (§6 FuelEntry; §7.2, §7.3, §7.8, §7.13; §8) and `CLAUDE.md`
(§6, §8, §11) before starting.

**Prerequisites:** Phase 12 complete and green; v1.4.0 tagged.

---

## Scope

**In:**
- An economy check on every closed full-to-full segment, in the series the
  Fuel tab already measures.
- A flag with a likely cause and links to the fill-ups to check, on the Fuel
  tab, the fill-up edit page, the save notice and the dashboard's *Recent
  fuel*.
- A *Looks right* confirmation that silences one segment until its figures
  change.
- A *To check* filter on the Fuel tab, and a count after a CSV import.
- Translations and the v1.5.0 release.

**Out:**
- Notifications or reminders for odd economy. A push for every odd tank
  would get the channel muted.
- Removing flagged segments from averages. The averages stay exactly as
  they are; the flag asks the owner to correct the data instead.
- Economy checks by grade. Grades refine a family; the check uses the
  family series only.
- Settings for the thresholds (see open questions).
- Diagnosing mechanical faults. The hints name common causes; they are not
  a diagnosis.
- An economy-check column in CSV export.

---

## Design decisions (record in `spec.md` before building)

### What is checked

- **A closed full-to-full segment**, exactly as §7.3 defines it, in its own
  series (liquid fuel or electricity; a plug-in hybrid has one of each).
  The segment belongs to its **closing** fill-up, the one that shows its
  economy today.
- **Compared in canonical consumption** (litres or kWh per 100 km), never
  in mpg or km/L. Consumption is proportional to fuel used, so "30% more"
  means the same thing whatever unit the owner reads. mpg is its inverse:
  a threshold applied to mpg would be lopsided.
- **Checkable segments** are at least **100 km** long. Shorter ones are too
  noisy to judge (a few litres of rounding at the pump moves them a lot):
  they are neither checked nor used in a baseline.

### The baseline

- **The median** consumption of the up-to-10 checkable segments of the same
  vehicle and series that **ended before** this one. A median, not a
  mean, so one bad segment barely moves it, and there is no need to leave
  flagged segments out of later baselines.
- **Needs at least 5** earlier checkable segments. Until then the segment
  is *not checked* (no flag, no hint of one).
- **Earlier segments only**, never later ones. A segment's flag therefore
  never changes because a newer fill-up was logged, only when that segment
  or an earlier one is edited. An imported history is checked from its
  sixth checkable segment on.

### The flag

- **Ratio** r = segment consumption ÷ baseline.
  - Liquid fuel: *more than usual* at r ≥ 1.25, *less than usual* at
    r ≤ 0.80.
  - Electricity: *more than usual* at r ≥ 1.35, *less than usual* at
    r ≤ 0.74. EV efficiency swings far more with the seasons (heating,
    cold batteries), so the band is wider.
  - Both bands are symmetric on a log scale (1.25 ↔ 1/1.25), so a figure
    that is as far off in either direction is treated alike.
- **Wording** is in fuel used, the same in every unit: "Used about 32% more
  than usual (8.9 L/100 km; usually 6.7)" or "Used about 28% less than
  usual". The figures in brackets are in the owner's consumption unit.
- **Likely cause**, one line under the figure:
  - *Less than usual*: "Was a fill-up missed, or was this one or the one
    before it not quite full? Check the odometer too."
  - *More than usual*: "Check the odometer and the amount. Was the fill-up
    before it only partly full? Winter, towing, short trips and roof boxes
    also cost fuel."
- **A pair points at one fill-up.** When a segment is flagged one way and
  the next segment in the series is flagged the other way, the fill-up they
  share is almost certainly the mistake (its odometer, or whether the tank
  was really full). If the two segments taken together are inside the band,
  both flags say so: "Probably the fill-up on 3 Sep: taken together, these
  two tanks are normal." The check stays derived; the pair is found while
  walking the series.
- **Links, not one-click fixes.** Each flag links to *Edit this fill-up*
  and *Edit the fill-up on {date}* (the segment's opening fill, or the
  shared one for a pair). The edit form already has the odometer, the
  amounts and the partial / missed flags. Nothing is changed for the owner.

### Derived, never stored, except the confirmation

- **The check is derived on every read**, like economy itself
  (`Service\Fuel\EconomyCheck`, typed results). The Fuel tab already walks
  every segment; a median of ten per segment adds nothing noticeable.
- **Averages are unchanged.** A flagged segment still counts in every
  average, trend and cost figure until the owner corrects it. Every figure
  is identical before and after the upgrade.
- **Confirmation:** a *Looks right* button on a flag stores
  `fuel_entries.economy_confirmed` (`decimal(14,6)`, nullable) on the
  closing fill-up: the segment's canonical consumption at the moment it was
  confirmed. The flag is hidden while the segment's consumption still
  equals that value (to 6 places). Any edit that changes the segment (its
  odometers, volumes or flags, or a fill-up added inside it) brings the
  flag back, because the owner confirmed a figure, not a fill-up. A plain
  POST form with CSRF; works without JS. *Undo* is on the same row.

### Where it shows

- **Fuel tab:**
  - each flagged fill-up row gets an icon and short text next to its
    economy (never colour alone), expanding to the full flag;
  - the summary card gains "N fill-ups to check" when there are any,
    linking to `?check=1`, which lists only flagged fill-ups (a plain
    GET; works without JS; paging as usual);
  - confirmed segments show a small "checked" mark, no flag.
- **Save notice:** saving a fill-up that closes a flagged segment adds the
  flag to the notice that already shows its economy and any odometer
  warning. The fill-up is saved.
- **Fill-up edit page:** the flag, above the form.
- **Dashboard *Recent fuel*:** the icon beside a flagged fill-up's economy.
- **CSV import result page:** "3 imported fill-ups look unusual" linking to
  `?check=1`. Imports are where most typing mistakes arrive.
- Nowhere else: not in history, print, reports, garage cards or the pinned
  card.
- With `fuel` off, nothing about economy checks appears.

---

## Tasks

### 13.0 Spec first
Per `CLAUDE.md` §12, update `spec.md` before building:
- [ ] §6 FuelEntry: `economy_confirmed` and what it means.
- [ ] §7.3: checkable segments, baseline, bands, wording, pairs,
      confirmation, where flags show; averages unchanged.
- [ ] §7.8: the icon on *Recent fuel*.
- [ ] §7.13: the import result count; backup note.
- [ ] §13: a Phase 13 entry.
- [ ] `ROADMAP.md` Phase 13 row 🚧; `CHANGELOG.md` `[Unreleased]` entry.

### 13.1 Migration
- [ ] `fuel_entries.economy_confirmed` (`decimal(14,6)`, nullable).
      Reversible; applies and rolls back on SQLite, PostgreSQL, MySQL and
      MariaDB.
- [ ] Entity, repository and `Row` mapping carry it.

### 13.2 Service
- [ ] `EconomyCheck` takes the vehicle's segments (from the existing fuel
      economy service, not a second walk of its own) and returns, per
      closing fill-up: *not checked* | *normal* | *more* | *less* |
      *confirmed*, with ratio, baseline, the pair's shared fill-up and
      whether the pair is normal together.
- [ ] Thresholds and minimums as constants on the service, one place.
- [ ] `FuelService::confirmEconomy()` / `unconfirmEconomy()`; refused on a
      fill-up that closes no checkable segment.

### 13.3 Display
- [ ] Fuel tab: row flags, summary count, `?check=1` filter, confirmed
      mark; *Looks right* / *Undo* forms.
- [ ] Save notice and edit page.
- [ ] Dashboard *Recent fuel* icon.
- [ ] Import result count.
- [ ] Accessible: icon plus text, `aria-describedby` from the economy
      figure to its flag; forms reachable from the keyboard.

### 13.4 Demo seed
- [ ] The seeded Golf gets one mistyped odometer (a pair: less then more)
      and one genuinely thirsty winter tank that is confirmed, so each
      state shows.

### 13.5 i18n
- [ ] English and German for every label, flag, hint and notice: *Mehr
      verbraucht als üblich*, *Weniger verbraucht als üblich*, *Sieht
      richtig aus*, *Zu prüfen*, *Wahrscheinlich die Tankung vom {date}*.
      Percentages and ratios through ICU number formatting.

### 13.6 Release v1.5.0
- [ ] `VERSION` → `1.5.0`; sidebar, Settings and `/health` show it.
- [ ] `CHANGELOG.md` `[1.5.0]`: *Added* — economy checks. Upgrade notes: one
      nullable column; every existing figure is unchanged; the backup
      schema rule (restore an older backup with its own version first,
      then upgrade); no config changes.
- [ ] `ROADMAP.md`: Phase 13 row ✅.
- [ ] Tag `v1.5.0`; image published as `1.5.0`, `1.5`, `1` and `latest`.

### 13.7 Tests
- [ ] **Unit (baseline):** median of the last 10 earlier checkable segments;
      fewer than 5 → not checked; a 90 km segment neither checked nor in a
      baseline; a newer fill-up never changes an older flag.
- [ ] **Unit (bands):** liquid at r = 1.24 / 1.25 / 0.81 / 0.80; electricity
      at 1.34 / 1.35 / 0.75 / 0.74; compared in L/100 km, so a figure shown
      in mpg UK and mpg US gets the same result.
- [ ] **Unit (pairs):** an odometer typed 1,000 km too high at one fill-up
      flags *less* then *more*, both naming that fill-up, normal together;
      two genuinely bad tanks in a row are not a pair.
- [ ] **Unit (series):** a plug-in hybrid's petrol and charging segments are
      checked separately; a missed-previous fill-up starts a fresh segment
      and is handled as §7.3 says.
- [ ] **Unit (confirmation):** confirmed hides the flag; editing the volume
      brings it back; adding a partial inside the segment brings it back;
      an unrelated fill-up leaves it confirmed.
- [ ] **Integration:** Fuel tab flags and `?check=1`; *Looks right* and
      *Undo* with CSRF, with and without JS; save notice; *Recent fuel*
      icon; import result count; `fuel` off hides everything; every
      average and cost figure identical before and after the migration;
      migration up and down; backup → restore round-trip.
- [ ] Pass on **both** MySQL and Postgres (plus MariaDB and SQLite via
      `bin/test-all-dbs.sh`); smoke test at a subpath.

---

## Deliverables
Fill-ups whose economy is far from the vehicle's usual are flagged with a
likely cause and a link to the fill-up to fix, a mistyped reading is
pinpointed, and a genuine odd tank can be confirmed. Released as Logbook
v1.5.0.

## Acceptance criteria
- [ ] A segment is checked only with at least 5 earlier checkable segments,
      against their median, in canonical consumption.
- [ ] A single mistyped odometer is flagged as a pair naming that fill-up.
- [ ] *Looks right* silences a segment until its figures change.
- [ ] No notifications; no average, trend or cost figure changes.
- [ ] `/health`, sidebar and Settings show v1.5.0; changelog and roadmap
      updated.
- [ ] Suite green on both DBs; translatable (en + de); works behind a
      subpath with deep-link refresh; Docker and bare-PHP paths both work.

## Gotchas
- **Never compare in mpg.** It is the inverse of fuel used; percentages in
  it are lopsided and differ between UK and US gallons.
- **Earlier segments only.** A baseline that includes later segments makes
  old flags appear and vanish with every new fill-up.
- **Confirm the figure, not the row.** A boolean "checked" would keep a
  segment silent after the owner edits it into something else.
- **Don't drop flagged segments from averages.** The owner's figures would
  change without the owner changing anything, and a genuinely thirsty car
  would look better than it is.
- **Most flags are data, not engines.** Word the hints so they send the
  owner to the fill-up first.

## Open questions
- **Seasonal baselines.** Comparing a winter tank with the previous ten
  (mostly autumn) tanks is fair; comparing it with last winter's might be
  fairer, but needs a year of history. Phase 12's seasonal economy chart
  will show whether owners' cars swing enough to need it; revisit if winter
  flags are common.
- **Threshold setting.** One *sensitivity* setting (low / normal / high)
  rather than raw ratios, if owners ask.
- **A sudden, sustained change** (every tank 15% worse since a date) is a
  better signal of a real fault than one odd tank, but needs a different
  test. A later maintenance-insights phase.
