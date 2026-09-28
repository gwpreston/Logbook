# Phase 8 — Fuel grades + v1.0 release

**Goal:** record *which* fuel went in, not just which kind: the petrol grade
(E10 or E5 and its octane, E85, ethanol-free), the diesel blend (B7, B10,
B20, B100, HVO) and, for electric vehicles, how it was charged (at home, on
public AC, or on rapid / ultra-rapid DC). Show what each grade costs
and, where the data allows, what it does to economy. Then cut **v1.0.0**.

Read `spec.md` (§6 FuelEntry and Vehicle, §7.3, §7.7, §7.13, §7.15, §8) and
`CLAUDE.md` (§6, §8, §11) before starting.

**Prerequisites:** Phase 7 complete and green, including its open browser
check (every modal, chart and layout, light and dark, each accent, desktop
and phone) and its acceptance criteria ticked.

---

## Scope

**In:** a `grade` on each fill-up, an optional default grade per vehicle, a
single grouped fuel picker on the fill-up form, grade labels and badges
wherever fill-ups are listed, average price and economy by grade, grade in
CSV export / import and backups, translations, and the v1.0.0 release
(version, changelog, roadmap, README).

**Out:** new energy families (CNG, LNG, hydrogen: sold by the kilogram, so
they need a mass unit), grades for LPG (one product at the pump), charger
power in kW or connector type (the charging types below cover the useful
distinction), charging losses, network tariffs, anything in
`spec.md` §12. Open questions at the end.

---

## Design decisions (record in `spec.md` before building)

- **Grade refines fuel; it does not replace it.** `fuel` stays the energy
  family (`petrol`|`diesel`|`lpg`|`ev`|`other`) and keeps driving the
  economy series, units and full-to-full math exactly as today. `grade` is a
  new, **optional** code that must belong to the entry's family. Nothing in
  the consumption engine changes shape.
- **Grades** (codes are stored; labels are translated; the list lives in one
  PHP enum `FuelGrade` with `family()`, so adding a grade needs no migration):

  | Family | Code | Label (en) | Short | Picker |
  |---|---|---|---|---|
  | petrol | `e10_95` | E10 unleaded, 95 RON | E10 95 | main |
  | petrol | `e5_95` | E5 unleaded, 95 RON | E5 95 | main |
  | petrol | `e5_97` | E5 super unleaded, 97 RON | E5 97 | main |
  | petrol | `e5_98` | E5 super unleaded, 98 RON | E5 98 | main |
  | petrol | `e10_98` | E10 super unleaded, 98 RON | E10 98 | main |
  | petrol | `e5_99` | E5 super unleaded, 99+ RON | E5 99+ | main |
  | petrol | `e0` | Ethanol-free (E0) | E0 | main |
  | petrol | `e85` | E85 (flex fuel) | E85 | main |
  | petrol | `e15` | E15 (Unleaded 88) | E15 | regional: US |
  | petrol | `e20` | E20 | E20 | regional: IN |
  | petrol | `aki_87` | Regular, 87 AKI | Regular 87 | regional: US, CA |
  | petrol | `aki_89` | Mid-grade, 89 AKI | Mid 89 | regional: US, CA |
  | petrol | `aki_91` | Premium, 91+ AKI | Premium 91+ | regional: US, CA |
  | diesel | `b7` | B7 diesel | B7 | main |
  | diesel | `b7_premium` | B7 premium diesel | B7 premium | main |
  | diesel | `b10` | B10 diesel | B10 | main |
  | diesel | `b20` | B20 biodiesel blend | B20 | main |
  | diesel | `b100` | B100 biodiesel | B100 | main |
  | diesel | `xtl` | HVO / XTL (paraffinic) | XTL | main |
  | ev | `home` | Home charging | Home | main |
  | ev | `ac` | Public AC charging (up to 22 kW) | AC | main |
  | ev | `dc` | DC charging (speed not recorded) | DC | main |
  | ev | `dc_rapid` | Rapid DC charging (25–99 kW) | Rapid | main |
  | ev | `dc_ultra` | Ultra-rapid DC charging (100 kW+) | Ultra-rapid | main |

  *Main* grades appear in their family's group on the picker. *Regional*
  grades appear there only when the owner's locale region matches (e.g.
  `en_US` sees the AKI grades); everyone else finds them in a trailing *More
  grades* group, so a UK owner's picker is not cluttered with US pump
  octanes. The charging bands follow the common UK Zap-Map terms; home
  charging is its own choice because it is where the cost difference is.

  `lpg` and `other` have no grades (LPG is already its own choice on the
  picker and gets a badge). A blank grade means *not recorded* and is always
  valid. **Codes never change once released**: they end up in CSV files and
  backups; labels can be reworded freely.
