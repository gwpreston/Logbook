# Phase 33.4 — Cost of ownership, Ask and Fuel stations to the prototype + v3.0 release

*What each car has really cost, in one look; and the last two pages
brought into line with the design.*

Status: ✅ complete · released as **v3.0.0** (Phases 33.1–33.4) · file
lives in `docs/phases/`

The prototype in `design-import/` has a *Cost of ownership* page: a card
per vehicle with a multicolour bar showing what its cost is made of, and
four summary cards above them (*Total cost*, *Per month*, *Depreciation*,
*Finance interest*). Logbook already has every figure (Phase 14.2's
ownership, Phase 29's finance, Phase 32's breakdown) but shows them as a
table. The prototype also redraws Ask and Fuel stations. This phase builds
the three pages and releases **v3.0.0** with Phases 33.1–33.3.

Read [`CLAUDE.md`](../../CLAUDE.md), [`spec.md`](../../spec.md) §7.7
(cost ledger, Ownership report), §7.26 (Ask), §7.32 (finance: cost of
credit), §7.33–7.34 (stations and prices), §7.35 (true cost) and §8 first,
and the *Working from the prototype* section of
[Phase 33.2](phase-33.2.md).

**Prerequisites:** [Phase 33.3](phase-33.3.md) built; [Phase 32](phase-32.md)
released (its breakdown is the bar).

---

## Goals

1. **Cost of ownership page:** the four summary cards and a card per
   vehicle with its multicolour bar, from existing figures only.
2. **Ask** laid out as, and doing what, the prototype's Ask does, within
   Ask's existing rules.
