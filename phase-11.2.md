# Phase 11.2 — Tread depth, wear and age reminders + v1.3.0

**Goal:** tell the owner when tyres need replacing before an MOT tester or
a wet roundabout does. Record **tread depth** when tyres are fitted, checked
or swapped. Estimate each fitted tyre's **wear rate**, its depth today and
the **distance left** to the owner's replace-at depth. Remind the owner of
worn tyres and of tyres past an **age limit** from their DOT date, through
the existing reminder engine. Then cut **v1.3.0**.

Read `spec.md` (§6 User, Reminder; §7.4, §7.6, §7.8, §7.11, §7.17 from
Phase 11.1; §8) and `CLAUDE.md` (§6, §8, §11) before starting.

**Prerequisites:** [Phase 11.1](phase-11.1.md) complete and green.

---

## Scope

**In:**
- A depth unit preference (mm or 32nds of an inch).
- A tread depth on any tyre change line, plus a new *Check tread* change.
- A wear estimate per fitted tyre.
- Replace-at, legal-minimum and age-limit settings.
- One tyre reminder per vehicle (wear and age).
- Tread shown on the Tyres tab, the overview card, history and the print
  view.
- CSV and backups, translations, and the v1.3.0 release.

**Out:**
- Depth per tread zone (inner / centre / outer).
- Uneven-wear or alignment advice.
- Region-specific legal rules beyond one configurable minimum.
- Pressure.
- Seasonal-swap reminders: still a maintenance schedule or manual reminder
  (Phase 11.1).

---

## Design decisions (record in `spec.md` before building)

### Depth and its unit

- **Stored in millimetres**, `decimal(6,3)`, so a value typed in 32nds
  round-trips exactly. For example, 10/32″ is 7.938 mm, which displays
  back as 10/32″.
- **New user preference `depth_unit`:** `mm` | `in32`.
  - Presets: Metric and UK set `mm`; US sets `in32`.
  - The migration sets `in32` for existing users whose volume unit is
    `gal_us`, and `mm` for everyone else. The changelog says so.
- **Display:**
  - `mm`: one decimal ("4.2 mm").
  - `in32`: whole or half 32nds ("6/32″", "6½/32″").
  - Input takes a plain number in the owner's unit; halves are allowed in
    32nds.
- **Validation:** 0–20 mm (0–25/32″) blocks. 0 is valid; it is alarming,
  not impossible.
- **Warn, don't block** when a tyre measures more than 0.5 mm deeper than
  its previous measurement: "Deeper than last time (5.1 mm on 3 Jun) —
  check the reading." Tread doesn't grow back, but a regrooved or misread
  tyre is the owner's call.

### Where depths come from

- **Any tyre change line can carry a depth** (`tyre_change_lines.tread_mm`,
  nullable). There is no separate measurements table and no depth on the
  tyre row. A depth is a measurement made at a visit, and the change is
  that visit.
- **The forms:**
  - *Fit tyres*: *Tread depth when new* (optional, applied to every
    position). Hint: "On the invoice or the tyre's specification; about
    8 mm for most car tyres."
  - *Tyres already on the vehicle*, *Swap set* and *Remove*: an optional
    depth per tyre. Tyre storage services usually measure on the way in.
  - **New change kind `check`** (*Check tread*): one depth input per fitted
    tyre, with lines of action `measure`. Its odometer is required and
    prefilled, like other changes. Tyres left blank are simply not
    measured.
- `check` joins the change kinds, the replay (a `measure` line never moves
  a tyre), history and CSV. Its rows read "Checked tread: 5.1–6.3 mm".

### The wear estimate (derived, never stored)

`TyreWear` computes the estimate on every read from a tyre's measurements
and its distance (Phase 11.1). It is **derived, never stored**, like
economy: a vehicle has a handful of tyres, so the cost is negligible and it
can never go stale.

