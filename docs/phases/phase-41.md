# Phase 41 — DVSA MOT history + release

*Past MOT tests, their mileages and advisories, from the official UK
record.*

Status: 🚧 in progress · file lives in `docs/phases/`

**Reopens #7** (parked 2026-09-30: "sits with registration lookup in §12",
for keeping data local). Phase 30.2 has since set the precedent: an
official, free UK feed, **off until an admin enables it**, behind a
provider interface, with its credentials sealed and its licence shown.
The DVSA MOT history API fits that pattern. For a UK vehicle it brings:

- **past test mileages**, a free plausibility check against the owner's
  own readings and a mileage history for years before Logbook was used;
- **advisories and defects**, the most useful input the issues log
  ([Phase 40.1](phase-40.1.md)) could have;
- **test dates, results and expiries**, which can become inspection
  documents and drive MOT reminders.

What leaves the server is the vehicle's **registration** (or VIN), sent
to DVSA, only for vehicles whose owner chose to fetch, on an install
whose admin enabled it.

Read [`CLAUDE.md`](../../CLAUDE.md), [`spec.md`](../../spec.md) §4, §6
(Vehicle, OdometerReading, ComplianceDocument), §7.1 *First MOT due*,
§7.2, §7.5, §7.24, §7.25 (secrets, `env:`), §7.30, §7.34 (the provider
pattern this copies) and [Phase 40.1](phase-40.1.md) first.

**Prerequisites:** [Phase 40.2](phase-40.2.md) complete and green (v3.6.0, the issues it writes through).

**Credentials:** individuals can apply (open question A, answered
2026-10-08). DVSA asks for a name, email and postal address and answers
within about 5 working days.

---

## The API (to record in §4)

- `GET https://history.mot.api.gov.uk/v1/trade/vehicles/registration/{registration}`
  and `…/vin/{vin}`. Each answers the vehicle (make, model, fuel, colour,
  registration and first-use dates, first MOT due for a new vehicle) and
  its tests: completed date, result, expiry, odometer value and unit and
  whether it was read, test number, and defects (text and type:
  advisory, minor, major, dangerous, fail, PRS, user-entered).
