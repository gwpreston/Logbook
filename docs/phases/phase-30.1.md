# Phase 30.1 — Fuel stations + v2.13 release

*Where you fill up, what you paid there, and how far it is from home.*

Status: ✅ complete · released as **v2.13.0** · file lives in `docs/phases/`

A fill-up's station is free text today ("Tesco Antrim", "tesco antrim rd"),
so Logbook can't say where someone usually fills up, or what they've paid
at each place. This phase turns stations into **records**: name, brand,
address, position, grades sold, opening hours, and a favourite flag. Each
fill-up links to one.

Every price is already in the fill-ups, so each station gets a **price
history of what the user paid there** without any outside data.
Saved **places** (Home, Work) give straight-line distances.

Everything here is local; no request leaves the server. Live prices from
outside sources follow in Phase 30.2, built on these records.

Read [`CLAUDE.md`](../../CLAUDE.md), [`spec.md`](../../spec.md) §6, §7.3,
§7.13, §7.20, §7.21 and §7.26 first.

---

## Goals

1. **Station records** shared across the install, created from the fill-up
   form or the stations page.
2. **Fill-ups linked** to a station, with the existing free-text station
   turned into records by the upgrade and a **merge** tool for duplicates.
3. **Station pages:** visits, spend, average and cheapest price paid per
   grade, and a price history chart from the user's own fill-ups.
4. **Places** per user (Home, Work and more) and **straight-line distances**
   from them.
5. **Favourites** per user, shown first everywhere a station is chosen.
6. A *By station* card on the Fuel tab, plus imports, API and Ask Logbook
   support.

## Not in scope

- Prices not paid by the user (Phase 30.2).
- Maps, address lookup or road distances. These need third-party services.
  An *Open in maps* link is the most offered.
- EV charging locations (see *Open questions*).
- Storing the user's location history. Places are only what the user saves.

---

## Spec additions

### §6 Data model