- **Points:** (the tyre's distance at the change, depth). The tyre's
  distance at a change is its rolling distance up to that change's
  odometer, so time in storage or as the spare adds nothing.
- **Enough data:** at least two points spanning at least 1,000 km of the
  tyre's own distance. Without that the estimate is *not known yet* and
  the tab shows the latest measurement only.
- **Rate:** least-squares slope of depth over distance. If the slope is not
  negative (no measurable wear, or noisy readings) the estimate is *not
  known yet*.
- **Depth now** = the latest measurement + rate × the tyre's distance since
  it. The latest measurement is the anchor, not the fitted line, so a fresh
  reading is always what the owner sees first.
- **Distance left** = (depth now − replace-at) ÷ |rate|, floored at 0.
  **Wear-out odometer** = current reading + distance left. **Wear-out date**
  comes from the existing projection of average daily distance (§7.4,
  needs a week of history). These exist only while the tyre is fitted at a
  road position. A stored tyre or spare isn't wearing, so it shows its
  latest measurement with no countdown.
- **Always labelled as an estimate:** "about 3.4 mm now · about 6,000 mi
  left · around Mar 2027".

### Thresholds and age

New settings page **Settings → Tyres** (`/settings/tyres`), shown when the
`tyres` module is on. It is stored in `settings` (scope user, key
`tyres.thresholds`, JSON) and typed in the owner's depth unit:

| Setting | Car | Motorbike |
|---|---|---|
| Replace at | 3.0 mm | 2.0 mm |
| Replace winter tyres at | 4.0 mm | — |
| Legal minimum | 1.6 mm | 1.0 mm |
| Age limit | 6 years (0 = off, 1–15) | same |

- **Replace at** drives the wear estimate and reminders. Winter tyres
  (season `winter`) use their own value.
- **Legal minimum** only drives a flag:
  - *Below the legal minimum* when a *measured* depth is at or under it;
  - *May be below the legal minimum — check it* when only the *estimate*
    is.

  Hint: "Legal minimums differ by country; check yours." No region rules
  are built in.
- **Age limit** applies to fitted and stored tyres with a DOT date. The
  due date is `manufactured_on` + N years, clamped like maintenance
  intervals. Retired tyres raise nothing.
- The same judgement drives the Tyres tab badges whether or not the
  `reminders` module is on, as lead times already drive the other tabs'
  badges (§7.10).

### One reminder per vehicle

- **Source `tyre`, `source_id` = the vehicle's id.** This gives one reminder
  per vehicle, not per tyre: four tyres wearing together should be one
  nudge, not four pushes.
  - The existing unique key `(vehicle_id, source, source_id)` holds.
  - `source_id` holds the vehicle id because the reminder's source is the
    vehicle's tyres as a whole. The spec says so explicitly, so nobody
    "fixes" it into a tyre id.
- **Due point:** the soonest of every fitted tyre's wear-out date and every
  non-retired tyre's age-limit date.
  - `due_km` is the wear-out odometer when wear is the soonest.
  - When a wear-out has a distance but no date yet (under a week of
    mileage history), the reminder has `due_km` and no `due_on`, as a
    distance-only schedule does.
- **Title:** names what is due, wear first. Examples: "Tyres: front left
  and front right worn", "Tyres: rear due in about 800 mi", "Tyres: Winter
  wheels over 6 years old". Tyres are grouped by set when a whole set is
  due for age.
- **Status**, judged against the owner's today:
  - *overdue* once any tyre is at or under its replace-at depth (measured,
    or estimated now), or past the age limit;
  - *due* within the owner's **schedule** lead time or lead distance
    (§7.6), because tyres are maintenance and need no lead times of their
    own;
  - *upcoming* otherwise.
  - With nothing judgeable (no estimate, no DOT dates) there is no
    reminder, and sync deletes any old one.
- **Occurrence = the id of the vehicle's latest tyre change.** This keeps
  notifications quiet:
  - The projected date moves with every fill-up. The reminder's
    `due_on`/`due_km` update in place, with no reopen and no reset of
    notification state.
  - The occurrence changes only when the owner records something about the
    tyres: a check, a fit, a swap. Then the reminder opens again for the
    new estimate, exactly as a logged schedule does.
  - A status change (upcoming → due) still notifies once, as for every
    other source.
- **Everything else is the existing engine:**
  - sync on read;
  - dismiss / done / reopen;
  - idempotent dispatch through `last_notified_at` and `notified_status`;
  - all channels and the digest;
  - the calendar feed (when there is a date);
  - archived vehicles raise none.
  - With `tyres` off, tyre reminders are neither listed nor sent, and are
    kept for when the module returns, as §7.10 does for schedules.
  - The reminder links to the Tyres tab.
- Because the pinned card's *Next due*, the garage cards' and tiles' "N
  due" and the sidebar status dots already read the reminder service, they
  pick up tyres with no change of their own.

---

## Tasks

### 11.2.0 Spec first
Per `CLAUDE.md` §12, update `spec.md` before building:
- [x] §6 User: `depth_unit` and the presets.
- [x] §6 TyreChangeLine: `tread_mm`. TyreChange: kind `check`.
- [x] §6 Reminder: source `tyre`, with the vehicle-id `source_id` rule.
- [x] §7.6: the tyre source, due point, title, status, and the occurrence
      rule with its reason.
- [x] §7.17: depths, the `check` form, the wear estimate, thresholds, age
      and flags.
- [x] §8: the depth unit in the units engine.
- [x] §7.13: depth columns in CSV; backup note.
- [x] §13: a Phase 11.2 entry.
- [x] `ROADMAP.md` gains a Phase 11.2 row and section.
- [x] `CHANGELOG.md` `[1.3.0]` entry.

### 11.2.1 Units + migration
- [x] Migration: `users.depth_unit` (string, default `mm`). A data step sets
      `in32` where `volume_unit = 'gal_us'` (DBAL, bound values).
      `tyre_change_lines.tread_mm` (`decimal(6,3)`, nullable).
- [x] Reversible. Applies and rolls back on SQLite, PostgreSQL, MySQL and
      MariaDB.
- [x] `DepthUnit` enum, and the units engine gains mm ↔ 32nds (1/32″ =
      0.79375 mm) with parse and format. No conversion anywhere else.
- [x] Preferences: *Tread depth* select beside the other units, and in the
      Metric / UK / US presets.

### 11.2.2 Domain + services
- [x] `TyreChangeKind::Check` and `TyreLineAction::Measure`. The replay
      treats `measure` as no movement.
- [x] `TyreChangeService::check()`, plus optional depths on existing, fit,
      swap and remove. Depth validation and the "deeper than last time"
      warning (a notice after save, the change saved).
- [x] `TyreThresholds` (settings read with defaults, by vehicle type and
      season) and `TyreWear` (points, rate, depth now, distance left,
      wear-out odometer and date, flags). Both return typed results.
- [x] `ReminderGenerator` gains the `tyre` source: build, sync, status and
      occurrence as above, reusing schedule lead times and the daily
      distance projection.

### 11.2.3 Forms + settings
- [x] *Check tread* page and modal: one depth input per fitted tyre in
      position order, labelled with position and tyre. Works without JS;
      errors keep typed values.
- [x] Depth fields on *Fit tyres* (once), and on *Tyres already on the
      vehicle*, *Swap set* and *Remove* (per tyre).
- [x] The Tyres tab menu gains *Check tread*. The *Log entry* chooser's
      *Tyre change* offers it too.
- [x] Settings → Tyres page (plain form, CSRF, owner's depth unit) and its
      link in Settings navigation when `tyres` is on.

### 11.2.4 Display
- [x] Tyres tab:
  - fitted cards gain the latest measured depth with its date, and the
    estimate line when known;
  - flags use the status colours, and a flag never uses colour alone
    (icon and text too);
  - stored tyres show their latest depth;
  - retired tyres show their last measured depth;
  - the tab badge follows the same judgement as the reminder.
- [x] Overview *Tyres* card: each fitted tyre's depth, and the soonest
      "about N mi left".
- [x] History and *Recent activity*: `check` rows. Depths appear in change
      summaries where recorded.
- [x] Print view: the *Tyres fitted* header block gains each tyre's latest
      measured depth and date (estimates are not printed, because a buyer
      gets measurements).
- [x] Reminders list, dashboard *Upcoming reminders*, *Next due*, garage
      badges and sidebar dots: verify tyre reminders appear, with no new
      code beyond the generator.

### 11.2.5 CSV + backup
- [x] Changes export gains a depth per tyre (owner's unit). Tyres export
      gains latest depth, its date, depth now and distance left (blank
      when not known).
- [x] Backups: the new column and setting are included automatically. The
      schema version moves, so the rule is repeated in the upgrade note.

### 11.2.6 Demo seed
- [x] The seeded car's fronts get new depths at fitting and three checks
      across the year, so the estimate and a *due* reminder appear. The
      winter set gets depths when swapped and a DOT date old enough to be
      *upcoming* for age.
- [x] The motorbike's rear gets one depth at fitting and one check.

### 11.2.7 i18n
- [x] English and German for every new label, hint, flag, reminder title,
      notification text and setting:
  - *Profiltiefe*, *Profil prüfen*, *Ersetzen bei*;
  - *Winterreifen ersetzen bei*, *Gesetzliche Mindestprofiltiefe*;
  - *Altersgrenze*, *etwa {distance} verbleibend*.
- [x] Reminder titles use ICU lists and plurals. 32nds display is
      locale-neutral.

### 11.2.8 Release v1.3.0
- [x] `VERSION` → `1.3.0`. The sidebar, Settings and `/health` show it.
- [x] `CHANGELOG.md` `[1.3.0]` gathers Phases 11.1 and 11.2. Upgrade notes:
  - four new tyre tables and a new odometer reading source (11.1);
  - `FEATURES_TYRES` (default on; `.env.example` and
    `docs/configuration.md` updated);
  - a depth column and a user preference, with US-gallon users set to
    32nds (11.2);
  - the backup schema rule;
  - nothing in existing data changes.
- [x] `ROADMAP.md`: Phase 11.1 and 11.2 rows ✅.
- [ ] Tag `v1.3.0`. Image published as `1.3.0`, `1.3`, `1` and `latest`.

### 11.2.9 Tests
- [x] **Unit (units):**
  - 10/32″ ↔ 7.938 mm round-trips;
  - halves in 32nds;
  - 0 valid; 21 mm and −1 rejected;
  - presets set the depth unit;
  - the migration's data step sets `in32` for `gal_us` only.
- [x] **Unit (wear):**
  - worked example: 8.0 mm at 0 km and 5.0 mm at 15,000 km gives
    0.2 mm per 1,000 km; with replace-at 3.0 mm that is 10,000 km left;
  - one point, or a span under 1,000 km, is *not known yet*;
  - a flat or rising slope is *not known yet*;
  - storage time and spare time add no distance between points;
  - depth now anchors on the latest measurement;
  - the winter threshold is used for a winter tyre and the bike
    threshold for a bike;
  - a stored tyre has no countdown.
- [x] **Unit (flags):** measured at the legal minimum → *below*; estimated
      only → *may be below*; "deeper than last time" at +0.6 mm and not at
      +0.4 mm.
- [x] **Unit (reminder):**
  - one reminder per vehicle with several due tyres, with the title
    naming them;
  - due point is the soonest of wear and age;
  - distance-only when there is under a week of history;
  - status boundaries at the lead time and lead distance;
  - age due at `manufactured_on` + N years, including a 29 Feb case;
  - age limit 0 turns age off;
  - none when nothing is judgeable, and sync deletes an old one;
  - archived vehicles raise none.
- [x] **Unit (occurrence):**
  - new fill-ups move `due_on` without reopening or clearing
    `notified_status`;
  - a *Check tread* reopens a dismissed reminder;
  - repeated scheduled-task runs send nothing twice.
- [x] **Integration:**
  - *Check tread* page and modal, with and without JS;
  - depths on fit, swap and remove;
  - Settings → Tyres saves in 32nds and reads back;
  - the tyre reminder appears in the list, on the dashboard's *Next due*
    and in the calendar feed as a valid VEVENT;
  - dispatch through a mocked channel;
  - `tyres` off hides and stops tyre reminders, and on restores them;
  - `reminders` off still shows the tab badge;
  - print header depths;
  - CSV columns;
  - backup → restore round-trip;
  - migration up and down.
- [x] Pass on **both** MySQL and Postgres (plus MariaDB and SQLite via
      `bin/test-all-dbs.sh`); smoke test at a subpath.

---

## Deliverables
Tread depths recorded wherever tyres are handled, a wear estimate and
distance left for every fitted tyre, and one reminder per vehicle for worn
or ageing tyres through every channel the owner already uses. Released with
Phase 11.1 as Logbook v1.3.0.

## Acceptance criteria
- [x] Depth is entered and shown in mm or 32nds per the owner's preference,
      and round-trips exactly.
- [x] A fitted tyre with enough measurements shows depth now, distance left
      and a date, labelled as estimates. Without enough it shows the latest
      measurement only.
- [x] Worn and old tyres raise one reminder per vehicle, with the right
      status relative to the schedule lead time and distance.
- [x] Daily fill-ups never re-send a tyre reminder. A new check reopens it.
- [x] Legal-minimum flags distinguish measured from estimated.
- [x] `/health`, sidebar and Settings show v1.3.0; changelog and roadmap
      updated.
- [x] Existing data untouched; every existing figure unchanged.
- [x] Suite green on both DBs; translatable (en + de); works behind a
      subpath with deep-link refresh; Docker and bare-PHP paths both work.

## Gotchas
- **Occurrence must not follow the projection.** Keying it on the projected
  date would reopen and re-send the reminder after every fill-up. Key it on
  the latest tyre change.
- **Distance, not odometer, on the x-axis.** A winter set measured in March
  and again in November did no distance in between; measuring against the
  odometer would show impossible wear.
- **Anchor on the measurement.** An estimate that disagrees with a reading
  the owner took yesterday is wrong in the owner's eyes, whatever the
  regression says.
- **Estimates are never printed.** A buyer's service history shows what was
  measured.
- **32nds need three decimals in mm.** Two would drift by a 32nd after a
  few edits.
- **One tyre reminder per vehicle.** Resist per-tyre reminders; four pushes
  for one set of tyres will get the channel muted.

## Open questions
- **Region presets for thresholds** (UK, DE with a winter-tyre rule, US).
  One set of defaults and a hint for now.
- **Depth per zone** (inner / centre / outer) would reveal alignment
  problems. It triples the check form, so it waits for demand.
- **Rotation suggestion:** when the fronts wear much faster than the rears,
  suggest a rotation if the owner has no rotation schedule. This sits
  better with a later maintenance-insights phase.