- **Existing entries are not guessed.** The migration adds the column as
  null; old fill-ups show "Not recorded". No backfill from the vehicle.
- **Default grade on the form:** the grade of the vehicle's most recent
  fill-up of that family, else the vehicle's `default_grade`, else none.
- **Economy by grade is attributed to the fuel that was burned.** In a
  full-to-full segment A → B the car ran on what went in at A (plus any
  partials inside the segment), not on what went in at B. A segment counts
  towards a grade only when the opening full fill and every partial in it
  share that grade; mixed or unrecorded segments still count in the family
  average but in no grade's. Show a grade's economy only once it has at
  least two such segments ("Not enough fills yet" otherwise), and label it
  as an indication, not a test result.
- **Price by grade** is simple: total cost ÷ volume over fills of that
  grade, per currency, in the owner's volume unit (per kWh for charging
  types). Free charges (cost 0, e.g. a free workplace or hotel charger) count
  towards it, since that is what the energy actually cost.
- **Badges follow the pump and charger labels:** EN 16942 circle for petrol
  grades (AKI grades too), square for diesel, rhombus for LPG, and the
  EN 17186 hexagon for charging types. Shape plus text, never colour alone;
  they do not use the accent colour.

---

## Tasks

### 8.0 Spec first
Per `CLAUDE.md` §12, update `spec.md` before building:
- [x] §6 FuelEntry — `grade` (optional code, must match `fuel`).
- [x] §6 Vehicle — `default_grade` (optional code, must match the vehicle's
      fuel type; for `hybrid` a petrol grade).
- [x] §7.3 — grade list (main and regional), grouped fuel picker, form
      default, price and economy by grade (attribution rule above), cost per
      kWh by charging type, badges.
- [x] §7.7 / §7.13 — grade column in CSV export and import.
- [x] §8 — grade badges (shape + text, independent of accent).
- [x] `ROADMAP.md` gains a Phase 8 row; `CHANGELOG.md` gets the 1.0.0 entry.

### 8.1 Domain + migration
- [x] `FuelGrade` enum (codes above) with `family(): Fuel`, `label key`,
      `short label key`; `FuelGrade::forFamily(Fuel): list<self>`.
- [x] Migration: `fuel_entries.grade` (`string`, 20, `'null' => true`) and
      `vehicles.default_grade` (same). Reversible; applies and rolls back on
      SQLite, PostgreSQL, MySQL and MariaDB.
- [x] `FuelEntry` / `Vehicle` entities, repositories and `Row` mapping
      carry the grade; an unknown stored code reads as null (logged), so a
      grade removed in a later release never breaks a page.
- [x] Validation: a grade from another family is rejected with a clear
      message ("B7 is a diesel grade; this fill-up is petrol").

### 8.2 Fill-up form
- [x] Replace the *Fuel* select with **one grouped select** (`<optgroup>`
      per family): e.g. *Petrol — grade not recorded*, *E10 unleaded, 95
      RON*, … *Electricity — not recorded*, *Home charging*, *Public AC*,
      *DC*, *Rapid DC*, *Ultra-rapid DC*, *LPG*, *Other*, then *More grades*
      (regional grades for other regions). Values are `family` or `family:grade` (`petrol:e10_95`),
      parsed server-side into both fields — works without JS.
