# Phase 39.2 — API writes, edit and delete

*What an entry form writes, a key can write; what the pages let you
correct or remove, a key can correct or remove.*

Status: 🚧 in progress · no release of its own (**v3.5.0** ships with
[Phase 39.3](phase-39.3.md)) · file lives in `docs/phases/`

The second of Phase 39's three parts (#281). [Phase 39.1](phase-39.1.md)
has the review of what the API was missing, the goals and what stays out
of scope for the whole phase.

Read [`CLAUDE.md`](../../CLAUDE.md) and [`spec.md`](../../spec.md) first:
§5 (`EntryAccess`), §7.20 *Phase 39* (*Conventions* and *Writes, edits and
deletes*), §7.1, §7.4, §7.6, §7.17, §7.21, §7.22, §7.24, §7.29, §7.32,
§7.33, §7.34, and Phases 14.1, 18.2 and 26.3.

**Prerequisites:** [Phase 39.1](phase-39.1.md) complete and green (its
nested routes, entry guard and `ETag`).

---

## Decisions (and why)

- **The form's parser and service** for every write, edit and delete, as
  Phases 18.2 and 26.3 did for creates, so there is no second set of
  validation to keep in step.
- **`PATCH` is partial** (#283): sent fields are laid over the stored
  entry, `null` clears an optional one, then the edit form's parser runs.
  It matches how automations correct one field (the odometer typed wrong).
- **`If-Match` is optional** (#282): a stale tag answers 412 and writes
  nothing; a Shortcut that sends none gets the pages' last-write-wins.
- **Vehicle create is in** (#284): importers and migrations from other
  apps need it, and it grants nothing the key's user can't already do.
  Deleting a vehicle stays out.
- **Finance writes are in** (#287): agreements, payment events, quotes and
  *End*. Endings where the vehicle leaves go through archive, which
  already holds the rules.
- **No delete for vehicles; no account administration.** Both are
  high-impact, rare, and better behind a confirmation page.

---

## Tasks

### 39.2.1 Shared plumbing for writes
- [x] JSON input adapter: `PATCH` overlay onto the stored entry before the
      edit form's parser; `null` clears an optional field; unknown fields
      refused.
- [x] `If-Match` on `PATCH` and `DELETE`: 412 `precondition_failed`,
      nothing written.
- [x] 409 `reading_derived` with `links.entry`; 409 `vehicle_archived`
      on every write but restore and an allowed valuation.
- [x] CORS preflight: `PUT, PATCH, DELETE` and `If-Match`.

### 39.2.2 Edit and delete entries
- [x] `PATCH` and `DELETE` for fill-ups, readings (manual only), service
      records, documents, expenses, trips and incidents, through the edit
      and delete services, under `EntryAccess::canChange`.
- [x] Manual reminders: `PATCH`, `DELETE` (`Manage`); other sources 409
      `reminder_not_manual`.
- [x] Page edits keep unchanged converted values (spec.md §8 *Units*):
      the fill-up, reading, service record, document, incident, tyre
      change and trip edit forms keep the stored km, litres and price per
      litre when the submitted value equals what the form showed, so a
      miles or gallons user saving a notes-only edit changes no stored
      column (found while building the parity tests). Regression test per
      form.

### 39.2.3 New writes
- [x] Vehicles: create (duplicate key over 10 minutes), edit, archive
      (each disposal, through the archive page's service), restore.
- [x] Valuations: create, edit, delete; allowed on an archived vehicle
      within the sale-date rule.
- [x] Schedules: create (duplicate key), edit, delete (records kept).
- [x] Tyre changes: create (replayed, every kind), edit, delete (replay,
      409 where the page refuses); tyre details edit.
- [x] Journeys: create, edit, delete (trips kept).
- [x] Station favourites (`PUT`, `DELETE`, idempotent); price alerts
      (create, edit, delete, the form's limits); attention hide
      (idempotent; no unhide, #296).
- [x] Finance: agreements (create with the one-active rule, edit; the
      number never returned), payment events and settlement quotes
      (create and delete for each), *End*.
- [x] Shared with the pages so the rules can't drift: the *Archive*
      page's rules as `Service\Vehicle\VehicleArchiving`, the agreement
      page's payment, quote and *End* rules as `Service\Finance\FinanceEvents`
      (both read numbers in "en" for the API).
- [x] `GET /vehicles/{id}` gains `disposal` and an `ETag`; every `PATCH`
      answers with the object's new `ETag`, and `If-Match` applies to every
      `PATCH` and `DELETE` of a stored object (favourites and *Hide* ignore it).

### 39.2.4 OpenAPI, docs, translations
- [x] `docs/api/openapi.json`: every operation, schema and error code of
      this sub-phase (under 39.1's `info.version`).
- [x] `docs/api.md`: editing and deleting with `If-Match`; logging a
      valuation from a Shortcut; creating a vehicle from an importer.
- [x] Translations for every new string, in every shipped locale.

### 39.2.5 Tests
- [ ] **Contract:** every new response validated against the OpenAPI
      description, success and error.
- [ ] **Access matrix:** each new endpoint for owner, `manage`, `log`,
      `view` share with and without *Can see costs*, and a stranger; a
      `read` key on each write (403 `insufficient_scope`); a `log` share
      editing its own and someone else's entry.
- [ ] **Parity:** an edit and a delete over the API leave the database
      exactly as the page's form does (economy segments, schedules,
      reminders, readings, attachments) for each entry type.
- [ ] **Retries:** duplicate keys on vehicles, valuations and schedules;
      idempotent favourites and attention hiding.
- [ ] **Concurrency:** a stale `If-Match` answers 412 and writes nothing.
- [ ] **Archived vehicles:** every write refused (409) except restore and
      an allowed valuation.
- [ ] Modules off: every path of that module 404.
- [ ] Suite green on SQLite, PostgreSQL, MySQL and MariaDB; coverage at
      or above the floor; smoke test at a subpath.

---

## Acceptance criteria

1. Every write in §7.20 *Writes, edits and deletes* works as specified.
2. Every write gives the same result, validation messages and warnings as
   the page's form.
3. No key can do more than its user can on the pages, and a `read` key
   can change nothing.
4. Retries never write twice; a stale edit is refused.
5. Existing API clients work unchanged.
6. Definition of done (CLAUDE.md §11) holds.

## Open questions

This sub-phase's questions (#282–#284, #287, #288) were decided on
2026-10-08; see [Phase 39.1](phase-39.1.md#open-questions). #296 (decided
2026-10-08): no `unhide`; the API hides only, as the pages.

- **#298** (found while starting, decided 2026-10-08): a fill-up `PATCH`
  sending only one of volume, price per unit and total keeps the other
  two as stored, as the edit form does; nothing is re-derived. spec
  §7.20 *Conventions*.
- **#299** (found while building, decided 2026-10-08): `POST
  …/payments` takes `missed`, `paid_late` and `extra`, as the page;
  `settlement` answers 422, because a settlement is recorded by
  `…/end` with outcome `settled`, which also ends the agreement. spec
  §7.20 *Writes, edits and deletes*.
