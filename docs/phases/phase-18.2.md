# Phase 18.2 — REST API v1 + v1.10 release

*Let Home Assistant, Shortcuts, Grafana and OBD tools read and log.*

Status: ✅ complete · released as **v1.10.0** together with Phase 18.1

A small, documented JSON API, authorised by API keys that belong to a user
and go through the Phase 18.1 access policy. This version reads everything a
dashboard or automation needs, and writes the two things automations
actually log: fill-ups and odometer readings. That is enough for Home
Assistant sensors, Apple Shortcuts and Android automations logging a
fill-up, Grafana panels, Node-RED flows, and OBD tools posting the odometer.
It is also the base for AI actions later (an MCP server on top of the
OpenAPI description), which is not in this phase.

Read [`CLAUDE.md`](../../CLAUDE.md), [`spec.md`](../../spec.md) §5 and the Phase 18.1
policy first.

---

## Goals

1. **API keys:** created, named, scoped (read, or read and write), shown
   once, revoked and last-used-at, in Settings.
2. **Read endpoints** for vehicles, a per-vehicle summary, fill-ups,
   odometer readings, service records, documents, expenses, tyres, *Coming
   up* and reminders.
3. **Write endpoints:** log a fill-up and add an odometer reading, through
   the same services and validation as the forms, with safe retries.
4. **OpenAPI 3.1** description, served by the app and validated in the
   tests.
5. **Guides** for Home Assistant, Apple Shortcuts, Grafana and Node-RED.

## Not in scope

- Editing or deleting through the API, and writes other than fill-ups and
  readings. A later version adds them endpoint by endpoint.
- Attachments over the API, including uploading a receipt photo.
- OAuth, OIDC or sessions for the API. Keys only.
- Webhooks for new entries. The existing webhook channel covers reminders.
- An MCP server or any AI integration.
- Aggregated reports and ownership figures over the API. Summary fields
  cover the common sensor needs.

---

## Spec addition (§7.20 REST API)

### 7.20 REST API (Phase 18.2)

**Base and format.** Everything is under `{APP_BASE_PATH}/api/v1`, in
JSON (`application/json`). Errors use RFC 9457 problem details
(`application/problem+json`) with a stable `code`, English `detail`, and
`errors` per field for validation (the form's message key and English
text). API routes are outside the session and CSRF groups, like `/health`,
so they never create a session. `API_ENABLED` (default `true`) switches the
whole group off (404).

**Keys.**
- Settings → API keys (`/settings/api-keys`) creates a key with a name
  ("Home Assistant") and a scope: `read` or `read_write`. The token
  `lbk_` + 32 random bytes (base64url) is shown **once**, with a copy
  button.
- Only a keyed hash is stored: HMAC-SHA256 with `SESSION_SECRET`, like
  session ids and calendar tokens. Changing `SESSION_SECRET` therefore
  disables every key, and §9 must say so.
- The list shows name, scope, created, last used (updated at most once a
  minute) and *Revoke*. Revoking is immediate and cannot be undone.
- A key belongs to the user who created it and has **exactly that user's
  access** through the Phase 18.1 policy, narrowed by its scope. Sent as
  `Authorization: Bearer lbk_…`. A missing, malformed, unknown or revoked
  key answers 401 with `WWW-Authenticate: Bearer`; a read key on a write
  answers 403.
- Failed attempts are logged with the client address, never the token.
  After 20 failures from one address in 10 minutes, that address gets 429
  for 10 minutes (a small counter in the cache directory; no new service).
- **Table** `api_keys`: id, user_id (`ON DELETE CASCADE`), name (up to
  100), token_hash (unique), scope, created_at, last_used_at, revoked_at
  (UTC). It is in backups. Keys restored into an install with another
  `SESSION_SECRET` stop working, and the restore page says so.

**Values.**
- Quantities are **canonical** (km, litres, kWh, L/100 km or kWh/100 km)
  and money is in the vehicle's currency. All are **decimal strings**
  (`"78421.000"`, `"61.320"`), never floats, with the unit named once per
  object (`"distance_unit": "km"`, `"currency": "GBP"`). Instants are
  ISO 8601 UTC (`2026-09-29T07:42:00Z`), and calendar dates are
  `YYYY-MM-DD`.
- The summary endpoint also carries `display`: the same figures formatted
  in the key owner's units, locale and currency ("48,730 mi",
  "52.1 mpg"), for sensors that just show text.
- Costs follow `ViewCosts`. Without it, amount fields are omitted, not
  zeroed.
