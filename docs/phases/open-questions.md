# Open questions

Every *Open questions* item from the phase files, with its status. Read
this before starting a phase ([`CLAUDE.md`](../../CLAUDE.md) §12).

- **Answered:** the app already does something definite, recorded as a
  choice in the spec or pinned by a test. The last column says where.
- **Parked:** recorded as future work in [`spec.md`](../../spec.md) §12, not
  scheduled.
- **Scheduled:** decided, with the work in a later phase.
- **Needs a decision:** still open, and an answer would change the app. The
  owner decides; nothing is built until then.
- **Obsolete:** no longer applies. The last column says why.

The first review (Phase 20, 2026-09-30) covered Phases 0–19. Phases 0–8,
9.2, 10.2, 17.2 and 18.1 have no *Open questions* section. Phase 8's intro
promises one "at the end", but none was written and nothing in it was left
undecided.

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
| 12 | [11.2](phase-11.2.md) | Tread depth per zone (inner / centre / outer) | Needs a decision | Not built: one `tread_mm` per change line. Parked "until demand". | pending |
| 13 | [11.2](phase-11.2.md) | Rotation suggestion when fronts wear faster | Needs a decision | Not built. Parked for "a later maintenance-insights phase", which isn't planned. | pending |
| 14 | [12](phase-12.md) | Remember the last print choice (*Show costs*)? | Needs a decision | Not built: `PrintOptions` reads `costs=1` from the query only. "Wait and see." | pending |
| 15 | [12](phase-12.md) | A hint on filing the V5C as *Registration* vs the invoice under *Bought* | Needs a decision | Not built: the purchase paperwork hint doesn't mention it. | pending |
| 16 | [13](phase-13.md) | Seasonal baselines for economy checks | Needs a decision | Not built: the baseline is the previous 10 segments, minimum 5 (`EconomyCheck`). "Revisit if winter flags are common." | pending |
| 17 | [13](phase-13.md) | One *sensitivity* setting for economy checks | Answered | spec §7.3: "Not in scope: … thresholds as settings". The bands are a fixed constant in `EconomyCheck`. | 2026-09-30 |
| 18 | [13](phase-13.md) | Detect a sudden, sustained change | Needs a decision | Not built. Parked for "a later maintenance-insights phase", which isn't planned. | pending |
| 19 | [14.1](phase-14.1.md) | Insurer's agreed value as a valuation | Needs a decision | Not built. "Wait for demand." | pending |
| 20 | [14.1](phase-14.1.md) | A valuation's mileage as context | Needs a decision | Not built: valuations hold date, amount, source and notes only. "Left out to keep one mileage series." | pending |
| 21 | [14.2](phase-14.2.md) | Dashboard tile or widget for cost of ownership | Needs a decision | Not built. spec §7.7: "Not on the dashboard yet: … need a design look before a fifth." | pending |
| 22 | [14.2](phase-14.2.md) | Business mileage | Scheduled (in part) | [Phase 22](phase-22.md) adds trips, claim reports and a *Business mileage* widget. A *cost per business mile* figure isn't in it, so that part needs a decision (#23). | 2026-09-30 |
| 23 | [14.2](phase-14.2.md) | Cost per business mile, once trips exist | Needs a decision | Not in Phase 22. | pending |
| 24 | [15](phase-15.md) | Recurring expenses (road tax, permits) in *Coming up* | Needs a decision | Not built: expenses have no interval. Repeats apply to schedules and documents only (spec §7.18). | pending |
| 25 | [15](phase-15.md) | A *Needs attention* list on the overview | Scheduled | [Phase 24](phase-24.md): overview card, dashboard widget and garage marker. Not a score. | 2026-09-30 |
| 26 | [15](phase-15.md) | Price drift in forecast costs | Needs a decision | Not built: forecasts show "about £240 (last time)" (spec §7.18). "Leave it out unless owners ask." | pending |
| 27 | [16](phase-16.md) | Reference grade: most used by volume, or `default_grade`? | Answered | Most used by volume in the last 12 months, ties to the latest (spec §7.3 *Grade verdict*; `GradeComparison::reference()`; `GradeComparisonTest::testTheReferenceIsTheMostUsedGradeByVolumeInTheLastYearTiesToTheLatest`). | 2026-09-30 |
| 28 | [16](phase-16.md) | Diesel blends: one price-pair rule, or relaxed for diesel? | Answered | One rule for petrol and diesel: 3 pairs within 30 days (spec §7.3; `GradeComparison::MIN_PAIRS`; `testFewerThanThreePairsGivesNoVerdict`). | 2026-09-30 |
| 29 | [16](phase-16.md) | Minimum months before *Economy by month* appears | Answered | None: it shows once one month has a figure (spec §7.3; `SeasonalEconomyTest::testNoMonthWithAFigureHidesTheCard`). | 2026-09-30 |
| 30 | [17.1](phase-17.1.md) | Vehicle photo in the sale pack | Scheduled | Optional and off by default, on a cover page before the summary ([Phase 21.1](phase-21.1.md)). | 2026-09-30 |
| 31 | [17.1](phase-17.1.md) | 31 days between purchase and first reading, or "since the first reading on …"? | Needs a decision | Today: past 31 days, *Owned since* stays and only the distance is left out (spec §7.19; `OwnershipSpan::MAX_DAYS`; `OwnershipSpanTest::testAFirstReading45DaysLaterLeavesTheDistanceOut`). | pending |
| 32 | [18.2](phase-18.2.md) | `API_ENABLED` default | Answered | `true` (`AppSettings`, `.env.example`, docs/configuration.md). A fresh install has no key. | 2026-09-30 |
| 33 | [18.2](phase-18.2.md) | Per-vehicle API keys | Answered | None: a key has its user's access. spec §7.20 *Not in this version*: a device gets its own user. | 2026-09-30 |
| 34 | [19](phase-19.md) | Do admins see every vehicle? | Answered | No: their own and shared ones only (spec §7.21; `SharedVehicleAccess`; `SharedVehicleAccessTest::testOwnVehiclesOnlyWithEveryAbility`). | 2026-09-30 |
| 35 | [19](phase-19.md) | Do Log shares see documents? | Answered | Yes, documents are part of View (spec §7.21; `/documents` needs View in `config/routes.php`). | 2026-09-30 |
| 36 | [19](phase-19.md) | Self-service password reset by email | Answered | No: an admin's one-time reset link (spec §7.9; `UsersTest::testAResetLinkEndsSessionsAndSetsANewPassword`). | 2026-09-30 |

## Other loose ends found in the review

These aren't questions, but they are unticked boxes in finished phases:

- [Phase 7](phase-7.md) §7.12 and its acceptance list, and
  [Phase 17.2](phase-17.2.md)'s browser check list (Firefox, Safari,
  Android and iOS print preview), are manual checks never ticked.
- The release-tag tasks in Phases 9.2, 10, 10.2, 11.2, 12 and 13 are
  unticked. The tags (`v1.1.0` to `v1.5.0`) exist.
