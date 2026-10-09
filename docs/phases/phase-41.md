# Phase 41 — DVSA MOT history + release

*Past MOT tests, their mileages and advisories, from the official UK
record.*

Status: ✅ complete (v3.7.0; tag once merged) · file lives in `docs/phases/`

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
- Field names and quotas are in §4, from DVSA's OpenAPI specification;
  they are checked against a recorded response with real credentials
  when 41.1 is built.

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

## Spec

The full behaviour is spec [§7.38](../../spec.md) (written 2026-10-08,
41.0), with the data in §6 (MotTest, MotDefect, MotHistorySecret, the
vehicle's MOT columns, reading source `mot`) and the endpoints in §4.
The draft that stood here is replaced by it; where they differed, §7.38
and the decisions below win. In short:

- **Settings → MOT history** (admins, `ManageMotHistory`): *Off* or *DVSA
  (UK)*, four sealed credentials, *Test* (token and `bulk-download`), the
  last call. Part of `compliance`; blocked in demo mode.
- **Per vehicle** (`Own`, #321): *Fetch MOT history* after a one-time
  confirmation, *Refresh*, *Stop and remove*; by registration, then VIN;
  a mismatched make or model is refused; upsert by test number, missing
  tests kept (#331).
- **Mileage:** every read odometer a `mot` reading (#322), checked like
  any other, with the "Your reading … the MOT …" wording.
- **Review card:** passed tests as `inspection` documents, DVSA's first
  MOT due date, defects as issues (`advisory`, `minor`, `user_entered`,
  `non_specific` and `system_generated` watching with *Look again* at that test's expiry less 30 days; the
  rest open; `dangerous` and `major` *Affects safety*), repeats as
  updates, *Not now*.
- **Recalls** (#325, #329), ***Look up*** on the add form (#326, #330),
  the `mot_history` job with its window (#323) and keep-alive (#327);
  History, Ask, API, CSV, backups (never the secrets, #332), sale pack.

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
- [x] B–H decided (2026-10-08, #321–#327), and #328–#332 found while
      starting.
- [x] §4 (endpoints, auth, quotas from DVSA's documentation), §6 (MotTest,
      MotDefect, reading source `mot`, vehicle `mot_history_enabled_at`,
      `mot_history_secrets`), §7.38, §7.2, §7.24 wording, §7.16, §7.19,
      §7.20, §7.26, §7.30, §9, §13; #7 marked *Scheduled*; `ROADMAP.md` row.
      (2026-10-08: §4, §6, §7.1, §7.16, §7.19, §7.20, §7.24, §7.26,
      §7.30, §7.38, §12, §13; #7 *Scheduled*; no new environment
      variables, so §9 is unchanged. Secrets are never in backups, as
      every other secret table.)

### 41.1 Provider
- [x] Interface, registry, `uk_dvsa` adapter (token, request, parsing,
      unit conversion, error mapping), fixtures. (2026-10-08: synthetic
      fixtures from DVSA's OpenAPI specification;
      `bin/record-mot-history.php` records real answers, scrubbed, once
      credentials arrive, and `DvsaRecordedTest` checks them. Still to do
      with a real key: confirm `bulk-download` accepts it, #327.)
- [x] Settings page, secrets, *Test*, last status; ability. (Also the
      migration, backups, demo block and job-log redaction.)

### 41.2 Fetch and store
- [x] Fetch, VIN fallback, mismatch refusal, upsert, readings, *Stop and
      remove*. (The migration, reversible on every engine, landed in
      41.1 with the secrets table it needs.)
- [x] MOT history page; review card (documents, first MOT due,
      advisories and defects to issues, repeats, *Not now*).
- [x] Recall state stored, shown and its *Needs attention* item (#325).
- [x] *Look up* on the add-vehicle form, with and without JS (#326).
- [x] Sample provider for development (#335) and MOT history in
      `--with-sample-data` (`DemoDataSeeder`); README's flag text.

### 41.3 Refresh and elsewhere
- [x] `mot_history` job; keep-alive (#327, #342); a new car by its
      first MOT due date (#339); overview notice; reminder closing.
- [x] History, Ask tool, API, CSV (#340, #341), backups, export-user
      (it lacked the tests its `mot` readings point at), sale pack.
- [x] Translations (en, de: every shipped locale); attribution on the
      page, card, History, sale pack, API and Ask. Owners get "MOT
      history isn't available right now" for a credentials problem (a
      41.2 gap found while writing `docs/mot-history.md`, which is
      drafted early so the API, Ask and MCP docs can link it).

### 41.4 Tests
- [x] Adapter against recorded responses: passes, fails, unreadable
      odometers, km tests, a new vehicle with only a first-due date,
      defects of every type, 404, 429, auth failure.
- [x] Mismatch refused and nothing stored; VIN fallback; plate change
      noticed.
- [x] Refresh upserts without duplicates; *Stop and remove* removes
      tests and readings only.
- [x] Readings: written per read test; implausible-reading item fires
      against an owner's reading with the new wording, *Fix* to the
      owner's.
- [x] Review card: documents not duplicated (reference and date match);
      issues created with the right status and look-again; repeats
      become updates; *Not now* sticks.
- [x] Job selects only vehicles in the window and not fetched in 7 days;
      stops on throttling; keep-alive after 80 days only.
- [x] Recall states each worded; `yes` raises the *Now* item, the rest
      don't.
- [x] *Look up* fills only blank fields, stores nothing, needs the
      provider on; works without JS.
- [x] Nothing is sent with the provider off, compliance off, or before
      the owner's confirmation; access matrix; suite green on every
      engine; coverage at or above the floor.

Where each is tested: the adapter in `tests/Unit/Service/MotHistory`
(`DvsaParserTest`, `DvsaProviderTest`, `DvsaRecordedTest` once DVSA's
answers are recorded); fetching, mismatch, VIN, upsert (a changed
answer, #331), *Stop and remove* and compliance off in
`MotHistoryFetchTest`; readings, recalls (each state) and the reading
wording in `MotAttentionTest`; the card, notice and reminder closing in
`MotReviewTest`; the job in `MotHistoryJobTest`; *Look up* in
`VehicleLookupTest`; History, API, CSV, sale pack and access in
`MotElsewhereTest`; Ask in `MotHistoryToolTest`; backups and the user
export in `MotBackupTest`; the sample data in `DemoMotHistoryTest`.

### 41.5 Release
- [x] `VERSION` → 3.7.0; `CHANGELOG.md` (*Added* — MOT history from
      DVSA, off until an admin enables it; *Upgrade notes* — one
      migration, how to apply for credentials); OpenAPI 1.24.0.
- [x] `docs/mot-history.md` (applying to DVSA, settings, what is sent);
      README; `ROADMAP.md` row ✅. The printed and linked URLs (gov.uk
      MOT checker, OGL v3.0, DVSA's registration page) checked
      2026-10-09. Tag once merged.
- [ ] Still open after release: run `bin/record-mot-history.php` with
      real credentials once DVSA issues them, so `DvsaRecordedTest`
      checks the adapter against DVSA's real answers.

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
6. An outstanding recall is stated and raises a *Now* item; *Look up*
   fills a new vehicle's blank fields from DVSA.
7. Definition of done (CLAUDE.md §11) holds.

## Open questions

Logged as #320–#350 in [`open-questions.md`](open-questions.md). A was
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
  history page states it in words; `Yes` raises a *Now* item in *Needs
  attention*, with no *Hide* (#329); the others raise nothing. (#325)
- **G. Vehicle lookup on add.** *Decided 2026-10-08:* in this phase: a
  *Look up* button on the add-vehicle form while the provider is on.
  (#326)
- **H. DVSA revokes an API key unused for 90 days, and a token-only
  *Test* passes a wrong or revoked key** (found while starting).
  *Decided 2026-10-08:* use `GET /v1/trade/vehicles/bulk-download`,
  which sends no vehicle and answers only file links: *Test* calls it
  (token and key together), and the daily job calls it when the last
  successful call is more than 80 days old. DVSA's OpenAPI
  specification gives `bulk-download` the same global security (bearer
  token and API key) as the vehicle endpoints, with no override;
  checked against a real key before it is built, and if the endpoint
  refuses ordinary keys, the owner is asked again. (#327)

Found while starting, decided by the owner on 2026-10-08:

- `user_entered` defects (a tester's own note) become *watching*, as
  advisories. (#328)
- The recall item is a *Now* item with no *Hide* (a recall is work to
  do, not data that looks wrong); it goes when a later fetch stops
  saying `Yes`. (#329)
- *Look up* is for anyone who may add a vehicle, on the add form only,
  and may fill *First MOT due*. (#330)
- A test missing from a later DVSA answer is kept. (#331)
- #221, #279 and #280 (earlier phases) were reviewed and carried as
  they are: none changes Phase 41, which adds no draft type.
- Found while building 41.1, from DVSA's OpenAPI specification: its
  defect types are `ADVISORY`, `DANGEROUS`, `FAIL`, `MAJOR`, `MINOR`,
  `NON SPECIFIC`, `SYSTEM GENERATED` and `USER ENTERED` (a type may be
  null), with no PRS. Stored as DVSA's: `prs` is dropped,
  `non_specific` and `system_generated` added and offered as *watching*;
  a null or unknown type is `non_specific`. (#333)
- Also from the specification: a test may have no number (keyed by its
  source and completed time) or, for heavy vehicles, no completed date
  (skipped, and the fetch says how many). (#334)
- `bin/dev-setup.sh --with-sample-data` includes MOT history (asked
  2026-10-08): a *Sample MOT history* provider, as fuel prices' sample
  one, outside production only and not in demo mode; the seeder enables
  it and stores the sample vehicles' history. Built with 41.2. (#335)
- The mismatch check refuses on the make only (aliases such as VW and
  Merc allowed); a different model is noted, never refused, since owners
  write "3 Series" where DVSA writes "320D M SPORT" (found while building
  41.2). *Look up* fills no colour: vehicles have no colour field. (#336)
- *Look again* for an issue from any test is 30 days before the latest
  test's expiry (before the next MOT), none when that has passed, so an
  older advisory never raises an overdue item the moment it is added
  (found while building 41.2). (#337)
- Repeats match any open or watching issue made from a defect with the
  same text, and "not advised again" is judged at the next pass, so a
  retest after a fail breaks neither (found while building 41.2). (#338)
- Asked when starting 41.3 (2026-10-09), decided by the owner:
  - a vehicle with no tests is in the refresh window by DVSA's first
    MOT due date, as if it were the expiry, so a new car's first MOT
    arrives on its own (#339);
  - `mot-tests.csv` has one row per defect, with the test's columns
    repeated; a test with no defects is one row with the defect columns
    blank (#340);
  - the CSV carries no attribution: it is pure data, and
    `docs/mot-history.md` states it; the page, the review card, the
    API, Ask and the sale pack carry it (#341);
  - the keep-alive treats "never succeeded" as too old, so it calls
    (#342).
- Found by the merge review (2026-10-09) and fixed before the PR, at the
  owner's go-ahead: a per-person limit on *Look up* and *Fetch* (#343);
  "already logged" judged on the owner's day (#344); defects linked again
  to the issues made from them after a new fetch (#345). Left open for
  the owner: distance before the purchase date (#346), who may use *Look
  up* (#347), the settings on rollback (#348), recorded answers in git
  (#349) and page query budgets (#350).
- Answered from the spec: `mot_history_secrets` is never in backups, as
  §6's AiSecret, NotificationSecret and FuelPriceSecret aren't; the
  *Spec addition* draft's "the secrets sealed as others" means that.
  (#332)
