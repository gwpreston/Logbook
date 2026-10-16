# Phase 51 — Import from Tracktor + release

*Bring a whole Tracktor garage across: vehicles, fill-ups, services,
documents and reminders.*

Status: ✅ complete · releases **v3.16.0** · file lives in `docs/phases/`

[Tracktor](https://github.com/javedh-dev/tracktor) is the self-hosted
vehicle tracker (SvelteKit, SQLite, MIT licence) whose feature list
Logbook's spec started from. Its users are the most likely people to try
Logbook, and today they would have to retype everything.

Tracktor has one export: *Settings → Export*, a single JSON file holding
every table, optionally encrypted with a password. This phase adds a
**Tracktor reader** to Phase 31's app importer. The mapping, preview,
single transaction and `import_sources` steps are shared, so this phase
writes only a reader and its mappings.

Logbook isn't affiliated with Tracktor.

Read [`CLAUDE.md`](../../CLAUDE.md) and [`spec.md`](../../spec.md) §7.13
(*Importing from another app*, and Phase 31's Fuelio reader as the
pattern), §6 (`import_sources`, Vehicle, FuelEntry, MaintenanceEntry,
ComplianceDocument, Reminder), §7.3, §7.5 and §7.6 first.

**Prerequisites:** none.
Independent of Phases 45 to 50.

---

## Tracktor's export, as read from its source

Read from `javedh-dev/tracktor` at `dev`, commit `a60e826` (4 Sep 2026,
`package.json` 2.0.0), and confirmed on 2026-10-16 against real exports
made with Tracktor's own Docker images, 2.1.0 and 1.4.1.

- **Envelope:** `{version: "1.0", timestamp, encrypted, data}`. With
  `encrypted` true, `data` is a string holding `{iv, salt, data}`, all in
  hex. The cipher is AES-256-GCM, with the key from scrypt(password,
  salt, 32) at Node's defaults (N = 16384, r = 8, p = 1) and a 16-byte
  IV. The GCM tag is appended to `data` after a `:`.
- **`data`** has these keys:
  - `vehicles`, `fuelLogs`, `maintenanceLogs`, `complianceDocuments` and
    `reminders`: these are read.
  - `notifications`, `notificationProviders` and `configs`: of these,
    only `configs` is read, for the units and currency. It is a list of
    `{key, value, description, created_at, updated_at}`. Provider
    credentials are encrypted with the Tracktor server's `APP_SECRET`.
    They are never read, decrypted or stored.
- **Ids:** every row has a UUID `id` that stays the same in every export
  (a database dump), so re-importing a newer export adds only new rows.
- **Dates** are the browser's local midnight of the date picked, as an
  ISO instant in UTC: the London fixture's 30 March is
  `2026-03-29T23:00:00.000Z`. Tracktor's server doesn't normalise them, so
  the reader takes the date in the owner's zone when the instant is
  midnight there, else the nearest UTC midnight's (found by the real
  exports in 51.1; spec §7.13).
- **Vehicles:** `make`, `model`, `year`, `licensePlate`, `vin`, `color`,
  `odometer` (a current reading with no date), `image` (a file name),
  `fuelType` (`petrol` | `diesel` | `electric` | `lpg` | `cng`),
  `vehicleType` (`car`, `motorcycle`, `scooter`, `truck`, `van`, `bus`,
  `farm_vehicle`, `yacht`, `rv`, `other`) and `customFields` (a JSON
  object of strings, itself a string).
- **Fuel logs:** `date`, `odometer` (optional since January 2026),
  `fuelAmount`, `rate`, `cost`, `filled` (a full tank), `missedLast` (the
  previous fill was not logged), `notes` and `attachment`.
- **Maintenance logs:** `date`, `odometer` (required), `serviceCenter`,
  `cost`, `notes` and `attachment`. There is no title and no category.
- **Compliance documents:** `type` (`insurance` | `emissions` |
  `roadworthiness` | `registration` | `other` with `otherLabel`),
  `documentNumber`, `issuer`, `startDate`, `endDate`, `recurrenceType`
  (`none` | `yearly` | `monthly` | `no_end`) and `recurrenceInterval`,
  `cost`, `notes` and `attachment`. A recurring document is saved with no
  `endDate`; Tracktor shows the next anniversary as its due date.
