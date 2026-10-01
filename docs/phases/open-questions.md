# Open questions

Every *Open questions* item from the phase files, with its status. Read
this before starting a phase ([`CLAUDE.md`](../../CLAUDE.md) §12).

- **Answered:** the app already does something definite, recorded as a
  choice in the spec or pinned by a test. The last column says where.
- **Parked:** recorded as future work in [`spec.md`](../../spec.md) §12, not
  scheduled.
- **Scheduled:** decided, with the work in a later phase.
- **Decided:** the owner answered, and nothing needs building (or the
  answer is "no").
- **Needs a decision:** still open, and an answer would change the app. The
  owner decides; nothing is built until then.
- **Obsolete:** no longer applies. The last column says why.

The first review (Phase 20, 2026-09-30) covered Phases 0–19. Phases 0–8,
9.2, 10.2, 17.2 and 18.1 have no *Open questions* section. Phase 8's intro
promises one "at the end", but none was written and nothing in it was left
undecided. The owner answered every *Needs a decision* item on 2026-09-30,
together with the open questions of Phases 21.1 and 21.2 (#37–#42), and
Phase 22's on the same day before it started (#43–#47). Phase 22's
question found while building (#48) and Phase 23.1's (#49–#51) were
answered on 2026-10-01, before Phase 23.1 started, and Phase 23.2's
(#52–#55, two of them found while starting it) on the same day, before
Phase 23.2 started. Phase 24's (#56–#58) were answered on 2026-10-01,
before it started, and Phase 25's (#59–#64, four of them found while
starting it) on the same day, before Phase 25 started. Phase 26.1's
(#65–#69, two of them found while starting it) were answered on
2026-10-01, before Phase 26.1 started, and Phase 26.2's (#70–#73, one of
them found while starting it) on the same day, before Phase 26.2 started,
and Phase 26.3's (#74–#78, three of them found while starting it) on the
same day, before Phase 26.3 started, and Phase 26.4's (#79–#84, two of
them found while starting it) on the same day, before Phase 26.4 started.

| # | Phase | Question | Status | Decision or where answered | Date |
|---|---|---|---|---|---|
| 1 | [9.1](phase-9.1.md) | Date the starting reading? | Answered | Phase 12: an optional *As of* beside *Current odometer*, stored at local noon on that date. | 2026-09-30 |
| 2 | [9.1](phase-9.1.md) | MOT due from first registration? | Scheduled | [Phase 21.2](phase-21.2.md): an optional, stored *First MOT due* date suggested from first registration. | 2026-09-30 |
| 3 | [9.1](phase-9.1.md) | Registration lookup to fill variant and first registration | Parked | spec §12 (VIN decode / registration lookup). | 2026-09-30 |
| 4 | [10](phase-10.md) | Overview: keep the latest fill-ups list beside *Recent history*? | Answered | Phase 12: no, the list is removed. | 2026-09-30 |
| 5 | [10](phase-10.md) | Purchase and sale paperwork | Answered | Phase 12: owner types `purchase` and `sale`. | 2026-09-30 |
| 6 | [10](phase-10.md) | Print defaults for a buyer: costs on or off? | Answered | Phase 12: off, the copy a buyer can be handed (`PrintOptions`). | 2026-09-30 |
| 7 | [10](phase-10.md) | DVSA MOT history import | Parked | Sits with registration lookup in spec §12; the sale pack prints a link instead ([17.1](phase-17.1.md)). | 2026-09-30 |
| 8 | [11.1](phase-11.1.md) | Moving a tyre set between vehicles | Answered | spec §7.17: "Moving a set to another vehicle is out of scope." Tyres and sets keep their `vehicle_id`. | 2026-09-30 |
| 9 | [11.1](phase-11.1.md) | Wheels (winter tyres on their own rims) | Answered | A free-text note on the set (`tyre_sets.notes`, `templates/tyres/set_form.twig`). | 2026-09-30 |
| 10 | [11.1](phase-11.1.md) | CSV import of tyre history | Answered | spec §7.17: "There is no tyre import." Export only. | 2026-09-30 |
| 11 | [11.2](phase-11.2.md) | Region presets for tread thresholds | Answered | spec §7.17: "No region rules are built in." One set of defaults in `TyreThresholds`. | 2026-09-30 |
| 12 | [11.2](phase-11.2.md) | Tread depth per zone (inner / centre / outer) | Parked | spec §12. Not built: one `tread_mm` per change line. | 2026-09-30 |
| 13 | [11.2](phase-11.2.md) | Rotation suggestion when fronts wear faster | Parked | spec §12, maintenance insights. | 2026-09-30 |
| 14 | [12](phase-12.md) | Remember the last print choice (*Show costs*)? | Decided | No: *Show costs* stays a per-print choice (`PrintOptions` reads `costs=1` only), so every print starts buyer-safe. | 2026-09-30 |
| 15 | [12](phase-12.md) | A hint on filing the V5C as *Registration* vs the invoice under *Bought* | Answered | Built in [Phase 21.1](phase-21.1.md) §5: the purchase paperwork hint (spec §7.1; `VehiclePaperworkTest`). | 2026-09-30 |
| 16 | [13](phase-13.md) | Seasonal baselines for economy checks | Scheduled | Built in [Phase 25](phase-25.md): economy drift compares with the same months a year earlier (spec §7.24 item 7). Was parked in spec §12. | 2026-10-01 |
| 17 | [13](phase-13.md) | One *sensitivity* setting for economy checks | Answered | spec §7.3: "Not in scope: … thresholds as settings". The bands are a fixed constant in `EconomyCheck`. | 2026-09-30 |
| 18 | [13](phase-13.md) | Detect a sudden, sustained change | Scheduled | Built in [Phase 25](phase-25.md): economy drift, recent tanks against the 12-month baseline (spec §7.24 item 7). Was parked in spec §12. | 2026-10-01 |
| 19 | [14.1](phase-14.1.md) | Insurer's agreed value as a valuation | Parked | spec §12. Until then, log it as a valuation with the source "Insurer". | 2026-09-30 |
| 20 | [14.1](phase-14.1.md) | A valuation's mileage as context | Decided | No: valuations keep no mileage, so there is one mileage series. | 2026-09-30 |
| 21 | [14.2](phase-14.2.md) | Dashboard tile or widget for cost of ownership | Parked | spec §12, pending a design pass for a fifth tile. | 2026-09-30 |
| 22 | [14.2](phase-14.2.md) | Business mileage | Scheduled | [Phase 22](phase-22.md) adds trips, claim reports and a *Business mileage* widget, and now cost per business mile (#23). | 2026-09-30 |
| 23 | [14.2](phase-14.2.md) | Cost per business mile, once trips exist | Scheduled | Added to [Phase 22](phase-22.md): cost of ownership per distance beside the claim value per business mile. | 2026-09-30 |
| 24 | [15](phase-15.md) | Recurring expenses (road tax, permits) in *Coming up* | Parked | spec §12. It would need its own phase. | 2026-09-30 |
| 25 | [15](phase-15.md) | A *Needs attention* list on the overview | Scheduled | [Phase 24](phase-24.md): overview card, dashboard widget and garage marker. Not a score. | 2026-09-30 |
| 26 | [15](phase-15.md) | Price drift in forecast costs | Decided | No: forecasts keep "about £240 (last time)" (spec §7.18). | 2026-09-30 |
| 27 | [16](phase-16.md) | Reference grade: most used by volume, or `default_grade`? | Answered | Most used by volume in the last 12 months, ties to the latest (spec §7.3 *Grade verdict*; `GradeComparison::reference()`; `GradeComparisonTest::testTheReferenceIsTheMostUsedGradeByVolumeInTheLastYearTiesToTheLatest`). | 2026-09-30 |
| 28 | [16](phase-16.md) | Diesel blends: one price-pair rule, or relaxed for diesel? | Answered | One rule for petrol and diesel: 3 pairs within 30 days (spec §7.3; `GradeComparison::MIN_PAIRS`; `testFewerThanThreePairsGivesNoVerdict`). | 2026-09-30 |
| 29 | [16](phase-16.md) | Minimum months before *Economy by month* appears | Answered | None: it shows once one month has a figure (spec §7.3; `SeasonalEconomyTest::testNoMonthWithAFigureHidesTheCard`). | 2026-09-30 |
| 30 | [17.1](phase-17.1.md) | Vehicle photo in the sale pack | Answered | Built in [Phase 21.1](phase-21.1.md) §4: `photo=1` only, a cover page before the summary, a screen-only warning (spec §7.19; `SalePackOptions::$photo`; `SalePackTest::testPhotoOneStartsThePackWithTheCover`). | 2026-09-30 |
| 31 | [17.1](phase-17.1.md) | 31 days between purchase and first reading, or "since the first reading on …"? | Decided | Keep 31 days: past it, *Owned since* stays and only the distance is left out (spec §7.19; `OwnershipSpan::MAX_DAYS`; `OwnershipSpanTest::testAFirstReading45DaysLaterLeavesTheDistanceOut`). | 2026-09-30 |
| 32 | [18.2](phase-18.2.md) | `API_ENABLED` default | Answered | `true` (`AppSettings`, `.env.example`, docs/configuration.md). A fresh install has no key. | 2026-09-30 |
| 33 | [18.2](phase-18.2.md) | Per-vehicle API keys | Answered | None: a key has its user's access. spec §7.20 *Not in this version*: a device gets its own user. | 2026-09-30 |
| 34 | [19](phase-19.md) | Do admins see every vehicle? | Answered | No: their own and shared ones only (spec §7.21; `SharedVehicleAccess`; `SharedVehicleAccessTest::testOwnVehiclesOnlyWithEveryAbility`). | 2026-09-30 |
| 35 | [19](phase-19.md) | Do Log shares see documents? | Answered | Yes, documents are part of View (spec §7.21; `/documents` needs View in `config/routes.php`). | 2026-09-30 |
| 36 | [19](phase-19.md) | Self-service password reset by email | Answered | No: an admin's one-time reset link (spec §7.9; `UsersTest::testAResetLinkEndsSessionsAndSetsANewPassword`). | 2026-09-30 |
| 37 | [21.1](phase-21.1.md) | Digest default: existing users keep theirs, or on for everyone without a choice? | Answered | Existing users keep what they have. Built without a migration: setup and invitations store `digest: true` with the new account, and a missing row still reads as off (spec §7.11; `NotificationPreferences::forNewUser()`; `DigestDefaultTest`). | 2026-09-30 |
| 38 | [21.1](phase-21.1.md) | Modals: tyre edit only, or every tyre form? | Decided | Every tyre form: tyre, change and set edits and the three deletes. | 2026-09-30 |
| 39 | [21.1](phase-21.1.md) | Drop zones on CSV import and backup restore? | Decided | Yes, the same macro. | 2026-09-30 |
| 40 | [21.2](phase-21.2.md) | Existing vehicles: a one-time prompt or a backfill? | Decided | A one-time, dismissible prompt per vehicle (option A). | 2026-09-30 |
| 41 | [21.2](phase-21.2.md) | Northern Ireland: a hint, or a GB / NI choice? | Decided | The hint; the stored date can always be changed. | 2026-09-30 |
| 42 | [21.2](phase-21.2.md) | More regions than GB and DE? | Decided | Add FR, IE, IT and ES at 48 months. A locale with no region gets no suggestion, and the hint says so. | 2026-09-30 |
| 43 | [22](phase-22.md) | More than one employment: an *Employer* per trip with its own threshold? | Parked | One threshold per person across their cars; employers in spec §12. | 2026-09-30 |
| 44 | [22](phase-22.md) | A native .xlsx claim export? | Parked | No: CSV only, which opens in Excel; .xlsx in spec §12. | 2026-09-30 |
| 45 | [22](phase-22.md) | `trips` on by default for GB-locale users? | Decided | No: off for everyone (`FEATURES_TRIPS=false`). | 2026-09-30 |
| 46 | [22](phase-22.md) | A `van` vehicle type? | Parked | No: vans stay `car`, which has the same approved rates; a van type in spec §12. | 2026-09-30 |
| 47 | [22](phase-22.md) | Cost per business mile: ownership cost or running costs only? | Answered | Cost of ownership per distance (#23; spec §7.7 falls back to running costs alone without a value). | 2026-09-30 |
| 48 | [22](phase-22.md) | An API endpoint listing saved journeys (for Shortcuts)? | Scheduled | Yes: `GET /api/v1/journeys`, built in [Phase 23.1](phase-23.1.md) (spec §7.20). | 2026-10-01 |
| 49 | [23.1](phase-23.1.md) | OIDC client library, or a JWT library with the checks written here? | Decided | `firebase/php-jwt` plus `symfony/http-client`; every check written and tested here (spec §4). | 2026-10-01 |
| 50 | [23.1](phase-23.1.md) | More than one OIDC provider? | Parked | One provider; more in spec §12. | 2026-10-01 |
| 51 | [23.1](phase-23.1.md) | `OIDC_LINK=email` on a verified email? | Parked | No, while Logbook doesn't verify its own emails; spec §12. | 2026-10-01 |
| 52 | [23.2](phase-23.2.md) | Validate Authentik's signed JWT header? | Decided | Yes, built in this phase: HS256 with the proxy provider's client secret (what Authentik sends), `AUTH_PROXY_TRUSTED` optional in that mode (spec §7.9, §9). | 2026-10-01 |
| 53 | [23.2](phase-23.2.md) | Default `AUTH_PROXY_LINK`? | Decided | `username` (spec §7.9, §9). | 2026-10-01 |
| 54 | [23.2](phase-23.2.md) | How does `identity` mode link a proxy account? | Decided | A *Link your proxy account* banner for a signed-in user with an unlinked header, built in this phase (spec §7.9). | 2026-10-01 |
| 55 | [23.2](phase-23.2.md) | A header for an unlinked account in a password session? | Decided | The session stays; only a header for another user replaces it (spec §7.9). | 2026-10-01 |
| 56 | [24](phase-24.md) | *Needs attention*: due-soon items as well as overdue? | Decided | No, overdue only; due-soon work stays in *Coming up* and the reminders (spec §7.24). | 2026-10-01 |
| 57 | [24](phase-24.md) | A *Needs attention* section in the monthly digest? | Decided | Yes, built in this phase: the recipient's *Check* items after the due reminders; a month with checks and nothing due still sends (spec §7.11, §7.24). | 2026-10-01 |
| 58 | [24](phase-24.md) | Stale mileage and valuation thresholds: constants or settings? | Decided | Built in this phase: user settings, the vehicle owner's: 60 days and 12 months by default, on Settings → Reminders; the §7.1 stale-value hint follows them (spec §7.1, §7.24). | 2026-10-01 |
| 59 | [25](phase-25.md) | Trend and cost thresholds: constants or settings? | Decided | Settings, the vehicle owner's, on the *Needs attention* card: drift 10%, price 35%, cost 3× and 100 by default (spec §7.24). | 2026-10-01 |
| 60 | [25](phase-25.md) | A wider drift threshold for electricity? | Decided | Yes, 15% by default, its own setting (spec §7.24). | 2026-10-01 |
| 61 | [25](phase-25.md) | Access to the new checks? | Decided | As the existing items: drift `Log`; price and cost outliers `Manage`, or `Log` for an entry they added (spec §7.24). | 2026-10-01 |
| 62 | [25](phase-25.md) | The cost floor without exchange rates? | Decided | 100 in the vehicle's currency's major unit (spec §7.24). | 2026-10-01 |
| 63 | [25](phase-25.md) | A fill-up priced at 0? | Decided | Never flagged and never counted in the median, for every fuel (spec §7.24). | 2026-10-01 |
| 64 | [25](phase-25.md) | The drift title's percentage: consumption or the unit shown? | Decided | Worked out from the two figures shown (spec §7.24). | 2026-10-01 |
| 65 | [26.1](phase-26.1.md) | Per-user AI connections (members' own keys)? | Decided | No: connections are admin-only; members use the admins' (spec §7.25). | 2026-10-01 |
| 66 | [26.1](phase-26.1.md) | Stream answers to the browser? | Decided | No: returned whole, with a progress indicator (spec §7.25). | 2026-10-01 |
| 67 | [26.1](phase-26.1.md) | Default for *Use AI features*? | Decided | On for every user once AI is set up; switchable off per user (spec §7.25, user setting `ai.use`). | 2026-10-01 |
| 68 | [26.1](phase-26.1.md) | A second AI request while one runs: wait or refuse? | Decided | Refused at once ("Still working on your last question"), a lock row per user (spec §7.25 *Limits*, §6 AiBusy). | 2026-10-01 |
| 69 | [26.1](phase-26.1.md) | Tailscale (100.64.0.0/10): *Your network* or *Internet*? | Decided | *Your network* (spec §7.25 *Where it runs*). | 2026-10-01 |
| 70 | [26.2](phase-26.2.md) | Ask threads: kept 30 days, or nothing beyond the session? | Decided | 30 days by default; a user setting of 1, 7, 30 or 90 days (spec §7.26). | 2026-10-01 |
| 71 | [26.2](phase-26.2.md) | Feedback: counts only, or the question and answer with the mark? | Decided | The mark is stored on the thread's answer and deleted with the thread; counts are kept too. Nothing extra is stored, whatever `AI_LOG_CONTENT` says (spec §7.26). | 2026-10-01 |
| 72 | [26.2](phase-26.2.md) | Does an admin's *Ask* see every vehicle? | Answered | No: only what they see in the app (#34; spec §7.21; `SharedVehicleAccess`). The tools use the same access. | 2026-10-01 |
| 73 | [26.2](phase-26.2.md) | Progress lines without streaming? | Decided | The loop records each tool call as it starts; the page polls a JSON progress URL about once a second (spec §7.26). | 2026-10-01 |
| 74 | [26.3](phase-26.3.md) | Several drafts at once: *Add all*, or one press per entry? | Decided | One press per entry. Each draft has its own card; there is no *Add all* (spec §7.26 *Drafting entries*). | 2026-10-01 |
| 75 | [26.3](phase-26.3.md) | Settings by chat (lead times, units, modules)? | Parked | spec §12. Settings stay forms only. | 2026-10-01 |
| 76 | [26.3](phase-26.3.md) | The five new input-adapter mappings: API endpoints too? | Decided | Yes. `POST /api/v1` for maintenance, documents, expenses, tread checks and manual reminders, built in this phase (spec §7.20 *More write endpoints*). | 2026-10-01 |
| 77 | [26.3](phase-26.3.md) | A reminder relative to a document: fixed date, or one that follows it? | Answered | A fixed date. Manual reminders have no source (`reminders.source_id` is empty for manual rows, spec §6 Reminder), and the phase computes the date from the source (spec §7.26). | 2026-10-01 |
| 78 | [26.3](phase-26.3.md) | Draft tools: module gating and model | Answered | Each tool needs its kind's module (`Feature`, spec §7.10) as well as `ai_actions`. They run on the `ask` task's model, which spec §7.25 already lists for drafting. | 2026-10-01 |
| 79 | [26.4](phase-26.4.md) | VAT and line items: in the description, or new fields? | Decided | In the description, with labour and parts totals and "VAT £30.75 (20%)"; cost = total; no schema change (spec §7.27). | 2026-10-01 |
| 80 | [26.4](phase-26.4.md) | Strip EXIF from every photo attachment, or scans only? | Decided | Every JPEG, PNG and WebP upload, vehicle photos included, turned upright and re-encoded without metadata; stored files left alone (spec §7.12). | 2026-10-01 |
| 81 | [26.4](phase-26.4.md) | Failed MOTs: document without expiry, vehicle note, or nothing? | Decided | An `other` document "MOT failed …" with failures and advisories in notes and the file attached; an `inspection` without expiry would replace the valid MOT (`DocumentState`) (spec §7.27). | 2026-10-01 |
| 82 | [26.4](phase-26.4.md) | Manual reminders by distance? | Decided | Yes: an optional *Due at* odometer (`reminders.due_km`), whichever comes first; form, API `due_odometer`, the scan's card (spec §7.6, §7.20). | 2026-10-01 |
| 83 | [26.4](phase-26.4.md) | `gd` and `exif` (found while starting) | Decided | In the Docker image on every architecture and required on bare PHP (`ext-gd`, `ext-exif`) (spec §4, §10). | 2026-10-01 |
| 84 | [26.4](phase-26.4.md) | PDF text extractor (found while starting) | Decided | `smalot/pdfparser`, pinned, LGPL-3.0, unmodified through Composer (spec §4). | 2026-10-01 |

## Other loose ends found in the review

These aren't questions, but they are unticked boxes in finished phases:

- [Phase 7](phase-7.md) §7.12 and its acceptance list, and
  [Phase 17.2](phase-17.2.md)'s browser check list (Firefox, Safari,
  Android and iOS print preview), are manual checks never ticked.
- The release-tag tasks in Phases 9.2, 10, 10.2, 11.2, 12 and 13 are
  unticked. The tags (`v1.1.0` to `v1.5.0`) exist.
