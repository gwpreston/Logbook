# Phase 39.1 — API reads and reminder actions

*Everything a vehicle's pages show, an automation can read, and a
reminder can be marked done from a phone notification.*

Status: 🚧 in progress · no release of its own (**v3.5.0** ships with
[Phase 39.3](phase-39.3.md)) · file lives in `docs/phases/`

Phase 18.2 gave the API reads and two writes. Phases 22, 26.3, 27.1, 29.2,
30.2 and 32 each added a few endpoints for their own feature. That has left
gaps. Some were deliberate deferrals (spec §7.20 *Not in this version*).
Others crept in because later features never got an endpoint. Phase 39
closes them, so a Home Assistant automation, a Shortcut or a script can do
anything a vehicle's pages can do, under the same access rules.

Phase 39 was planned as one phase and split in three on 2026-10-08 (#281),
each runnable, released together as v3.5.0:

- **39.1** (this file): the reads and the reminder actions. Useful on
  their own, and the least risk.
- **[39.2](phase-39.2.md):** writes, edit and delete.
- **[39.3](phase-39.3.md):** attachments, entry webhooks and the release.

Read [`CLAUDE.md`](../../CLAUDE.md) and [`spec.md`](../../spec.md) first:
§5 (access policy, `EntryAccess`), §7.20 in full (its *Phase 39* block is
the contract for all three sub-phases), §7.1 (including valuations and
depreciation), §7.4, §7.6, §7.7, §7.16, §7.17, §7.24, §7.32, §7.33,
§7.34, and Phases 14.1, 14.2, 18.2 and 26.3.

**Prerequisites:** [Phase 38](phase-38.md) complete and green.

---

## The review: what the API is missing

What each area of the app does, and what the API could do with it before
Phase 39. The last column says which sub-phase closes the gap.

| Area | In the app | In the API before 39 | Missing | In |
|---|---|---|---|---|
| Vehicles (§7.1) | add, edit, photo, archive, restore, delete, transfer | list, read one | create, edit, archive, restore; photo | 39.2; photo 39.3 |
| Fill-ups, readings, service records, documents, expenses | add, edit, delete, attach files | list, add | read one, list filters (`category`, `q`, `type`, current documents); edit, delete | reads 39.1; edit, delete 39.2 |
| Maintenance schedules (§7.4) | add, edit, delete; *last done*, *next due* | none (only through *Coming up*) | everything | reads 39.1; writes 39.2 |
| Reminders (§7.6) | mark done, dismiss, reopen, edit, delete manual ones; closed ones listed | open ones listed, manual ones added | the three actions, closed reminders; edit, delete | 39.1; edit, delete 39.2 |
| Valuations (§7.1, Phase 14.1) | add, edit, delete, attach | none | everything | reads 39.1; writes 39.2; files 39.3 |
| Cost of ownership, depreciation (Phase 14.2) | overview card, Reports | summary only | ownership figures | 39.1 |
| Reports (§7.7) | costs by group, month and vehicle; cost per distance; fuel statistics; mileage | none (Ask and MCP have them as tools) | the four reports | 39.1 |
| History (§7.16) | per vehicle and fleet | none | both feeds | 39.1 |
| Tyres (§7.17) | fit, swap, rotate, repair, remove, existing, check; sets; edit a tyre or a change | tyre list, tread check | changes and sets (read); changes, tyre edit (write) | 39.1; 39.2 |
| Trips, journeys (§7.22) | trips add, edit, delete; journeys add, edit, delete | trips list and add; journeys list | trip read one; trip edit and delete; journey writes | 39.1; 39.2 |
| Incidents (§7.29) | add, edit, delete | list, add, history | read one; edit, delete | 39.1; 39.2 |
| Finance (§7.32) | agreement add and edit, payment events, settlement quotes | the active agreement, read only | every agreement (read); the writes | 39.1; 39.2 |
| Needs attention (§7.24) | the list; hide and unhide an item | none | the list; hide, unhide | 39.1; 39.2 |
| Stations (§7.33) | list, favourites | list (`?q=`, `?favourites=`), read one | favourite, unfavourite | 39.2 |
| Fuel prices (§7.34) | cheapest near me, price alerts | cheapest near me | price alerts (read; write) | 39.1; 39.2 |
| Attachments (§7.12) | upload, list, download, delete | none | everything | 39.3 |
| New-entry notifications | reminders only, through the channels | none | webhooks for entries | 39.3 |

*Corrected while starting (2026-10-08):* the plan listed `GET /stations` as
missing. It has existed since Phase 30.1 with `?q=` and `?favourites=`;
only the favourite writes are new.

Out of this review on purpose, and still left out (see *Not in scope*):
account and instance administration, sharing and transfer, deleting a
vehicle, Ask (MCP is the AI surface), places, imports, CSV exports and the
sale pack.

---

## Goals (Phase 39 as a whole)

1. **Read everything a vehicle's pages show:** single entries, schedules,
   valuations, ownership, reports, history, tyre changes and sets,
   needs attention, price alerts, finance agreements and closed
   reminders. *(39.1)*
2. **Act on reminders:** mark done, dismiss, reopen. *(39.1)*
3. **Write everything an entry form writes:** vehicles, valuations,
   schedules, tyre changes, journeys, finance, price alerts, attention
   hiding, station favourites. *(39.2)*
4. **Edit and delete every entry** through the API, under `EntryAccess`,
   with optional optimistic concurrency. *(39.2)*
5. **Attachments:** list, download, upload and delete. *(39.3)*
6. **Webhooks** for entries created, changed and deleted. *(39.3)*
7. OpenAPI, `docs/api.md` guides, and a minor release (v3.5.0). The API
   stays `v1`: every change is additive. *(each; release 39.3)*

## Not in scope (Phase 39 as a whole)

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
  are in (39.2).
- **Ask over the API.** The MCP server (§7.28) is how an assistant uses
  Logbook. `/me` keeps leaving out the AI modules.
- **Places.** Private to their user and never in the API, Ask or MCP
  (Phase 30.1). Unchanged.
- **Imports, CSV exports, the sale pack and print views.** They are file
  workflows on pages. The API's JSON already carries the same data.
- **Automatic valuation.** The Phase 14.1 non-goal stands: Logbook never
  fetches a value or sends a registration anywhere.
- **New MCP tools** for the new writes. A follow-up can map them, as Phase
  26.5 mapped the Phase 26.3 writes.

---

## Spec

Written into `spec.md` §7.20 *Phase 39: the rest of the API* on
2026-10-08, before any code, for all three sub-phases, with §6 Webhook and
WebhookDelivery, the §7.11 cross-reference, the §7.30 `webhooks` job, §9
`WEBHOOKS_ENABLED`, a §12 note separating #168's webhook *format* from
entry webhooks, and §13. *Not in this version* is replaced by *Still not
in the API*.

This sub-phase builds §7.20's *Conventions* that reads need (nested paths,
read one with `ETag`, modules, amounts, the minor `info.version` bump),
*New reads* and *Reminder actions*.

