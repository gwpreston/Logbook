# Phase 39.3 — API attachments and entry webhooks + v3.5 release

*Files go in and out over the API, and other systems hear when an entry
changes.*

Status: 📋 planned · releases **v3.5.0** (Phases 39.1 to 39.3) · file
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
- [ ] List per entry, download (the pages' authenticated handler), upload
      (multipart, field `file`, one file, the pages' checks and limits),
      delete; every owner type of §7.12, including `vehicle_photo`.

### 39.3.2 Webhooks
- [ ] Migration: `webhooks`, `webhook_deliveries` (reversible on every
      engine).
- [ ] Event recorder hooked into the entry services (one place per
      service, inside its transaction), so every path (form, import,
      API, Ask draft, MCP) queues; `reminder.changed` on status changes.
- [ ] `webhooks` job on the scheduler: signing, pinned destination under
      §7.11's policy, backoff, pause after 50 failures with a notice
      through the user's channels, 7-day cleanup; held by
      `WEBHOOKS_ENABLED=false` and `API_ENABLED=false`; a disabled or
      deleted user's webhooks stop.
- [ ] Settings → API keys → Webhooks (`/settings/webhooks`): add, events,
      secret shown once (`Cache-Control: no-store`), status, *Send test*,
      *Pause*, *Delete* (confirmation page), *Needs a new secret*; works
      without JS.
- [ ] Backups: `webhooks` in without `secret`, restored paused
      (`restored`); deliveries out.

### 39.3.3 OpenAPI, docs, translations
- [ ] `docs/api/openapi.json`: the attachment operations (multipart) and
      the webhook payload schema.
- [ ] `docs/api.md`: attachments with `curl`; webhooks with a Node-RED
      flow and signature checking in Python and JavaScript.
- [ ] Translations for every new page string, in every shipped locale.

### 39.3.4 Tests
- [ ] **Contract:** every new response validated against the OpenAPI
      description, success and error.
- [ ] **Access matrix:** attachments for owner, `manage`, `log`, `view`
      with and without *Can see costs*, and a stranger; a `read` key on
      upload and delete.
- [ ] **Attachments:** content check, size limit, stripping, incident
      originals only with `ViewIncidentDetails`, the form's limits.
- [ ] **Webhooks:** every event from every path (form, import, API, Ask
      draft, MCP); payload has no amounts; signature verifies; refused
      destinations per §7.11's setting; retry schedule; pause after 50;
      a disabled user's webhooks stop; `API_ENABLED=false` and
      `WEBHOOKS_ENABLED=false` hold deliveries; backup and restore
      without the secret.
- [ ] Migration applies and rolls back on SQLite, PostgreSQL, MySQL and
      MariaDB; upgrade from v3.4.0.
- [ ] Suite green on every engine; coverage at or above the floor; smoke
      test at a subpath.

### 39.3.5 Release
- [ ] `VERSION` → **3.5.0** (additive API; one new page; one migration).
- [ ] `CHANGELOG.md`: *Added*, the endpoints by area (39.1–39.3) and
      webhooks; *Upgrade notes*, the migration, `WEBHOOKS_ENABLED`, CORS
      now allowing `PUT`, `PATCH`, `DELETE` and `If-Match`.
- [ ] `.env.example` for `WEBHOOKS_ENABLED`.
- [ ] README and `ROADMAP.md` rows for 39.1–39.3 ✅; tag once merged.

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
2026-10-08; see [Phase 39.1](phase-39.1.md#open-questions). Still open
(found while starting 39.1, #290; to decide before 39.3 starts):

- **Which entries fire webhooks, and with which `kind`?** §7.20 says an
  entry on a vehicle fires, with `kind` from the history feed's kinds,
  but schedules, valuations, tyre changes, tyre sets, finance events
  and attachments may not be history kinds. Options: (1) the history
  feed's kinds only; (2) everything 39.2 can write, with new kinds where
  the feed has none; (3) (2) without attachments.
