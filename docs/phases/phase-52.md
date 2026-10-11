# Phase 52 — Import from Tracktor + release

*Bring a whole Tracktor garage across: vehicles, fill-ups, services,
documents and reminders.*

Status: 📋 planned · file lives in `docs/phases/`

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

**Prerequisites:** [Phase 45.3](phase-45.3.md) complete: hardening passed (no feature phase starts before it).
Independent of Phases 46 to 51.

---

## Tracktor's export, as read from its source

Read from `javedh-dev/tracktor` at `dev`, commit `a60e826` (4 Sep 2026,
`package.json` 2.0.0). To be confirmed against real exports in 50.1.

- **Envelope:** `{version: "1.0", timestamp, encrypted, data}`. With
  `encrypted` true, `data` is a string holding `{iv, salt, data}`, all in
  hex. The cipher is AES-256-GCM, with the key from scrypt(password,
  salt, 32) at Node's defaults (N = 16384, r = 8, p = 1) and a 16-byte
  IV. The GCM tag is appended to `data` after a `:`.
- **`data`** has these keys:
  - `vehicles`, `fuelLogs`, `maintenanceLogs`, `complianceDocuments` and
    `reminders`: these are read.
  - `notifications`, `notificationProviders` and `configs`: of these,
    only `configs` is read, for the units, currency and date format.
    Provider credentials are encrypted with the Tracktor server's
    `APP_SECRET`. They are never read, decrypted or stored.
- **Ids:** every row has a UUID `id` that stays the same in every export
  (a database dump), so re-importing a newer export adds only new rows.
- **Vehicles:** `make`, `model`, `year`, `licensePlate`, `vin`, `color`,
  `odometer` (a current reading with no date), `image` (a file name),
  `fuelType` (`petrol` | `diesel` | `electric` | `lpg` | `cng`),
  `vehicleType` (`car`, `motorcycle`, `scooter`, `truck`, `van`, `bus`,
  `farm_vehicle`, `yacht`, `rv`, `other`) and `customFields` (a JSON
  object of strings).
- **Fuel logs:** `date` (ISO), `odometer`, `fuelAmount`, `rate`, `cost`,
  `filled` (a full tank), `missedLast` (the previous fill was not
  logged), `notes` and `attachment`.
- **Maintenance logs:** `date`, `odometer` (required), `serviceCenter`,
  `cost`, `notes` and `attachment`. There is no title and no category.
- **Compliance documents:** `type` (`insurance` | `emissions` |
  `roadworthiness` | `registration` | `other` with `otherLabel`),
  `documentNumber`, `issuer`, `startDate`, `endDate`, `recurrenceType`
  and `recurrenceInterval`, `cost`, `notes` and `attachment`. Older
  Tracktor versions kept insurance and pollution certificates in
  separate tables, dropped in August 2026.
- **Reminders:** `type` (`maintenance`, `insurance`, `pollution`,
  `registration`, `inspection`, `custom`), `dueDate`, `remindSchedule`,
  recurrence, `note` and `isCompleted`.
- **Units and money:** `configs` holds `unitOfDistance` (`kilometer` |
  `mile`), `unitOfVolume` (`liter` | `gallon`, US or UK not stated),
  `unitOfLpg`, `unitOfCng`, `currency` and `dateFormat`. Amounts and
  quantities are JSON numbers stored as SQLite `REAL`.
- **Files are not in the export.** `attachment` and `image` name files in
  Tracktor's `UPLOADS_DIR` (`/data/uploads` in its Docker image).

---

## Goals

1. Settings → *Import from another app* accepts a Tracktor JSON export.
   `bin/import-app.php` accepts it too, plus an optional folder or ZIP of
   Tracktor's uploads for the attachments and photos (open question C).
2. Mapping:
   - vehicles to new or existing ones;
   - units, including which gallon (open question B), with Phase 31's
     economy sanity check;
   - document types, maintenance categories and reminder types to
     Logbook's.
3. A preview and one transaction across every vehicle. `import_sources`
   with app `tracktor`, so a newer export adds only what's new.
4. Docs (`docs/import-tracktor.md`), fixtures and release.

## Not in scope

- Writing Tracktor's format (no export to Tracktor).
- Notifications, notification providers, Tracktor's settings and its
  dashboard layout.
- Live sync or reading Tracktor's SQLite file directly.

---

## Mapping (proposed)

Settled in the spec in 50.0, with each mapping shown on Phase 31's
mapping page.