---

## Decisions (and why)

- **One rule for writes: the form's parser and service.** Phase 18.2 and
  26.3 did this for creates, and it is why the API never disagrees with
  the pages. Reminder actions here go through the Reminders page's
  service; edits and deletes in 39.2 follow the same rule.
- **Access is the pages' access.** Every endpoint declares the ability its
  page declares, and entry edits go through `EntryAccess`.
- **Reports from the report services.** The figures come from the
  services the Reports page and Ask's tools use, so all three agree to the
  penny.
- **`ETag` lands with the reads** (#282), so a client written against
  39.1 already holds the tags 39.2's `If-Match` checks.
- **API stays v1.** Every change adds a path, a method, a query parameter
  or an optional header. Existing clients see the same responses.
  `info.version` moves one minor version here, once for Phase 39.

---

## Tasks

### 39.1.0 Spec first
- [x] `spec.md` §7.20 *Phase 39* block (all of Phase 39); §6 `Webhook`
      and `WebhookDelivery`; §7.11 cross-reference; §7.30 `webhooks` job;
      §9 `WEBHOOKS_ENABLED`; §12 note on #168; §13 summaries for 39.1–39.3.
- [x] Open questions A–H and the one found while starting decided and
      logged in `open-questions.md` (#281–#289); #221, #279 and #280
      reviewed and carried.
- [x] Phase split into 39.1, 39.2 and 39.3; `ROADMAP.md` rows and
      sections.

### 39.1.1 Shared plumbing for reads
- [ ] Route conventions: nested entry paths (`/vehicles/{id}/{list}/{entry}`),
      an entry of another vehicle answering 404; an `EntryGuard` (or the
      existing equivalent) that loads an entry under its vehicle for API
      actions, reused by 39.2.
- [ ] `ETag` on single-entry reads: a hash of the entry's own stored
      columns (no derived or viewer-dependent fields); no
      `If-None-Match`; `ETag` in CORS `Access-Control-Expose-Headers`.
- [ ] OpenAPI `info.version` up one minor (the only bump in Phase 39).

### 39.1.2 Reads
- [ ] Single-entry reads for fuel, odometer, maintenance, documents,
      expenses, trips and incidents, each exactly as its list returns it.
- [ ] List filters: maintenance `?category=` and `?q=`; documents `?type=`
      and `?current=1` (the owner's time zone).
- [ ] Schedules (list and one), with status and the projected date of the
      distance limit.
- [ ] Valuations (list, paged, and one); ownership (`ViewCosts`, 403
      without), with `display` strings.
- [ ] History: per vehicle and fleet, `?kinds=`, `?since=`, `?until=`,
      cursor paging, amounts per `canSeeAmount`.
- [ ] Reports: costs (`?group_by=`), cost per distance, fuel, mileage,
      with `excluded` for vehicles without `ViewCosts`.
- [ ] Tyre changes and sets (vehicle and fleet).
- [ ] Closed reminders: `?status=done|dismissed`, `?closed=1`.
- [ ] *Needs attention*, with each item's `key`, its fix's API link, and
      `?hidden=1`.
- [ ] Price alerts (read); finance agreements (every one, with payment
      events and quotes, never the number).

### 39.1.3 Reminder actions
- [ ] `POST /reminders/{id}/done`, `/dismiss`, `/reopen` through the
      Reminders page's service, `Log`; any source; `"unchanged": true`
      on a repeat, writing nothing.

### 39.1.4 OpenAPI, docs, translations
- [ ] `docs/api/openapi.json`: every operation, schema and error code of
      this sub-phase.
- [ ] `docs/api.md`: reading one entry and its `ETag`; reports; history;
      marking a reminder done from a Home Assistant notification.
- [ ] Translations for any new string (the `display` strings and
      *Needs attention* words reuse the pages' keys), in every shipped
      locale.

### 39.1.5 Tests
- [ ] **Contract:** every new response validated against the OpenAPI
      description, success and error.
- [ ] **Access matrix:** for each new endpoint, owner, `manage`, `log`,
      `view` share with and without *Can see costs*, and a stranger; a
      `read` key on the reminder actions (403 `insufficient_scope`); a
      trip the key's user may not see read by id (404); an incident read
      by id without `ViewIncidentDetails` (details left out).
- [ ] **Reports parity:** each report's totals equal the Reports page's
      for every demo vehicle and period.
- [ ] **Retries:** a repeated reminder action is `unchanged` and writes
      nothing.
- [ ] **Time zones:** documents `?current=1` and report periods at the
      day boundary in a non-UTC owner zone.
- [ ] Modules off: every new path of that module 404.
- [ ] Suite green on SQLite, PostgreSQL, MySQL and MariaDB; coverage at
      or above the floor; smoke test at a subpath.

---

## Acceptance criteria

1. Every read in §7.20 *New reads* and the three reminder actions work as
   specified; nothing marked 39.2 or 39.3 is needed for them.
2. Every report gives the same figures as the Reports page.
3. No key can see more than its user can on the pages, and a `read` key
   can't act on a reminder.
4. A repeated reminder action writes nothing.
5. Existing API clients work unchanged.
6. Definition of done (CLAUDE.md §11) holds.

## Open questions

Phase 39's questions, decided 2026-10-08 before 39.1 started (logged as
#281–#289 in `open-questions.md`):

- **A. One phase or three?** — *Decided 2026-10-08 (#281):* three, each
  runnable, released together: 39.1 reads and reminder actions, 39.2
  writes, edit and delete, 39.3 attachments, webhooks and release.
- **B. Optimistic concurrency on edits.** — *Decided 2026-10-08 (#282):*
  `ETag` on reads (39.1) and `If-Match` **optional** on `PATCH` and
  `DELETE`, 412 on mismatch (39.2).
- **C. `PATCH` meaning.** — *Decided 2026-10-08 (#283):* partial; only
  sent fields change, `null` clears an optional one. In 39.2.
- **D. Vehicle create over the API.** — *Decided 2026-10-08 (#284):* yes.
  In 39.2.
- **E. Webhook payload.** — *Decided 2026-10-08 (#285):* ids and links
  only. In 39.3.
- **F. Attachment upload format.** — *Decided 2026-10-08 (#286):*
  multipart, one file per request. In 39.3.
- **G. Finance writes in this phase?** — *Decided 2026-10-08 (#287):* yes,
  agreements, payments, quotes and *End*. In 39.2.
- **H. Proposed numbers.** — *Decided 2026-10-08 (#288):* kept and
  written into §7.20: 50 consecutive failures, 7 days of delivery rows,
  retries after 1 min, 5 min, 30 min, 2 h and 6 h, and a 10-minute
  duplicate window for vehicle create.
- **Is a webhook's signing secret in backups?** (found while starting) —
  *Decided 2026-10-08 (#289):* no, as channel secrets; a restored webhook
  is paused with *Needs a new secret*. In 39.3.

Still open, for 39.3 (none of them blocks 39.1 or 39.2; see
[Phase 39.3](phase-39.3.md#open-questions)): #290, found while starting,
and #291–#295, raised by the spec review of 2026-10-08.
