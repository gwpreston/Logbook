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
them found while starting it) on the same day, before Phase 26.4 started;
two found while building it (#85, #86) were answered the same day.
Phase 26.5's (#87–#91, three of them found while starting it) were
answered on 2026-10-01, before Phase 26.5 started. Phase 27's
(#92–#103, eight of them found while starting it) were answered on
2026-10-01, before Phase 27.1 started; the phase was split into 27.1 and
27.2, and #98–#101 belong to 27.2. One found while building it (#104)
was answered the same day. Phase 27.2's question found while starting it
(#105) was answered on 2026-10-02, before it was built. Phase 28.1's
(#106–#109, two of them found while starting it) were answered on
2026-10-02, before Phase 28.1 started; one found while building it
(#110) was answered the same day. Phase 28.2's (#111–#117, four of
them found while starting it) were answered on 2026-10-02, before it was
built. Phase 29's (#118–#125, four of them found while starting it) were
answered on 2026-10-02, before Phase 29.1 started; the phase was split
into 29.1 and 29.2. Phase 29.2's (#126–#129, all found while starting it)
were answered on 2026-10-02, before it was built; one found while
building it (#130) was answered the same day. Phase 30.1's (#131–#135,
three of them found while starting it) were answered on 2026-10-02,
before it was built. Phase 30.2's (#136–#144, six of them found while
starting it) were answered on 2026-10-03, before it was built. Phase
31's (#145–#149, two of them found while starting it) were answered on
2026-10-04, before it was built. Phase 32's (#150–#156, four of them
found while starting it) were answered on 2026-10-05, before it was
built. Phase 33.1's (#157–#165, four of them found while starting
it) were answered on 2026-10-05, before it was built. Phase 33.3's
(#173–#185, eight of them found by its prototype audit) were answered
on 2026-10-05, before it was built.

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
| 21 | [14.2](phase-14.2.md) | Dashboard tile or widget for cost of ownership | Scheduled | [Phase 32](phase-32.md): a `true_cost` widget, not a fifth tile (spec §7.35). Was parked in spec §12. | 2026-10-05 |
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
| 36 | [19](phase-19.md) | Self-service password reset by email | Scheduled | Reopened by the owner 2026-10-04: yes, a 60-minute link by email from the sign-in page, built in [Phase 33.1](phase-33.1.md) (spec §7.9 *Forgotten password*). Was: no, an admin's one-time reset link only. | 2026-10-04 |
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
| 85 | [26.4](phase-26.4.md) | A V5C photo sends its reference as pixels (found while building) | Decided | Accept and warn: text redacted before sending, never extracted or stored; the Scan page names where the file goes and warns on *Internet*. Criterion 4 reworded (spec §7.27). | 2026-10-01 |
| 86 | [26.4](phase-26.4.md) | *Fill from* a file already attached? (found while building) | Decided | New files only: the create forms link to Scan for that vehicle and form (spec §7.27). | 2026-10-01 |
| 87 | [26.5](phase-26.5.md) | Ship a `bin/mcp-stdio.php` bridge now? | Parked | No: `docs/mcp.md` documents `mcp-remote` (Node on the client only); the PHP bridge in spec §12. | 2026-10-01 |
| 88 | [26.5](phase-26.5.md) | `log_fill_up` and `add_reading` over MCP: direct writes or drafts? | Decided | Direct writes for `read_write` keys, through the API's write path; every other kind is a draft (spec §7.28). | 2026-10-01 |
| 89 | [26.5](phase-26.5.md) | Which MCP protocol versions? (found while starting) | Decided | Dual-era: `2026-07-28`, and `initialize` for `2025-11-25` and `2025-06-18`; stateless in both, no session id (spec §7.28). | 2026-10-01 |
| 90 | [26.5](phase-26.5.md) | The MCP PHP SDK or our own? (found while starting) | Decided | Our own small implementation, tested against the specification's JSON schemas (spec §4, §7.28). | 2026-10-01 |
| 91 | [26.5](phase-26.5.md) | What switches MCP off for a user? (found while starting) | Decided | `MCP_ENABLED`, `API_ENABLED` and the key; each tool needs its own module; the AI modules and *Use AI features* don't apply (spec §7.28). | 2026-10-01 |
| 92 | [27.1](phase-27.1.md) | Write-off in the sale pack: only with incidents, or always? | Decided | Only when incidents are included; otherwise a screen-only notice tells the seller (spec §7.29). | 2026-10-01 |
| 93 | [27.1](phase-27.1.md) | Total loss: archive as *Written off*? | Scheduled | Yes, in [Phase 27.2](phase-27.2.md): the settlement as the sale price, the incident linked (spec §7.29 *Total loss*). | 2026-10-01 |
| 94 | [27.1](phase-27.1.md) | `incidents` module on by default? | Decided | On by default, switchable (spec §7.10). | 2026-10-01 |
| 95 | [27.1](phase-27.1.md) | AI reading of insurer letters and repair estimates? | Scheduled | Yes, in [Phase 27.2](phase-27.2.md): scan kinds `claim_letter` and `repair_estimate` (spec §7.27, §7.29). | 2026-10-01 |
| 96 | [27.1](phase-27.1.md) | How are incident photos stored, given every photo is stripped? (found while starting) | Decided | The original bytes after the same checks, not rotated; stripped only as a copy leaves through the sale pack ZIP (spec §7.12). | 2026-10-01 |
| 97 | [27.1](phase-27.1.md) | `draft_incident` over MCP? (found while starting) | Decided | Yes, as a draft for `read_write` keys (spec §7.28). | 2026-10-01 |
| 98 | [27.2](phase-27.2.md) | A total-loss settlement in ownership: sale price or payout? (found while starting) | Decided | The sale price; left out of *Insurance payouts*, with a note, so it counts once (spec §7.7, §7.29). | 2026-10-01 |
| 99 | [27.2](phase-27.2.md) | How does archiving offer *Written off*? (found while starting) | Decided | A disposal reason (`sold` \| `written_off`); one click unless a settled write-off exists, then a confirm page with the sale prefilled (spec §6, §7.29). | 2026-10-01 |
| 100 | [27.2](phase-27.2.md) | A scanned claim letter matching an incident: update or create? (found while starting) | Decided | Update: the matching incident's edit form, by claim number; *Update from a letter* on the incident page (spec §7.27, §7.29). | 2026-10-01 |
| 101 | [27.2](phase-27.2.md) | Store a repair estimate? (found while starting) | Decided | Yes, `repair_estimate` on the incident, shown as information and never counted (spec §6, §7.29). | 2026-10-01 |
| 102 | [27.1](phase-27.1.md) | One phase or two, after #93 and #95? (found while starting) | Decided | Two: 27.1 incidents; 27.2 total loss, reading letters and the v2.10.0 release. | 2026-10-01 |
| 103 | [27.1](phase-27.1.md) | A tyre change linked to a service record: which carries the incident? (found while starting) | Decided | The change follows its record's incident; an unlinked change is linked on its own; costs read from the ledger (spec §7.29). | 2026-10-01 |
| 104 | [27.1](phase-27.1.md) | Who gets an incident photo's GPS? (found while building) | Decided | The original only for those who see the incident's details; anyone else gets an upright, stripped copy as it is served (spec §7.12). | 2026-10-01 |
| 105 | [27.2](phase-27.2.md) | Clearing a sold vehicle's sale date: keep or clear disposal `sold`? (found while starting) | Decided | Clear it; a `written_off` disposal is never changed by the edit form (spec §7.29 *Total loss*). | 2026-10-02 |
| 106 | [28.1](phase-28.1.md) | Run now for members? | Decided | No: admins only (`RunJobs`); members get 404 on every jobs route (spec §7.30 *Access*). | 2026-10-02 |
| 107 | [28.1](phase-28.1.md) | Tell admins when a job fails twice in a row? | Decided | Yes: a dashboard notice while the streak lasts, and once per streak a `job_failed` notification through each admin's own channels (spec §7.30 *Failure alerts*). | 2026-10-02 |
| 108 | [28.1](phase-28.1.md) | How often does `cleanup` run? (found while starting) | Decided | Hourly, so the 24-hour promise for unclaimed scans still holds (spec §5 *Jobs*). | 2026-10-02 |
| 109 | [28.1](phase-28.1.md) | How long are closed invitations kept? (found while starting) | Decided | 90 days after they were used, revoked or expired; open links are never deleted (spec §7.30). | 2026-10-02 |
| 110 | [28.1](phase-28.1.md) | Mask `logbook`, the compose files' default database password, in job output? (found while building) | Decided | No: it is public in the repository, and masking it garbled every file name (spec §5 *Jobs*, *Redaction*). | 2026-10-02 |
| 111 | [28.2](phase-28.2.md) | Pre-releases: an *Include pre-releases* option? | Parked | No: stable releases only (`releases/latest`); the option in spec §12. | 2026-10-02 |
| 112 | [28.2](phase-28.2.md) | The setup checkbox: ticked or unticked? | Decided | Unticked (spec §7.31). | 2026-10-02 |
| 113 | [28.2](phase-28.2.md) | Mark security releases and show their banner even when it is off? | Parked | No: every release alike, the banner setting always applies; marking in spec §12. | 2026-10-02 |
| 114 | [28.2](phase-28.2.md) | Failure alerts for a failed update check? (found while starting) | Decided | No: GitHub's errors are an `ok` run with the error as its summary; only an error in Logbook itself fails the run (spec §7.31). | 2026-10-02 |
| 115 | [28.2](phase-28.2.md) | A renamed repository's `html_url` fails the link check (found while starting) | Decided | Follow the redirect, then record "moved to {owner/name}; set `UPDATE_CHECK_REPO`", no banner; names compared ignoring case (spec §7.31). | 2026-10-02 |
| 116 | [28.2](phase-28.2.md) | Where does *How to upgrade* point? (found while starting) | Decided | GitHub's `docs/deployment.md#upgrading` at the release's tag (spec §7.31). | 2026-10-02 |
| 117 | [28.2](phase-28.2.md) | *Check now* during a rate limit's wait? (found while starting) | Decided | It waits too: no request, "Rate limited by GitHub until {time}" (spec §7.31). | 2026-10-02 |
| 118 | [29.1](phase-29.1.md) | Payment frequency: monthly only, or weekly and four-weekly too? | Parked | Monthly only; other frequencies in spec §12. | 2026-10-02 |
| 119 | [29.1](phase-29.1.md) | Settlement: an "up to" line with extra early-settlement interest? | Parked | No: the present value only, labelled as an estimate; the line in spec §12. | 2026-10-02 |
| 120 | [29.1](phase-29.1.md) | Store the agreement number? | Decided | Yes, optional, masked to its last 4 characters except on the edit form; never in the API, Ask, CSV or sale pack; in backups (spec §6). | 2026-10-02 |
| 121 | [29.1](phase-29.1.md) | Business leases: VAT on rentals and its recovery? | Parked | Out of scope; rentals entered as paid; spec §12. | 2026-10-02 |
| 122 | [29.1](phase-29.1.md) | Cost of credit for a loan, which has no cash price (found while starting) | Decided | Total amount payable − amount of credit; none for a lease (spec §7.32). | 2026-10-02 |
| 123 | [29.1](phase-29.1.md) | Exact cost after a PCP is handed back (found while starting) | Decided | Exact: everything paid − (cash price − final payment); built in [Phase 29.2](phase-29.2.md), a hand back archiving as a sale at the final payment (spec §7.32). | 2026-10-02 |
| 124 | [29.1](phase-29.1.md) | Split Phase 29? (found while starting) | Decided | Yes: [29.1](phase-29.1.md) agreements, figures and costs; [29.2](phase-29.2.md) mileage, ending, the rest and v2.12.0. | 2026-10-02 |
| 125 | [29.1](phase-29.1.md) | Derived finance lines for cost viewers below `Manage` (found while starting) | Decided | They count, as plain *Finance and lease* lines with no agreement detail (spec §7.32 *Access*). | 2026-10-02 |
| 126 | [29.2](phase-29.2.md) | Where do *Selling with finance owing* and the hand-back choices go? (found while starting) | Decided | The archive page: with an active agreement it opens with *Sold*, *Returned to the lender*, *Returned to the lessor*, *Written off* and *Just archive*; the vehicle form is unchanged (spec §7.32 *Archive page*, §7.29). | 2026-10-02 |
| 127 | [29.2](phase-29.2.md) | Excess mileage and damage charges on handing back, and the overlap warning (found while starting) | Decided | Two optional amounts on the *End agreement* form, saved as *Finance and lease* expenses on the end date; an ended agreement's months stop the day before its end date (spec §7.32 *Ending*, *Overlap warning*). | 2026-10-02 |
| 128 | [29.2](phase-29.2.md) | *Coming up* finance lines for `ViewCosts` below `Manage` (found while starting) | Decided | Plain lines with no link or lender, so every viewer's planned total matches (spec §7.18, §7.32 *Coming up*). | 2026-10-02 |
| 129 | [29.2](phase-29.2.md) | The agreement's length for the mileage allowance (found while starting) | Decided | Calendar months from started_on to the end date (spec §7.32 *Mileage*). | 2026-10-02 |
| 130 | [29.2](phase-29.2.md) | Two finance reminders for one agreement, but one row per vehicle, source and source_id (found while building) | Decided | Two sources: `finance` (the final payment) and `finance_end` (*Agreement ends*), both with the agreement as source_id (spec §6 Reminder, §7.32 *Reminders*). | 2026-10-02 |
| 131 | [30.1](phase-30.1.md) | Charging locations as stations? | Decided | Public chargers yes, their charging grades as grades sold; home charging (grade `home`) never linked, skipped by the upgrade and import (spec §6 Station, §7.33). | 2026-10-02 |
| 132 | [30.1](phase-30.1.md) | Who edits and merges shared stations? | Decided | The creator or an admin; anyone adds and favourites (spec §7.33). | 2026-10-02 |
| 133 | [30.1](phase-30.1.md) | Upgrade: group station texts per user or install-wide, and the creator (found while starting) | Decided | Install-wide; creator the owner of the vehicle with the earliest fill-up under that name; country from their locale region or none (spec §7.33 *Upgrading*). | 2026-10-02 |
| 134 | [30.1](phase-30.1.md) | Receipt scans and Ask drafts with a station name (found while starting) | Decided | Link or create by normalised name as the import does, shown in the review step (spec §7.33). | 2026-10-02 |
| 135 | [30.1](phase-30.1.md) | The API's fill-up `station`: object or text? (found while starting) | Decided | Additive: `station` stays the text, `station_id` added, writes take either (spec §7.20, §7.33). | 2026-10-02 |
| 136 | [30.2](phase-30.2.md) | E5 mapping: one admin-chosen mapping, or per station? | Decided | One install-wide mapping an admin chooses: E5 97 by default, or E5 98 or E5 99+ (spec §7.34 *Grade map*). | 2026-10-03 |
| 137 | [30.2](phase-30.2.md) | Road factor: constant 1.3 or a user setting? | Decided | A constant 1.3, labelled wherever it is used (spec §7.34 *Effective cost*). | 2026-10-03 |
| 138 | [30.2](phase-30.2.md) | Price alerts on favourite stations? | Decided | Built in this phase: a price per grade on a favourite linked station, one notification per drop below it, re-armed above it (spec §6 PriceAlert, §7.11, §7.34). | 2026-10-03 |
| 139 | [30.2](phase-30.2.md) | The recorded feed fixture needs One Login credentials (found while starting) | Decided | A synthetic fixture to the published schema, plus `bin/record-fuel-finder.php` to record and trim a real download (spec §4). | 2026-10-03 |
| 140 | [30.2](phase-30.2.md) | Temporary and permanent closures in the feed (found while starting) | Decided | Permanent: treated as removed. Temporary: kept, labelled, left out of rankings, the widget and alerts (spec §7.34 *Closures*). | 2026-10-03 |
| 141 | [30.2](phase-30.2.md) | Prices in pounds instead of pence, and outliers (found while starting) | Decided | Under 2.0 is pounds (× 100); outside 50–500p dropped and counted; stored as pounds per litre (spec §7.34 *Prices*). | 2026-10-03 |
| 142 | [30.2](phase-30.2.md) | Sync cadence when *Sync now* runs in the request (found while starting) | Decided | Incremental each run, full on the first run, with no stations, after a provider change and daily; only a full sync removes (spec §7.34 *Sync job*). | 2026-10-03 |
| 143 | [30.2](phase-30.2.md) | Links by row id would dangle after a restore (found while starting) | Decided | Stations link by `provider` and `provider_ref`, the feed's own id, with no foreign key (spec §6 Station, §7.34 *Linking stations*). | 2026-10-03 |
| 144 | [30.2](phase-30.2.md) | Comparing past fill-ups needs the price at a moment (found while starting) | Decided | Each listed price change of tracked stations is kept; the price in effect at the fill-up's time, reported within 48 hours before it (spec §6 ListedPriceChange, §7.34). | 2026-10-03 |
| 145 | [31](phase-31.md) | The sample Fuelio export | Decided | The owner supplied a CSV export and a backup ZIP; the format is confirmed from them (spec §7.13 *Importing from another app*). | 2026-10-04 |
| 146 | [31](phase-31.md) | LPG and CNG: skip, or add the fuel families? | Decided | Add them: LPG already exists; CNG added in kg with its own consumption series (spec §6 Vehicle, FuelEntry, §7.3 *CNG*). | 2026-10-04 |
| 147 | [31](phase-31.md) | Fuelio GPS trips: skip, or import as private trips? | Parked | Import as private trips with "Fuelio trip" as the places, once an export with trips exists to build against; the sample has none (spec §12). | 2026-10-04 |
| 148 | [31](phase-31.md) | A 213 MB backup against `MAX_UPLOAD_MB` of 10 (found while starting) | Decided | The web page takes the CSV only; backup ZIPs import with `bin/import-app.php` (spec §7.13). | 2026-10-04 |
| 149 | [31](phase-31.md) | The backup's photos are a nested ZIP, `pictures.data` (found while starting) | Decided | Allowed as the one nested archive, by that exact name, one level deep, under the same limits (spec §7.13 *ZIP safety*). | 2026-10-04 |
| 150 | [32](phase-32.md) | UK tax years beside calendar years on the trend? | Parked | Calendar years only for now; tax years in spec §12. | 2026-10-05 |
| 151 | [32](phase-32.md) | Depreciation as time-based or mileage-based? | Decided | Always time-based: *What changed* gives it a distance line (spec §7.35); a per-vehicle option is parked (spec §12). | 2026-10-05 |
| 152 | [32](phase-32.md) | The `true_cost` widget's default period | Decided | *Last 12 months*, with *Since bought* one link away (spec §7.35). | 2026-10-05 |
| 153 | [32](phase-32.md) | Documents dated on one day swing short periods (found while starting) | Decided | Spread over their cover by day for *Last 12 months* and calendar years; *Since bought* unchanged (spec §7.35). | 2026-10-05 |
| 154 | [32](phase-32.md) | A value gain per distance (found while starting) | Decided | A negative part in every period, *Since bought* included; Phase 14.2's per-distance figure changes for gains only (spec §7.1, §7.35). | 2026-10-05 |
| 155 | [32](phase-32.md) | Price and economy split for plug-in hybrids (found while starting) | Decided | Per energy: a price and an economy line for each (spec §7.35). | 2026-10-05 |
| 156 | [32](phase-32.md) | Where insurance payouts go in the breakdown (found while starting) | Answered | Their own *Insurance payouts* line, as spec §7.7 already shows them (spec §7.35). | 2026-10-05 |
| 157 | [33.1](phase-33.1.md) | Verify email addresses? | Decided | Yes: a new address is pending until its 24-hour link is used (spec §7.9 *Email addresses*); #51 stays parked. | 2026-10-05 |
| 158 | [33.1](phase-33.1.md) | "Revoke the user": disable, delete or sign out? | Decided | *Disable* / *Enable* renamed *Revoke access* / *Restore access*; behaviour unchanged (spec §7.9). | 2026-10-05 |
| 159 | [33.1](phase-33.1.md) | Self-service reset lifetime | Decided | 60 minutes; an admin's link stays 7 days (spec §6 Invitation, §7.9 *Forgotten password*). | 2026-10-05 |
| 160 | [33.1](phase-33.1.md) | Reset for SSO-only users with local sign-in on? | Decided | No: nothing sent, the same answer (spec §7.9 *Forgotten password*). | 2026-10-05 |
| 161 | [33.1](phase-33.1.md) | Avatar visibility | Decided | Any signed-in user (spec §7.9 *Avatars*). | 2026-10-05 |
| 162 | [33.1](phase-33.1.md) | Sign in with username or email? (found while starting) | Decided | Either: a username first, else a confirmed address held by exactly one active user with a password (spec §7.9 *Sign-in by username or email*). | 2026-10-05 |
| 163 | [33.1](phase-33.1.md) | Addresses already in the notification preferences: confirmed? (found while starting) | Decided | Yes, on upgrade (spec §6 User). | 2026-10-05 |
| 164 | [33.1](phase-33.1.md) | What a pending address is used for (found while starting) | Decided | Nothing; the old confirmed address stays in use (spec §7.9 *Email addresses*). | 2026-10-05 |
| 165 | [33.1](phase-33.1.md) | Addresses from outside the profile form (found while starting) | Decided | Confirmed without a link: *Add user* once its link is used, OIDC with `email_verified`, the proxy, the sample users (spec §7.9 *Email addresses*). | 2026-10-05 |
| 166 | [33.2](phase-33.2.md) | Settings as sections with their own URLs? (found by the audit) | Decided | No: one page, regrouped, with in-page anchors, as the prototype draws it (spec §8 *Settings layout*). | 2026-10-05 |
| 167 | [33.2](phase-33.2.md) | User management pages the prototype doesn't draw (found by the audit) | Decided | Restyled with the shared card, list-row and button styles; controls unchanged (spec §8). | 2026-10-05 |
| 168 | [33.2](phase-33.2.md) | New things in the prototype's Settings and sign-in | Parked | Webhook formats, *Send at*, *Frequency*, *Reset dashboard layout*, a Settings expenses export, a self-service *Reset password*, a letter-and-number rule (spec §12). | 2026-10-05 |
| 169 | [33.2](phase-33.2.md) | Keep any Settings link where it is? | Decided | No: the draft grouping as it stands (spec §8 *Settings layout*). | 2026-10-05 |
| 170 | [33.2](phase-33.2.md) | Display name in *Account* or *Preferences*? (found while building) | Decided | *Preferences*: it is saved by that form; the *Account* card shows the name (spec §8 *Settings layout*). | 2026-10-05 |
| 171 | [33.2](phase-33.2.md) | Fold the email, picture and password forms under the *Account* card? (found by the design review) | Obsolete | The forms moved to their own profile page (#172), where they stay open. | 2026-10-05 |
| 172 | [33.2](phase-33.2.md) | A profile page from the sidebar's name and avatar? (asked by the owner) | Decided | `/profile` takes the *Account* and *Preferences* groups (revised the same day: preferences too); reached from the sidebar, the narrow top bar's avatar and a Settings row (spec §8 *Profile page*). | 2026-10-05 |
| 173 | [33.3](phase-33.3.md) | Finance bullet: does the brief mean the prototype's finance content? | Decided | Yes: the prototype's Finance tab content (spec §7.32). | 2026-10-05 |
| 174 | [33.3](phase-33.3.md) | Insights the app can't back (economy up, 3-month outlook, yearly fuel saving; draft sources with no example) | Decided | Built in [33.4](phase-33.4.md) as AI insights: the model finds them from the *Ask* tools, generated daily per user and cached, with *Refresh*; shown only when AI is on; grounding check as *Ask* (spec §7.26). | 2026-10-05 |
| 175 | [33.3](phase-33.3.md) | Insights from AI? | Obsolete | The prototype's insights are computed; only *Ask* uses a model ([33.4](phase-33.4.md)). | 2026-10-05 |
| 176 | [33.3](phase-33.3.md) | Business and personal period | Answered | The user's tax year start (spec §6 *Trip settings*, §7.23), which the trips tab uses; no picker in the prototype. | 2026-10-05 |
| 177 | [33.3](phase-33.3.md) | "Your vehicles" on trips: which screen? | Decided | The dashboard *Your vehicles* widget, restyled to the prototype, 3 / 2 / 1 per row (spec §7.8). | 2026-10-05 |
| 178 | [33.3](phase-33.3.md) | Where Insights lives (found by the audit) | Decided | A dashboard *Insights* widget now (computed); the Insights page with *Ask* in [33.4](phase-33.4.md). No overview card (spec §7.1, §7.8). | 2026-10-05 |
| 179 | [33.3](phase-33.3.md) | Vehicle tab order with Finance (found by the audit) | Decided | The prototype's order: … Documents, Incidents, Finance, Expenses (spec §8). | 2026-10-05 |
| 180 | [33.3](phase-33.3.md) | Vehicle name above each tab's title (found by the audit) | Decided | Each tab's title stays the `<h1>`, visually hidden; the name is the one visible heading (spec §8). | 2026-10-05 |
| 181 | [33.3](phase-33.3.md) | Finance tab with several agreements; the overview card (found by the audit) | Decided | The tab is the active agreement's page in the prototype's cards, schedule, extras and quotes under them; earlier agreements listed below. The overview card stays (spec §7.32). | 2026-10-05 |
| 182 | [33.3](phase-33.3.md) | Finance *Purchase* card: seller and mileage when bought not stored (found by the audit) | Decided | Stored: seller, and the odometer when bought as a dated reading (spec §6 Vehicle, §7.1, §7.32). | 2026-10-05 |
| 183 | [33.3](phase-33.3.md) | PCP end note wording (found by the audit) | Decided | Kept as a neutral list of the options (spec §7.32). | 2026-10-05 |
| 184 | [33.3](phase-33.3.md) | Tyre bar scale (found by the audit) | Decided | From the tyre's first measured depth to the legal minimum; no bar until two measurements (spec §7.17). | 2026-10-05 |
| 185 | [33.3](phase-33.3.md) | Other prototype extras: *Breakdown* type, *Copy for insurance quote*, period picker, "Fitted {month}", thresholds note (found by the audit) | Decided | Build *Copy for insurance quote*, *Fitted {month}*, the thresholds note from the user's settings and a *Breakdown* incident type; the period picker is parked (spec §12). Amended by the owner after the design review: a move or rotation reads "Moved {month}", the distance is the tyre's whole distance, and "since {date}" only for a tyre recorded as already on and not fitted or moved since (spec §7.17). | 2026-10-05 |

## Other loose ends found in the review

These aren't questions, but they are unticked boxes in finished phases:

- [Phase 7](phase-7.md) §7.12 and its acceptance list, and
  [Phase 17.2](phase-17.2.md)'s browser check list (Firefox, Safari,
  Android and iOS print preview), are manual checks never ticked.
- The release-tag tasks in Phases 9.2, 10, 10.2, 11.2, 12 and 13 are
  unticked. The tags (`v1.1.0` to `v1.5.0`) exist.