- **Older exports (Tracktor 1.x, before August 2026)** have `insurances`
  (`provider`, `policyNumber`, `startDate`, `endDate`, recurrence, `cost`,
  `notes`, `attachment`) and `puccs` (`certificateNumber`, `issueDate`,
  `expiryDate`, recurrence, `testingCenter`, `notes`, `attachment`)
  instead of `complianceDocuments`, no `reminders`, no `vehicleType`, and
  also `users` (password hashes) and `sessions`, which are never read.
- **Reminders:** `type` (`maintenance`, `insurance`, `pollution`,
  `registration`, `inspection`, `custom`), `dueDate`, `remindSchedule`
  (`same_day`, `one_day_before`, `three_days_before`, `one_week_before`,
  `one_month_before`), recurrence (`none`, `daily`, `weekly`, `monthly`,
  `yearly`), `note` and `isCompleted`.
- **Units and money:** `configs` holds `unitOfDistance` (`kilometer` |
  `mile`), `unitOfVolume` (`liter` | `gallon`, US or UK not stated),
  `unitOfLpg` and `unitOfCng` (`liter`, `gallon`, `kilogram`, `pound`),
  `currency` and `dateFormat`. Amounts and quantities are JSON numbers
  stored as SQLite `REAL`.
- **Files are not in the export.** `attachment` and `image` name files in
  Tracktor's `UPLOADS_DIR` (`/data/uploads` in its Docker image), as
  `<epoch ms>-<original name>`.

---

## Goals

1. Settings → *Import from another app* accepts a Tracktor JSON export,
   with an optional ZIP of Tracktor's uploads folder (#465).
   `bin/import-app.php` accepts it too, plus `--files <folder|zip>`.