| Tracktor | Logbook |
|---|---|
| vehicle `make`, `model`, `year`, `licensePlate`, `vin` | make, model, year, registration, VIN |
| `vehicleType` | `car` for car, van, truck, rv and other; `bike` for motorcycle and scooter. The rest are highlighted and default to `car` (open question E) |
| `fuelType` `electric` | `ev`; the others to the same code |
| `color`, `customFields` | the vehicle's notes, one `key: value` per line |
| vehicle `odometer` | a `manual` reading dated the export's `timestamp`, only when higher than every imported reading |
| fuel log | a fill-up. `filled` = full; `missedLast` = missed previous. Any two of `fuelAmount`, `rate` and `cost` derive the third, as the form does; a row with all three keeps them and is flagged if they disagree by more than a cent |
| maintenance log | a service record. The title is the first line of `notes`, or *Service* when blank; the category is chosen on the mapping page (default `service`); `serviceCenter` becomes the vendor |
| compliance `insurance`, `emissions`, `roadworthiness`, `registration`, `other` | insurance, pollution certificate, inspection, registration, other (`otherLabel` as its title); `documentNumber` and `issuer` to the matching fields |
| reminder, not completed | a manual reminder (open question D) |
| `attachment`, `image` | a file on the row, or the vehicle photo, when the uploads are given (C) |

- **Numbers** are read from their shortest decimal form (the JSON text)
  into `Decimal`, never through float arithmetic. Dates are calendar
  dates. Tracktor's `created_at` is ignored.
- **Recurring documents** keep their dates. Recurrence becomes a
  schedule or reminder only if the owner ticks *Make schedules*, as in
  Phase 31.

---

## Tasks

### 50.0 Spec first
- [ ] Open questions A–F decided by the owner and logged; §7.13 (the
      Tracktor reader), §4 if A adds a dependency, §13; `ROADMAP.md`
      row.

### 50.1 Fixtures
- [ ] Real exports from current Tracktor, anonymised, into
      `tests/Fixtures/import/tracktor/`: metric and imperial, one with an
      EV, one encrypted (if A), and one from before the compliance merge
      if one can be had (F).

### 50.2 Reader
- [ ] `Import\App\Tracktor\TracktorReader`: envelope, version check,
      decryption (A), configs, rows to the shared import rows; unknown
      keys listed as not read.
- [ ] Safety: the size limit, JSON depth limit, row limit (20,000 across
      sections, as Fuelio) and the uploads folder or ZIP under Phase 31's
      ZIP limits, with paths confined to it.
- [ ] Mapping page entries for the gallon, document types, maintenance
      category and reminder types.

### 50.3 Pages, docs and release
- [ ] *Tracktor* on Settings → *Import from another app*; the CLI's
      `--files`.
- [ ] `docs/import-tracktor.md`: exporting from Tracktor, what comes
      across and what doesn't, files on the command line.
- [ ] Translations (every shipped locale).
- [ ] Tests: each fixture end to end on every engine; a re-import adds
      nothing; a newer export adds only new rows; amounts to the penny;
      the gallon check; a wrong password and a tampered file refused with
      nothing written; provider credentials never read.
- [ ] `VERSION`, `CHANGELOG.md`, README; `ROADMAP.md` row ✅; tag once
      merged.

---

## Acceptance criteria

1. A Tracktor user can bring every vehicle, fill-up, service record,
   document and open reminder across in one import, and see it all in
   the preview first.
2. Importing a newer export later adds only what's new.
3. Economy and totals match Tracktor's for the same data, allowing for
   Logbook's full-to-full rules.
4. Definition of done (CLAUDE.md §11) holds.

## Open questions

Numbered in `open-questions.md` when logged. Not built until decided.

- **A. Encrypted exports.** PHP has no built-in scrypt.
  - (1) Refuse them, with "Export again from Tracktor without a
    password", and park support in §12.
  - (2) Support them with a small, tested pure-PHP scrypt.
  - (3) Support them with a Composer package.

  *Recommendation:* (1). Exporting again is one click, and it avoids
  carrying key-derivation code for one reader.

- **B. Which gallon?** Tracktor doesn't say.
  - (1) Ask on the mapping page, preselected from the owner's locale and
    checked by Phase 31's economy sanity check.
  - (2) Always US.

  *Recommendation:* (1).

- **C. Attachments and photos.**
  - (1) On the command line only, with `--files <dir|zip>` pointing at
    Tracktor's uploads, as Fuelio's photos are.
  - (2) Also as a second upload on the web page.
  - (3) Not at all.

  *Recommendation:* (1). The web page names any files left behind.

- **D. Reminders.**
  - (1) Open reminders become manual reminders, and completed ones are
    skipped.
  - (2) All of them, the completed ones marked done.
  - (3) None, since schedules and documents raise their own.

  *Recommendation:* (1). Maintenance-type reminders are offered as
  schedules under *Make schedules*.

- **E. Vehicle types Logbook doesn't have** (bus, farm vehicle, yacht).
  - (1) Import them as `car`, highlighted.
  - (2) Leave them out.

  *Recommendation:* (1). Their fill-ups and services still make sense.
  The `van` type stays parked (#46).

- **F. Exports from before Tracktor's compliance merge** (separate
  insurance and pollution tables).
  - (1) Read them too, if a real one can be had as a fixture.
  - (2) Current format only, with older files refused and the reason
    given.

  *Recommendation:* (1) if a fixture exists, else (2). Nothing is read
  without a real file to test against.