- Module toggles apply: a switched-off module's endpoints answer 404, and
  its fields leave other responses.

**Read endpoints** (scope `read`). Lists are paged by cursor (`limit`
1–200, default 50; `next` URL in the response) and filtered by `since` /
`until` (dates or instants, matching the field).

| Endpoint | Returns |
|---|---|
| `GET /vehicles` | visible vehicles (`?status=active\|archived\|all`, default active) |
| `GET /vehicles/{id}` | one vehicle, as the edit form holds it |
| `GET /vehicles/{id}/summary` | current odometer and its time, average economy (per series), last fill-up, cost per distance (last 12 months), next due item, open reminder counts, current documents' expiry, tyre status |
| `GET /vehicles/{id}/fuel` | fill-ups, each with its segment economy when it closes one and its economy-check flag |
| `GET /vehicles/{id}/odometer` | readings with source |
| `GET /vehicles/{id}/maintenance` | service records |
| `GET /vehicles/{id}/documents` | compliance documents |
| `GET /vehicles/{id}/expenses` | ad-hoc expenses |
| `GET /vehicles/{id}/tyres` | tyres with status, position, latest measured tread |
| `GET /upcoming` | *Coming up* items, `?vehicle=` optional |
| `GET /reminders` | open reminders, `?vehicle=`, `?status=` |
| `GET /me` | the key's user (display name, units, locale, time zone) and the key's scope |
| `GET /openapi.json` | the OpenAPI description (no key needed) |

**Write endpoints** (scope `read_write`, ability `Log`).
- `POST /vehicles/{id}/fuel`: body `filled_at` (instant; default now),
  `odometer` with optional `distance_unit` (`km`\|`mi`, default the
  owner's), `fuel` and `grade` (codes; defaults as the form's), any two of
  `volume` (with `volume_unit`: `l`\|`gal_uk`\|`gal_us`\|`kwh`, default the
  owner's) / `price_per_unit` / `total_cost`, `is_partial`,
  `is_missed_previous`, `station`, `notes`. Numbers are decimal strings or
  JSON numbers and are parsed as decimals, never floats.
- `POST /vehicles/{id}/odometer`: `recorded_at` (default now), `odometer`,
  `distance_unit`, `note`.
- Both are mapped to the **same command objects and services** as the
  forms and CSV import, so they get the same validation, derived third
  amount, odometer reading written in the same transaction, schedules,
  reminders and economy check. The response is `201` with the created
  entry, plus `warnings` (the plausibility warning, the economy-check flag)
  that never block.
- **Retries are safe:** an entry that matches an existing one by the CSV
  import's duplicate key (fill-up: same time and odometer; reading: same
  time and odometer) answers `200` with the existing entry and
  `"duplicate": true`. Nothing is written. An automation that retries after
  a timeout never doubles a fill-up.
- Archived vehicles refuse writes (409, `vehicle_archived`), as the forms
  do.

**CORS** is off by default. `API_CORS_ORIGINS` (comma-separated origins)
allows browser dashboards; preflight answers only for those origins.

Update §9 (`API_ENABLED`, `API_CORS_ORIGINS`, and that `SESSION_SECRET`
disables API keys) and §12 (remove the REST API line).

---

## Decisions (and why)

- **Keys inherit their user's access.** With Phase 19, a partner's key sees
  only what the partner sees. Nothing in the API has to change when users
  arrive.
- **Canonical values plus a `display` block.** Automations need numbers
  they can compare and chart without knowing preferences. Sensors that just
  show text get the owner's formatting for free. Decimal strings keep the
  app's no-floats rule at the boundary.
- **Idempotency by the existing duplicate key**, rather than an
  `Idempotency-Key` table. It is already defined and tested for imports,
  and it covers what automations actually do (retry the same fill-up).
- **Only two writes.** They cover Shortcuts, Android and OBD. Each further
  write needs its own review of validation and side effects, so they come
  in later versions.

---

## Tasks

### Spec and docs
- [x] §7.20, §9 and §12 in `spec.md`; the Phase 18 line in §13.
- [x] `docs/api.md`: keys, values, paging and errors, with worked examples
      for Home Assistant (a REST sensor on `/summary`), Apple Shortcuts
      ("Log fill-up" with Ask for Input), Grafana (Infinity data source on
      `/fuel`) and Node-RED (http request node). Link it from the README
      documentation table.
- [x] `docs/api/openapi.json` as the single source, served at
      `/api/v1/openapi.json`.