2. Mapping:
   - vehicles to new or existing ones, matched by Tracktor's ids (#469);
   - units, including which gallon (#464), with Phase 31's economy
     sanity check;
   - document types, the maintenance category and reminders to Logbook's.
3. A preview and one transaction across every vehicle. `import_sources`
   with app `tracktor`, so a newer export adds only what's new.
4. Docs (`docs/import-tracktor.md`), fixtures and release.

## Not in scope

- Writing Tracktor's format (no export to Tracktor).
- Notifications, notification providers, Tracktor's settings and its
  dashboard layout.
- Live sync or reading Tracktor's SQLite file directly.
- Password-protected exports (#463, parked in spec §12).

---

## Mapping

Settled in spec §7.13 *The Tracktor reader* in 51.0, with each mapping
shown on Phase 31's mapping page.

| Tracktor | Logbook |
|---|---|
| vehicle `make`, `model`, `year`, `licensePlate`, `vin` | make, model, year, registration, VIN |
| `vehicleType` | `bike` for motorcycle and scooter; `car` for the rest. Bus, farm vehicle and yacht are highlighted (#467) |
| `fuelType` `electric` | `ev`; the others to the same code |
| `color`, `customFields` | listed as not imported (#470): a Logbook vehicle has no notes |
| vehicle `odometer` | a `manual` reading dated the export's `timestamp`, only when higher than every imported reading and the target's latest |
| fuel log | a fill-up. `filled` = full; `missedLast` = missed previous. Any two of `fuelAmount`, `rate` and `cost` derive the third, as the form does; a row with all three keeps them and is marked if they disagree by more than a cent. LPG by weight and CNG by volume are not imported; CNG in pounds becomes kg (#471) |
| maintenance log | a service record. The title is the first line of `notes`, or *Service* when blank; the category is chosen on the mapping page (default `service`); `serviceCenter` becomes the vendor |
| compliance `insurance`, `emissions`, `roadworthiness`, `registration`, `other` | insurance, pollution certificate, inspection, registration, other (`otherLabel` as its title); `documentNumber` and `issuer` to the matching fields |
| 1.x `insurances`, `puccs` | insurance (`provider`, `policyNumber`) and pollution certificate (`testingCenter`, `certificateNumber`) (#468) |
| reminder | a manual reminder; a completed one marked done (#466) |
| `attachment`, `image` | a file on the row, or the vehicle photo, when the uploads are given (#465) |
| `configs` `currency` | a new vehicle's currency when it differs from the owner's; a warning for an existing vehicle in another currency (#472) |

- **Numbers** are read from their shortest decimal form (the JSON text)
  into `Decimal`, never through float arithmetic. Dates are calendar
  dates. Tracktor's `created_at` is ignored.
- **Recurring documents** keep their dates. Recurrence becomes a
  schedule or reminder only if the owner ticks *Make schedules*, as in
  Phase 31: an open maintenance reminder recurring monthly or yearly
  becomes a service schedule, and a recurring document without an end
  takes the next due date Tracktor shows as its expiry.

---

## Tasks

### 51.0 Spec first
- [x] Open questions A–F decided by the owner and logged (#463–#468),
      with G–J found while starting it (#469–#472); §7.13 (the Tracktor
      reader), §6 ImportSource, §12, §13; `ROADMAP.md` row. A adds no
      dependency.

### 51.1 Fixtures
- [x] Real exports from Tracktor 2.1.0 and 1.4.1, made with its own
      Docker images and invented data, into
      `tests/Fixtures/import/tracktor/`: metric (two vehicles, files),
      imperial with an EV and a bus, one encrypted, one from before the
      compliance merge (F), and the uploads folder as a ZIP.

### 51.2 Reader
- [x] `Import\App\Tracktor\TracktorReader`: envelope, version check,
      encrypted exports refused (A), configs, rows to typed records;
      unknown keys listed as not read.
- [x] Safety: the size limit, JSON depth limit, row limit (20,000 across
      sections, as Fuelio) and the uploads folder or ZIP under Phase 31's
      ZIP limits, with paths confined to it.
- [x] `TracktorImporter`: guess, analyse and import in one transaction,
      through the same forms and services as typing them in; mapping page
      entries for the gallon, document types, maintenance category and
      reminders; *Make schedules*.

### 51.3 Pages, docs and release
- [x] *Tracktor* on Settings → *Import from another app*, with the
      uploads ZIP (C); the CLI's `--files`.
- [x] `docs/import-tracktor.md`: exporting from Tracktor, what comes
      across and what doesn't, files on the web and the command line.
- [x] Translations (every shipped locale).
- [x] Tests: each fixture end to end on every engine; a re-import adds
      nothing; a newer export adds only new rows; amounts to the penny;
      the gallon check; a password-protected and a tampered file refused
      with nothing written; provider credentials never read.
- [x] `VERSION`, `CHANGELOG.md`, README; `ROADMAP.md` row ✅.
- [ ] Tag `v3.16.0` once merged.

### Design review (2026-10-16)

On the dev stack (PostgreSQL), with a Tracktor export of three vehicles
made to show every state: gallons, a row without an odometer, amounts
that disagree, a bus, files left behind. Upload, map, preview and
result at 375, 768 and 1280 px, light and dark. The steps are a GET form
and POST forms, so they work without JS; the subpath is covered by
`ImportTracktorTest::testWorksBehindASubpath`. No page scrolls sideways;
the row tables scroll in their own frame, as Fuelio's do.

| # | Severity | Finding | Done |
|---|---|---|---|
| 1 | MEDIUM | The section headings inside a vehicle's card used the page-level `section-title`, larger than the card's own title. | Fixed: `card__subtitle`. |
| 2 | MEDIUM | The sanity line lower-cased the units: "uk gallons", and German nouns too. Fuelio's page had the same. | Fixed for both: `ImportAppAction::inSentence()`. |
| 3 | LOW | Document rows showed Tracktor's codes ("roadworthiness"). | Fixed: the type's label. |
| 4 | LOW | The options card repeated the page's hidden heading. | Fixed: its own, "Reminders and schedules". |
| 5 | LOW | At 375 px a long "A new vehicle: …" option is cut off in its closed select, as on Fuelio's page; the open list shows it whole. | Kept. |
| 6 | LOW | "Not read: notifications, notificationProviders" uses Tracktor's own names for its lists. | Kept: they are what the export calls them. |

---

## Acceptance criteria

1. A Tracktor user can bring every vehicle, fill-up, service record,
   document and reminder across in one import, and see it all in the
   preview first.
2. Importing a newer export later adds only what's new.
3. Economy and totals match Tracktor's for the same data, allowing for
   Logbook's full-to-full rules.
4. Definition of done (CLAUDE.md §11) holds.

## Open questions

Decided by the owner on 2026-10-16 and logged as #463–#472 in
`open-questions.md`, before it was built.

- **A. Encrypted exports.** *Decided 2026-10-16 (#463): the
  recommendation.* PHP has no built-in scrypt.
  - (1) Refuse them, with "Export again from Tracktor without a
    password", and park support in §12.
  - (2) Support them with a small, tested pure-PHP scrypt.
  - (3) Support them with a Composer package.

  *Recommendation:* (1). Exporting again is one click, and it avoids
  carrying key-derivation code for one reader.

- **B. Which gallon?** *Decided 2026-10-16 (#464): the recommendation.*
  Tracktor doesn't say.
  - (1) Ask on the mapping page, preselected from the owner's locale and
    checked by Phase 31's economy sanity check.
  - (2) Always US.

  *Recommendation:* (1).

- **C. Attachments and photos.** *Decided 2026-10-16 (#465): (2), on the
  command line and as a second upload on the web page.*
  - (1) On the command line only, with `--files <dir|zip>` pointing at
    Tracktor's uploads, as Fuelio's photos are.
  - (2) Also as a second upload on the web page.
  - (3) Not at all.

  *Recommendation:* (1). The web page names any files left behind.

- **D. Reminders.** *Decided 2026-10-16 (#466): (2), all of them, the
  completed ones marked done.*
  - (1) Open reminders become manual reminders, and completed ones are
    skipped.
  - (2) All of them, the completed ones marked done.
  - (3) None, since schedules and documents raise their own.

  *Recommendation:* (1). Maintenance-type reminders are offered as
  schedules under *Make schedules*.

- **E. Vehicle types Logbook doesn't have** (bus, farm vehicle, yacht).
  *Decided 2026-10-16 (#467): the recommendation.*
  - (1) Import them as `car`, highlighted.
  - (2) Leave them out.

  *Recommendation:* (1). Their fill-ups and services still make sense.
  The `van` type stays parked (#46).

- **F. Exports from before Tracktor's compliance merge** (separate
  insurance and pollution tables). *Decided 2026-10-16 (#468): the
  recommendation; a real 1.4.1 export was made, so they are read.*
  - (1) Read them too, if a real one can be had as a fixture.
  - (2) Current format only, with older files refused and the reason
    given.

  *Recommendation:* (1) if a fixture exists, else (2). Nothing is read
  without a real file to test against.

- **G. Importing an imported export into another vehicle** (found while
  starting) *Decided 2026-10-16 (#469): match by Tracktor's ids.* A
  Tracktor vehicle already imported is prefilled with the Logbook vehicle
  holding its ids, so a re-import never duplicates; choosing another
  vehicle shows a warning. Option: as Fuelio (ids per target vehicle, no
  warning).
- **H. Colour and custom fields** (found while starting) *Decided
  2026-10-16 (#470): listed as not imported.* Options: add vehicle notes
  (a migration); put a variant into Logbook's variant.
- **I. LPG and CNG in units Logbook doesn't record** (found while
  starting) *Decided 2026-10-16 (#471): convert or skip.* CNG in pounds
  becomes kg and LPG in gallons uses the chosen gallon; LPG by weight and
  CNG by volume are not imported, with the reason. Option: skip every
  odd unit.
- **J. Tracktor's currency** (found while starting) *Decided 2026-10-16
  (#472): a new vehicle takes it.* When it differs from the owner's; an
  existing vehicle in another currency gets a warning. Amounts are never
  converted. Option: ignore it.
