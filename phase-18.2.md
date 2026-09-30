# Phase 18.2 — REST API v1 + v1.10 release

*Let Home Assistant, Shortcuts, Grafana and OBD tools read and log.*

Status: 📋 planned · releases **v1.10.0** with Phase 18.1

A small, documented JSON API, authorised by API keys that belong to a user
and go through the Phase 18.1 access policy. This version reads everything a
dashboard or automation needs, and writes the two things automations
actually log: fill-ups and odometer readings. That is enough for Home
Assistant sensors, Apple Shortcuts and Android automations logging a
fill-up, Grafana panels, Node-RED flows, and OBD tools posting the odometer.
It is also the base for AI actions later (an MCP server on top of the
OpenAPI description), which is not in this phase.

Read [`CLAUDE.md`](CLAUDE.md), [`spec.md`](spec.md) §5 and the Phase 18.1
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
- [ ] §7.20, §9 and §12 in `spec.md`; the Phase 18 line in §13.
- [ ] `docs/api.md`: keys, values, paging and errors, with worked examples
      for Home Assistant (a REST sensor on `/summary`), Apple Shortcuts
      ("Log fill-up" with Ask for Input), Grafana (Infinity data source on
      `/fuel`) and Node-RED (http request node). Link it from the README
      documentation table.
- [ ] `docs/api/openapi.json` as the single source, served at
      `/api/v1/openapi.json`.

### Migration
- [ ] `api_keys` table (Phinx, applies and rolls back on every engine;
      unique index on token_hash, index on user_id). This moves the schema
      version.
- [ ] Backup and restore include `api_keys`. The restore page gets a
      notice about `SESSION_SECRET`.

### Code
- [ ] `Domain\Api\ApiKey`, `ApiScope` enum; `Repository\ApiKeyRepository`.
- [ ] `Service\Api\ApiKeyService`: create (returns the token once), verify
      (constant-time compare of the hash), revoke, touch last-used
      (throttled).
- [ ] `Middleware\ApiAuthMiddleware` (bearer → user and scope on the
      request), `ApiErrorMiddleware` (exceptions and validation →
      problem+json), `ApiThrottle`, and a CORS middleware (only when
      configured).
- [ ] Reuse `VehicleAccessMiddleware` for `{id}` API routes. The route
      inventory test gains the API group.
- [ ] `Action\Api\…`: one invokable class per endpoint.
- [ ] `Support\Api\Serializer`: typed domain objects → arrays, with decimal
      strings, units and costs gated by `ViewCosts`.
- [ ] `Support\Api\JsonInput`: JSON → the existing fill-up and reading
      commands. It is a new input adapter; the services are unchanged.
- [ ] Settings → API keys pages (list, create, created-once, revoke), with
      CSRF and translations.
- [ ] `config/routes.php` API group; `.env.example` entries.

### Tests
- [ ] Contract tests: every endpoint's responses validated against
      `openapi.json` (`league/openapi-psr7-validator`, dev only).
- [ ] Auth: missing, malformed, unknown and revoked keys → 401; read key on
      POST → 403; throttle after 20 failures; the token never appears in
      logs.
- [ ] Access: with the Phase 18.1 test policy, another user's vehicle →
      404; no `ViewCosts` → amounts omitted from every response.
- [ ] Values: decimal strings round-trip exactly; UTC instants; `display`
      in the owner's units (km, UK and US preferences).
- [ ] Writes: any two of volume / price / total derive the third as the
      form does; gallons and miles convert exactly; a duplicate returns 200
      with `duplicate: true` and writes nothing; validation errors carry the
      same keys as the form's; plausibility and economy warnings are
      returned and never block; an archived vehicle → 409.
- [ ] Module toggles: fuel off → `/fuel` 404, and fuel fields are gone from
      the summary.
- [ ] No API request creates a session row.
- [ ] Works under `APP_BASE_PATH`; `API_ENABLED=false` → 404.
- [ ] Integration suite green on every engine; migration rolls back on
      every engine.
- [ ] `bin/smoke-test.sh` gains an API check: create a key via the CLI
      helper, then `GET /vehicles`.

### CLI
- [ ] `php bin/api-key.php create --user <username> --name <n> --scope
      read|read_write` and `revoke <id>`, for headless installs.

### Release
- [ ] `CHANGELOG.md` **1.10.0**: the access policy (no visible change) and
      the API. Upgrade notes: one migration; new optional variables; a note
      on `SESSION_SECRET`.
- [ ] Bump `VERSION`, rebuild assets, update the README status and
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

## Open questions

- Should `API_ENABLED` default to `false` for a fresh install, so the
  attack surface is off until someone wants it? Keys are required anyway,
  so `true` is drafted.
- Is a per-vehicle key restriction worth adding now (a key for the OBD
  dongle that can only log to one car), or is it enough once Phase 19
  exists and the dongle can have its own user?