- [x] A *Used on this vehicle* group comes first (up to four grades from its
      last 12 months), so the usual choice is one tap on a phone.
- [x] Only the families that make sense for the vehicle are offered next
      (petrol for petrol; petrol and electricity for a hybrid; electricity
      for an EV); the rest stay available under *Other fuels*.
- [x] Preselect per the form-default rule; editing keeps the stored grade.
- [x] Works in the desktop modal, the *Log entry* chooser path and offline:
      the PWA's cached `/fuel/new` forms include the grade, queued entries
      carry it, and a queued entry from before this release (no grade)
      still sends.

### 8.3 Vehicle form
- [x] *Default grade* select on add / edit vehicle, filtered to the
      vehicle's fuel type (petrol grades for `hybrid`; charging types for
      `ev`, typically *Home charging*);
      hidden for `lpg` / `other`. Changing the fuel type clears a default
      that no longer fits.

### 8.4 Display
- [x] Grade badge + short label on the Fuel tab list, dashboard *Recent
      fuel* and *Recent activity*, the vehicle overview's latest fill-ups
      and the Expenses ledger line for a fill-up; "Not recorded" shows no
      badge.
- [x] Fuel tab gains a **By grade** card (in the `.split` grid beside the
      existing summary on wide screens): per grade used on this vehicle —
      number of fills, volume, average price, and economy where the
      attribution rule allows. Hidden when the vehicle has no graded fills.
- [x] For electricity the card leads with **cost per kWh and share of
      energy per charging type** (e.g. Home 74% at 7.5p/kWh, Rapid 19% at
      79p/kWh), the figure EV owners actually want; a blended cost per kWh
      closes the list.
- [x] Price trend chart: one series per grade used (plus *Not recorded*),
      so E5 vs E10, or home vs rapid charging, can be compared; series colours come
      from the chart tokens, with the legend naming each grade.
- [x] Nothing about grades appears when the `fuel` module is off.

### 8.5 Services
- [x] `FuelStatistics` (or the existing fuel service) returns typed
      per-grade results; no grade maths in templates or Actions.
- [x] The attribution rule is implemented once and unit-tested with worked
      examples (see 8.9).

### 8.6 CSV export / import + backup
- [x] Export: a *Grade* column (code in a `grade` column plus the
      translated label, like other choice columns).
- [x] Import: the column is optional; accepts the code, the label in the
      owner's language or English, and the short label ("E10", "B7",
      "Rapid", "Home"). Ambiguous words ("Unleaded", "Super", "Diesel",
      "Premium") are not treated as grades. A grade from another family makes the row invalid. Files
      without the column import as before (grade not recorded). Duplicate
      detection is unchanged (grade is not part of the key).
- [x] Backups include the new columns automatically; the schema version
      moves, so the upgrade note repeats the existing rule (restore an older
      backup with its version first, then upgrade).

### 8.7 i18n
- [x] All grade labels, short labels, picker groups and the *By grade* card
      in English and German (German pumps use the same E5 / E10 / B7 / B10
      labels; "Super", "Super E10", "Super Plus" are the usual names, and
      RON is ROZ — translate the long labels, keep the codes in the short
      ones). Charging types: "Laden zu Hause", "AC-Laden (öffentlich)",
      "DC-Laden", "Schnellladen (DC)", "Ultraschnellladen (HPC)".

### 8.8 v1.0.0 release
- [x] `VERSION` → `1.0.0`; sidebar, Settings and `/health` show it.
- [x] `CHANGELOG.md` `[1.0.0]` entry with upgrade notes (new columns,
      backup schema rule, no config changes).
- [x] `ROADMAP.md`: Phase 8 row; "Beyond the core phases" becomes
      "After 1.0".