### Migration
- [x] `api_keys` table (Phinx, applies and rolls back on every engine;
      unique index on token_hash, index on user_id). This moves the schema
      version.
- [x] Backup and restore include `api_keys`. The restore page gets a
      notice about `SESSION_SECRET`.

### Code
- [x] `Domain\Api\ApiKey`, `ApiScope` enum; `Repository\ApiKeyRepository`.
- [x] `Service\Api\ApiKeyService`: create (returns the token once), verify
      (constant-time compare of the hash), revoke, touch last-used
      (throttled).
- [x] `Middleware\ApiAuthMiddleware` (bearer → user and scope on the
      request), `ApiErrorMiddleware` (exceptions and validation →
      problem+json), `ApiThrottle`, and a CORS middleware (only when
      configured).
- [x] Reuse `VehicleAccessMiddleware` for `{id}` API routes. The route
      inventory test gains the API group.
- [x] `Action\Api\…`: one invokable class per endpoint.
- [x] `Support\Api\Serializer`: typed domain objects → arrays, with decimal
      strings, units and costs gated by `ViewCosts`.
- [x] `Support\Api\JsonInput`: JSON → the existing fill-up and reading
      commands. It is a new input adapter; the services are unchanged.
- [x] Settings → API keys pages (list, create, created-once, revoke), with
      CSRF and translations.
- [x] `config/routes.php` API group; `.env.example` entries.

### Tests
- [x] Contract tests: every endpoint's responses validated against
      `openapi.json` (`league/openapi-psr7-validator`, dev only).
- [x] Auth: missing, malformed, unknown and revoked keys → 401; read key on
      POST → 403; throttle after 20 failures; the token never appears in
      logs.
- [x] Access: with the Phase 18.1 test policy, another user's vehicle →
      404; no `ViewCosts` → amounts omitted from every response.
- [x] Values: decimal strings round-trip exactly; UTC instants; `display`
      in the owner's units (km, UK and US preferences).
- [x] Writes: any two of volume / price / total derive the third as the
      form does; gallons and miles convert exactly; a duplicate returns 200
      with `duplicate: true` and writes nothing; validation errors carry the
      same keys as the form's; plausibility and economy warnings are
      returned and never block; an archived vehicle → 409.
- [x] Module toggles: fuel off → `/fuel` 404, and fuel fields are gone from
      the summary.
- [x] No API request creates a session row.
- [x] Works under `APP_BASE_PATH`; `API_ENABLED=false` → 404.
- [x] Integration suite green on every engine; migration rolls back on
      every engine.
- [x] `bin/smoke-test.sh` gains an API check: create a key via the CLI
      helper, then `GET /vehicles`.

### CLI
- [x] `php bin/api-key.php create --user <username> --name <n> --scope
      read|read_write` and `revoke <id>`, for headless installs.

### Release
- [x] `CHANGELOG.md` **1.10.0**: the access policy (no visible change) and
      the API. Upgrade notes: one migration; new optional variables; a note
      on `SESSION_SECRET`.
- [x] Bump `VERSION`, rebuild assets, update the README status and
      documentation table.

---

## Acceptance criteria

1. A Home Assistant REST sensor shows the car's odometer and economy from
   `/summary` with a read key.
2. An Apple Shortcut logs a fill-up in UK gallons and miles, and retrying
   it does not double it.
3. Grafana charts economy per fill-up from `/fuel`.
4. Every response matches the published OpenAPI description.
5. A key never sees more than its user, and a read key never writes.
6. Definition of done (CLAUDE.md §11) holds, including the migration on
   every engine and the multi-arch image.

## Open questions (answered)

- **`API_ENABLED` defaults to `true`.** Nothing is reachable without a key,
  and a fresh install has none; the description (`openapi.json`) is the
  only open address. `false` still switches it all off.
- **No per-vehicle keys yet.** Phase 19 lets a device have its own user
  with access to one car, which covers the OBD dongle without a second
  access model.

---

## Changed while building it

spec.md §7.20 is the current text.

- **CORS is global, not in the API group.** An `OPTIONS` catch-all route in
  the group would have made every unknown API path a 405 instead of a 404,
  so `ApiCorsMiddleware` sits outermost on the whole app, acts on API paths
  only, answers preflights before routing (204 for a listed origin, 403
  `cors_not_allowed` otherwise) and adds the header to errors too. The
  router's own errors under `/api/` (unknown path, wrong method, the API
  off) are problem details from the global error handler.
