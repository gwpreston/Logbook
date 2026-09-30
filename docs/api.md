# REST API

A small JSON API for automations: Home Assistant sensors, Apple Shortcuts
and Android automations that log a fill-up, Grafana panels, Node-RED flows
and OBD tools that post the odometer. It reads what a dashboard needs and
writes two things: **fill-ups** and **odometer readings**.

The contract is the OpenAPI 3.1 description, served by your install at
`<your URL>/api/v1/openapi.json` (the same file as
[`docs/api/openapi.json`](api/openapi.json)); the test suite checks every
response against it. `spec.md` §7.20 has the rules.

- [Keys](#keys)
- [Requests and values](#requests-and-values)
- [Endpoints](#endpoints)
- [Lists: paging and dates](#lists-paging-and-dates)
- [Logging fill-ups and readings](#logging-fill-ups-and-readings)
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
  logging fill-ups and odometer readings; a key never edits or deletes).
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
  can (with the multi-user phases, a partner's key sees only the partner's
  vehicles). Cost figures follow the user's access to costs: without it,
  amount fields are **left out**, not zeroed.
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
| fuel volume | litres, or kWh for electricity (`"volume_unit": "l"` / `"kwh"`) | `"volume": "44.210"` |
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
| `GET /vehicles/{id}` | one vehicle, as its edit form holds it |
| `GET /vehicles/{id}/summary` | odometer and its time, economy per series (liquid, electric), the last fill-up, running cost per km over 12 months, what is due next, open reminder counts, current documents' expiry, tyre status, and `display` text |
| `GET /vehicles/{id}/fuel` | fill-ups, each with the economy of the tank it closes and its economy-check flag (paged) |
| `POST /vehicles/{id}/fuel` | log a fill-up (read and write key) |
| `GET /vehicles/{id}/odometer` | readings with their source (manual, fuel, maintenance, document, tyre) (paged) |
| `POST /vehicles/{id}/odometer` | add a reading (read and write key) |
| `GET /vehicles/{id}/maintenance` | service records (paged) |
| `GET /vehicles/{id}/documents` | compliance documents with their status and days left (paged) |
| `GET /vehicles/{id}/expenses` | expenses (paged; needs cost access) |
| `GET /vehicles/{id}/tyres` | tyres: fitted, stored, retired, with tread and what is due |
| `GET /upcoming` | *Coming up* over the next 12 months (`?vehicle=`) |
| `GET /reminders` | open reminders, most urgent first (`?vehicle=`, `?status=overdue\|due\|upcoming`) |
| `GET /openapi.json` | the OpenAPI description (no key) |

A vehicle id the key's user cannot see answers `404`, like one that does
not exist.

## Lists: paging and dates

The `fuel`, `odometer`, `maintenance`, `documents` and `expenses` lists are
**newest first** and paged:

- `?limit=` 1–200, default 50.
- The response is `{"items": [...], "next": "<URL of the next page>"}`;
  `next` is `null` on the last page. Follow it as it is. An entry logged
  while you page never shifts a page.
- `?since=` / `?until=` take a date (`2026-09-01`: from the start / to the
  end of that day in UTC) or an instant (`2026-09-01T08:00:00Z`, or with an
  offset), both inclusive, on the entry's own date or time.

`/vehicles`, `/tyres`, `/upcoming` and `/reminders` are short and come
whole.

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
| `volume_unit` | `l`, `gal_uk`, `gal_us`; `kwh` for electricity; default the owner's |
| `is_partial`, `is_missed_previous` | `true` / `false` |
| `station`, `notes` | text |

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
| 409 | `vehicle_archived` | writes to an archived vehicle |
| 422 | `validation_failed` | per field: the form's message key and text |
| 429 | `too_many_failures` | too many failed keys from this address; see `Retry-After` |
| 500 | `internal_error` | logged on the server |

## Browser dashboards (CORS)

Servers (Home Assistant, Grafana, Node-RED) call the API directly and need
nothing here. A web page on another origin that calls it from the browser
needs its origin listed: `API_CORS_ORIGINS=https://dash.example.com`
(comma-separated). Anything the page sends carries the key, so only list
pages you trust.

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
`fuel.electric` in place of `fuel.liquid` (kWh/100 km).

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