- [x] README status line updated for 1.0; `docs/import.md` documents the
      grade column.
- [x] Tag `v1.0.0`; the multi-arch image is published as `1.0.0`, `1.0`,
      `1` and `latest`. (CI now tags semver `{{version}}`, `{{major}}.{{minor}}`,
      `{{major}}` and `latest` on `v*` tags; the tag itself is pushed after
      this branch is merged.)

### 8.9 Tests
- [x] Unit: `FuelGrade::family()` for every code; family / grade mismatch
      rejected; picker value parsing (`petrol`, `petrol:e5_97`,
      `ev:dc_rapid`, garbage); main vs regional grouping for `en_GB`,
      `en_US`, `de_DE` and a locale with no region.
- [x] Unit (attribution, worked examples): E10 full → E10 full gives an E10
      segment; E5 full → E10 full attributes to **E5**; a partial of another
      grade inside the segment makes it mixed; an unrecorded opening fill
      makes it unattributed; a *missed previous* fill still discards the
      open segment; the family average is unchanged by grades; fewer than
      two segments → no grade figure; cost per kWh and share of energy per
      charging type, including a free (cost 0) charge, per currency.
- [x] Unit: form default (last grade of that family → vehicle default →
      none); a hybrid's EV charge does not take the petrol grade.
- [x] Integration: add / edit a fill-up with and without a grade (page and
      modal); vehicle default grade saved and cleared on fuel-type change;
      CSV export includes the grade and re-imports it exactly; import with
      codes, labels, short labels, no column and a mismatched grade; a
      stored unknown code renders as "Not recorded"; migration up / down.
- [x] Pass on **both** MySQL and Postgres (plus MariaDB and SQLite via
      `bin/test-all-dbs.sh`); smoke test at a subpath.

---

## Deliverables
Fill-ups that record the grade actually bought (E10 / E5 and octane, E85,
E0, B7 to B100 and HVO) and charges that record how they were charged (home,
public AC, DC, rapid, ultra-rapid), shown with pump-style badges, compared by price and —
with enough data — by economy, carried through CSV and backups; released as
Logbook v1.0.0.

## Acceptance criteria
- [x] A fill-up can be saved with any grade of its family, or none; a grade
      from another family is refused with a clear message.
- [x] Existing fill-ups are untouched and show "Not recorded".
- [x] The fill-up form offers one grouped picker, works without JS, in the
      modal and offline, and preselects the last grade used.
- [x] Family economy figures are identical before and after the upgrade.
- [x] *By grade* shows fills, volume and average price per grade, and
      economy only for correctly attributed segments.
- [x] Price trend separates grades; EVs show cost per kWh and share of
      energy for each charging type.
- [x] Regional grades appear in the main groups only for owners in that
      region; everyone can still pick them under *More grades*.
- [x] CSV round-trips the grade; older files still import.
- [x] Badges carry text and shape, not colour alone, and ignore the accent.
- [x] `/health`, sidebar and Settings show v1.0.0; changelog and roadmap
      updated.
- [x] Suite green on both DBs; translatable (en + de); works behind a
      subpath with deep-link refresh; Docker and bare-PHP paths both work.

## Gotchas
- **Attribute economy to the opening fill**, not the closing one — the
  obvious implementation credits the wrong grade.
- **Don't split the economy series by grade.** Full-to-full stays per
  family; per-grade figures are a view over the same segments.
- **Grade must not become required** — receipts often don't say, and the
  offline queue may hold entries from before the upgrade.
- **Codes, not labels, in storage and CSV.** Pump names vary by country and
  brand ("Super Plus", "V-Power"); the brand goes in *station*.
- **Charging types mix place and speed** (home vs rapid). That is
  deliberate, since it matches how people think about cost; `dc` exists for
  when the speed is unknown, so nobody is forced to guess.
- **Hybrids have two families.** The form default must look up the last
  grade *of the family being logged*.
- MySQL returns the new column like any other string; read through `Row`.
