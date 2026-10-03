# Phase 30.2 — Live fuel prices and cheapest near me + v2.14 release

*Today's prices near you, ranked by what the trip really saves.*

Status: 🚧 in progress · releases **v2.14.0** · file lives in `docs/phases/`

Phase 30.1 made stations records. This phase brings in **listed prices**
from official open-data feeds, starting with the UK's **Fuel Finder**
scheme. It is built behind a provider interface, so other countries' feeds
can be added as adapters.

Prices and station details are synced on a schedule as a Phase 28.1 job.
For a provider that publishes the whole country, "cheapest near me" is
answered **on the owner's server**, and their location never leaves it.

The ranking is Logbook's own. It uses the vehicle's usual fill-up and its
economy to weigh a cheaper price against the extra driving, so "2p cheaper,
5 miles away" doesn't win when it costs more to get there.

It is **off until an admin switches it on**, because it calls a third party.

Read [`CLAUDE.md`](../../CLAUDE.md), [`spec.md`](../../spec.md) §7.3 (grades,
fuel insights), §7.33 (stations), §7.30 (jobs) and §7.25 (secrets and
location classes), and Phase 30.1 first.

---

## Background: UK Fuel Finder

- **Live since:** 2 February 2026, under the Motor Fuel Price (Open Data)
  Regulations 2025. Every UK filling station must report a price change
  within 30 minutes, and enforcement began on 1 May 2026.
- **Grades:** E5, E10, diesel, super diesel, B10 and HVO.
- **Station data:** trading name, address, latitude and longitude, usual
  opening hours and amenities, kept up to date by the retailer.
- **To confirm at implementation:** the exact endpoints, whether a key or
  registration is needed, and the licence and attribution terms. Record
  them in `spec.md` §4, and design for a key.

---

## Goals

1. A **price provider interface** with two kinds of provider: *bulk*
   (download everything, query locally) and *area* (ask per search),
   each with grade mapping to Logbook's codes.
2. The **UK Fuel Finder adapter** (bulk).
3. Settings → *Fuel prices* (admins): provider, credentials, refresh
   interval, attribution, last sync.
4. **Sync** as a Phase 28.1 job: provider stations and current prices, plus
   daily **listed price history** for stations users have used or
   favourited.
5. **Linking** Logbook stations to provider stations, after which their
   details and prices update.
6. **Cheapest near me** from the current location, a place or a station,
   for a vehicle and grade, ranked by **effective cost**.
7. **"Was it worth it?"**: each ranked station shows the sum in full (fuel
   saving, extra distance, fuel for the extra distance, actual saving), and
   a saved fill-up at a linked station is compared with the user's usual
   station at that moment.
8. The listed price on the fill-up form, a dashboard widget, an API
   endpoint and an Ask Logbook tool.

## Not in scope

- EV charging prices and availability.
- Turn-by-turn directions or road distances.
- Price predictions or "fill up now or wait" advice.
- Reporting prices back to any provider.
- Adapters for other countries (each is a small later task; see
  *Candidate providers*).

---

## Spec additions

