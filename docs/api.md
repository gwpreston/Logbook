# REST API

A small JSON API for automations: Home Assistant sensors, Apple Shortcuts
and Android automations that log a fill-up, Grafana panels, Node-RED flows
and OBD tools that post the odometer. It reads what a dashboard needs and
writes **fill-ups**, **odometer readings** and **trips**, and from 2.7
**service records**, **documents**, **expenses**, **tread checks** and
**manual reminders**. From 3.5 it also **edits and deletes** them, and
writes what else the pages do: **vehicles**, **valuations**,
**schedules**, **tyre changes**, **saved journeys**, **favourite
stations**, **price alerts**, **finance**, and *Hide* on *Needs
attention*.

The contract is the OpenAPI 3.1 description, served by your install at
`<your URL>/api/v1/openapi.json` (the same file as
[`docs/api/openapi.json`](api/openapi.json)); the test suite checks every
response against it. `spec.md` §7.20 has the rules.

The same keys open the [MCP server](mcp.md) (`/mcp`), for Claude Desktop
and other assistants.

- [Keys](#keys)
- [Requests and values](#requests-and-values)
- [Endpoints](#endpoints)
- [Lists: paging and dates](#lists-paging-and-dates)
- [Logging fill-ups and readings](#logging-fill-ups-and-readings)
- [Logging other entries](#logging-other-entries)
- [Editing and deleting](#editing-and-deleting)
- [More writes](#more-writes)
- [Errors](#errors)
- [Browser dashboards (CORS)](#browser-dashboards-cors)
- Examples: [curl](#curl) · [Home Assistant](#home-assistant) ·
  [Apple Shortcuts](#apple-shortcuts) · [Grafana](#grafana) ·
  [Node-RED](#node-red)

---

## Keys

Every call needs an API key, except `openapi.json`.

- **Create one** in **Settings → API keys**: give it a name ("Home
  Assistant") and choose **Read only** or **Read and write** (read, plus
  logging fill-ups, odometer readings and trips; a key never edits or deletes).
  The key (`lbk_` and 43 characters) is shown **once**. Copy it then; it
  cannot be shown again, only replaced.
- On a headless install use the command line (Docker: prefix with
  `docker compose exec -u www-data app`):

  ```sh
  php bin/api-key.php create --user pat --name "OBD dongle" --scope read_write   # prints the key
  php bin/api-key.php list
  php bin/api-key.php revoke 3
  ```

- **Send it** with every request: `Authorization: Bearer lbk_…`.
- A key sees **exactly what its user sees** and can do only what its user
  can: their own vehicles and those shared with them, at the share's level
  ([users-and-sharing.md](users-and-sharing.md)). Logging needs Log access.
  Cost figures follow the user's access to costs: without it, amount fields
  are **left out**, not zeroed, except on the entries the user added
  themselves (a driver sees what they paid). Each logged fill-up or reading
  records the key's user as who added it.
- A key of a **disabled or deleted** user stops working at once (`401`).
- **Revoke** a key in Settings → API keys (or `bin/api-key.php revoke`). It
  stops working at once and for good. The list shows when each key was last
  used (to the minute).
- Keys are stored only as a keyed hash (HMAC-SHA256 with `SESSION_SECRET`).
  **Changing `SESSION_SECRET` disables every key**, and so does restoring a
  backup into an install with a different one.
- Failed keys are logged with the client address (never the key). **20
  failures from one address in 10 minutes** block that address for 10
  minutes (`429` with `Retry-After`), even with a good key.
- A signed-in browser cannot use the API: the session cookie is ignored.
- `API_ENABLED=false` switches the whole API off (every address is a `404`).

## Requests and values

Base URL: `<APP_URL><APP_BASE_PATH>/api/v1`, e.g.
`https://garage.example.com/api/v1` or `https://example.com/logbook/api/v1`.
Settings → API keys shows yours.

Responses are JSON. Values are **canonical**, the same whatever the key's
user prefers, so automations can compare and chart them:

| What | Unit | Example |
|---|---|---|
| distances, odometer | kilometres (`"distance_unit": "km"`) | `"odometer": "78421.000"` |
| fuel volume | litres, kWh for electricity, kg for CNG (`"volume_unit": "l"` / `"kwh"` / `"kg"`) | `"volume": "44.210"` |
| consumption | L/100 km or kWh/100 km (`"consumption_unit"`) | `"average_consumption": "6.357"` |
| money | the vehicle's currency (`"currency": "GBP"`) | `"total_cost": "61.370"` |
| tread depth | millimetres | `"depth": "7.938"` |
| instants | ISO 8601, UTC | `"filled_at": "2026-09-29T07:42:00Z"` |
| calendar dates | `YYYY-MM-DD`, no zone | `"expiry_on": "2027-03-14"` |

- Numbers are **decimal strings** at their stored precision, never floats,
  so nothing is lost on the way. Most tools read `"61.370"` as a number
  (Home Assistant's `| float`, Grafana's *Number* column).
- To show other units: mpg (UK) = 282.481 ÷ L/100 km, mpg (US) = 235.215 ÷
  L/100 km, km/L = 100 ÷ L/100 km; miles = km ÷ 1.609344. Or use the
  summary's `display` block, which is already in the owner's units,
  language and currency ("48,730 mi", "44.4 mpg").
- A field with no value is `null`. Codes (`fuel`, `grade`, `category`,
  `status`, …) are the app's own; `openapi.json` lists them.
- A switched-off module's endpoints answer `404`, and its fields leave the
  summary (Fuel off: no `fuel`, no economy).

## Endpoints

| Endpoint | Returns |
|---|---|
| `GET /me` | the key's user and preferences, the key's name and scope, which modules are on |
| `GET /vehicles` | visible vehicles (`?status=active\|archived\|all`, default `active`) |
| `GET /vehicles/{id}` | one vehicle, as its edit form holds it, with an `ETag` and its `disposal` (`sold`, `written_off`, …) |
| `GET /vehicles/{id}/summary` | odometer and its time, economy per series (liquid, electric, gas for CNG), the last fill-up, running cost per km over 12 months and the true cost per km (depreciation included), what is due next, open reminder counts, current documents' expiry, tyre status, and `display` text |
| `GET /vehicles/{id}/fuel` | fill-ups, each with the economy of the tank it closes and its economy-check flag (paged) |
| `POST /vehicles/{id}/fuel` | log a fill-up (read and write key) |
| `GET /vehicles/{id}/odometer` | readings with their source (manual, fuel, maintenance, document, tyre, incident, purchase, issue, issue_update) (paged) |
| `POST /vehicles/{id}/odometer` | add a reading (read and write key) |
| `GET /vehicles/{id}/{list}/{entry}` | one entry of `fuel`, `odometer`, `maintenance`, `documents`, `expenses`, `trips`, `incidents`, `issues`, `schedules` or `valuations`, exactly as its list returns it, with an `ETag` ([Reading one entry](#reading-one-entry)) |
| `GET /vehicles/{id}/maintenance` | service records (paged; `?category=`, and `?q=` for words in the title, vendor or description) |
| `POST /vehicles/{id}/maintenance` | add a service record (read and write key) |
| `GET /vehicles/{id}/documents` | compliance documents with their status and days left (paged; `?type=`, and `?current=1` for those in force today) |
| `POST /vehicles/{id}/documents` | add a document (read and write key) |
| `GET /vehicles/{id}/mot-tests` | the MOT history fetched from DVSA (Phase 41): tests newest first with their mileage (km), the unit tested in, defects and what was made from them, the recall state (`yes`, `no`, `unknown`, `unavailable`) and the provider's attribution. Never fetches; `404` while MOT history is off ([docs/mot-history.md](mot-history.md)) |
| `GET /vehicles/{id}/expenses` | expenses (paged; needs cost access) |
| `POST /vehicles/{id}/expenses` | add an expense (read and write key; cost access not needed) |
| `GET /vehicles/{id}/tyres` | tyres: fitted, stored, retired, with tread and what is due |
| `GET /vehicles/{id}/tyres/changes` | tyre changes and tread checks, newest first, with their lines (tyres module) |
| `GET /vehicles/{id}/tyre-sets`, `GET /tyre-sets` | tyre sets with their tyres, for one vehicle or every active one (`?vehicle=`; tyres module) |
| `GET /vehicles/{id}/schedules` | maintenance schedules with their due state, most urgent first, as the Maintenance tab: `status` (`overdue`, `soon`, `ok`, `unknown` in your lead times), the `trigger` that applies (date or distance), `due_on` (the date, or the day the distance is projected to be reached: `due_on_projected`), `days_left`, `distance_left` (maintenance module) |
| `GET /vehicles/{id}/valuations` | valuations, newest first (paged; cost access) |
| `GET /vehicles/{id}/ownership` | cost of ownership and depreciation, as the overview's card (cost access, else `403`) |
| `GET /vehicles/{id}/history`, `GET /history` | the history feed of one vehicle or every active one, newest first (`?kinds=fuel,expense`, `?since=` / `?until=` days, paged); amounts only where you may see them |
| `POST /vehicles/{id}/tyres/checks` | record a tread check (read and write key) |
| `GET /vehicles/{id}/true-cost` | true cost per km for `?period=last_12_months` (default) or `since_bought`: each part (fuel, maintenance, compliance, other, depreciation) and insurance payouts, adding up exactly, the change against the 12 months before, each calendar year and what changed from the year before, with translated sentences (cost access, else `403`) |
| `GET /vehicles/{id}/finance` | the active finance agreement's figures and schedule, else the latest ended one's; estimates marked as such, never the agreement number (Manage and cost access, else `404`; finance module) |
| `GET /vehicles/{id}/finance/agreements` | every agreement, the active one first, each with its payment events and settlement quotes (as above) |
| `POST /vehicles/{id}/reminders` | add a manual reminder (read and write key; Manage) |
| `GET /upcoming` | *Coming up* over the next 12 months (`?vehicle=`) |
| `GET /reminders` | open reminders, most urgent first (`?vehicle=`, `?status=overdue\|due\|upcoming`); `?closed=1` (or `?status=done\|dismissed`) the done and dismissed ones, most recently closed first; every reminder carries `closed_at` (null while open) |
| `POST /reminders/{id}/done`, `/dismiss`, `/reopen` | the Reminders page's buttons, for a reminder of any kind; safe to repeat (`"unchanged": true`) (read and write key; Log) |
| `GET /attention` | *Needs attention* for every active vehicle (`?vehicle=`), in the page's order and words, each with a link to its fix; an item you can hide carries a `key` for `POST /attention/{key}/hide` |
| `GET /reports/costs` | spend by category group, month or vehicle (`?group_by=`), per currency ([Reports](#reports)) |
| `GET /reports/cost-per-distance` | cost per km, per vehicle and in all ([Reports](#reports)) |
| `GET /reports/fuel` | fuel statistics per vehicle, kind and grade, with the grade verdicts ([Reports](#reports); fuel module) |
| `GET /reports/mileage` | distance driven in the period, and the averages ([Reports](#reports)) |
| `GET /vehicles/{id}/trips` | trips: your own, or every driver's when you manage or own the vehicle (paged; trips module) |
| `POST /vehicles/{id}/trips` | log a trip, or one from a saved journey (read and write key; trips module) |
| `GET /trips/claim` | your mileage claim's figures for a tax year or date range (trips module) |
| `GET /journeys` | your saved journeys, in your order (trips module) |
| `GET /stations` | stations, your favourites first, then by your last visit, each with what you paid there per grade (`?q=` name, brand or postcode; `?favourites=true`; stations module) |
| `GET /stations/{station}` | one station and what you paid there; a merged station's id answers with the station it became (stations module) |
| `GET /fuel-prices/near` | *Cheapest near me*: listed prices near a point, ranked by effective cost for a vehicle ([Fuel prices](#fuel-prices); only while a price provider is enabled) |
| `GET /fuel-prices/alerts` | your price alerts: station, grade, the price per litre below which it tells you, and whether it is armed (only while a price provider is enabled) |
| `GET /vehicles/{id}/issues`, `GET /issues` | issues, newest noticed first (paged; `?status=`; [Issues](#issues); issues module) |
| `PATCH`, `DELETE /vehicles/{id}/{list}/{entry}` | edit or delete a fill-up, manual reading, service record, document, expense, trip, incident or issue ([Editing and deleting](#editing-and-deleting); read and write key) |
| `POST /vehicles/{id}/issues`, `…/issues/{issue}/updates`, `/fix`, `/reopen` | log an issue, add an update, mark it fixed, reopen it ([Issues](#issues); Log) |
| `PATCH`, `DELETE /reminders/{id}` | edit or delete a manual reminder (Manage) |
| `POST /vehicles`, `PATCH /vehicles/{id}` | add a vehicle (you own it) or edit one (Manage) ([More writes](#more-writes)) |
| `POST /vehicles/{id}/archive`, `/restore` | the *Archive* page and *Restore* (Own) |
| `POST /vehicles/{id}/valuations`, `PATCH`, `DELETE …/valuations/{valuation}` | valuations (Manage; also on an archived vehicle) |
| `POST /vehicles/{id}/schedules`, `PATCH`, `DELETE …/schedules/{schedule}` | maintenance schedules (Manage; maintenance module) |
| `POST /vehicles/{id}/tyres/changes`, `PATCH`, `DELETE …/tyres/changes/{change}`, `PATCH …/tyres/{tyre}` | tyre changes and a tyre's details (tyres module) |
| `POST /journeys`, `PATCH`, `DELETE /journeys/{id}` | your saved journeys (trips module) |
| `PUT`, `DELETE /stations/{id}/favourite` | star or unstar a station (stations module) |
| `POST /fuel-prices/alerts`, `PATCH`, `DELETE /fuel-prices/alerts/{id}` | your price alerts (a price provider enabled) |
| `POST /attention/{key}/hide` | *Hide* a *Needs attention* item for you (Log) |
| `POST`, `PATCH /vehicles/{id}/finance/agreements…` | agreements, payment events, settlement quotes and *End* ([More writes](#more-writes); finance module) |
| `GET /openapi.json` | the OpenAPI description (no key) |

A vehicle id the key's user cannot see answers `404`, like one that does
not exist.

## Lists: paging and dates

The `fuel`, `odometer`, `maintenance`, `documents`, `expenses`, `trips`,
`incidents`, `issues` and `valuations` lists are **newest first** and paged:

- `?limit=` 1–200, default 50.
- The response is `{"items": [...], "next": "<URL of the next page>"}`;
  `next` is `null` on the last page. Follow it as it is. An entry logged
  while you page never shifts a page.
- `?since=` / `?until=` take a date (`2026-09-01`: from the start / to the
  end of that day in UTC) or an instant (`2026-09-01T08:00:00Z`, or with an
  offset), both inclusive, on the entry's own date or time.

`/vehicles`, `/tyres`, `/upcoming`, `/reminders`, `/schedules`,
`/tyres/changes`, `/tyre-sets` and `/attention` are short and come whole.

The history feeds page the same way, but `since` and `until` there are
calendar days on your calendar (the entries are dated by day), and their
cursor is their own: follow `next` as it is.

## Reading one entry

Every list's entry can be read on its own at the list's address plus its
id (`GET /vehicles/1/fuel/42`), exactly as the list returns it. An id that
belongs to another vehicle answers `404`, and so does a trip someone else
logged that you may not see.

The history feeds and *Needs attention* link each item to the entry
or list that holds it (`links.entry`, `links.fix`) as a path under
`/api/v1`: add it to your base URL (`https://garage.example.com/api/v1`)
to fetch it. `next` is a full URL.

The response carries an `ETag`: a tag of the entry as it is stored. It
changes when the entry is edited, never because something around it
changed (a neighbouring fill-up altering this one's economy). It is there
for the edits that come next: send it back in `If-Match` and an edit that
would overwrite someone else's change is refused. `If-None-Match` is not
supported.

```sh
curl -si -H "Authorization: Bearer $LOGBOOK_KEY" \
  https://garage.example.com/api/v1/vehicles/1/fuel/42 | grep -i etag
```

## Reports

The reports take the Reports page's choices: `range` (`month`, `3m`,
`12m` the default, `ytd`, `all`, or `custom` with `from` and `to`),
`vehicle` (one; otherwise every active vehicle) and `include_archived=1`.
Their figures come from the same code as the page, so they always agree
with it. Money is per currency and never added across currencies.
Vehicles whose costs you may not see are not counted; their ids are in
`excluded`. A value that can't be read answers `400`, where the page's
form would fall back to the default.

```sh
# This year's spend, month by month.
curl -s -H "Authorization: Bearer $LOGBOOK_KEY" \
  "https://garage.example.com/api/v1/reports/costs?range=ytd&group_by=month"
```

## Logging fill-ups and readings

Both writes go through the same checks and side effects as the forms: the
same validation and messages, the odometer reading of a fill-up, service
intervals, reminders and the economy check.

`POST /vehicles/{id}/fuel`, body (JSON object):

| Field | |
|---|---|
| `filled_at` | instant with a zone (`2026-09-29T08:42:00+01:00`); default now. Kept to the minute. |
| `odometer` | required |
| `distance_unit` | `km` or `mi`; default the owner's |
| `fuel`, `grade` | codes; default the vehicle's usual fuel and the grade last bought. A grade alone is enough (`"grade": "e5_97"`). |
| `volume`, `price_per_unit`, `total_cost` | **any two**; the third is worked out as the form does. `price_per_unit` is per `volume_unit`. |
| `volume_unit` | `l`, `gal_uk`, `gal_us`; `kwh` for electricity, `kg` for CNG; default the owner's |
| `is_partial`, `is_missed_previous` | `true` / `false` |
| `station` | a station's name: linked to the station with that name (ignoring case and spacing), or a new one. Not with `station_id`. |
| `station_id` | a station from `GET /stations`. Not with `station`. Ignored for home charging, which is never a station. |
| `notes` | text |

`POST /vehicles/{id}/odometer`: `recorded_at` (default now), `odometer`
(required), `distance_unit`, `note`.

Numbers may be JSON numbers (`44.21`) or strings (`"44.21"`); either way
they are read as exact decimals, with `.` as the decimal point. Unknown
fields are refused, so a typo (`"price"`) is caught rather than ignored.

The answer is `201` with the entry as the lists show it, plus `warnings`
(`odometer_backwards`, `odometer_jump`, `economy_check`) that are worth a
look but never stop the entry.

**Retries are safe.** An entry with the same time and odometer as one
already logged (the CSV import's duplicate rule; for readings, whatever
wrote the existing one, a fill-up included) is not written again: the
answer is `200` with the existing entry and `"duplicate": true`. So an
automation that retries after a timeout never doubles a fill-up, **as long
as it sends `filled_at`** (a retry without it is a new "now").

An archived vehicle refuses writes (`409 vehicle_archived`).

## Logging other entries

From 2.7, five more writes follow the same rules: the form's validation
and messages, `201` with the entry as its list shows it (a tread check has
no list, so its answer is the check), `warnings`, safe retries,
`409` for an archived vehicle, and `404` while the entry's module is off.
Dates are `YYYY-MM-DD` and default to today in your time zone.

| Endpoint | Body | Needs | A retry matches |
|---|---|---|---|
| `POST /vehicles/{id}/maintenance` | `performed_on`, `odometer`, `distance_unit`, `category` (required: `service`, `oil`, `tyres`, …), `title` (required), `cost`, `vendor`, `description`, `schedule_id` | Log | same date, category, title and cost |
| `POST /vehicles/{id}/documents` | `type` (required: `insurance`, `inspection`, …), `title`, `provider`, `reference`, `start_on`, `expiry_on`, `cost`, `odometer` (needs `start_on`), `distance_unit`, `notes` | Log | same type, reference, start and expiry |
| `POST /vehicles/{id}/expenses` | `spent_on`, `category` (required: `parking`, `tolls`, …), `amount` (0 is fine), `note` | Log | same date, category, amount and note |
| `POST /vehicles/{id}/tyres/checks` | `checked_on`, `odometer`, `distance_unit`, `depth_unit` (`mm` or `in32`; default yours), `depths` (required: `{"fl": "6.5", "fr": "6.4"}`, fitted positions only), `note` | Log | same date and the same depth at every position |
| `POST /vehicles/{id}/reminders` | `title` (required); `due_on`, `due_odometer` (in `distance_unit`, default yours; from 2.8.0) or both, at least one, whichever comes first; `lead_time_days` (default your manual lead time), `notes` | Manage | an open manual reminder with the same title, due date and due odometer |

```sh
# A service record in miles; it writes its odometer reading, as the form does.
curl -H "Authorization: Bearer $KEY" -H "Content-Type: application/json" \
     -d '{"performed_on": "2026-09-20", "odometer": 30280, "distance_unit": "mi",
          "category": "service", "title": "Annual service", "cost": "187.43"}' \
     "$BASE/vehicles/1/maintenance"

# A tread check in 32nds of an inch.
curl -H "Authorization: Bearer $KEY" -H "Content-Type: application/json" \
     -d '{"odometer": 30280, "depth_unit": "in32", "depths": {"fl": 8, "fr": 7.5}}' \
     "$BASE/vehicles/1/tyres/checks"
```

## Trips

The trips module is off by default (*Settings → Modules*, or
`FEATURES_TRIPS=true`); with it off every trip path answers `404`. Trips
are personal: a list shows the key user's own trips, and other drivers'
only to someone who manages or owns the vehicle. The claim is always the
key user's own.

`POST /vehicles/{id}/trips` goes through the trip form's checks. Distances
are in **km** and are **the whole trip**: a return's `distance_km` is both
ways, never doubled.

| Field | |
|---|---|
| `travelled_on` | date (`2026-09-29`); default today in your time zone |
| `from`, `to` | places (required unless `journey_id` gives them) |
| `is_return` | `true` / `false`; default `false` |
| `distance_km` | the whole trip, or leave it out and send both odometers |
| `odometer_start_km`, `odometer_end_km` | both or neither; the distance is end − start (a `distance_km` more than 0.5 away from it is refused) |
| `is_business` | default `true`; a business trip needs a `purpose` |
| `purpose`, `notes` | text |
| `passengers` | business passengers, 0–8 |
| `journey_id` | one of your saved journeys (`GET /journeys` lists them with their ids) |

A saved journey fills `from`, `to`, `is_return`, `is_business`, `purpose`
and the distance: the journey's one-way distance, doubled for a return,
unless you send `distance_km` or odometers. Anything you send wins over the
journey. A `journey_id` that is not yours is a `422` on `journey_id`.

The answer is `201` with the trip (as the list shows it) and `warnings`
(`trip_longer_than_driven`: the odometer readings either side of that day
allow less than this distance; it never blocks). **Retries are safe:** a
trip of yours on the vehicle with the same date, places and distance (the
CSV import's rule) answers `200` with it and `"duplicate": true`; nothing is
written. Send `travelled_on`, so a retry just after midnight is recognised.
Archived vehicles refuse trips (`409`).

`GET /journeys` lists your saved journeys in your *Settings → Trips*
order: `id`, `from`, `to`, `journey` (the label), `distance_km` (**one
way**), and the `is_return`, `is_business` and `purpose` a trip logged from
it starts with. A Shortcut can offer them with *Choose from List* and send
the chosen `id` as `journey_id`.

`GET /trips/claim` is the claim report's figures: your business trips on
every vehicle you can see, at your mileage rates (UK users get HMRC's
automatically). `?year=2026` is the tax year starting in 2026 (default the
current one); `?period=custom&from=2026-01-01&to=2026-12-31` a date range;
`vehicles[]=1&vehicles[]=2` narrows it. Each trip has its `distance` in the
rate set's `unit`, its `lines` (two for the trip that crosses a threshold),
`passenger_amount` and `amount`; `totals` has one entry per currency with
the distance at each rate, `mileage_amount`, `passenger_amount`,
`approved_amount` and, with employer rates, `employer_amount` and
`difference` (approved mileage minus what the employer paid). `unvalued`
counts trips with no rate set in effect on their date.

```sh
# A 45.2 km return trip (90.4 km in all), and this tax year's claim.
curl -H "Authorization: Bearer $KEY" -H "Content-Type: application/json" \
     -d '{"travelled_on": "2026-09-29", "from": "Ballymena", "to": "Belfast", "is_return": true,
          "distance_km": 90.4, "purpose": "Client visit"}' \
     "$BASE/vehicles/1/trips"
curl -H "Authorization: Bearer $KEY" "$BASE/trips/claim"

# The same from saved journey 3.
curl -H "Authorization: Bearer $KEY" -H "Content-Type: application/json" \
     -d '{"journey_id": 3, "travelled_on": "2026-09-29"}' "$BASE/vehicles/1/trips"
```

"Ballymena → Belfast" as an iPhone Shortcut, from saved journey `3`, with a
**read and write** key:

1. **Current Date**, then **Format Date**: *Custom*, `yyyy-MM-dd`.
2. **Get Contents of URL**: `https://garage.example.com/api/v1/vehicles/1/trips`
   - Method **POST**
   - Headers: `Authorization` = `Bearer lbk_…`
   - Request Body **JSON**: `journey_id` (Number) = `3`,
     `travelled_on` (Text) = *Formatted Date*.
3. **Get Dictionary Value** `duplicate`; **If** it is *1*, **Show Result**
   "Already logged today". Otherwise **Get Dictionary Value**
   `entry.journey` and show "Logged " with it.

Add it to the Home Screen or ask Siri by the shortcut's name. For a one-way
trip on a return journey add `is_return` (Boolean) = *false*.

## Fuel prices

From 2.14, with a fuel price provider enabled on Settings → Fuel prices
([stations.md](stations.md#fuel-prices)). While none is enabled, or the
stations module is off, `GET /fuel-prices/near` answers `404` and stations
have `"listed": null`.

`GET /fuel-prices/near` is *Cheapest near me* for the key's user: the
stations with a listed price for the grade, ranked by **effective cost**
(the vehicle's usual fill plus the fuel to drive there and back), each
with its sum against the nearest station. It is answered from the copy of
the feed on your server; nothing is sent to the provider.

| Parameter | |
|---|---|
| `lat`, `lng` | a position in degrees (both) |
| `place` | one of your places, by name (`Home`; capitals and spacing ignored) |
| `station` | a station id (`GET /stations`) that has a position |
| `vehicle` | a vehicle id; default the petrol or diesel vehicle filled most recently. Electric vehicles are not offered. |
| `grade` | a grade code the provider lists for the vehicle's fuel (`e10_95`, `e5_97`, `b7`, …); default the vehicle's usual grade |
| `radius` | above 0 and up to 50, **in the key user's distance unit**; default `5` |
| `sort` | `effective` (default), `price` or `distance` |
| `include_older` | `true` also counts prices reported more than 48 hours ago |

Give **exactly one** origin: `lat` and `lng`, `place` or `station`. None,
or more than one, is a `400 invalid_parameter`, as is a bad value for any
parameter. A position in the request is used for this answer only: it is
never stored, logged or echoed back. An unknown vehicle, or none with
petrol or diesel, is a `404`.

```sh
curl -H "Authorization: Bearer $KEY" "$BASE/fuel-prices/near?place=Home&radius=5"
```

The answer, here with two stations, for a user who uses miles:

```json
{
  "provider": {
    "code": "uk_fuel_finder",
    "name": "UK Fuel Finder",
    "attribution": "Contains public sector information licensed under the Open Government Licence v3.0.",
    "licence_url": "https://www.nationalarchives.gov.uk/doc/open-government-licence/version/3/"
  },
  "currency": "GBP",
  "synced_at": "2026-10-03T13:00:12Z",
  "origin": {"kind": "place", "label": "Home"},
  "vehicle_id": 1,
  "grade": "e10_95",
  "radius_km": 8.047,
  "sort": "effective",
  "include_older": false,
  "usual_fill": {"litres": "45.210", "assumed": false, "display": "45.2 L"},
  "economy": {"distance_km": "6436.000", "litres": "378.770", "all_time": false, "display": "48.0 mpg"},
  "road_factor": 1.3,
  "total": 2,
  "items": [
    {
      "station_id": 12,
      "provider_ref": "a1b2c3d4",
      "name": "Tesco Antrim",
      "brand": "Tesco",
      "address": "Junction One, Antrim",
      "postcode": "BT41 4LL",
      "latitude": "54.705100",
      "longitude": "-6.240100",
      "distance_km": 3.4,
      "listed": {
        "grade": "e10_95",
        "price": "1.369",
        "currency": "GBP",
        "reported_at": "2026-10-03T11:20:00Z",
        "fresh": true,
        "display": "£1.369/L"
      },
      "usual_fill_litres": "45.210",
      "detour_km": 8.84,
      "detour_litres": "0.520",
      "effective_cost": "62.60",
      "nearest": false,
      "worth_it": {
        "fuel_saving": "0.90",
        "extra_km": 4.4,
        "extra_road_km": 5.72,
        "fuel_for_that": "0.46",
        "actual_saving": "0.45",
        "worth_the_trip": true
      },
      "display": {"distance": "2.1 mi", "effective_cost": "£62.60 for your usual 45.2 L", "saves": "saves £0.45"}
    },
    {
      "station_id": null,
      "provider_ref": "e5f6a7b8",
      "name": "Sample Service Station",
      "brand": null,
      "address": "Main Street, Antrim",
      "postcode": "BT41 1AB",
      "latitude": "54.716900",
      "longitude": "-6.220300",
      "distance_km": 1.2,
      "listed": {
        "grade": "e10_95",
        "price": "1.389",
        "currency": "GBP",
        "reported_at": "2026-10-03T09:05:00Z",
        "fresh": true,
        "display": "£1.389/L"
      },
      "usual_fill_litres": "45.210",
      "detour_km": 3.12,
      "detour_litres": "0.184",
      "effective_cost": "63.05",
      "nearest": true,
      "worth_it": null,
      "display": {"distance": "0.7 mi", "effective_cost": "£63.05 for your usual 45.2 L", "saves": "Nearest"}
    }
  ]
}
```

- Distances are kilometres, **straight-line**; `detour_km` and
  `extra_road_km` are by road, × `road_factor`. Prices are per litre in
  `currency`, to three places; money is to two.
- `origin.kind` is `here` (a position), `place` or `station`; `label` is
  the place's or station's name, `null` for a position.
- `usual_fill.assumed` is `true` when the vehicle has no full fills and
  40 L is used. `economy` is `null` when the vehicle has none: then the
  drive is not counted and `detour_litres` is `null`.
- `station_id` is the linked Logbook station, or `null`.
- The nearest station selling the grade has `"nearest": true` and
  `"worth_it": null`; every other row's `worth_it` is its sum against
  that one, and `actual_saving` is negative when the trip costs more than
  it saves.
- `total` is how many stations matched; `items` holds up to 50.
- Show the `provider.attribution` wherever you show the data.

**Station responses** (`GET /stations`, `GET /stations/{station}`) carry
`listed`: for a linked station, its listed prices now, one per grade, as
`listed` above (`grade`, `price`, `currency`, `reported_at`, `fresh`,
`display`). `fresh` is `false` for a price reported more than 48 hours
ago. A linked station with no prices yet has `[]`. It is `null` for a
station that isn't linked (or whose feed record isn't synced yet), and
while no provider is enabled.

## Editing and deleting

From 3.5, `PATCH` edits an entry and `DELETE` deletes it, at the address
it is read from: `/vehicles/{id}/{list}/{entry}` for `fuel`, `odometer`,
`maintenance`, `documents`, `expenses`, `trips`, `incidents` and (from 3.6) `issues`, and
`/reminders/{id}` for a manual reminder. They go through the page's edit
form and delete confirmation, so the validation, the messages and the
knock-on effects are the pages': a fill-up's odometer reading moves with
it and the economy around it is recomputed, a schedule falls back to the
record before, and an entry's attachments go with it.

- **Only what you send changes.** Every other field keeps its stored
  value exactly. `null` clears an optional field (and on a required one
  is the form's *required* error). A field the create doesn't know is
  refused here too. Units work as on create: `distance_unit` and
  `volume_unit` say what the numbers you send are in.
- **Nothing is worked out that the form wouldn't.** Sending only
  `total_cost` keeps the stored volume and price, as the edit form does.
- **Who may:** Manage on the vehicle, or Log on an entry you added
  yourself (`403` otherwise); Manage for a reminder.
- **Not here:** an archived vehicle (`409 vehicle_archived`); a reading
  written by another entry (`409 reading_derived`, with `links.entry`
  pointing at the entry to change instead); a reminder that follows a
  schedule or document (`409 reminder_not_manual`); `journey_id` on a
  trip (a saved journey fills a new trip only).
- `PATCH` answers `200` with `entry` (as its single read returns it),
  `warnings`, and the new `ETag`. `DELETE` answers `204`.

**Two people editing the same entry.** `If-Match` is optional. Send the
`ETag` you read and the edit (or delete) only goes through if nobody has
changed the entry since; otherwise the answer is `412
precondition_failed` and nothing is written. Without it, the last edit
wins, as on the pages. A reminder has no single read: its `PATCH`
answer carries the `ETag` to send next time. The same goes for every
other `PATCH` and `DELETE` in [More writes](#more-writes) (vehicles,
valuations, schedules, tyre changes and tyres, journeys, alerts,
agreements, payment events and quotes); favourites and *Hide* are
switches and ignore it.

```sh
# Correct a mistyped odometer on fill-up 42, only if it is unchanged since read.
TAG=$(curl -si -H "Authorization: Bearer $KEY" "$BASE/vehicles/1/fuel/42" \
      | awk -F': ' 'tolower($1)=="etag" {print $2}' | tr -d '\r')
curl -X PATCH -H "Authorization: Bearer $KEY" -H "Content-Type: application/json" \
     -H "If-Match: $TAG" -d '{"odometer": 41230, "distance_unit": "km"}' \
     "$BASE/vehicles/1/fuel/42"

# Delete an expense.
curl -X DELETE -H "Authorization: Bearer $KEY" "$BASE/vehicles/1/expenses/7"
```

## Issues

From 3.6 (issues module). An issue is a fault you've noticed and not
fixed yet: the date, the mileage, your words, and a status (`open`,
`watching`, `fixed`). Logbook records what you noted and what fixed it;
it never suggests a cause.

| Endpoint | Needs | Notes |
|---|---|---|
| `GET /vehicles/{id}/issues`, `GET /issues` | View | newest noticed first, paged; `?status=open\|watching\|fixed` (every status without it); `/issues` takes every vehicle you can see that isn't archived, or `?vehicle=`. Each issue has its `updates` (oldest first) and `fixed_by` (service record ids); sort on `affects_safety` to put safety issues first, as the pages do |
| `GET /vehicles/{id}/issues/{issue}` | View | one issue, with an `ETag` that changes when an update, a fix or a status change is written |
| `POST /vehicles/{id}/issues` | Log | `title` (required, up to 120), `noticed_on` (default today), `odometer`, `distance_unit`, `description`, `category` (the maintenance categories), `status` (`open` or `watching`), `look_again_on` and `look_again_odometer` (watching only), `affects_safety` (`true`/`false`). A retry with the same date and title answers `duplicate: true` |
| `PATCH`, `DELETE …/issues/{issue}` | Log (own) or Manage | as [Editing and deleting](#editing-and-deleting); `status` is `open` or `watching`, and not on a fixed issue |
| `POST …/issues/{issue}/updates` | Log | `note`, `noted_on` (default today), `odometer`, and optionally `status` (`open` or `watching`, with a look-again point); `201` |
| `POST …/issues/{issue}/fix` | Log | `{"records": [12, 15]}`: the vehicle's service records dated on or after it was noticed; or `{"fixed_on": "2026-09-29", "note": "Went away"}`: fixed without a record. On a fixed issue nothing changes and the answer says `"unchanged": true` |
| `POST …/issues/{issue}/reopen` | Log | *It's back* on a fixed issue (the earlier fix is kept as history), or back to `open` from `watching`; `"unchanged": true` on an open one |

The writes answer with the issue in `entry` and its new `ETag`, and honour
`If-Match`. An issue's odometer adds a reading to the mileage log, except
where the vehicle already has one that day at the same odometer. Fixing
from the service record's side is the *Fixes* checklist on the page; over
the API, use `/fix`.

```sh
# Log a noise, then link the service record that fixed it.
curl -H "Authorization: Bearer $KEY" -H "Content-Type: application/json" \
     -d '{"title": "Knock from front left under braking", "odometer": 25480, "distance_unit": "mi",
          "affects_safety": true}' \
     "$BASE/vehicles/1/issues"
curl -H "Authorization: Bearer $KEY" -H "Content-Type: application/json" \
     -d '{"records": [57]}' "$BASE/vehicles/1/issues/9/fix"
```

## More writes

From 3.5, the rest of what the pages write. Each takes the page form's
fields (as JSON, numbers as numbers or decimal strings), validates as it
does, and answers with the object as its read returns it, in `entry`.
`PATCH` works as in [Editing and deleting](#editing-and-deleting). The
OpenAPI description has every field.

| Endpoint | Needs | Notes |
|---|---|---|
| `POST /vehicles` | a read and write key | the add form: `type`, `make`, `model`, `fuel_type` required; `current_odometer` for a starting reading; *First MOT due* suggested as the form does unless you send it (`null`: none). You own it. A retry within 10 minutes (same registration, make and model) answers `200` with `duplicate: true` |
| `PATCH /vehicles/{id}` | Manage | the edit form; a `sale_date` marks the vehicle sold (`disposal: "sold"`), clearing it clears that |
| `POST /vehicles/{id}/archive` | Own | the *Archive* page: no body just archives; `disposal` (`sold`, `written_off`, `returned_lender`, `returned_lessor`) where the page offers it, with `sale_date`, `sale_price`, `incident_id` (written off), `settle_from_sale` and `settlement` (an active HP or PCP) |
| `POST /vehicles/{id}/restore` | Own | *Restore* |
| `POST /vehicles/{id}/valuations` | Manage | `amount` (required), `valued_on` (default today), `source`, `notes`; the one write an archived vehicle takes (a scrap value), within its sale date. A retry with the same date, amount and source answers `duplicate: true` |
| `POST /vehicles/{id}/schedules` | Manage | `category`, `title`, `interval_km` or `interval_distance` (in `distance_unit`), `interval_months`, `baseline_done_on`, `baseline_odometer`; a retry with the same category, title and intervals answers `duplicate: true`. Deleting keeps its records |
| `POST /vehicles/{id}/tyres/changes` | Log | `kind` (`existing`, `fit`, `swap`, `rotate`, `repair`, `remove`) and its lines, replayed as the change form ([Tyre changes](#tyre-changes)) |
| `PATCH …/tyres/changes/{change}` | Log (own) or Manage | `changed_on`, `odometer`, `note`, `service_record_id` only; a delete the page would refuse is `409 tyre_change_refused` |
| `PATCH /vehicles/{id}/tyres/{tyre}` | Manage | `brand`, `model`, `size`, `season`, `dot`, `notes` |
| `POST /journeys` | your own | `from`, `to`, `distance_km` (one way), `is_return`, `is_business` (default true), `purpose` |
| `PUT`, `DELETE /stations/{id}/favourite` | your own | `204`, safe to repeat; unstarring removes the station's alerts |
| `POST /fuel-prices/alerts` | your own | `station_id` (a favourite), `grade`, `below` per `volume_unit`; at most 20; an alert for that station and grade is changed (`200`, `duplicate: true`). `PATCH` changes `below` |
| `POST /attention/{key}/hide` | Log | the `key` from `GET /attention`; `204`, safe to repeat; `404` once the item has changed. Nothing un-hides, as on the page |
| `POST /vehicles/{id}/finance/agreements` | Manage and cost access | the agreement form; one active agreement (`409 finance_active_exists`); the agreement number is stored, never returned |
| `POST …/agreements/{agreement}/payments` | as above | `kind`: `missed` or `paid_late` with `due_on` (and `paid_on`), or `extra` with `amount` and `paid_on`; `DELETE …/payments/{event}` |
| `POST …/agreements/{agreement}/quotes` | as above | `quoted_on`, `amount`, `valid_until`, `notes`; `DELETE …/quotes/{quote}` |
| `POST …/agreements/{agreement}/end` | as above | `outcome`, `ended_on`, `settlement`, `excess_charge`, `damage_charge`; the vehicle stays. Selling or handing it back goes through `…/archive` |

```sh
# An importer adding a vehicle from another app, and its history after it.
curl -H "Authorization: Bearer $KEY" -H "Content-Type: application/json" \
     -d '{"type": "car", "make": "Skoda", "model": "Octavia", "fuel_type": "diesel",
          "registration": "OC21 TAV", "first_registered_on": "2021-06-01",
          "purchase_date": "2023-02-01", "purchase_price": 15995,
          "purchase_odometer": 30000, "distance_unit": "mi"}' \
     "$BASE/vehicles"
```

### Tyre changes

A change is replayed through the change form, so whatever the form
refuses (a tyre that isn't on, a date before it was fitted) is refused
with its message. The lines, by kind:

| `kind` | Lines |
|---|---|
| `existing` | `tyres`: `[{"position": "fl", "brand": …, "model": …, "size": …, "season": …, "dot": "2325", "tread": 8}]` |
| `fit` | `tyre`: `{"brand", "model", "size", "season"}`, `tread`, `positions`: `[{"position": "fl", "dot": "2325", "replace": "store"}]` (`replace`, for a tyre on there now: `store` or a retire reason) |
| `swap` | `set` (a set id, or `{"name", "storage"}` for a new one), `on`: `{"<stored tyre id>": "fl"}`, `depths`: `{"<tyre id>": 5.5}` |
| `rotate` | `moves`: every fitted tyre `{"<tyre id>": "fr"}` |
| `repair` | `tyres`: `[<tyre id>]` |
| `remove` | `set`, `tyres`: `{"<tyre id>": "store"}` (or a retire reason), `depths` |

An error points at the body's path: `positions.0.dot`, `moves.12`.

## Attachments

From 3.5, receipts, invoices and photos go in and out over the API, with
the pages' checks: a PDF, or a JPEG, PNG or WebP image (detected from the
content), up to `MAX_UPLOAD_MB`; images are turned upright and stripped
of their metadata, except an incident's photos, which are kept as taken.

| Endpoint | Needs | Notes |
|---|---|---|
| `GET /vehicles/{id}/{list}/{entry}/attachments` | as reading the entry | `{list}` is `fuel`, `odometer`, `maintenance`, `documents`, `expenses`, `valuations`, `trips`, `incidents` or `issues`; `items` with `id`, `filename`, `content_type`, `size`, `uploaded_at`, `uploaded_by` and a `download` link |
| `POST …/{entry}/attachments` | Log (your own entry) or Manage; Manage for valuations | `multipart/form-data`, **one file** in the field `file`; `201` with the attachment. A reading another entry wrote takes none (`409 reading_derived`) |
| `GET`, `POST /vehicles/{id}/purchase/attachments` and `…/sale/attachments` | Can see costs; Manage to add | the purchase or sale paperwork; needs the purchase or sale date (`422`) |
| `GET /attachments/{id}` | View | the file, as the pages serve it: an incident photo is the original only for its author or with *Can see incident details*, otherwise an upright copy without its metadata. `?download=1` downloads an image instead of opening it |
| `DELETE /attachments/{id}` | Log (your own upload) or Manage | `204` |
| `GET`, `POST`, `DELETE /vehicles/{id}/photo` | View; Manage to change | the vehicle's photo (not an attachment): `POST` a JPEG, PNG or WebP in the field `file`; `204` |

An archived vehicle's files don't change (`409 vehicle_archived`), except
a valuation's. A trip's files are only for those who may see the trip; an
expense's, a valuation's and the purchase and sale paperwork only with
*Can see costs* (or your own upload).

```sh
# Attach a receipt to fill-up 42, list its files and download the first.
curl -H "Authorization: Bearer $KEY" -F "file=@receipt.pdf" "$BASE/vehicles/1/fuel/42/attachments"
curl -H "Authorization: Bearer $KEY" "$BASE/vehicles/1/fuel/42/attachments"
curl -H "Authorization: Bearer $KEY" -o receipt.pdf "$BASE/attachments/7"
```

## Webhooks

From 3.5, Logbook can tell another system when something changes, so a
dashboard or a Node-RED flow refreshes without polling. Add one on
**Settings → API keys → Webhooks**: a name, an address and the events
(`entry.created`, `entry.updated`, `entry.deleted`, `reminder.changed`).
Its signing secret is shown once; *New secret* makes another. You can
have up to 10.

Each call is a `POST` of JSON with **ids and links only**, never the
entry or any amount: fetch what changed with your own key, so access is
checked when you read it.

```json
{
  "event": "entry.created",
  "id": "5f0c1d2e3a4b5c6d7e8f9a0b1c2d3e4f",
  "occurred_at": "2026-10-08T09:00:00Z",
  "vehicle_id": 1,
  "kind": "fuel",
  "entry_id": 42,
  "links": {"entry": "/vehicles/1/fuel/42", "list": "/vehicles/1/fuel", "vehicle": "/vehicles/1"}
}
```

`kind` is the history's kind where there is one (`fuel`, `odometer`,
`maintenance`, `document`, `expense`, `tyre` for a tyre change,
`valuation`, `trip`, `incident`, and `issue`, whose updates, fixes,
unlinks and reopens are `entry.updated` of the issue), else `tread_check`, `tyre_details`
(`entry_id` is the tyre), `schedule`, `finance` (the agreement; a
payment, quote or *End* updates it) and `vehicle`. `reminder.changed`
has `kind: "reminder"` and `change`: `due`, `overdue`, `done`,
`dismissed`, `reopened`, `created`, `updated` or `deleted`. Links are
relative to `/api/v1`; a deleted entry has no `entry` link. *Send test*
posts `{"event": "webhook.test", …}`.

Everyone who may see the vehicle can have webhooks for it; a trip only
reaches those who may see it. Addresses follow the same rules as your
notification channels (your administrator decides which networks members
may reach). Calls are sent by the scheduler, so they arrive with the next
pass (every 15 minutes by default). A call that doesn't get a `2xx`
within 10 seconds is tried again after at least 1 minute, 5 minutes, 30
minutes, 2 hours and 6 hours; after 50 failed tries in a row the webhook
pauses and you are told through your notification channels. *Resume*
sends what waited (up to 7 days).

### Checking the signature

Every call carries `X-Logbook-Signature: t=<unix time>,v1=<hex>`, where
`<hex>` is the HMAC-SHA256 of `t` + `.` + the raw body, keyed with the
secret. Check it against the raw body and reject old timestamps:

```python
import hashlib, hmac, time

def verify(secret: str, body: bytes, header: str, tolerance: int = 300) -> bool:
    parts = dict(p.split("=", 1) for p in header.split(","))
    t, sent = int(parts["t"]), parts["v1"]
    if abs(time.time() - t) > tolerance:
        return False
    expected = hmac.new(secret.encode(), f"{t}.".encode() + body, hashlib.sha256).hexdigest()
    return hmac.compare_digest(expected, sent)
```

```js
const crypto = require('crypto');

function verify(secret, rawBody, header, tolerance = 300) {
    const parts = Object.fromEntries(header.split(',').map((p) => p.split('=')));
    const t = Number(parts.t);
    if (Math.abs(Date.now() / 1000 - t) > tolerance) return false;
    const expected = crypto.createHmac('sha256', secret).update(`${t}.${rawBody}`).digest('hex');
    return expected.length === parts.v1.length
        && crypto.timingSafeEqual(Buffer.from(expected), Buffer.from(parts.v1));
}
```

## Errors

Errors are [RFC 9457 problem details](https://www.rfc-editor.org/rfc/rfc9457)
(`application/problem+json`):

```json
{
  "type": "about:blank",
  "title": "Unprocessable Content",
  "status": 422,
  "code": "validation_failed",
  "detail": "Some fields are missing or invalid; see \"errors\".",
  "errors": {
    "volume": {"key": "fuel.need_two", "message": "Enter at least two of volume, price and total."}
  }
}
```

Branch on `code`; `detail` and `message` are English text for people.

| Status | `code` | |
|---|---|---|
| 400 | `invalid_parameter`, `invalid_body` | a query parameter or the body cannot be read |
| 401 | `missing_key`, `invalid_key` | no key, or a malformed, unknown or revoked one |
| 403 | `insufficient_scope` | a read-only key on a write |
| 403 | `forbidden` | the user may see the vehicle but not do this with it |
| 403 | `cors_not_allowed` | a browser preflight from an origin that is not allowed |
| 404 | `not_found` | no such address or vehicle, a switched-off module, or the API is off |
| 405 | `method_not_allowed` | see `Allow` |
| 409 | `vehicle_archived` | writes to an archived vehicle (but a valuation, and *Restore*) |
| 409 | `reading_derived` | a reading another entry wrote; `links.entry` is that entry |
| 409 | `reminder_not_manual` | a reminder that follows its schedule or document |
| 409 | `tyre_change_refused` | deleting a tyre change later ones depend on |
| 409 | `finance_active_exists`, `finance_ended` | a second active agreement; a change to one that has ended |
| 412 | `precondition_failed` | `If-Match` no longer matches; nothing was written |
| 422 | `validation_failed` | per field: the form's message key and text |
| 429 | `too_many_failures` | too many failed keys from this address; see `Retry-After` |
| 500 | `internal_error` | logged on the server |

## Browser dashboards (CORS)

Servers (Home Assistant, Grafana, Node-RED) call the API directly and need
nothing here. A web page on another origin that calls it from the browser
needs its origin listed: `API_CORS_ORIGINS=https://dash.example.com`
(comma-separated). Anything the page sends carries the key, so only list
pages you trust. The preflight allows `GET`, `POST`, `PUT`, `PATCH`,
`DELETE` and the `If-Match` header, and responses expose `ETag`.

---

## curl

```sh
KEY=lbk_…
BASE=https://garage.example.com/api/v1

curl -H "Authorization: Bearer $KEY" "$BASE/vehicles"
curl -H "Authorization: Bearer $KEY" "$BASE/vehicles/1/summary"

# Log a fill-up in UK gallons and miles; run it twice: the second answers "duplicate": true.
curl -H "Authorization: Bearer $KEY" -H "Content-Type: application/json" \
     -d '{"filled_at": "2026-09-29T08:42:00+01:00", "odometer": 30280, "distance_unit": "mi",
          "volume": 8.5, "volume_unit": "gal_uk", "total_cost": 61.20}' \
     "$BASE/vehicles/1/fuel"

# An OBD tool posting the odometer.
curl -H "Authorization: Bearer $KEY" -H "Content-Type: application/json" \
     -d "{\"recorded_at\": \"$(date -u +%Y-%m-%dT%H:%M:%SZ)\", \"odometer\": 78421.4, \"distance_unit\": \"km\", \"note\": \"OBD\"}" \
     "$BASE/vehicles/1/odometer"
```

## Home Assistant

A [RESTful sensor](https://www.home-assistant.io/integrations/rest/) on the
summary, with a **read only** key. In `secrets.yaml`:

```yaml
logbook_auth: "Bearer lbk_…"
```

In `configuration.yaml` (vehicle `1`; `GET /vehicles` lists the ids):

```yaml
rest:
  - resource: https://garage.example.com/api/v1/vehicles/1/summary
    headers:
      Authorization: !secret logbook_auth
    scan_interval: 900
    sensor:
      - name: "Golf odometer"
        value_template: "{{ value_json.odometer.value | float(0) | round(0) }}"
        unit_of_measurement: km
        device_class: distance
        state_class: total_increasing
      - name: "Golf economy"
        value_template: "{{ value_json.fuel.liquid.average_consumption }}"
        unit_of_measurement: "L/100km"
      - name: "Golf last fill-up"
        value_template: "{{ value_json.fuel.last_fill_up.filled_at }}"
        device_class: timestamp
      - name: "Golf next due"
        value_template: "{{ value_json.display.next_due }}"
      - name: "Golf reminders due"
        value_template: "{{ value_json.reminders.overdue + value_json.reminders.due }}"
```

With `device_class: distance` and `km`, Home Assistant shows the odometer in
miles when your system is set to US customary units. For text exactly as
Logbook shows it, use `value_json.display.odometer` or
`value_json.display.economy` ("44.4 mpg"). For an electric car use
`fuel.electric` in place of `fuel.liquid` (kWh/100 km), and for CNG `fuel.gas`
(kg/100 km).

### Mark a reminder done from a notification

With a **read and write** key, a
[RESTful command](https://www.home-assistant.io/integrations/rest_command/)
marks a reminder done, so an actionable notification can do it from the
phone. `GET /reminders` gives the ids.

```yaml
rest_command:
  logbook_reminder_done:
    url: "https://garage.example.com/api/v1/reminders/{{ reminder_id }}/done"
    method: post
    headers:
      Authorization: !secret logbook_auth
```

Call it from the notification's action with `reminder_id`. Pressing it
twice is harmless: the second answers `"unchanged": true` and changes
nothing. `/dismiss` and `/reopen` work the same way.

## Apple Shortcuts

"Log fill-up" with *Ask for Input*, using a **read and write** key:

1. **Ask for Input**: Number, prompt "Odometer (miles)".
2. **Ask for Input**: Number, prompt "Litres".
3. **Ask for Input**: Number, prompt "Total cost".
4. **Current Date**, then **Format Date**: Date Format *ISO 8601*, *Include
   ISO 8601 Time* on. (This is the fill-up's time; the shortcut takes it
   once, so re-running step 5 can never double the entry.)
5. **Get Contents of URL**: `https://garage.example.com/api/v1/vehicles/1/fuel`
   - Method **POST**
   - Headers: `Authorization` = `Bearer lbk_…`
   - Request Body **JSON**:
     `filled_at` (Text) = *Formatted Date*,
     `odometer` (Number) = *Provided Input* of step 1,
     `distance_unit` (Text) = `mi`,
     `volume` (Number) = step 2,
     `volume_unit` (Text) = `l` (or `gal_uk`),
     `total_cost` (Number) = step 3.
6. **Get Dictionary Value** `duplicate`, then **If** it is *1*: **Show
   Result** "Already logged". Otherwise **Get Dictionary Value**
   `entry.economy.segment.consumption` and show it, and any `warnings`.

"Log a saved journey": **Get Contents of URL** `…/api/v1/journeys` (GET,
the same header), **Get Dictionary Value** `items`, **Choose from List**
showing each item's `journey`, then POST `{"journey_id": <its id>}` to
`…/api/v1/vehicles/1/trips`. Today's date and the journey's distance,
return and purpose are filled in.

"Log a valuation" (a figure from a dealer or a price guide; Logbook never
fetches one): **Ask for Input** Number "Value", then **Get Contents of
URL** `…/api/v1/vehicles/1/valuations`, Method **POST**, the same header,
Request Body **JSON** `amount` (Number) = *Provided Input*, `source`
(Text) = `Dealer quote`. The date is today; run it twice and the second
answers `duplicate: true`.

Android automations (Tasker's *HTTP Request*, Home Assistant's companion
app) are the same request: POST, the header, and the JSON body.

## Grafana

With the [Infinity data source](https://grafana.com/grafana/plugins/yesoreyeram-infinity-datasource/):

1. Data source: *Authentication* → **Bearer token** with a read only key;
   *Allowed hosts*: `https://garage.example.com`.
2. Panel query: Type **JSON**, Parser **Backend**, Source **URL**,
   `https://garage.example.com/api/v1/vehicles/1/fuel?limit=200&since=${__from:date:YYYY-MM-DD}`.
3. *Rows / Root*: `items`. Columns:
   - `filled_at`, *Timestamp* (format: ISO 8601)
   - `economy.segment.consumption`, *Number*, as "L/100 km"
   - `total_cost`, *Number*, as "Cost"
4. Filter out rows where the consumption is empty (fill-ups that close no
   full tank), and draw a time series.

More than 200 fill-ups in the range: the response's `next` URL has the
rest.

## Node-RED

An *http request* node with the key in `msg.headers`. Logging the odometer
from, for example, an OBD integration:

```js
// function node, before the http request node
msg.method = 'POST';
msg.url = 'https://garage.example.com/api/v1/vehicles/1/odometer';
msg.headers = {
    Authorization: 'Bearer ' + env.get('LOGBOOK_KEY'),
    'Content-Type': 'application/json',
};
msg.payload = {
    recorded_at: new Date().toISOString(),
    odometer: msg.payload.odometer_km,
    distance_unit: 'km',
    note: 'OBD',
};
return msg;
```

Set the http request node's *Method* to "- set by msg.method -" and
*Return* to "a parsed JSON object"; `msg.payload.duplicate` then says
whether the reading was already there, and `msg.statusCode` is `201` or
`200`. Reading the summary is a GET to `/vehicles/1/summary` with the same
header.

### Receiving webhooks in Node-RED

An *http in* node (`POST /logbook`), a function node that checks the
signature, then an *http request* node that fetches the entry, and an
*http response* node answering `200`. Turn on the http in node's raw body
(or set `httpNodeMiddleware` to keep it) so the signature is checked
against the exact bytes:

```js
// function node after "http in"; LOGBOOK_SECRET and LOGBOOK_KEY in the flow's environment
const crypto = global.get('crypto');   // functionGlobalContext: { crypto: require('crypto') }
const header = msg.req.headers['x-logbook-signature'] || '';
const parts = Object.fromEntries(header.split(',').map((p) => p.split('=')));
const raw = msg.req.rawBody || JSON.stringify(msg.payload);
const expected = crypto.createHmac('sha256', env.get('LOGBOOK_SECRET')).update(`${parts.t}.${raw}`).digest('hex');
if (expected !== parts.v1 || Math.abs(Date.now() / 1000 - Number(parts.t)) > 300) {
    msg.statusCode = 401;
    return [null, msg];                 // second output: straight to "http response"
}
const link = msg.payload.links.entry || msg.payload.links.list;
msg.statusCode = 200;
return [{
    method: 'GET',
    url: 'https://garage.example.com/api/v1' + link,
    headers: {Authorization: 'Bearer ' + env.get('LOGBOOK_KEY')},
}, msg];
```
