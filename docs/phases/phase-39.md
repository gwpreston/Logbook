# Phase 39 — The rest of the REST API + release

*Everything you can do to a vehicle in Logbook, an automation can do too:
read it, log it, change it, and hear about it.*

Status: 📋 planned · file lives in `docs/phases/`

Phase 18.2 gave the API reads and two writes. Phases 22, 26.3, 27.1, 29.2,
30.2 and 32 each added a few endpoints for their own feature. That has left
gaps. Some were deliberate deferrals (spec §7.20 *Not in this version*).
Others crept in because later features never got an endpoint. This phase
closes them, so a Home Assistant automation, a Shortcut or a script can do
anything a vehicle's pages can do, under the same access rules.

Read [`CLAUDE.md`](../../CLAUDE.md) and [`spec.md`](../../spec.md) first:
§5 (access policy, `EntryAccess`), §7.20 in full, §7.1 (including
valuations and depreciation), §7.4, §7.6, §7.7, §7.12, §7.16, §7.17,
§7.22, §7.24, §7.32, §7.33, §7.34, and Phases 14.1, 14.2, 18.2 and 26.3.

**Prerequisites:** [Phase 38](phase-38.md) complete and green.

---

## The review: what the API is missing

What each area of the app does, and what the API can do with it today.

| Area | In the app | In the API today | Missing |
|---|---|---|---|
| Vehicles (§7.1) | add, edit, photo, archive, restore, delete, transfer | list, read one | create, edit, archive, restore |
| Fill-ups, readings, service records, documents, expenses | add, edit, delete, attach files | list, add | read one, edit, delete; list filters the pages have (`category`, `type`, current documents) |
| Maintenance schedules (§7.4) | add, edit, delete; *last done*, *next due* | none (only through *Coming up*) | everything |
| Reminders (§7.6) | mark done, dismiss, reopen, edit, delete manual ones; closed ones listed | open ones listed, manual ones added | the three actions, edit, delete, closed reminders |
| Valuations (§7.1, Phase 14.1) | add, edit, delete, attach | none | everything |
| Cost of ownership, depreciation (Phase 14.2) | overview card, Reports | summary only | ownership figures |
| Reports (§7.7) | costs by group, month and vehicle; cost per distance; fuel statistics; mileage | none (Ask and MCP have them as tools) | the four reports |
| History (§7.16) | per vehicle and fleet | none | both feeds |
| Tyres (§7.17) | fit, swap, rotate, repair, remove, existing, check; sets; edit a tyre or a change | tyre list, tread check | changes, sets, edit |
| Trips, journeys (§7.22) | trips add, edit, delete; journeys add, edit, delete | trips list and add; journeys list | trip edit and delete; journey writes |
| Incidents (§7.29) | add, edit, delete | list, add, history | edit, delete |
| Finance (§7.32) | agreement add and edit, payment events, settlement quotes | the active agreement, read only | the writes |
| Needs attention (§7.24) | the list; hide and unhide an item | none | both |
| Stations (§7.33) | list, favourites | by id or name on a fill-up only | list, favourite |
| Fuel prices (§7.34) | cheapest near me, price alerts | cheapest near me | price alerts |
| Attachments (§7.12) | upload, list, download, delete | none | everything |
| New-entry notifications | reminders only, through the channels | none | webhooks for entries |

Out of this review on purpose, and still left out (see *Not in scope*):
account and instance administration, sharing and transfer, deleting a
vehicle, Ask (MCP is the AI surface), places, imports, CSV exports and the
sale pack.

---

## Goals

1. **Read everything a vehicle's pages show:** single entries, schedules,
   valuations, ownership, reports, history, tyre changes and sets,
   stations, needs attention, price alerts and closed reminders.
2. **Act on reminders:** mark done, dismiss, reopen.
3. **Write everything an entry form writes:** vehicles, valuations,
   schedules, tyre changes, journeys, finance, price alerts, attention
   hiding, station favourites.
4. **Edit and delete every entry** through the API, under `EntryAccess`,
   with optional optimistic concurrency.
5. **Attachments:** list, download, upload and delete.
6. **Webhooks** for entries created, changed and deleted.
7. OpenAPI, `docs/api.md` guides, and a minor release. The API stays `v1`:
   every change is additive.

## Not in scope