> **Station** (Phase 30.1), shared by every user of the install, since
> stations are public places: id, name (up to 100, required), brand
> (optional, up to 50), address (optional, up to 200), postcode (optional,
> up to 20), country (ISO 3166-1, default the creator's region), latitude
> and longitude (optional, `decimal(9,6)` each, both or neither), grades
> (JSON list of grade codes sold), opening hours (optional free text, up to
> 200), notes, created_by (user), merged_into (nullable; a merged station
> points at the one it became), created/updated (UTC). Index `(name)` and
> `(latitude, longitude)`.
>
> **FuelEntry** gains `station_id` (nullable, `ON DELETE SET NULL`). The
> existing `station` text column is kept: a fill-up shows the station's
> name when linked, else its text.
>
> **StationFavourite:** user_id, station_id (both `ON DELETE CASCADE`),
> unique together.
>
> **Place** (per user): id, user_id (`ON DELETE CASCADE`), name (*Home*,
> *Work*, or the user's own, up to 50), latitude, longitude, sort_order,
> created/updated. Places are private to their user.
>
> Backups carry all four. The schema version moves.

### §7.33 Stations (new)

> - **Module** `stations` (§7.10), on by default, part of the `fuel` module's
>   pages. With `fuel` off it is off too.
> - **Upgrading** (the migration, in batches): the distinct non-empty
>   `station` texts on each user's fill-ups are normalised (trimmed,
>   whitespace collapsed, case-folded) and **one station is created per
>   normalised name**, using the most common spelling as its name. Each
>   fill-up is linked to its station. Nothing is merged across different
>   spellings; that is the merge tool's job. The release note says so.
> - **Fill-up form:** the *Station* field becomes a combo box. Typing
>   searches stations by name, brand and postcode, with favourites first,
>   then stations the user used recently, then the rest.
>   - *Add "Tesco Antrim"* creates a station from what was typed.
>   - Without JS, it is a select of favourites and recent stations plus
>     *Other*, which shows a text field and creates the station on save.
>   - Under the field: "Last time here: £1.389/L E10 95, 12 Sep", from the
>     user's own fill-ups. This is a hint, never prefilled.
> - **Stations page** (`/stations`, in the Fuel section of navigation):
>   - favourites first, then by last visit, with name, brand, distance from
>     each of the user's places, visits, last visit, and the average price
>     paid in the last 12 months for the user's most-used grade there;
>   - search by name, brand or postcode;
>   - *Add station*.
> - **Station page** (`/stations/{id}`):
>   - details, *Favourite*, *Edit*, *Merge*, and *Open in maps* (a link to
>     `geo:` on phones and to OpenStreetMap elsewhere, only when it has a
>     position; nothing is loaded until clicked);
>   - per grade, the user's visits, spend, average and cheapest price paid,
>     and a **price history** chart of what they paid (Chart.js, with the
>     table as the no-JS fallback);
>   - the user's fill-ups there, newest first.
>   Only fill-ups on vehicles the user can see count (Phase 19).
> - **Positions:** typed as latitude and longitude, or *Use my current
>   location* while standing at the station (the browser's geolocation,
>   asked once with an explanation, sent only to Logbook and stored on the
>   station).
> - **Places** (Settings → Account → *Places*): *Home*, *Work* and any
>   others. Each is set by typing coordinates, copying a station's position,
>   or *Use my current location*. They are shown only to their user and are
>   never in the sale pack, print views, the API's vehicle data or other
>   users' pages.
> - **Distances** are great-circle (haversine) distances in the user's unit,
>   labelled "in a straight line", because road distance needs a routing
>   service.
> - **Merge** (the station's creator or an admin): choose the station to
>   keep. Every fill-up and favourite moves to it, its details win where
>   both have a value (with a chance to pick per field), and the other
>   station gets `merged_into` so old links still resolve. A **duplicates**
>   view lists stations with similar names (same brand, or names one edit
>   apart, or within 150 m of each other) to make tidying quick.
> - **Edit:** the creator or an admin. Any user can add stations and
>   favourite them.
> - **Fuel tab** (§7.3): a *By station* card with the top five stations by
>   spend in the last 12 months, each with visits, spend, and the average
>   price paid for the vehicle's main grade, linking to the station page.
> - **CSV import** (§7.13): the station column links to an existing station
>   by normalised name, or creates one. The preview says which ("new
>   station: Tesco Antrim").
> - **API** (§7.20): fill-ups keep `station` (the text) and gain
>   `station_id` (additive, #135). `GET /api/v1/stations` (search,
>   favourites first) and `GET /api/v1/stations/{id}` return station
>   details and the key user's price statistics. Fill-up writes take
>   `station_id` or `station` (name, linked or created as the import does).
>   Places are never in the API.
> - **Ask Logbook** (§7.26): a `stations(query?, favourites_only?)` tool
>   for "Where do I usually fill up?" and "What's the cheapest I've paid at
>   Tesco?".

---

## Decisions (and why)

- **Stations are shared; favourites and places are personal.** A station
  is a public place, and the household shares one list without duplicates.
  Where someone lives and works is theirs alone.
- **The upgrade creates stations but never guesses merges.** "Tesco
  Antrim" and "Tesco, Antrim Rd" may or may not be the same forecourt.
  Creating one per spelling is safe; the duplicates view makes merging a
  minute's work.
- **Price history from the user's own fill-ups.** It is exact, already
  there, and needs nothing from outside. Phase 30.2 adds listed prices
  beside it, never instead of it.
- **Straight-line distances, labelled.** They need no service, and the label
  stops anyone mistaking them for road miles.
- **"Last time here" is a hint, not a prefill.** Prices change daily. A
  prefilled price is a typo waiting to be saved.

---

## Tasks

### Spec and docs
- [x] §6 and §7.33 in `spec.md`; the fill-up form, import and API changes;
      the Phase 30.1 line in §13.
- [x] `docs/stations.md`: stations, merging, places and distances, privacy.

### Migrations (every engine, each reversible)
- [x] `stations`, `station_favourites`, `places`; `fuel_entries.station_id`.
- [x] Data migration: create and link stations from the station texts, in
      batches. Rollback unlinks and drops the new tables; the text column
      was never changed.

### Code
- [x] `Domain\Station\*`, `Repository\StationRepository` (search,
      normalised-name lookup, nearby by bounding box then haversine).
- [x] `Service\Station\StationStats` (visits, spend, average and cheapest
      by grade, history) over the user's visible fill-ups.
- [x] `Service\Station\Merge` and `Duplicates` (similar names, edit
      distance 1, within 150 m).
- [x] `Support\Geo\Haversine`; places service.
- [x] The combo box (progressive enhancement), stations pages, places
      settings, the Fuel tab card, and the fill-up form hint.
- [x] Import, API and Ask tool changes.
- [x] Translations (en, de).

### Tests
- [x] Upgrade: distinct texts become stations by normalised name; the
      most common spelling wins; every fill-up linked; empty texts left
      alone; rollback restores the previous state.
- [x] Fill-up form: search order (favourites, recent, rest); create from
      typed text; the no-JS *Other* path; the "last time here" hint.
- [x] Stats: averages weighted by volume; cheapest; only visible vehicles'
      fill-ups; per grade.
- [x] Merge: fill-ups and favourites move; field choice; `merged_into`
      resolves; duplicates view finds the three kinds.
- [x] Haversine: known distances (London to Edinburgh, two points 1 km
      apart) in km and miles.
- [x] Places: private to their user in pages, API, print, sale pack and
      other users' views.
- [x] Geolocation: optional; nothing stored unless saved on a station or
      place.
- [x] Import links or creates stations, shown in the preview.
- [x] Module and `fuel` off: everything gone, data kept.
- [x] Integration suite green on every engine.

### Sample data
- [x] `DemoDataSeeder`: about eight stations across the demo fill-ups (two
      spellings of one, ready to merge), two favourites, Home and Work
      places, and positions on most stations.

### Release
- [x] `CHANGELOG.md` **2.13.0**: fuel stations, places and distances.
      Upgrade notes: migrations; existing station names become stations,
      one per spelling, so use *Duplicates* to merge.
- [x] Bump `VERSION`, rebuild assets, update the README status and the
      documentation table.
- [x] Tag `v2.13.0` once merged.

---

## Acceptance criteria

1. After upgrading, every fill-up with a station name is linked to a
   station, and two spellings of one forecourt can be merged in one step.
2. Choosing a station on a fill-up takes a few letters, favourites first,
   with what was paid there last time.
3. A station's page shows what the user paid there by grade over time,
   from their own fill-ups.
4. Distances from Home and Work appear on the stations list, and nobody
   else can see those places.
5. Nothing in this phase makes a request to any outside service.
6. Definition of done (CLAUDE.md §11) holds.

## Open questions

Answered on 2026-10-02, before the phase was built (the full text is in
`spec.md` §6 and §7.33):

- **#131 Charging locations.** *Decided 2026-10-02:* public chargers are
  stations, their charging grades listed as grades sold. Home charging
  (grade `home`) is never a station: those fill-ups keep their text, the
  upgrade and the import skip them, and the form offers no station picker.

- **#132 Editing.** *Decided 2026-10-02:* as drafted. Any user adds and
  favourites stations; the creator or an admin edits and merges.

Found while starting this phase:

- **#133 Upgrade grouping and creator.** *Decided 2026-10-02:* normalised
  across the whole install, one shared list. The creator is the owner of
  the vehicle with the earliest fill-up under that name, and the country
  that owner's locale region, or none (country is optional).
- **#134 Receipt scans and Ask drafts.** *Decided 2026-10-02:* they link
  or create a station by normalised name as the import does, shown in the
  review step.
- **#135 The API's fill-up shape.** *Decided 2026-10-02:* additive.
  `station` stays the text, reads gain `station_id`, and writes take
  either.