- **The throttle is a service, not a middleware.** `FailedKeyThrottle`
  (one JSON counter per address hash under `var/cache/api-throttle`, with a
  lock) is asked by `ApiAuthMiddleware`, which knows when a key failed. A
  missing key is not counted as a guess.
- **The key's user replaces any session user** and the formatting context:
  `ApiAuthMiddleware` sets the `user` attribute, `AccessContext`, and runs
  the rest of the request in the owner's language and units
  (`UserDisplayScope`), so `display` and names need nothing of their own.
  A signed-in browser with no key gets 401 even on a POST (no CSRF group
  here, so this matters), and a test proves it.
- **Only the entry lists are paged.** `/vehicles`, `/tyres`, `/upcoming`
  and `/reminders` are short and in their own order (urgency, position),
  so they come whole. Paging is in PHP over what the service reads (the
  economy of a fill-up needs the whole history anyway); the cursor names
  the last item's time and id.
- **Writes let the form convert.** `JsonInput` puts the request's units into
  the preferences the form parses with (locale `en`, UTC), rather than
  converting first as the CSV import does, so "derives the third as the
  form does" and "converts exactly" hold by construction. Instants are
  taken to the minute, as the forms keep them. Number tokens are turned
  into strings before `json_decode`, so a JSON number never becomes a
  float (a test uses `40123.4565`, which rounds the other way as a float).
- **Unknown fields are refused** (`api.validation.unknown_field`), and the
  API's own input rules have `api.validation.*` keys (en + de), so a typo
  such as `price` for `price_per_unit` is caught instead of meaning "only
  one amount".
- **Retries need the time.** The duplicate key is time plus odometer, so a
  retry without `filled_at` is a new "now"; the spec and the Shortcuts
  guide say to send it. The key itself moved from `CsvImporter` to
  `Service\Import\DuplicateKey`, shared by both. A reading that repeats
  any existing reading (a fill-up's included) is a duplicate.
- **Archived vehicles:** the forms never refused a write to one; they only
  never offered it. The API refuses (409) anyway, as planned.
- **The summary syncs reminders first**, as the Reminders page does, so its
  counts are not those of the last scheduler run. Its cost per distance is
  the running cost over the last 12 months as Reports counts it.
- **`/me` also lists the modules**, so a client knows which endpoints exist.
- **Settings → API keys stays when the API is off** (keys can be prepared;
  the page says so) and revoking has its own confirmation page. The new
  token is on the page that answers the POST (`Cache-Control: no-store`),
  never flashed through the session.
- **OpenAPI validation:** `league/openapi-psr7-validator` 0.24 (on
  `devizzent/cebe-php-openapi`) reads 3.1 type arrays, so the description
  is real 3.1. Every API test goes through `tests/Support/ApiClient`,
  which validates each response, errors included; preflights are not
  operations and are checked on their own. `tests/Support/JsonDoc` gives
  the tests typed access to responses for PHPStan.
- **Access tests:** `VehicleRoutes` leaves the API routes out of the
  session-driven loops, and `ApiAccessTest` runs the same no-access, view
  only and no-costs checks over every API vehicle route with a key. The
  route inventory classifies the API routes, and a new test proves every
  API route but the description answers 401 without a key.
- **Deployment:** `.dockerignore` excluded `docs`, so it now re-includes
  `docs/api` for the served description; `public/.htaccess` and the image's
  vhost hand the `Authorization` header to PHP-FPM (mod_php passes it
  already). `docs/deployment.md` explains both and the throttle behind a
  proxy.
- `ExpenseService::entries()` was added for the expenses list (repositories
  stay behind services).
- **Every decimal has a fixed number of places** (`Serializer::dec()`: 3 for
  km, litres, kWh, mm and money, 6 for a price per unit, 3 for consumption,
  4 for money per km), so a sum that starts at zero reads `"0.000"`, not
  `"0"`. An edge-case test (a single fill-up, every vehicle detail, a
  plug-in hybrid, a distance interval, a document's odometer, a manual
  reminder, stored, retired and worn tyres) runs every GET through the
  contract; it failed on the one-fill summary before the fix.
- **`/fuel` computes the economy checks once per request**, not once per
  item: 200 fill-ups of 500 went from about 2.7 s to under 0.2 s.
- **Known limit:** the duplicate check reads, then writes, without a lock, so
  a retry sent while the first request is still being saved could write
  twice. Retries after a timeout (the case that matters) arrive after the
  first write and are caught.