- **OAuth, sessions, per-vehicle keys.** As Phase 18.2 and Phase 19
  decided: keys only; a device that needs narrower access gets its own
  user.
- **Account and instance administration:** users, invitations, sharing,
  transfer, Settings (units, lead times, modules), notification channels,
  AI connections, jobs, updates, backups and API keys themselves. A key
  must never be able to widen its own reach or another user's. Settings by
  chat is already parked in §12 (#75).
- **Deleting a vehicle.** It takes every entry and file with it and cannot
  be undone. The confirmation page stays the only way. Archive and restore
  are in.
- **Ask over the API.** The MCP server (§7.28) is how an assistant uses
  Logbook. `/me` keeps leaving out the AI modules.
- **Places.** Private to their user and never in the API, Ask or MCP
  (Phase 30.1). Unchanged.
- **Imports, CSV exports, the sale pack and print views.** They are file
  workflows on pages. The API's JSON already carries the same data.
- **Automatic valuation.** The Phase 14.1 non-goal stands: Logbook never
  fetches a value or sends a registration anywhere. The new valuation
  endpoint takes a figure someone quoted, sent by the owner's own tool.
- **New MCP tools** for the new writes. A follow-up can map them, as Phase
  26.5 mapped the Phase 26.3 writes.

---

## Spec changes (§7.20 REST API)

Written into `spec.md` §7.20 before any code. *Not in this version* is
replaced by the list under *Still not in the API* below.

### Conventions for the new endpoints

- **Paths are nested under the vehicle:** `/vehicles/{id}/fuel/{entry}`.
  An entry id that belongs to another vehicle answers 404, as an
  unreadable vehicle does. Journeys, price alerts and stations belong to
  the user or the install, so their paths are top level.
- **Read one:** every list gains `GET …/{entry}`, returning the object
  exactly as the list returns it. It answers with an `ETag` (see open
  question B).
- **Edit is `PATCH`** with the fields to change (open question C). The
  stored entry is loaded, the sent fields are laid over it, and the result
  goes through the **edit form's parser and service**, as the create
  endpoints go through the add form's. So validation, messages (422),
  recomputation and `warnings` are the form's. Unknown fields are refused
  (`api.validation.unknown_field`), as on create. Answers `200` with the
  entry.
- **Delete is `DELETE`**, through the same service as the delete
  confirmation page, with the same knock-on effects (a schedule falls back
  to the previous record, a fill-up's economy segments are recomputed, the
  entry's attachments are removed). Answers `204`.
- **Abilities** are the pages': edit and delete declare `Log` and are
  checked with `EntryAccess::canChange` once the entry is loaded (`Manage`,
  or `Log` on the key user's own entry), 403 `forbidden` otherwise. Where
  the page needs `Manage` or `Own`, so does the API, as listed below.
- **Archived vehicles** refuse every write but *Restore* (409,
  `vehicle_archived`), as today.
- **Derived readings** (written by a fill-up, service record or document)
  can't be edited or deleted on their own, as on the pages: 409
  `reading_derived`, with `links.entry` pointing at the entry that owns it.
- **Modules** apply as today: a switched-off module's paths answer 404.
- **Amounts** follow `EntryAccess::canSeeAmount` on every new read, and
  are omitted, not zeroed, as today.
- **CORS** preflight allows `GET, POST, PATCH, DELETE` and the
  `If-Match` header for the allowed origins.
- **OpenAPI** gains every operation below, and the tests validate every
  response against it, as for the others. `info.version` moves one minor
  version.

### 1. New read endpoints (scope `read`)

| Endpoint | Returns | Needs |
|---|---|---|
| `GET /vehicles/{id}/{list}/{entry}` for fuel, odometer, maintenance, documents, expenses, trips, incidents | one entry, as its list returns it | `View` |
| `GET /vehicles/{id}/maintenance` | gains `?category=` and `?q=` (text in title, vendor, description), as the page and Ask's `maintenance` tool | `View` |
| `GET /vehicles/{id}/documents` | gains `?type=` and `?current=1` (not expired today in the owner's time zone) | `View` |
| `GET /vehicles/{id}/schedules` | schedules with interval, baseline, stored last done and next due, status (`overdue`, `due_soon`, `on_track`, `unknown`), and the projected date of the distance limit (§7.4) | `View` |
| `GET /vehicles/{id}/valuations` | valuations, newest first, paged as the entry lists | `ViewCosts` |
| `GET /vehicles/{id}/ownership` | Phase 14.2's figures: lifetime running cost, purchase and current value, depreciation (amount, percentage, per year, per distance, or the state that stops it: `no_purchase_price`, `no_value`), the stale-valuation flag, with `display` strings | `ViewCosts` (403 without) |
| `GET /vehicles/{id}/history`, `GET /history` | the `ActivityFeed` (§7.16) for one vehicle or every visible one: kind, date, summary, amount (per `canSeeAmount`), odometer, attachment count, entry id and API link; `?kinds=`, `?since=`, `?until=`, paged by cursor | `View` |
| `GET /reports/costs` | §7.7's totals by category group, month or vehicle (`?group_by=`), per currency, with distance driven; `?vehicles=`, `?period=` as Reports accepts them | `ViewCosts` on each vehicle counted; others are left out and listed in `excluded` |
| `GET /reports/cost-per-distance` | per vehicle and fleet, with distance | as above |
| `GET /reports/fuel` | Phase 16's fuel statistics: economy, volume, spend, price per unit, by grade, with verdicts; `?vehicle=`, `?period=`, `?grade=` | `View`; amounts per `ViewCosts` |
| `GET /reports/mileage` | distance driven, average per month and per year; `?vehicle=`, `?period=` | `View` |
| `GET /vehicles/{id}/tyres/changes` | tyre changes, newest first, with their lines (kind, tyre, from and to position, retire reason, depth) | `View` |
| `GET /vehicles/{id}/tyre-sets`, `GET /tyre-sets` | sets with name, storage, and their tyres | `View` |
| `GET /reminders` | gains `?status=done\|dismissed` and `?closed=1`, as the Reminders page's closed list (#208) | `View` |
| `GET /attention` | *Needs attention* (§7.24) for the visible vehicles, `?vehicle=`, in the page's order and words, each item with its fix's API link where one exists; hidden items left out, `?hidden=1` lists them | `View` |
| `GET /stations` | stations, `?q=`, `?favourites=1`, favourites first, with the key user's visits, spend and average and cheapest price per grade over the vehicles they can see (as Ask's `stations` tool); never places | `stations` module |
| `GET /fuel-prices/alerts` | the key user's price alerts | a price provider enabled |
| `GET /vehicles/{id}/finance/agreements` | every agreement, active first, then ended ones newest first, with payment events and settlement quotes; never the agreement number | `finance` module; §7.32's access |

Reports read the **same services as the Reports page**, so the API's
totals always match it. A report's `period` takes the page's values.

### 2. Reminder actions (scope `read_write`)

- `POST /reminders/{id}/done`, `/dismiss`, `/reopen`: the Reminders
  page's one-click forms, with the same ability (`Log`) and the same
  rules. They work on any reminder the key user can act on, of any source
  (schedule, document, tyre, finance, first MOT, manual). Answers `200`
  with the reminder. Repeating an action that is already in effect answers
  `200` with `"unchanged": true` and writes nothing, so a retry is safe.
- `PATCH` and `DELETE /reminders/{id}` for **manual** reminders (`Manage`,
  as the page). Other sources answer 409 `reminder_not_manual`: they are
  changed through their source (the schedule, the document).

### 3. Vehicles (scope `read_write`)

- `POST /vehicles`: the add form's fields (type, make, model and fuel type
  required; everything else optional, with the form's validation,
  including *First MOT due* and its suggestion when the field is not
  sent). The key's user becomes the owner. `201` with the vehicle as
  `GET /vehicles/{id}` returns it. Duplicate key for a safe retry: same
  owner, registration (when given), make and model, created in the last
  ten minutes.
- `PATCH /vehicles/{id}`: the edit form (`Manage`). Purchase and sale
  fields follow the form's rules (a sale date marks the vehicle *Sold*;
  clearing it clears that).
- `POST /vehicles/{id}/archive` (`Own`): body `disposal`
  (`sold`, `written_off`, and from Phase 29.2 `returned_lender`,
  `returned_lessor`) and the fields the *Archive* page asks for with that
  reason (sale date and price; the settled incident for a write-off; the
  finance agreement's ending). It goes through the archive page's service,
  so every rule there applies (§7.1, §7.29 *Total loss*, §7.32 *Ending*).
- `POST /vehicles/{id}/restore` (`Own`): as *Restore*.
- The vehicle photo is an attachment of owner type `vehicle_photo` (see
  §9 below).

### 4. Valuations (scope `read_write`, `Manage` as the page)

- `POST /vehicles/{id}/valuations`: `valued_on` (default today in the key
  owner's time zone), `amount`, `source`, `notes`. The form's validation:
  not after today, not before the purchase date, not after the sale date
  (with its message), amount ≥ 0. Duplicate key: same date, amount and
  source.
- `PATCH`, `DELETE /vehicles/{id}/valuations/{valuation}`.
- Archived vehicles may take a valuation (§7.1: a scrapped car's scrap
  value) unless the sale-date rule refuses it. This is the **one write an
  archived vehicle accepts**, as on the page.

### 5. Maintenance schedules (scope `read_write`, `Manage`)

- `POST /vehicles/{id}/schedules`: `category`, `title`, `interval_km` or
  `interval_distance` with `distance_unit`, `interval_months` (at least
  one interval), `baseline_done_on`, `baseline_odometer`. Next due is
  computed and stored as by the form. Duplicate key: same category, title
  and intervals.
- `PATCH`, `DELETE /vehicles/{id}/schedules/{schedule}`. Deleting keeps the
  records that completed it (§7.4).

### 6. Tyres (scope `read_write`)

- `POST /vehicles/{id}/tyres/changes`: `kind` (`existing`, `fit`, `swap`,
  `rotate`, `repair`, `remove`), `changed_on`, `odometer`, `distance_unit`,
  `note`, `service_record_id` (optional link, as the form's), and the
  kind's lines, in the form's terms: new tyres with their details for
  `existing` and `fit`; the set for `swap`; a position per tyre for
  `rotate`; the tyres and `off` or `retire` (with reason) for `remove` and
  for tyres a `fit` replaces. The change is **replayed** through the same
  service as the form (§7.17 *State is replayed, then stored*), so every
  impossible state the form refuses is refused here (422). `check` stays
  on its own endpoint (`POST …/tyres/checks`). `Log`.
- `PATCH`, `DELETE /vehicles/{id}/tyres/changes/{change}`: only what the
  page lets you edit on a change (date, odometer, note, service-record
  link); deleting replays the rest, and is refused (409) when the page
  would refuse it. `EntryAccess`.
- `PATCH /vehicles/{id}/tyres/{tyre}`: a tyre's own details (brand,
  model, size, season, DOT code, notes), as its edit page. Status and
  position are never edited directly; they come from changes. `Manage`.
- Sets are created inline by `swap` and `remove` (name and storage), as on
  the forms.

### 7. Journeys, stations, price alerts, needs attention

- `POST /journeys`, `PATCH`, `DELETE /journeys/{id}` (scope
  `read_write`): the Settings → Trips journey form's fields, for the key's
  user only. Deleting a journey leaves the trips logged from it, as the
  page does. Module `trips`.
- `PUT`, `DELETE /stations/{id}/favourite` (scope `read_write`): the
  key user's favourite. Idempotent. Stations themselves are still created
  only by naming one on a fill-up.
- `POST /fuel-prices/alerts`, `PATCH`, `DELETE /fuel-prices/alerts/{id}`
  (scope `read_write`): the alert form's fields and limits (§7.34, #138).
- `POST /attention/{key}/hide`, `/unhide` (scope `read_write`): the
  *Needs attention* page's *Hide* and *Show again*, for the key's user
  (§6 AttentionHidden). Idempotent.

### 8. Finance (scope `read_write`, `Manage`, module `finance`)

- `POST /vehicles/{id}/finance/agreements`: the agreement form's fields
  (§6 FinanceAgreement, §7.32), with its derivations and its *one active
  agreement* rule (409 `finance_active_exists`). The agreement number is
  accepted on create and edit but **never returned**, as now.
- `PATCH /vehicles/{id}/finance/agreements/{agreement}`.
- `POST …/agreements/{agreement}/payments` (kind `missed`, `paid_late`,
  `extra`, `settlement`) and `POST …/agreements/{agreement}/quotes`, with
  `DELETE` for each, as the agreement page.
- Ending an agreement is **not** a separate endpoint: settling, completing
  and handing back happen through the agreement page's *End* action or
  `POST /vehicles/{id}/archive` with the matching disposal, as §7.32
  *Ending* describes. `POST …/agreements/{agreement}/end` mirrors the
  page's *End* for an agreement that ends without the vehicle leaving
  (settled early, completed).

### 9. Attachments

Owner types as §7.12: `fuel`, `maintenance`, `document`, `expense`,
`reading` (manual only), `valuation`, `purchase`, `sale`, `incident`,
`vehicle_photo`.

- `GET …/{entry}/attachments`: id, filename, content type, size,
  uploaded at, uploaded by (as the page names them), and a `download`
  link. `View`.
- `GET /attachments/{id}`: the file, through the same authenticated
  handler as the pages, so **incident photos follow §7.12 and #104**: the
  original only with `ViewIncidentDetails`, otherwise an upright,
  stripped copy made as it is served.
- `POST …/{entry}/attachments`: `multipart/form-data`, **one file per
  request** in the field `file` (open question F). The same content check,
  decode check, `MAX_UPLOAD_MB`, stripping (except incident photos), and
  the edit form's limits. `201` with the attachment. `Log` and
  `EntryAccess::canChange` on the entry, as the edit form.
- `DELETE /attachments/{id}`: as the page's delete link.

### 10. Webhooks for entries

A user can ask Logbook to tell another system when an entry changes, so a
dashboard or Node-RED flow refreshes without polling.

- **Settings → API keys → Webhooks** (`/settings/webhooks`): add a URL
  with a name and the events to send (`entry.created`, `entry.updated`,
  `entry.deleted`, `reminder.changed`; all by default). The signing secret
  is shown **once**, as a key's token is. Each webhook shows its last
  delivery status, time and error (redacted, 255 characters), with *Send
  test*, *Pause* and *Delete*.
- **Where it may send** is §7.11's *Where members' channels may send*:
  the same classes, the same always-refused ranges, resolved and pinned on
  save, test and every send. No new rules.
- **What triggers it:** any create, edit or delete of an entry on a
  vehicle the webhook's user can `View`, by any path (form, import, API,
  Ask draft, MCP). `reminder.changed` covers status changes (due,
  overdue, done, dismissed, reopened).
- **Payload** (open question E): `event`, `id` (unique per delivery),
  `occurred_at`, `vehicle_id`, `kind` (as the history feed's kinds),
  `entry_id`, and `links` (the API URLs to fetch it). **No entry
  contents and no amounts**: the receiver fetches with its own key, so the
  access rules are applied at that moment, never at send time.
- **Signing:** `X-Logbook-Signature: t=<unix time>,v1=<hex HMAC-SHA256 of
  "t.body" with the webhook's secret>`. `docs/api.md` shows how to verify
  it and reject old timestamps.
- **Delivery:** queued in the transaction that changes the entry and sent
  by the job scheduler (§7.30), never in the request. A failed delivery is
  retried with backoff (1, 5, 30 minutes, 2 and 6 hours). After **50
  consecutive failures** the webhook is paused and the user is told
  through their notification channels. Delivered or given up, a delivery
  row is removed after 7 days.
- **Switches:** `API_ENABLED=false` stops deliveries (queued ones wait).
  `WEBHOOKS_ENABLED` (default `true`) switches only this feature off. A
  disabled or deleted user's webhooks stop at once.
- **Tables:** `webhooks` (id, user_id, name, url, events, sealed secret,
  paused, last status fields, created/updated) and `webhook_deliveries`
  (id, webhook_id, event, payload, attempts, next_attempt_at, created_at).
  `webhooks` is in backups (secrets sealed as other secrets, so they need
  the same `SESSION_SECRET`); deliveries are not.

### Still not in the API

Replaces §7.20 *Not in this version*: OAuth and sessions; per-vehicle
keys; account and instance administration, sharing, transfer and Settings;
deleting a vehicle; Ask; places; imports, CSV exports, the sale pack and
print views; and editing a reading that an entry wrote (edit the entry).

---

## Decisions (and why)

- **One rule for writes: the form's parser and service.** Phase 18.2 and
  26.3 did this for creates, and it is why the API never disagrees with
  the pages. Edits and deletes follow it, so there is no second set of
  validation to keep in step.
- **Access is the pages' access.** Every endpoint declares the ability its
  page declares, and entry edits go through `EntryAccess`. A `Log` share
  can edit its own fill-up over the API exactly as on the page, and no
  further.
- **Reports from the report services.** The figures come from the
  services the Reports page and Ask's tools use, so all three agree to the
  penny.
- **Webhooks carry ids, not data.** Access can change between an entry
  being written and a webhook arriving (a share removed, *Can see costs*
  turned off). Sending ids and letting the receiver fetch with its key
  means the rules are applied when the data is read, and a leaked webhook
  payload reveals nothing but that something changed.
- **Outbound requests reuse the channel rules.** A user's webhook can
  reach exactly what a user's notification channel can. One set of rules,
  already reviewed for SSRF.
- **No delete for vehicles; no account administration.** Both are
  high-impact, rare, and better behind a confirmation page. Leaving them
  out costs automations nothing.
- **API stays v1.** Every change adds a path, a method, a query parameter
  or an optional header. Existing clients see the same responses.

---

## Tasks

### 39.0 Spec first
- [ ] `spec.md` §7.20 as *Spec changes*; §7.11 cross-reference for
      webhook destinations; §6 `Webhook` and `WebhookDelivery`; §9
      `WEBHOOKS_ENABLED`; §13 phase summary.
- [ ] Open questions A–H decided and logged in `open-questions.md`.
- [ ] `ROADMAP.md` Phase 39 row (📋).

### 39.1 Shared plumbing
- [ ] Route conventions: nested entry paths, `GET` one, `PATCH`, `DELETE`;
      `EntryGuard` used by API actions; 409 `reading_derived`.
- [ ] JSON input adapter: `PATCH` overlay onto the stored entry before the
      edit form's parser; `null` clears an optional field.
- [ ] `ETag` and `If-Match` handling (as decided in B).
- [ ] CORS: methods and `If-Match` in the preflight.

### 39.2 Reads
- [ ] Single-entry reads for every list.
- [ ] List filters (maintenance, documents, closed reminders).
- [ ] Schedules, valuations, ownership, history (vehicle and fleet).
- [ ] Reports: costs, cost per distance, fuel, mileage, with `excluded`.
- [ ] Tyre changes and sets; stations; price alerts; attention;
      finance agreements.

### 39.3 Writes
- [ ] Reminder actions; manual reminder edit and delete.
- [ ] Vehicles: create, edit, archive, restore.
- [ ] Valuations, schedules, tyre changes, tyre edit.
- [ ] Journeys, station favourites, price alerts, attention hide.
- [ ] Finance: agreements, payments, quotes, *End*.
- [ ] Edit and delete for fill-ups, readings, service records,
      documents, expenses, trips, incidents.

### 39.4 Attachments
- [ ] List, download (through the authenticated handler), upload
      (multipart, one file), delete; vehicle photo as `vehicle_photo`.

### 39.5 Webhooks
- [ ] Migration: `webhooks`, `webhook_deliveries` (reversible on every
      engine).
- [ ] Event recorder hooked into the entry services (one place per
      service, inside its transaction).
- [ ] Sender job on the scheduler: signing, pinned destination, backoff,
      pause after 50 failures with a notice to the user, 7-day cleanup.
- [ ] Settings page: add, events, secret shown once, status, *Send test*,
      *Pause*, *Delete* (confirmation page); works without JS.
- [ ] Backups: `webhooks` in, deliveries out; restore notes the
      `SESSION_SECRET` dependency, as for keys.

### 39.6 OpenAPI and docs
- [ ] `docs/api/openapi.json`: every new operation, schema and error
      code; `info.version` up one minor.
- [ ] `docs/api.md`: editing and deleting, reminders from Home Assistant
      (mark done from a notification), logging a valuation from a
      Shortcut, attachments with `curl`, webhooks with a Node-RED flow and
      signature checking in Python and JavaScript.
- [ ] Translations for every new page string, in every shipped locale.

### 39.7 Tests
- [ ] **Contract:** every new response validated against the OpenAPI
      description, success and error.
- [ ] **Access matrix:** for each new endpoint, owner, `manage`, `log`,
      `view` share with and without *Can see costs*, and a stranger; a
      `read` key on each write (403 `insufficient_scope`); a `log` share
      editing its own and someone else's entry.
- [ ] **Parity:** an edit and a delete over the API leave the database
      exactly as the page's form does (economy segments, schedules,
      reminders, readings, attachments) for each entry type.
- [ ] **Reports parity:** each report's totals equal the Reports page's
      for every demo vehicle and period.
- [ ] **Retries:** duplicate keys on the new creates; idempotent reminder
      actions, favourites and attention hiding.
- [ ] **Concurrency:** a stale `If-Match` answers 412 and writes nothing.
- [ ] **Archived vehicles:** every write refused (409) except restore and
      an allowed valuation.
- [ ] **Attachments:** content check, size limit, stripping, incident
      originals only with `ViewIncidentDetails`, the form's limits.
- [ ] **Webhooks:** every event from every path (form, import, API, Ask
      draft, MCP); payload has no amounts; signature verifies; refused
      destinations per §7.11's setting; retry schedule; pause after 50;
      a disabled user's webhooks stop; `API_ENABLED=false` and
      `WEBHOOKS_ENABLED=false` hold deliveries.
- [ ] Modules off: every path of that module 404.
- [ ] Suite green on SQLite, PostgreSQL, MySQL and MariaDB; coverage at
      or above the floor; smoke test at a subpath.

### 39.8 Release
- [ ] `VERSION` → the next **minor** version (additive API; one new
      page; one migration).
- [ ] `CHANGELOG.md`: *Added* — the endpoints by area, webhooks;
      *Upgrade notes* — the migration, `WEBHOOKS_ENABLED`, CORS now
      allows `PATCH` and `DELETE`.
- [ ] `.env.example` and §9 for `WEBHOOKS_ENABLED`.
- [ ] README and `ROADMAP.md` Phase 39 row ✅; tag once merged.

---

## Acceptance criteria

1. Everything in *The review* marked missing can be done over the API,
   apart from the items under *Still not in the API*.
2. Every write gives the same result, validation messages and warnings as
   the page's form, and every report the same figures as the Reports page.
3. No key can see or do more than its user can on the pages, and a `read`
   key can change nothing.
4. Retries never write twice; a stale edit is refused.
5. Webhooks arrive for every change, signed, carrying no entry data, and
   only to destinations a member's channel may reach.
6. Existing API clients work unchanged.
7. Definition of done (CLAUDE.md §11) holds.

## Open questions

Numbered in `open-questions.md` when logged. Not built until decided.

- **A. One phase or three?** This is the largest API change since 18.2.
  Options: (1) one phase, as written; (2) 39.1 reads and reminder actions,
  39.2 writes, edit and delete, 39.3 attachments, webhooks and release,
  each runnable, released together. *Recommendation:* (2), as Phases 26,
  29 and 36 were split; reminder actions and reads are useful on their
  own and carry the least risk.
- **B. Optimistic concurrency on edits.** Options: (1) none, last write
  wins as on the pages; (2) `ETag` on reads and `If-Match` **optional**
  on `PATCH` and `DELETE`, 412 on mismatch; (3) `If-Match` required.
  *Recommendation:* (2): safe for scripts that care, no burden on a
  Shortcut that doesn't.
- **C. `PATCH` meaning.** Options: (1) partial: only sent fields change,
  `null` clears an optional one; (2) `PUT` with the whole entry.
  *Recommendation:* (1); it matches how automations correct one field
  (the odometer typed wrong).
- **D. Vehicle create over the API.** Options: (1) yes, as written; (2)
  no, vehicles are set up on the pages. *Recommendation:* (1); importers
  and migrations from other apps need it, and it grants nothing the key's
  user can't already do.
- **E. Webhook payload.** Options: (1) ids and links only, as written;
  (2) the entry as the API returns it, filtered by the webhook user's
  access at send time. *Recommendation:* (1), for the reasons under
  *Decisions*; (2) could come later as an opt-in.
- **F. Attachment upload format.** Options: (1) multipart, one file per
  request; (2) multipart, several files (the form's `attachments[]`);
  (3) base64 in JSON. *Recommendation:* (1): simplest for `curl` and
  Shortcuts, and a failure affects one file.
- **G. Finance writes in this phase?** Options: (1) yes, as written; (2)
  read only stays, writes later. *Recommendation:* (1) for agreements,
  payments and quotes; the ending flows go through archive and *End*, which
  already hold the rules.
- **H. Proposed numbers.** 50 consecutive failures before a webhook is
  paused, 7 days of delivery rows, the retry backoff, and the ten-minute
  window in the vehicle create's duplicate key are proposals, not
  decisions. *Recommendation:* keep them; record them in
  §7.20 so they're tested values, not guesses in code.