> Superseded by `spec.md` §6 and §7.34 as decided on 2026-10-03 (#136–#144);
> the draft below is kept for the record.

### §6 Data model

> **ProviderStation** (Phase 30.2): id, provider (code), provider_id,
> name, brand, address, postcode, latitude, longitude, opening hours (JSON as
> the provider gives it, shown as text), amenities (JSON), grades (Logbook
> codes), updated_at, removed_at (no longer in the feed). Unique `(provider,
> provider_id)`; index on position.
>
> **ProviderPrice** (current): provider_station_id, grade (Logbook code),
> price per litre (`decimal(8,3)`, in the provider's currency), reported_at
> (UTC, as the provider gives it), synced_at. Unique `(provider_station_id,
> grade)`.
>
> **ListedPriceHistory:** provider_station_id, grade, date, low, high, close
> (`decimal(8,3)`). Kept only for provider stations linked to a Logbook
> station that someone has used or favourited, and for
> `PRICE_HISTORY_DAYS` (default 1,095, three years).
>
> **Station** (Phase 30.1) gains `provider_station_id` (nullable) and
> `keep_my_details` (bool, default false).
>
> Provider tables are **not in backups**; they are re-synced. Links and
> history are.

### §7.34 Fuel prices (new)

> - **Settings → Fuel prices** (admins; the page and every price feature
>   are hidden until a provider is enabled):
>   - **Provider:** *UK Fuel Finder* to start, with its description, the
>     data it sends (for bulk: "Logbook downloads the national price list.
>     Your location is never sent."), and its licence and attribution.
>   - **Credentials** if the provider needs them (encrypted, or `env:NAME`,
>     as Phase 26.1's secrets).
>   - **Refresh:** every 30, 60 (default) or 120 minutes, never faster than
>     the provider allows.
>   - *Sync now* (Phase 28.1's *Run now*), the last sync time, counts and
>     any error.
>   - **Area providers** (later adapters) show an *Internet* acknowledgement
>     naming what is sent: "Your search position and radius are sent to
>     {host}".
> - **Sync job** `fuel_prices`:
>   - downloads stations and prices, updates `provider_stations` and
>     `provider_prices` in batches inside a transaction, and marks stations
>     missing from the feed as removed;
>   - maps provider grades to Logbook's codes (§7.3) with the adapter's
>     table. UK Fuel Finder: E10 → `e10_95`, E5 → `e5_97` (UK super
>     unleaded; an admin can change it to `e5_98` or `e5_99` for their
>     area), diesel → `b7`, super diesel → `b7_premium`, B10 → `b10`, HVO →
>     `xtl`;
>   - records each linked station's daily low, high and close in the
>     history;
>   - fails loudly in the jobs page, and never deletes current prices on a
>     failed sync.
> - **Freshness:** every listed price shows its reported time ("Listed £1.379
>   at 14:20"). Prices reported more than **48 hours** ago are shown as "may
>   be out of date" and are left out of rankings unless *Include older
>   prices* is ticked.
> - **Linking stations:** a Logbook station with a position is offered the
>   provider stations within **150 m**, best name match first, under *Is
>   this the same station?*. Without a position, it is offered matches by
>   postcode and name.
>   - Once linked, address, position, opening hours and grades are kept up
>     to date from the feed, unless *Keep my details* is ticked.
>   - Merging (Phase 30.1) keeps the link.
>   - New stations can be added from a provider station in one tap from the
>     results.
> - **Station page:** the listed prices beside the user's own (*Listed
>   now*, *You paid on average*), and the listed price history for each
>   grade on the same chart as theirs, as a second series.
> - **Cheapest near me** (`/stations/near`, GET form, working without JS):
>   - **From:** *My current location* (browser geolocation with JS; the
>     position is used for the search only and never stored), a place, or a
>     station.
>   - **Vehicle** (defaults to the user's most recent); **grade**
>     (defaults to the vehicle's usual grade, Phase 16's reference grade);
>     **radius** 2, 5 (default), 10 or 20 in the user's unit.
>   - **Effective cost**, worked out for each station with a fresh price:
>     - usual fill = the median volume of the vehicle's last 10 full fills
>       (else 40 L, labelled);
>     - detour = 2 × the straight-line distance × **1.3** (a road factor,
>       labelled);
>     - detour fuel = detour × the vehicle's 12-month consumption;
>     - effective cost = price × usual fill + detour fuel × price.
>     The list is ordered by effective cost.
>   - **Columns:** station and brand, distance (straight line), listed
>     price and its time, *Effective* (for example "£61.98 for your usual
>     45 L"), and *Saves* against the nearest station selling the grade
>     ("saves £0.42"; "costs £0.31 more", shown as such).
>   - **Hint:** "Effective cost counts the fuel to get there and back, at
>     your usual economy. Distances are straight-line × 1.3."
>   - *Sort by price* is offered too, for anyone who wants the plain list.
> - **Was it worth it?** (the sum behind *Saves*):
>   - **Before going:** each result row on *Cheapest near me* opens a
>     breakdown against the nearest station selling the grade. For example,
>     for a station 7 mi further away, 4p cheaper, with a 50 L usual fill:
>     "Fuel saving £2.00 (50 L at 4p less) · Extra distance 14 mi there
>     and back, about 18 mi by road · Fuel for that £2.34 at your usual 48
>     mpg · **Actual saving −£0.34: not worth the trip**". Every number comes
>     from the effective
>     cost rule above, with its labels.
>   - **After a fill-up:** saving a fill-up at a linked station with a fresh
>     listed price compares it with the user's **usual station** for that
>     vehicle (the most visited in the last 12 months, also linked, with a
>     price listed within 2 hours of the fill-up). The extra distance is the
>     difference between the two stations' distances from the user's *Home*
>     place, doubled, × 1.3. The saved page shows "Compared with your usual
>     Tesco Antrim (£1.400): saved £2.00 on fuel, about £0.50 for the extra
>     4 mi, **£1.50 better off**". Without a Home place, only the fuel saving
>     is shown, labelled "before the extra driving". At the usual station,
>     nothing is shown.
>   - **Fuel tab:** a *Shopping around* line for the last 12 months: the sum
>     of those after-fill-up results, "about £18.40 better off from 23
>     fill-ups away from your usual station". It is shown only when at least
>     3 fill-ups were compared.
>   - All of it is a derived figure, never stored, and labelled as an
>     estimate wherever distance or economy is assumed.
> - **Fill-up form:** at a linked station, the hint becomes "Listed
>   £1.379/L E10 95 at 14:20 · Last time you paid £1.389". *Use listed
>   price* fills the price with one tap. It is never filled on its own.
> - **Dashboard widget** `cheapest_fuel`: the three cheapest by effective
>   cost near a chosen place for a chosen vehicle, with the time of the
>   latest sync.
> - **API** (§7.20): `GET /api/v1/fuel-prices/near?vehicle=&grade=&lat=&lng=&radius=`
>   or `&place=<name>`, returning the same rows. The position in a request
>   is used and not stored. **Ask Logbook** (§7.26): a
>   `cheapest_fuel(vehicle?, grade?, near: place | station | here,
>   radius?)` tool ("Where's the cheapest E10 near work?").
> - **Attribution** from the provider's licence is shown wherever its data
>   appears (stations, results, widget).

### Candidate providers (adapters after this phase)

Each needs its terms, coverage and method checked before work starts.
Germany (MTS-K, through an area API such as Tankerkönig), France (the
national open fuel price data), Spain, Italy and Austria (national price
feeds). The interface supports both kinds, so an area provider differs only
in what it sends and how it is disclosed.

---

## Decisions (and why)

- **Bulk first.** Downloading the national list and querying locally keeps
  the user's location on their own server. It also makes searches instant
  and independent of the provider's uptime.
- **Effective cost, not just price.** Logbook knows the usual fill and the
  car's economy, which no price app does. Counting the drive there and back
  is what turns a price list into an answer.
- **The road factor is shown.** Straight-line distance understates driving
  distance. 1.3 is a common approximation, and saying so keeps the figure
  honest without a routing service.
- **Listed history only for stations that matter to someone.** Keeping
  every station's daily history would be millions of rows a year; keeping it
  for linked, used or favourite stations is what the charts need.
- **Never prefill the price silently.** A listed price can be minutes stale
  or wrong. One tap to use it keeps the user's typed or chosen value as the
  record.
- **Off until switched on,** as every outside request in Logbook is.

---

## Tasks

### Spec and docs
- [ ] §6 and §7.34 in `spec.md`; §4 (provider endpoints, licence); §9
      (`PRICE_HISTORY_DAYS`); the Phase 30.2 line in §13.
- [ ] `docs/stations.md`: *Fuel prices*: enabling UK Fuel Finder, what is
      downloaded and stored, cheapest near me and effective cost explained,
      and adding a provider adapter.

### Migrations (every engine, each reversible)
- [ ] `provider_stations`, `provider_prices`, `listed_price_changes`,
      `price_alerts`, `fuel_price_secrets`; `stations.provider`,
      `stations.provider_ref`, `stations.keep_my_details` (#143, #144).

### Code
- [ ] `Service\FuelPrices\PriceProvider` interface (kind, grade map,
      `sync()` for bulk, `search()` for area), `ProviderRegistry`.
- [ ] `Service\FuelPrices\Uk\FuelFinderProvider`: download, parse, map
      grades, with timeouts, size limits and the configured credentials.
- [ ] `fuel_prices` job (Phase 28.1): batch upserts, removals, history.
- [ ] `Service\FuelPrices\StationLinker` (150 m candidates, name and
      postcode similarity).
- [ ] `Service\FuelPrices\CheapestNear` (bounding box then haversine,
      freshness, usual fill, consumption, effective cost, saving).
- [ ] Price alerts (#138): `price_alerts`, the station page form, the
      check after each sync, notification kind `price_alert`.
- [ ] `bin/record-fuel-finder.php` (#139): record and trim a real
      download into the test fixture.
- [ ] Settings page, station page additions, the results page with
      geolocation, the fill-up form hint, the widget, the API endpoint, the
      Ask tool, attribution.
- [ ] Translations (en, de).

### Tests
- [ ] **Feed fixture** (synthetic, to the published schema, #139): sync
      creates stations and prices, maps every grade, marks removed
      stations, and keeps current prices on a failed sync.
- [ ] History: price changes only for linked stations that are used or
      favourited; daily low, high and close derived; retention.
- [ ] Price alerts: sent once below the threshold, re-armed above it,
      never twice for one drop, not for stale prices or closed stations.
- [ ] Linking: candidates within 150 m ordered by name similarity; postcode
      fallback; *Keep my details* respected; merge keeps the link.
- [ ] Effective cost: worked examples in the test file (a cheaper, farther
      station that loses; one that wins), the 40 L default, the road factor,
      plug-in hybrids using the liquid series.
- [ ] **Was it worth it?**: the worked example (7 mi, 4p, 50 L, 48 mpg) gives the
      stated breakdown; after-fill-up comparison with and without a Home
      place; no comparison at the usual station or without a listed price
      in effect at the fill-up's time (#144); the *Shopping around* total and its minimum of
      3 fill-ups.
- [ ] Freshness: older than 48 hours excluded unless asked; labels.
- [ ] Location: a current-location search stores nothing; places are used
      by name in the API.
- [ ] Off by default: no request is made, and no page shows prices, until a
      provider is enabled.
- [ ] Access: settings admin only; results use only the user's vehicles.
- [ ] Integration suite green on every engine.

### Sample data
- [ ] `DemoDataSeeder`: a small synthetic provider dataset near the demo
      places (clearly fake names) with prices, so *Cheapest near me* works
      in the demo without any outside call.

### Release
- [ ] `CHANGELOG.md` **2.14.0**: live fuel prices (UK Fuel Finder) and
      cheapest near me. Upgrade notes: migrations; off until an admin
      enables a provider.
- [ ] Bump `VERSION`, rebuild assets, update the README status.

---

## Acceptance criteria

1. With UK Fuel Finder enabled, the stations near Home show today's listed
   E10 prices with their times, without the user's location being sent
   anywhere.
2. *Cheapest near me* ranks a cheaper but farther station below a nearer
   one when the extra driving costs more, and says by how much.
3. Logging a fill-up at a linked station offers the listed price in one
   tap, and afterwards says whether driving there beat the usual station,
   with the sum shown.
4. A station's chart shows what the user paid beside the listed price over
   time.
5. With no provider enabled, nothing about live prices appears and nothing
   is fetched.
6. Definition of done (CLAUDE.md §11) holds.

## Open questions

Answered on 2026-10-03, before the phase was built (the full text is in
`spec.md` §6 and §7.34):

- **#136 E5 mapping.** *Decided 2026-10-03:* as drafted, one install-wide
  mapping an admin chooses (E5 97 by default, or E5 98 or E5 99+).
- **#137 Road factor.** *Decided 2026-10-03:* as drafted, a constant 1.3,
  labelled wherever it is used.
- **#138 Price alerts.** *Decided 2026-10-03:* built in this phase. A
  price per grade on a favourite linked station; after each sync, one
  notification through the user's channels when its fresh listed price
  drops below it, re-armed when it goes back up.

Found while starting this phase:

- **#139 The recorded feed fixture.** *Decided 2026-10-03:* the feed
  needs GOV.UK One Login credentials, so the fixture is synthetic,
  following the published schema exactly, and `bin/record-fuel-finder.php`
  records and trims a real download to replace it.
- **#140 Closures.** *Decided 2026-10-03:* a permanently closed station
  is treated as removed; a temporarily closed one stays, labelled, and is
  left out of rankings, the widget and alerts.
- **#141 Implausible prices.** *Decided 2026-10-03:* a value under 2.0 is
  pounds and multiplied by 100; one outside 50–500p is dropped and
  counted. Stored as pounds per litre.
- **#142 Sync cadence.** *Decided 2026-10-03:* incremental each run
  (changes since the last good sync, less a margin), with a full sync on
  the first run, when no stations are stored, after a provider change,
  and daily. Only a full sync marks stations removed.
- **#143 The link key.** *Decided 2026-10-03:* a station points at its
  provider station by `provider` and `provider_ref` (the feed's id), not
  a row id, so links survive re-syncs and restores (provider tables are
  not backed up). History and alerts are keyed so too.
- **#144 Listed price history.** *Decided 2026-10-03:* each listed price
  change of a tracked station is kept (not a daily summary); the chart's
  daily low, high and close are derived. A past fill-up is compared with
  the price in effect at its time, reported within 48 hours before it,
  replacing "within 2 hours".