- **Auth:** OAuth 2 client credentials (client ID and secret, scope
  `https://tapi.dvsa.gov.uk/.default`, the token URL DVSA issues, a
  Microsoft `login.microsoftonline.com/{tenant}/oauth2/v2.0/token` URL)
  plus an `X-API-Key` header. The client secret expires every 2 years
  (DVSA emails the holder); a key unused for 90 days is revoked (#327).
- **Quotas:** 500,000 requests a day, 15 a second on average, a burst of
  10; over them, `429`, and a key over its daily quota is blocked for
  24 hours.
- `GET /v1/trade/vehicles/bulk-download` answers links to the whole-
  country files and sends nothing about a vehicle: Logbook uses it only
  as *Test* and as the keep-alive (#327), never downloading the files.
- **Recalls:** the vehicle's `hasOutstandingRecall` is `Yes`, `No`,
  `Unknown` or `Unavailable` (#325).
- **Coverage:** cars, motorcycles and vans in Great Britain since 2005 and
  Northern Ireland since 2017.
- **Licence:** Open Government Licence v3.0, with the same attribution
  line as Fuel Finder.
- Exact field names, quotas and error codes are taken from DVSA's current
  documentation and a recorded response when this is built, and written
  into §4 then.

---

## Goals

1. **Settings → MOT history** (admins): enable the provider, credentials,
   licence, last call status.
2. **Per vehicle:** *Fetch MOT history* (and *Refresh*), storing every
   test and its defects.
3. **Mileage:** each test's reading joins the mileage log, so the
   existing plausibility checks compare it with the owner's own.
4. **Documents:** passed tests can become inspection documents, and a
   new vehicle's first MOT due date can fill *First MOT due*.
5. **Advisories → issues:** a review card offers each advisory and defect
   as a Phase 40 issue.
6. **Refresh** after each MOT is due; History, Ask, API, backups.
   Release.

## Not in scope

- Downloading the bulk files (whole-country). Logbook asks per vehicle;
  the bulk endpoint is called only for *Test* and the keep-alive (#327).
- Any other country's inspection records. The provider interface allows
  them later.

---

## Spec addition (§7.38 MOT history)

### Provider and settings

- `Service\MotHistory\MotHistoryProvider` with one adapter,
  `uk_dvsa` (code, name, description, what it sends, licence and
  attribution, credentials, the countries it covers), registered in a
  registry as §7.34's providers are. One provider at a time.
- **Settings → MOT history** (`/settings/mot-history`, admins, new
  `InstanceAbility::ManageMotHistory`): *Off* (default) or *DVSA (UK)*;
  the credentials (client ID, client secret, API key, token URL), each
  *Saved* / *Not set*, sealed or `env:NAME` as §7.25's secrets (table
  `mot_history_secrets`); the statement "Sends the registration (or VIN)
  of vehicles whose owners choose to fetch their MOT history to DVSA.
  Nothing else is sent."; the licence; *Test* (a token request and a
  bulk-download call, which checks the key too; no vehicle sent, #327);
  and the last call's time, status and error (redacted).
- **Module:** part of `compliance`. With compliance off, or the provider
  off, nothing below appears or runs.

### Fetching

- **Who:** `Own` on the vehicle (it sends the vehicle's registration
  to a third party, an owner-level choice; #321). The vehicle
  needs a registration or a VIN.
- **Where:** on the vehicle's Documents tab and overview *Ownership*
  card: *Fetch MOT history*, with the statement above before the first
  fetch for that vehicle, confirmed once (stored on the vehicle as
  `mot_history_enabled_at`). After that, *Refresh* and *Stop and
  remove* (deletes the stored tests, their readings and the flag; issues
  and documents made from them stay, as the owner's own entries).
- **Lookup:** by registration; when DVSA has no record and the vehicle
  has a VIN, by VIN. A registration change (a private plate) is noticed
  when the VIN matches a record under another registration, and stated
  ("DVSA knows this vehicle as AB12 CDE").
- **Matching the answer:** when make or model clearly disagree with the
  vehicle's (case-folded, the model compared by its first word), nothing
  is stored and the page says so: "DVSA's record for AB12 CDE is a Ford
  Fiesta; this vehicle is a VW Golf. Check the registration." This
  catches a typo before a stranger's history lands in the log.
- **Requests** are made in the request that asked, with a 10 s limit and
  the redacted error shown on failure; the token is fetched per call and
  never stored.

### What is stored

**MotTest** (`mot_tests`): id, vehicle_id (`ON DELETE CASCADE`),
test_number (unique per vehicle), completed_at (UTC instant), result
(`passed` | `failed`), expiry_on (optional date), odometer_km (optional
`decimal(12,3)`, converted from the test's unit), odometer_unit (as
tested, `mi` | `km`), odometer_state (`read` | `unreadable` | `none`),
fetched_at.

**MotDefect** (`mot_defects`): id, mot_test_id (`ON DELETE CASCADE`),
type (`advisory` | `minor` | `major` | `dangerous` | `fail` | `prs` |
`user_entered`), text (as DVSA gives it), dangerous (bool), issue_id
(optional, `ON DELETE SET NULL`: the issue made from it), dismissed_at
(optional: *Not now* on the review card).

A refresh **upserts by test number**: tests and defects are never
duplicated, and DVSA's text replaces the stored text.

### Mileage

- Each test with a read odometer writes a reading, source **`mot`**
  (new), at the test's instant, linked to the test (`mot_test_id`,
  `ON DELETE CASCADE`), so the mileage log runs back to the car's first
  MOT. Like other derived readings it is edited only by refreshing, and
  goes with *Stop and remove*.
- Because they are ordinary readings, the **existing checks apply**:
  §7.2's backwards and 2,000 km a day warnings, and *Needs attention*
  item 2 (*Implausible readings*). A reading of the owner's that
  disagrees with DVSA's is flagged in the place every reading problem is
  already flagged.
- **Disagreement wording:** when the flagged pair is a `mot` reading and
  one of the owner's, the item says which is which: "Your reading on 2 Mar
  2026 (41,200 mi) is lower than the MOT on 14 Feb 2026 (43,950 mi)". The
  *Fix* link goes to the owner's reading, never the MOT's.
- An unreadable or missing odometer writes no reading; the test page
  shows "Odometer not read".

### Documents and first MOT

- The review card (below) offers each **passed** test not already logged
  as an `inspection` document (start = the test date, expiry, reference
  = the test number, provider "DVSA MOT", no cost, no odometer of its
  own: the test's `mot` reading is the reading). "Already logged" = an
  inspection document with that reference, or with that start date.
  *Add all* adds them in one go, oldest first, so the latest pass drives
  the MOT reminder (§7.5, §7.6).
- **First MOT due:** a vehicle with no tests whose record carries DVSA's
  first MOT due date, and whose *First MOT due* is blank, is offered it
  on the card ("DVSA: first MOT due 14 Mar 2027 · Use this date"). Never
  filled on its own.

### Advisories → issues

- **Review card** (`/vehicles/{id}/mot-history/review`, `Log`), shown
  after a fetch that brought anything new and linked from the MOT history
  page while anything is unreviewed. For each new test, its defects
  grouped by type, each with *Add as issue* / *Not now*, and *Add all*.
- An issue made from a defect: source `mot_advisory`, `source_ref` the
  test number, noticed on the test date at its mileage, title the
  defect's text (cut to 120 with the full text in the description),
  status `open` for `fail`, `dangerous`, `major` and `prs`; **`watching`**
  for `advisory` and `minor`, with *Look again* at the next MOT's expiry
  less 30 days. `dangerous` and `major` set *Affects safety* (Phase
  40.1's question D, decided yes 2026-10-08, #310).
- **Repeated advisories:** a defect whose text matches (case-folded,
  whitespace collapsed) one on the previous test that already became an
  issue is not offered again; the existing issue gets an update instead
  ("Advised again at the MOT on 14 Feb 2026, 43,950 mi").
- **Fixed since:** an advisory that does not appear on the next test is
  not closed automatically; the card notes it ("Not advised at the
  following MOT") beside the issue, for the owner to decide.

### Refresh

- Job `mot_history` (§7.30), daily while the provider is enabled: for
  each enabled vehicle, refresh when its latest stored expiry is between
  14 days before and 60 days after the owner's today and it was not
  fetched in the last 7 days, so a new test appears within a week
  without polling every vehicle every day. Never for archived vehicles.
  A run stops at the provider's throttle and carries on the next day.
- **Keep-alive (#327):** when the last successful call is more than 80
  days old, the job makes one bulk-download call (no vehicle sent), so
  DVSA doesn't revoke an unused key. A failure shows on Settings → MOT
  history like any other call.
- Anything new from a scheduled refresh shows the review card's link on
  the overview ("New MOT result: passed 14 Feb 2026"), and, with
  reminders on, closes the MOT reminder as done when a new pass is added
  as a document.

### Recalls (#325)

- Each fetch stores the vehicle's recall state (`mot_recall_state`:
  `yes` | `no` | `unknown` | `unavailable`, with `mot_recall_checked_at`).
- The MOT history page states it in words: "An outstanding recall"
  (with "Check with the manufacturer or a dealer"), "Recalls, all
  fixed", "No recalls found", "Recall status unavailable".
- `yes` raises a *Check* item in *Needs attention* ("Outstanding recall
  on AB12 CDE"), until a later fetch says otherwise or *Stop and
  remove*. The others raise nothing.

### Look up on add (#326)

- While the provider is on, the add-vehicle form shows *Look up* beside
  the registration (JS: a request that fills the form; without JS, a
  submit that redraws it filled). Anyone who can add a vehicle may use
  it; the statement "Sends this registration to DVSA" sits beside the
  button, and the click is the choice.
- It fills only blank fields: make, model, fuel, colour, first
  registration and, for a vehicle with no tests, *First MOT due*. The
  owner reviews and saves; nothing is stored until then, and the lookup
  does not enable MOT history for the new vehicle.
- Errors and "No DVSA record for AB12 CDE" show beside the button; the
  form still saves without a lookup.

### Pages and elsewhere

- **MOT history** page (`/vehicles/{id}/mot-history`, `View`): each test
  newest first (date, result, expiry, mileage in the owner's unit with
  the tested unit when different, defects with their type as text and an
  icon, never colour alone), links to issues and documents made from it,
  the attribution, and when it was fetched. Linked from the Documents
  tab.
- **History** (§7.16): kind *MOT test* (dated by the test), unless the
  test became a document, whose row carries it (never listed twice).
- **Ask:** `mot_history(vehicle)` read tool (tests, mileages, defects,
  links). **API:** `GET /vehicles/{id}/mot-tests`. **CSV:**
  `mot-tests.csv`. **Backups:** the two tables, the secrets sealed as
  others; `bin/export-user.php` includes the tests.
- **Sale pack** (§7.19): the printed DVSA link stays; with history
  fetched, the tests' summary is printed too (date, result, mileage).

---

## Decisions (and why)

- **Same shape as Fuel Finder.** Off by default, admin-enabled, sealed
  credentials, licence shown, a provider interface. It keeps the "data
  stays local unless you choose" rule: here the owner chooses per vehicle
  too, because the thing sent identifies their car.
- **Readings, not a parallel series.** One mileage log (§6 OdometerReading)
  is a founding rule. Making MOT mileages readings means every existing
  check, chart and projection uses them with no new comparison code.
- **Advisories become *watching*, failures *open*.** That is what an
  advisory is: something a tester wants watched. The look-again point
  before the next MOT is when an owner would want to see it again.
- **Never closed automatically.** An advisory missing from the next test
  might have been fixed, or the next tester may not have noted it. Only
  the owner knows.
- **Refuse a mismatched record.** Storing a stranger's MOT history
  because of a typo in a plate would put false readings in the log.

---

## Tasks

### 41.0 Spec first
- [x] Credentials question (A) answered from DVSA before anything else
      (2026-10-08: individuals can apply).
- [x] B–H decided (2026-10-08, #320–#327).
- [x] §4 (endpoints, auth, quotas from DVSA's documentation), §6 (MotTest,
      MotDefect, reading source `mot`, vehicle `mot_history_enabled_at`,
      `mot_history_secrets`), §7.38, §7.2, §7.24 wording, §7.16, §7.19,
      §7.20, §7.26, §7.30, §9, §13; #7 marked *Decided*; `ROADMAP.md` row.
      (2026-10-08: §4, §6, §7.1, §7.16, §7.19, §7.20, §7.24, §7.26,
      §7.30, §7.38, §12, §13; #7 *Scheduled*; no new environment
      variables, so §9 is unchanged. Secrets are never in backups, as
      every other secret table.)

### 41.1 Provider
- [ ] Interface, registry, `uk_dvsa` adapter (token, request, parsing,
      unit conversion, error mapping), recorded-response fixtures.
- [ ] Settings page, secrets, *Test*, last status; ability.

### 41.2 Fetch and store
- [ ] Fetch, VIN fallback, mismatch refusal, upsert, readings, *Stop and
      remove*; migration (reversible on every engine).
- [ ] MOT history page; review card (documents, first MOT due,
      advisories and defects to issues, repeats, *Not now*).
- [ ] Recall state stored, shown and its *Needs attention* item (#325).
- [ ] *Look up* on the add-vehicle form, with and without JS (#326).

### 41.3 Refresh and elsewhere
- [ ] `mot_history` job; keep-alive (#327); overview notice; reminder
      closing.
- [ ] History, Ask tool, API, CSV, backups, export-user, sale pack.
- [ ] Translations (every shipped locale); attribution everywhere data
      shows.

### 41.4 Tests
- [ ] Adapter against recorded responses: passes, fails, unreadable
      odometers, km tests, a new vehicle with only a first-due date,
      defects of every type, 404, 429, auth failure.
- [ ] Mismatch refused and nothing stored; VIN fallback; plate change
      noticed.
- [ ] Refresh upserts without duplicates; *Stop and remove* removes
      tests and readings only.
- [ ] Readings: written per read test; implausible-reading item fires
      against an owner's reading with the new wording, *Fix* to the
      owner's.
- [ ] Review card: documents not duplicated (reference and date match);
      issues created with the right status and look-again; repeats
      become updates; *Not now* sticks.
- [ ] Job selects only vehicles in the window and not fetched in 7 days;
      stops on throttling; keep-alive after 80 days only.
- [ ] Recall states each worded; `yes` raises the item, the rest don't.
- [ ] *Look up* fills only blank fields, stores nothing, needs the
      provider on; works without JS.
- [ ] Nothing is sent with the provider off, compliance off, or before
      the owner's confirmation; access matrix; suite green on every
      engine; coverage at or above the floor.

### 41.5 Release
- [ ] `VERSION` → next minor; `CHANGELOG.md` (*Added* — MOT history from
      DVSA, off until an admin enables it; *Upgrade notes* — one
      migration, how to apply for credentials).
- [ ] `docs/mot-history.md` (applying to DVSA, settings, what is sent);
      README; `ROADMAP.md` row ✅. Tag once merged.

---

## Acceptance criteria

1. With the provider off, nothing is ever sent and nothing changes.
2. With it on, an owner can fetch a vehicle's MOT history once
   confirmed, and only theirs: a mismatched record is refused.
3. MOT mileages are readings, checked like any other.
4. Advisories and defects become issues in one tap, with sensible
   statuses, and repeats don't duplicate.
5. A new MOT appears within a week of the old one expiring, without
   polling every vehicle daily, and an idle key isn't revoked.
6. An outstanding recall is stated and raises a *Check* item; *Look up*
   fills a new vehicle's blank fields from DVSA.
7. Definition of done (CLAUDE.md §11) holds.

## Open questions

Logged as #320–#327 in [`open-questions.md`](open-questions.md). A was
answered from DVSA's documentation; the owner decided B–G and the
question found while starting on 2026-10-08, before the phase started.

- **A. Can individuals get credentials?** *Answered 2026-10-08.* Yes:
  DVSA's [registration page](https://documentation.history.mot.api.gov.uk/mot-history-api/register)
  accepts organisations, businesses and individuals, asks for a name,
  email and postal address, and answers within about 5 working days.
  `docs/mot-history.md` says so. (#320)
- **B. Who may fetch?** *Decided 2026-10-08:* `Own` only, not `Manage`
  as first drafted: sending the registration to a third party is the
  owner's call, as sharing and transfer are. (#321)
- **C. Readings from failed tests?** *Decided 2026-10-08:* yes, every
  read odometer, pass or fail. (#322)
- **D. The refresh window.** *Decided 2026-10-08:* kept: latest expiry
  between 14 days before and 60 days after the owner's today, at most
  weekly; recorded in §7.38. (#323)
- **E. Advisories as *watching* by default?** *Decided 2026-10-08:* yes,
  as drafted: `advisory` and `minor` *watching*, the rest *open*. (#324)
- **F. Recalls.** *Decided 2026-10-08:* shown. DVSA's
  `hasOutstandingRecall` is `Yes` (at least one recall not yet fixed),
  `No` (recalls, all fixed), `Unknown` (none found) or `Unavailable`
  (the recalls service failed), per its OpenAPI specification. The MOT
  history page states it in words; `Yes` raises a *Check* item in *Needs
  attention*; the others raise nothing. (#325)
- **G. Vehicle lookup on add.** *Decided 2026-10-08:* in this phase: a
  *Look up* button on the add-vehicle form while the provider is on.
  (#326)
- **H. DVSA revokes an API key unused for 90 days, and a token-only
  *Test* passes a wrong or revoked key** (found while starting).
  *Decided 2026-10-08:* use `GET /v1/trade/vehicles/bulk-download`,
  which sends no vehicle and answers only file links: *Test* calls it
  (token and key together), and the daily job calls it when the last
  successful call is more than 80 days old. Checked against a real key
  before it is built; if the endpoint refuses ordinary keys, the owner
  is asked again. (#327)
