# Phase 39.3 — API attachments and entry webhooks + v3.5 release

*Files go in and out over the API, and other systems hear when an entry
changes.*

Status: ✅ complete · releases **v3.5.0** (Phases 39.1 to 39.3) · file
lives in `docs/phases/`

The last of Phase 39's three parts (#281). [Phase 39.1](phase-39.1.md)
has the review of what the API was missing, the goals and what stays out
of scope for the whole phase.

Read [`CLAUDE.md`](../../CLAUDE.md) and [`spec.md`](../../spec.md) first:
§6 Webhook and WebhookDelivery, §7.11 *Where members' channels may send*,
§7.12, §7.20 *Phase 39* (*Attachments* and *Webhooks*), §7.30, §9, and
Phase 27.1's incident-photo rules (#104).

**Prerequisites:** [Phase 39.2](phase-39.2.md) complete and green.

---

## Decisions (and why)

- **One file per upload** (#286): simplest for `curl` and Shortcuts, and a
  failure affects one file.
- **Downloads through the pages' handler**, so incident photos follow the
  same rules (#104) without a second copy of them.
- **Webhooks carry ids, not data** (#285). Access can change between an
  entry being written and a webhook arriving (a share removed, *Can see
  costs* turned off). Sending ids and letting the receiver fetch with its
  key means the rules are applied when the data is read, and a leaked
  payload reveals nothing but that something changed.
- **Outbound requests reuse the channel rules.** A user's webhook can
  reach exactly what a user's notification channel can. One set of rules,
  already reviewed for SSRF.
- **The numbers are spec values** (#288): 50 consecutive failures pause a
  webhook; retries after 1 min, 5 min, 30 min, 2 h, 6 h; delivery rows
  kept 7 days. Tests check them.
- **The secret isn't backed up** (#289), as channel secrets aren't: a
  restored webhook is paused with *Needs a new secret*.

---

## Tasks

### 39.3.1 Attachments
- [x] List per entry, download (the pages' authenticated handler), upload
      (multipart, field `file`, one file, the pages' checks and limits),
      delete; every owner type of §6 and §7.12, `trip` included (only for
      those who may see the trip), under each entry's API path (#300).
- [x] The vehicle photo on its own path: `GET`, `POST` (multipart) and
      `DELETE /vehicles/{id}/photo`, through the edit form's rules (#300).

### 39.3.2 Webhooks
- [x] Migration: `webhooks` (with `notice_pending`, #294),
      `webhook_deliveries` (reversible on every engine).
- [x] Event recorder hooked into the entry services (one place per
      service, inside its transaction), so every path (form, import,
      API, Ask draft, MCP) queues, with §7.20's kinds (#290, #301);
      vehicles and tyre details too; trips only for those who may see
      them (#302); cost kinds for everyone with `View` (#295);
      `reminder.changed` on status changes and a manual reminder's
      create, edit and delete; nothing from a restore or the demo reset.
- [x] `webhooks` job on the scheduler, every pass (#291): signing,
      pinned destination under §7.11's policy, backoff (minimums), pause
      after 50 consecutive failed attempts, reset by a success (#293),
      deliveries waiting while paused, 7-day cleanup; held by
      `WEBHOOKS_ENABLED=false` and `API_ENABLED=false`; a disabled or
      deleted user's webhooks stop.
- [x] Settings → API keys → Webhooks (`/settings/webhooks`): add, events,
      secret shown once (`Cache-Control: no-store`), status, *Send test*,
      *Pause*, *Resume* (failures back to 0), *New secret* (shown once),
      *Delete* (confirmation page), *Needs a new secret*; works without
      JS (#292).
- [x] The "webhook paused" notice: no category, every usable channel,
      held until quiet hours end, sent once (#294); translated.
- [x] Backups (§7.19): `webhooks` in without `secret`, restored paused
      (`restored`); deliveries out.

### 39.3.3 OpenAPI, docs, translations
- [x] `docs/api/openapi.json`: the attachment operations (multipart) and
      the webhook payload schema.
- [x] `docs/api.md`: attachments with `curl`; webhooks with a Node-RED
      flow and signature checking in Python and JavaScript.
- [x] Translations for every new page string, in every shipped locale.

### 39.3.4 Tests
- [x] **Contract:** every new response validated against the OpenAPI
      description, success and error.
- [x] **Access matrix:** attachments for owner, `manage`, `log`, `view`
      with and without *Can see costs*, and a stranger; a `read` key on
      upload and delete; a trip's files; the vehicle photo.
- [x] **Attachments:** content check, size limit, stripping, incident
      originals only with `ViewIncidentDetails`, the form's limits.
- [x] **Webhooks:** every event from every path (form, import, API, Ask
      draft, MCP); vehicles and tyre details; trips only to those who
      see them; nothing from a restore; payload has no amounts; signature
      verifies; refused destinations per §7.11's setting; retry
      schedule; pause after 50, reset by a success, *Resume*; the notice
      once, after quiet hours;
      a disabled user's webhooks stop; `API_ENABLED=false` and
      `WEBHOOKS_ENABLED=false` hold deliveries; backup and restore
      without the secret.
- [x] Migration applies and rolls back on SQLite, PostgreSQL, MySQL and
      MariaDB; upgrade from v3.4.0.
- [x] Suite green on every engine; coverage at or above the floor; smoke
      test at a subpath.

### 39.3.5 Release
- [x] `VERSION` → **3.5.0** (additive API; one new page; one migration).
- [x] `CHANGELOG.md`: *Added*, the endpoints by area (39.1–39.3, with
      the vehicle's new `disposal` and `ETag`, and `If-Match` on 39.2's
      edits and deletes) and webhooks; *Upgrade notes*, the migration, `WEBHOOKS_ENABLED`, CORS
      now allowing `PUT`, `PATCH`, `DELETE` and `If-Match`.
- [x] `.env.example`, `docs/configuration.md` (`WEBHOOKS_ENABLED`; the
      `API_CORS_ORIGINS` row's methods and `If-Match`), the compose files'
      pass-through, `docs/deployment.md` (the job's cadence, per #291),
      `docs/demo-mode.md` (webhooks off).
- [x] README and `ROADMAP.md` rows for 39.1–39.3 ✅.
- [ ] Tag v3.5.0 once merged.

---

## Acceptance criteria

1. Everything in [39.1's review](phase-39.1.md#the-review-what-the-api-is-missing)
   marked missing can be done over the API, apart from *Still not in the
   API*.
2. Attachments follow the pages' checks, limits and incident-photo rules.
3. Webhooks arrive for every change, signed, carrying no entry data, and
   only to destinations a member's channel may reach.
4. Existing API clients work unchanged.
5. Definition of done (CLAUDE.md §11) holds.


## Open questions

This sub-phase's questions (#285, #286, #288, #289) were decided on
2026-10-08; see [Phase 39.1](phase-39.1.md#open-questions). The rest were
decided on 2026-10-08, before 39.3 started:

- **Which entries fire webhooks, and with which `kind`?** (#290, found
  while starting 39.1) — *Decided 2026-10-08:* everything 39.2 can write
  on a vehicle, with the history feed's kind where it has one and new
  kinds where it has none (`tread_check`, `tyre_details`, `schedule`,
  `finance`, `vehicle`); attachments fire nothing of their own. spec
  §7.20 *Webhooks*.
- **#291 How often does the `webhooks` job run?** — *Decided
  2026-10-08:* every pass; the retry intervals are minimums, rounded up
  to the next pass; `docs/deployment.md` says how to make passes
  shorter. spec §7.30.
- **#292 How does a paused or restored webhook come back?** — *Decided
  2026-10-08:* *Resume* and *New secret* (shown once); a restored one
  needs a new secret before *Resume*; resuming sets the failures back
  to 0.
- **#293 What counts toward the 50 failures?** — *Decided 2026-10-08:*
  each failed attempt, first tries and retries alike, set back to 0 by
  any success. While paused, deliveries wait unattempted; on *Resume*
  those under 7 days old are sent. spec §6 Webhook, §7.20.
- **#294 Where does the "webhook paused" notice go?** — *Decided
  2026-10-08:* no category, to every usable channel the user has on, as
  the switched-off notice, held until quiet hours end, sent once (a new
  `notice_pending` column), and shown on the Webhooks page.
- **#295 Are events queued for a user without *Can see costs* on cost
  entries?** — *Decided 2026-10-08:* yes, ids and kind only; the fetch
  applies the rule.

Found while starting 39.3 (2026-10-08):

- **#300 Which attachment owner types?** §7.20 named `vehicle_photo` and
  left out `trip`, but a vehicle photo is not an attachment (§6). —
  *Decided 2026-10-08:* every real owner type, `trip` included (only for
  those who may see the trip); the photo on its own `GET`, `POST` and
  `DELETE /vehicles/{id}/photo`.
- **#301 Do non-entry writes fire?** — *Decided 2026-10-08:* vehicle
  create, edit, archive and restore (`vehicle`) and tyre details
  (`tyre_details`) fire `entry.*`; a manual reminder's create, edit and
  delete fire `reminder.changed` with `change` naming which.
- **#303 Who may download a cost entry's file?** (found while building)
  A share without *Can see costs* could fetch an expense receipt or a
  valuation's quote by id, on the API and the existing page route. —
  *Decided 2026-10-08:* only with *Can see costs*, or one's own upload, on
  both (404 otherwise).
- **#304 How many webhooks may a user have?** (found by the security
  review) One change queues a call per webhook, with no limit. —
  *Decided 2026-10-08:* 10 per user.
- **#305 Which other files show an amount?** (found by the security
  review) The purchase invoice proves the price the pages hide without
  *Can see costs*. — *Decided 2026-10-08:* purchase and sale paperwork
  follow #303's rule too (their list needs *Can see costs*); fuel and
  service files stay with their entry.
- **#302 Who hears about a trip?** — *Decided 2026-10-08:* only the
  users who may see it (its author, and those who see everyone's
  trips), as the history feed.