3. **Insights page and AI insights** (decided in Phase 33.3, #174,
   #178): the prototype's Insights page (`auto_awesome` in the sidebar)
   with *Ask* above every insight card, and AI insights (spec §7.26 *AI
   insights*) when AI is on; the dashboard widget's title links to it.
4. **Fuel stations** with whichever of the prototype's features the
   owner picks after the audit.
5. Release **v3.0.0** (Phases 33.1–33.4).

## Not in scope

- New cost figures, forecasts or comparisons with other people's cars.
- Map tiles, geocoding or anything else fetched from a third party for
  stations, unless the owner decides otherwise (see open questions).
- Changing Ask's grounding, privacy or tool rules (§7.26).

---

## Spec additions

Written into `spec.md` on 2026-10-05, after the audit and the owner's
answers (#186–#197):

- §7.1: the vehicle **Cost of ownership** tab (`/vehicles/{id}/ownership`,
  after Expenses, #191).
- §7.7 *Cost of ownership page*: the four summary cards per currency
  (*Finance interest* = the ledger's HP, PCP and loan lines so far, #186;
  *Per month* = the active vehicles combined, #187), a card per vehicle
  with §7.35's five parts as `ui.cost_bar` (#189), since bought only
  (#190); print and CSV unchanged.
- §7.17: "Fitted {month}" is the tyre's first fitting to the vehicle, with
  its distance since; no "Moved" (#197).
- §7.26 *Ask and the Insights page*: the Ask page as the prototype's card
  with its four suggestions; Ask stays in the navigation (#192); the
  Insights page with the Ask card (always opening the thread on `/ask`,
  #193), every computed insight and the AI insights.
- §7.33 *Fuel stations page*: *Prices nearby* above *Your stations*
  (#195), with the area average, the saving banner, favourite, OSM
  directions and *Log fill-up here* (#196); the fill-up form's
  `?station=` prefill.
- §13: the phase summary.

---

## Tasks

### 33.4.0 Spec first
- [x] `spec.md` §7.1, §7.7, §7.17, §7.26, §7.33 and §13, after the audit.

### 33.4.1 Prototype audit
- [x] *Prototype notes* for *Cost of ownership*, Ask, Insights and Fuel
      stations.
- [x] For Fuel stations, a list of the prototype's features with, for
      each, whether the app already has the data; the owner marked every
      one *build* (#196).

### 33.4.2 Cost of ownership
- [x] `OwnershipSummary` (the four totals per currency) from the existing
      ownership, true cost and finance services.
- [x] Vehicle cards with the stacked bar macro (`ui.cost_bar(parts)`),
      reused by the overview's true cost card.
- [x] Print and CSV unchanged; the screen layout to the prototype.
- [x] The vehicle *Cost of ownership* tab (#191).

### 33.4.3 Ask
- [x] Page to the prototype's *Ask Logbook* card within §7.26; the four
      suggestions.

### 33.4.3a Insights page and AI insights
- [x] Insights page (`/insights`, sidebar `auto_awesome`) with the Ask
      card above every computed insight; the widget's *All insights* link.
- [x] AI insights (spec §7.26 *AI insights*): daily per user, cached,
      *Refresh*, grounding check, marked as AI; only with AI on.
- [x] Tests: nothing generated or shown with AI off; grounding
      highlights an unmatched number; the cache serves the day.

### 33.4.4 Fuel stations
- [x] *Prices nearby* above *Your stations*: grade chips, *Cheapest* /
      *Nearest*, *Use my location*, the *Cheapest* badge, listed time.
- [x] Area average per row; the saving banner.
- [x] Favourite star (adds an unlinked station first), OSM *Directions*,
      *Log fill-up here* and the fill-up form's `?station=` prefill.

### 33.4.4a Tyres
- [x] "Fitted {month}" from the tyre's first fitting; "Moved" removed
      (#197).

### 33.4.5 Tests
- [x] Summary cards equal the sum of the vehicle cards; per currency with
      two currencies; *Per month* adds the active vehicles only.
- [x] *Finance interest*: HP and PCP interest and fees counted, lease
      rentals not, future payments not, "No finance" without either;
      matches §7.32's ledger.
- [x] Bar segments sum to the vehicle's total; a depreciation gain is left
      out of the bar; switched-off modules remove their part.
- [x] Access: a View share without costs shows no vehicle card and no
      totals for it, and no *Cost of ownership* tab.
- [x] Ask and Fuel stations: the existing suites stay green; area
      average, saving banner, prefill, add-then-favourite tested.
- [x] Design-reviewer clean of HIGH findings on every changed page.
      (2026-10-05: 3 HIGH, 4 MEDIUM, 5 LOW at 375/768/1280 px, light and
      dark, purple, teal and blue accents, the partner's view, no JS and
      keyboard. Every HIGH and MEDIUM fixed (33.4.7) and re-checked; the
      LOW left are listed there.)
- [x] Suite green on every engine: SQLite, PostgreSQL 17, MySQL 8.4 and
      MariaDB 11.4, migrations up, down and up on each; coverage 94.30%
      (floor 94).

### 33.4.7 Design review fixes
- [x] The `auto_awesome`, `directions` and `refresh` icons were missing
      from the sprite (blank tiles and buttons); added, with a test that
      every icon a template names is in it.
- [x] The price rows' star is an outline until favourited (state was by
      colour only).
- [x] At phone width the price goes under the station name, and names no
      longer break mid-word.
- [x] A lease's documentation fee was counted as *Finance interest*; every
      line of a lease is now left out (#186).
- [x] The cost legend is an even grid (dot, name, amount).
- [x] With the indigo or purple accent, *Insurance, tax and MOT* takes a
      rose colour so it stays apart from *Fuel* (the accent) in the bar.
- [x] A suggestion on the Ask card asks at once with JS (a link that
      fills the box without).
- [x] AI insights say "busy with another AI request" rather than Ask's
      "your last question"; insight action links are 36 px tall; a vehicle
      whose mileage log starts after the purchase says so.
- Left as LOW: Ask's first progress line reads "Thinking…" before the
  tools' own lines (the box shows "Reading your logbook…" while it
  posts); per-mile figures trim a trailing zero as everywhere in the
  app; the 403 page's wording for a tab without cost access is the app's
  general one.

### 33.4.6 Release
- [x] `CHANGELOG.md` **3.0.0**: forgotten password by email, admin
      controls, avatars, the redesigned sign-in and Settings, the vehicle
      tabs (Finance tab, Insights, trips, incidents, tyres), Cost of
      ownership, Ask, Insights and Fuel stations, Mailpit in development.
- [x] Upgrade notes: migrations (user email and avatar columns; reminder
      email addresses move to the user); a forgotten-password link
      appears on the sign-in page when email is configured
      (`PASSWORD_RESET_ENABLED=false` to hide it); *Stations* is now
      *Fuel stations* (URLs unchanged); Settings links have moved; the
      sample users no longer have fixed passwords.
- [x] Why 3.0: the sign-in flow and the account model change (self-service
      reset, one email per user), and the app's navigation and Settings
      are reorganised. No API change: the API stays v1.
- [x] Bump `VERSION`, rebuild assets, update README and ROADMAP status.
- [x] Tag `v3.0.0` once merged.

---

## Prototype notes

Audited 2026-10-05 against `design-import/Logbook.dc.html` (line numbers
are that file's). Sorted as in Phase 33.3: **L** layout and style (build),
**H** behaviour or data the app has (build from existing services), **N**
new (not built without the owner; see *Open questions*), **K** kept as the
app does it because an earlier decision or spec rule says so.

### Cost of ownership (Reports tab; l.893–905, JS `own()` l.1531–1536, l.1761–1765)

The prototype's Reports page has three segments (*Spending*, *Cost of
ownership*, *Claim history*) over the vehicle chips; *Cost of ownership*
has no period picker and always measures **since purchase** (from the
finance agreement's date, else the first reading, to today or the sale).

- **L** Four stat tiles, label / value / sub: *Total cost* ("3 vehicles
  since purchase"), *Per month* ("active vehicles combined"),
  *Depreciation* ("41% of total"), *Finance interest* ("paid so far").
  The app's report is a table per currency with a fleet footer.
- **L** A card per vehicle (a whole-card button): vehicle icon, name
  (" (sold)" when archived), sub "PCP · 34 months", total and "£312 a
  month" on the right; a 12 px stacked bar; a two-column legend of
  coloured dots, name and amount (largest first); "£0.31/mi over
  18,240 mi" at the foot. The prototype shows no photo or registration;
  the draft adds both (the app's `ui.vehicle_photo`, the garage cards'
  registration). Ordered **highest total first** (as drafted).
- **L** Under the cards one muted note: "Cost of ownership adds
  depreciation and finance interest to everything logged since each
  vehicle was bought. Without a sale price or your own valuation, current
  value is estimated."
- **H** Every figure: `VehicleCost` (§7.7) for total, per month, per
  distance and the owned months; §7.35's *Since bought* parts for the bar;
  `FinanceLedger`'s `Interest` and `Fee` lines (HP, PCP, loans; never
  `Rental`) for *Finance interest*. `ViewCosts`, *Include sold*, the
  vehicle filter, print and CSV unchanged.
- **N** Bar parts (#189): the prototype draws **seven** segments,
  *Depreciation*, *Finance interest*, *Fuel*, *Maintenance*, *Insurance*,
  *Tax & MOT*, *Other*. §7.35 has five (*Insurance, tax and MOT* is one;
  finance lines sit in *Other*), used by the overview card, the widget,
  the API and Ask.
- **N** *Per month* (#187): the prototype adds up the **active**
  vehicles' own monthly rates (each vehicle's total ÷ its own months);
  sold vehicles count in *Total cost* but not here.
- **N** *Finance interest* (#186): the prototype counts the interest part
  of payments made so far. The app's ledger also has fees (documentation,
  option to purchase) and an end-of-agreement adjustment to the exact cost
  of credit.
- **N** Period (#190): the draft spec says the page "keeps its period
  choice (*Since bought*, *Last 12 months*, a year)"; the app's ownership
  report has **none** (`OwnershipReportAction`: always the ownership
  period), and neither has the prototype. The periods are the *True cost*
  tab's (§7.35).
- **N** The card opens the vehicle's **Cost of ownership tab** (l.746–763,
  l.1725–1727): a total "over 34 months since buying it on …", one row
  per part with share and a bar scaled to the largest, *How it's worked
  out*, *Add purchase & finance* when no price, and four tiles (*Total
  cost*, *Per month*, *Per mile*, *Owned*). The app has no such tab; its
  overview *Cost of ownership* card and the Expenses tab's *True cost*
  card hold these figures (#191). Phase 33.3 left it to this phase.
- **K** The prototype clamps a depreciation gain to 0 and converts
  currencies at reference rates; the app shows a gain as negative (§7.35,
  #154; drafted: left out of the bar and shown under it) and never
  converts (per-currency summary cards, as every cross-vehicle total).
- **K** The prototype's *Spending* and *Claim history* segments are the
  app's Reports index and claims report (Phase 33.3); unchanged here.

### Ask (on the Insights page; l.960–985, JS `ask` l.1579–1581, l.1775–1776)

The prototype has **no Ask page and no Ask item in the nav** (l.1625:
Dashboard, Garage, Reminders, Reports, Fuel stations, Insights,
Settings). *Ask Logbook* is a card at the top of the Insights page.

- **L** Card head: 36 px accent tile with `auto_awesome`, "Ask Logbook"
  (17 px), "AI answers using only your logged data". A two-row textarea
  ("e.g. Why has my fuel spend gone up?") with an accent *Ask* button
  (`send`, "Thinking…" while busy); Enter sends, Shift+Enter a new line.
- **L** Four suggestion chips under it: "Which vehicle costs me most per
  mile?", "Summarise my last 12 months", "What's coming up in the next 3
  months?", "How could I cut my fuel costs?" (the app's four examples are
  different; all four are answerable with today's tools).
- **L** While waiting: `hourglass_top` "Reading your logbook…"; then the
  question (muted) over the answer in a surface panel; an error line with
  `error`.
- **H** Everything above: `AskService`, the progress endpoint, `ask.js`.
- **N** Where Ask lives (#192): the prototype shows one question and one
  answer, with no threads, sources, grounding marks, drafts, feedback,
  copy or retention. The app's `/ask` has all of those (§7.26), a sidebar
  entry (`forum`, `data-ask-entry`) and a top-bar icon.
- **K** Grounding check, read-only tools, *Add* for drafts, the AI
  location line: unchanged (§7.26). With AI off there is no Ask card.

### Insights page (l.960–990, `insights()` l.1552–1569)

- **L** Title "Insights", lead "Patterns spotted in your records, and
  answers to your own questions."; the Ask card; then a grid (min 380 px)
  of insight cards: 40 px tone tile, title 15 px, body 13.5 px, an accent
  action link with `arrow_forward`; empty: "Log a few more fill-ups and
  services and patterns will show up here."
- **H** The computed insights are Phase 33.3's four (§7.8 *Insights*), all
  of them rather than the widget's two.
- **H** AI insights after them (§7.26 *AI insights*, decided #174); the
  prototype draws none, so they take the same card with the `auto_awesome`
  tile, an "AI" mark, sources and *Refresh*.
- **L** Nav: `auto_awesome` *Insights* between Fuel stations and Settings;
  on a phone under *More*.

### Fuel stations (l.927–957, JS `stationRows` l.1538–1544, `fuelSaving` l.1545–1549, `useLocation` l.1609)

The prototype's page is **one price list** of nearby stations for a grade
(sample data). The app has two pages: *Stations* (your own stations:
favourites, visits, 12-month average, search, duplicates, add) and
*Cheapest near me* (`/stations/near`, provider prices from a place, a
station or the browser's position, effective cost and *worth it*). The
prototype has **no map** (no tiles, no iframe), so the drafted map
question is obsolete (#188).

| # | Prototype feature | App today | Sort |
|---|---|---|---|
| 1 | Title "Fuel stations", sub "{location} · prices" | "Fuel stations" (Phase 33.2), lead about your stations | **L** |
| 2 | *Use my location* button | `/stations/near`'s *My current location* (JS, never stored) | **H** |
| 3 | Grade chips (E10, E5, B7, premium diesel), default the vehicle's grade | `/near`'s grade select, `FuelGrade`, default from the vehicle | **H** |
| 4 | Sort *Cheapest* / *Nearest* | `/near`'s sort: effective, price, distance | **H**; *effective* stays on *Cheapest near me*, which *See all* opens |
| 5 | Rows: brand initials tile, name, *Cheapest* badge, "brand · area · 1.2 mi · updated 40 min ago", price, diff | provider station name, brand, distance, listed price and its time, *may be out of date* | **H** (the badge is the first row by price) |
| 6 | "−1.4p vs average" / "Area average" per row | none: no average of the listed prices | **N** |
| 7 | Saving banner: "The cheapest E10 nearby is … You've paid … on average in the Golf, so filling up there would save about £x a tank." | `/near` has *worth it* against the nearest; no comparison with the user's own average paid | **N** |
| 8 | Favourite star per row | favourites exist for **your** stations; a provider-only row would have to be added first | **H** for your stations |
| 9 | *Directions* (a Google Maps search link, new tab) | none; a link to a third party with the station's name | **N** |
| 10 | *Log fill-up here* (opens the fill-up form with the station) | the fill-up form has a station field, but no `station` prefill from a link | **N** (small) |
| 11 | Footer "Prices are sample data…" | the provider's attribution and last sync | **K** |
| 12 | No list of your own stations, visits or 12-month averages | the *Stations* page | **N** (#195) |
| 13 | Nothing when prices are off | the *Stations* page works without a provider | **K** |

---

## Acceptance criteria

1. Reports → *Cost of ownership* shows *Total cost*, *Per month*,
   *Depreciation* and *Finance interest*, then a card per vehicle whose
   coloured bar shows what its cost is made of, adding up exactly.
2. Ask and Fuel stations look and work like the prototype, for the
   features the owner chose.
3. `VERSION` is 3.0.0, the changelog and upgrade notes are written, and an
   upgrade from 2.16.0 on each engine keeps every user's reminder email.
4. Definition of done (CLAUDE.md §11) holds.

## Open questions

All answered by the owner on 2026-10-05, before the phase was built
(log #186–#197):

- **Finance interest** (#186): *Decided* 2026-10-05: the HP, PCP and
  loan lines counted so far (interest, fees, the end adjustment); leases
  left out.
- **Per month for the fleet** (#187): *Decided* 2026-10-05: the active
  vehicles' own rates added up, as the prototype.
- **Station maps** (#188): *Obsolete*: the prototype has no map; its
  *Directions* link is #196.
- **Bar parts** (found by the audit, #189): *Decided* 2026-10-05: §7.35's
  five parts; finance stays in *Other*.
- **Period on the Cost of ownership page** (found by the audit, #190):
  *Decided* 2026-10-05: since bought only; the draft's "keeps its period
  choice" corrected.
- **Vehicle Cost of ownership tab** (found by the audit, #191): *Decided*
  2026-10-05: build it.
- **Where Ask lives** (found by the audit, #192): *Decided* 2026-10-05:
  Ask keeps its navigation entry; the Insights page also has the Ask box.
- **The Insights Ask box** (found by the audit, #193): *Decided*
  2026-10-05: it always opens the new thread on `/ask`.
- **Ask features the existing tools can't support** (#194): *Obsolete*:
  none; the prototype's Ask uses only what Ask has.
- **Fuel stations layout** (found by the audit, #195): *Decided*
  2026-10-05: *Prices nearby* above *Your stations*.
- **Fuel stations features** (#196): *Decided* 2026-10-05: build all of
  them, including the area average, the saving banner, OSM directions
  and *Log fill-up here*.
- **Tyre "Moved" wording** (carried from Phase 33.3 #185, #197):
  *Decided* 2026-10-05: reverted to "Fitted {month}", the tyre's first
  fitting to the vehicle, with its distance since.
