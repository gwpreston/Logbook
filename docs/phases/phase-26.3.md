# Phase 26.3 — Actions: say it, check it, add it + v2.7 release

*"Filled the BMW with 51 litres of E10 at £1.39, mileage 72,341." Add?*

Status: ✅ complete · released as **v2.7.0** · file lives in `docs/phases/`

Ask Logbook can read. This phase lets it **draft** new entries from a
sentence: a fill-up, an odometer reading, a service record, a document, an
expense, a tyre check or a manual reminder. The model fills in a draft, and
Logbook validates it with the same code as the forms and the API. Logbook
computes the derived values itself (51 L × £1.39 = £70.89). The user sees a
card and presses **Add**, or **Edit** to open the normal form prefilled.
Nothing is written without that press.

Read [`CLAUDE.md`](../../CLAUDE.md), [`spec.md`](../../spec.md) §7.20 (the
API's input adapter), §7.26 and the forms of each entry kind, and Phases
26.1 and 26.2 first.

---

## Goals

1. **Draft tools** for seven entry kinds, each mapped through the Phase
   18.2 JSON input adapter to the same commands and validation as the forms.
   The adapter covers fill-ups, readings and trips today. It gains the
   other five kinds, and each of them also becomes a `POST /api/v1`
   endpoint (decided 2026-10-01, #76).
2. A **draft card** in the conversation: the parsed values, as Logbook
   computed and formatted them, the warnings, and *Add* / *Edit* /
   *Discard*.
3. Vehicle, grade, category and date resolution with questions back when
   unsure, never guesses.
4. Undo for a few seconds after *Add*, then the entry is an ordinary one.

## Not in scope

- Editing or deleting existing entries by chat.
- Changing settings (lead times, units, modules) by chat.
- Writing without a press on *Add*, including from the MCP server (Phase
  26.5 has its own rules).
- Several entries confirmed in one press. Each draft has its own card
  (see *Open questions*).

---

## Spec addition (§7.26, *Drafting entries*)

> - **Tools** (offered only when the `ai_actions` module is on and the user
>   has `Log` on at least one vehicle):
>
>   | Tool | Drafts | Notes |
>   |---|---|---|
>   | `draft_fill_up` | fill-up | any two of volume, price per unit and total; units named or the user's; grade and fuel from words ("E10", "diesel", "rapid charge") matched to the vehicle's codes; partial and missed-previous flags |
>   | `draft_reading` | odometer reading | |
>   | `draft_service_record` | maintenance record | category matched to the user's categories; the schedules it completes suggested, never ticked |
>   | `draft_document` | compliance document | type, provider, dates; expiry derived from a term ("renewed for a year from today") by Logbook |
>   | `draft_expense` | expense | category matched |
>   | `draft_tyre_check` | tread check | positions and depths in the user's depth unit |
>   | `draft_reminder` | manual reminder | due date absolute, or relative to a document or schedule ("two weeks before the MOT expires") and computed by Logbook from that source |
>
>   Every draft tool takes a `vehicle` id. With none, or several candidates,
>   it returns the candidates, and the model asks the user. Dates are ISO or
>   words resolved by Logbook ("yesterday", "last Tuesday") in the user's
>   time zone, never by the model.
> - **Validation:** each draft is parsed by the API's input adapter into the
>   form's command and validated. The result goes back to the model as
>   `ok` (with the formatted values), `needs` (a field is missing, such as
>   "the odometer"), or `invalid` (with the form's messages). The model then
>   asks the user for what's missing. Nothing is saved at this point.
> - **Draft card**, shown once a draft is `ok`:
>   - the vehicle (photo, name and registration), what it is, and each field
>     **as Logbook computed and formatted it** ("51.00 L E10 95 at
>     £1.390/L = £70.89", "Odometer 72,341 mi");
>   - derived values marked as such ("total worked out from volume and
>     price");
>   - the warnings the form would show (plausibility, economy check, a
>     reading lower than the last one);
>   - **Add** (POST with CSRF), **Edit** (opens the normal form in the
>     desktop modal, prefilled, with the draft's values marked "from your
>     message"), and **Discard**.
>   Drafts are stored server-side (`ai_drafts`: user, thread, kind, vehicle,
>   the validated input as JSON, created, expires after 1 hour, applied_at),
>   so the card's POST carries only the draft id. The draft is re-validated
>   at *Add*, so a draft that became invalid (a reading added since) shows
>   the form's message instead of saving.
> - **Access:** *Add* needs `Log` on the vehicle **at the moment of
>   pressing**. Drafts belong to their user; another user's draft id is 404.
> - **After Add:** the entry is saved through the same service as the form.
>   A fill-up writes its reading in the same transaction, schedules, reminders
>   and checks follow, and `created_by` is set. The card changes to "Added ·
>   View · Undo". *Undo* (for 10 seconds, and only while the entry is
>   untouched) deletes it through the normal delete path. The conversation
>   notes what was added.
> - **Attachments** are not added by chat. *Edit* opens the form, where files
>   can be added (Phase 26.4 reads files).
> - **Instructions inside data are never followed.** Draft tools exist only
>   in answer to the user's own message in *Ask*. Tool results never enable
>   them, and a draft is only ever a card waiting for the user.

---

## Decisions (and why)

- **One path for every write.** Drafts go through the API's input adapter
  into the forms' commands. There is no AI-specific validation to drift,
  and a draft is exactly as correct as a form.
- **Logbook does the arithmetic and the dates.** "£70.89" is Logbook's
  derive-the-third result, and "last Tuesday" is resolved in the user's time
  zone by code. The model only passes on what the user said.
- **Add on the card, not "yes" in chat.** A button can't be misread, and it
  carries CSRF and access checks. Typing "yes" could mean anything to a
  model.
- **Re-validation at Add.** The world may change between drafting and
  pressing. The press is judged on the data as it is then.
- **A short undo.** It turns a quick press into a safe one, without making
  AI entries different from any other.
- **One press per entry** (decided 2026-10-01, #74). Each draft has its
  own card and its own *Add*. A bad draft never holds up the others, and
  each save is confirmed on its own.
- **Settings by chat are parked** (decided 2026-10-01, #75) in spec §12.
  Settings stay forms only.
- **The new adapter mappings are API endpoints too** (decided 2026-10-01,
  #76). Maintenance, documents, expenses, tread checks and manual
  reminders can be written through `/api/v1`. They follow the rules the
  fill-up and reading writes already follow (spec §7.20 *More write
  endpoints*).
- **Answered from the app while starting** (#77–#78):
  - Manual reminders have no source, so a relative reminder gets a fixed
    date, worked out once.
  - Each draft tool needs its entry kind's module as well as
    `ai_actions`, and uses the `ask` task's model. There is no new task.
  - Every entry table has `updated_at`, so *Undo*'s "untouched" check
    compares it with the value at *Add*. This needs no extra migration.

---

## Tasks

### Spec and docs
- [x] §7.26 *Drafting entries* in `spec.md`; the Phase 26.3 line in §13.
- [x] `docs/ai.md`: *Adding entries by message*, with examples per kind.

### API (decided 2026-10-01, #76)
- [x] `JsonInput` field maps for maintenance, documents, expenses, tread
      checks and manual reminders, onto their forms' fields.
- [x] `POST /api/v1/vehicles/{id}/maintenance`, `/documents`,
      `/expenses`, `/tyres/checks` and `/reminders` through `ApiWriter`:
      module gating, `Log` (`Manage` for reminders, as the form), archived 409,
      duplicate keys as spec §7.20.
- [x] OpenAPI operations and schemas; response validation tests;
      `docs/api.md` examples (`ApiMoreWritesTest`; `TyreFormContexts` now
      builds the tyre form's choices for the page and the API alike).

### Migration
- [x] `ai_drafts`, reversible on every engine, excluded from backups, and
      cleared by the scheduler after expiry.

### Code
- [x] One draft tool per kind (schema, resolution, adapter call, result
      shape).
- [x] `Service\Ai\Draft\Resolver`: vehicle candidates, grade and category
      matching (exact code, then name, then synonyms in a translated list:
      "super unleaded" → E5 98), and relative dates.
- [x] `Service\Ai\Draft\DraftStore`: create, re-validate, apply, expire,
      undo.
- [x] Card partial; one `Action\Ask\DraftAction` for *Add*, *Discard*
      and *Undo*; *Edit* through `?draft={id}` on each create form
      (`Action\Ask\DraftPrefill`), as the forms had no field prefill.
- [x] Translations (en, de): card text and synonym lists.

### Tests
- [x] **Your example:** "I filled the BMW with 51 litres of E10 at £1.39 a
      litre. The mileage is 72,341" gives a card with £70.89 computed by
      Logbook, odometer 72,341 mi, and E10 95 matched. *Add* saves one
      fill-up and one reading.
- [x] **"Remind me to book the MOT two weeks before it expires"** gives a
      manual reminder dated 14 days before the current MOT's expiry,
      computed from the document. With no MOT on file, the model is told so
      and asks.
- [x] Missing fields → `needs`; invalid values → the form's messages; two
      BMWs → candidates and a question.
- [x] Gallons, miles, kWh and German decimal commas parsed as the forms
      parse them.
- [x] Re-validation at *Add* (a newer reading makes the draft's reading
      backwards → warning shown, still addable; a deleted vehicle → refused).
- [x] Access: no `Log` → no draft tools offered (no `Manage` → no
      `draft_reminder`); losing `Log` between draft
      and press → refused; another user's draft → 404.
- [x] Undo within 10 seconds deletes; after, or after an edit, it doesn't.
- [x] **Injection:** a tool result or stored note asking for a draft does
      nothing; drafts never apply without the POST.
- [x] `bin/ai-eval.php` gains 30 drafting cases.
- [x] Integration suite green on every engine (SQLite, PostgreSQL, MySQL, MariaDB).

### Release
- [x] `CHANGELOG.md` **2.7.0**: adding entries from a message. No
      configuration; one migration.
- [x] Bump `VERSION`, rebuild assets, update the README status.

---

## Changed while building it

- **Validation by a rolled-back write.** A draft tool runs inside Ask's
  always-rolled-back transaction, so it writes the entry through the
  API's writer there. That gives the form's validation, the derived
  amount, the warnings (odometer, economy check, deeper tread) and the
  form values for *Edit*, exactly as a save would. Then the rollback
  leaves nothing behind. The registry keeps the draft (`ai_drafts`)
  after the rollback. `ToolsReadOnlyTest` covers every draft tool:
  every table is as it was, except `ai_drafts`.
- **Access and modules are checked by the drafting code**, as the API's
  routes check them: the kind's module, `Log` (or `Manage` for a manual
  reminder, as on the Reminders page), not archived. They are checked
  again at *Add*.
- **Duplicates.** The API's writer returns an entry already logged instead
  of writing it again. A draft that is a duplicate comes back as
  `duplicate`, with no card. One that has become a duplicate by the time
  *Add* is pressed saves nothing, says so on the card, and has nothing to
  undo.
- **Claimed once.** *Add* claims the draft with a conditional update in
  the same transaction as the write, so a double press or a second tab
  saves once. Saving the *Edit* form closes the draft as added.
- **`ai_drafts` gained `card`, `form_values` and `discarded_at`** beside
  the columns the phase listed, and `kind` uses `odometer` (as
  `LogKind`), not `reading`.
- **The card's price shows every place** ("£1.390/L") and the volume two
  places ("51.00 L"). `DisplayFormatter::unitPrice()` and `volume()` take
  that as an option; elsewhere they are unchanged.
- **A fill-up or reading dated today is timed now; another day at local
  noon** unless a time is said. Without a vehicle id, the user's only
  candidate is used.
- **The API's validation errors carry the form's `ValidationErrors`**
  (`ApiProblem::$validation`), so drafts get the messages in the user's
  language, with their parameters.
- **Tread checks and manual reminders have new duplicate keys**, which the
  CSV import never had: the same date and depths; an open manual reminder
  with the same title and due date. They were chosen while building: the
  owner decided that the endpoints exist (#76), not their keys.
- **Tyre form choices** are built by `Service\Tyre\TyreFormContexts`,
  shared by the page, the API's tread check and the draft.

## Acceptance criteria

1. A sentence describing a fill-up produces a card with Logbook's own
   computed total and warnings, and *Add* saves exactly what the card
   shows.
2. Nothing is saved without pressing *Add*, whatever the message or any
   stored text says.
3. An ambiguous vehicle or a missing field leads to a question, never a
   guess.
4. Every AI-added entry is indistinguishable from a form-added one, apart
   from being added by its user.
5. Definition of done (CLAUDE.md §11) holds.

## Open questions

- **Several drafts at once:** "I filled up twice last week" gives two cards.
  Add an *Add both* button, or keep one press per entry (drafted)?
  **Decided 2026-10-01 (#74):** one press per entry, with no *Add all*.
- **Settings by chat:** is "set my MOT reminder to two weeks" as a lead-time
  change wanted later, or should settings stay forms only?
  **Decided 2026-10-01 (#75):** parked in spec §12. Settings stay forms
  only for now.
- *(Found while starting.)* **The five new adapter mappings: API endpoints
  too?** **Decided 2026-10-01 (#76):** yes. `POST /api/v1` endpoints for
  maintenance, documents, expenses, tread checks and manual reminders
  (spec §7.20).
- *(Found while starting.)* **A relative reminder: a fixed date, or one
  that follows the document?** **Answered (#77):** a fixed date. Manual
  reminders have no source (`reminders.source_id` is empty for manual
  rows), and the phase says the date is computed from the source.
- *(Found while starting.)* **Module gating per kind and the model used.**
  **Answered (#78):** each tool needs its kind's module (spec §7.10) as
  well as `ai_actions`. The tools run in *Ask*, on the `ask` task's model
  (spec §7.25 already lists drafting under `ask`).
