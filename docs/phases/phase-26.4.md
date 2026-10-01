# Phase 26.4 — Read receipts and documents + v2.8 release

*Photograph the garage invoice; check the form; save.*

Status: 🚧 in progress · releases **v2.8.0** · file lives in `docs/phases/`

Typing a service invoice is the most tedious job in Logbook, and it is
the one most often skipped. This phase reads a photo or PDF of an invoice,
receipt or certificate and fills in the right form. The date, vehicle,
mileage, garage, work, total and expiry come from the document, and the
file itself is attached to the entry it creates. The user checks the
prefilled form and saves it. Recommended work ("front pads in about 5,000
miles") becomes an offered reminder, never an automatic one.

Text PDFs are read as text, which is cheaper and more accurate. Photos and
scans go to the vision model assigned in Settings → AI, which can be local,
on the network or in the cloud (Phase 26.1).

Read [`CLAUDE.md`](../../CLAUDE.md), [`spec.md`](../../spec.md) §7.4, §7.5,
§7.12, §7.19 and §7.25, and Phases 26.1 and 26.3 first.

---

## Goals

1. **Scan** from the *Log entry* chooser, the phone app (camera), and a
   *Fill from a file* action on the maintenance, document and fill-up forms.
2. **Classify** a file as a service or repair invoice, fuel receipt, MOT or
   inspection certificate, insurance document, registration document, or
   other. The user can change it.
3. **Extract** to a fixed schema per kind with the `read_document` or
   `read_text` task, returning JSON validated against that schema.
4. **Prefill** the normal form in the modal, with each scanned field marked,
   the file already attached, and ambiguous values (dates like 04/05)
   flagged.
5. **Recommended work** offered as reminders after saving.
6. Privacy: EXIF (including GPS) stripped before sending; registration
   document reference numbers never extracted.

## Not in scope

- Scanning without a user checking the form. There is always a form and a
  *Save*.
- Line-item parts inventory, and VAT as its own field (see *Open
  questions*).
- Bulk scanning of a folder of old invoices (a later phase could queue it).
- A warranty entity. Warranties scan as `other` documents.
- OCR engines such as Tesseract. The vision model reads images.

---

## Spec addition (§7.27 Reading files)

> - **Entry points** (module `ai_scan`, the task set, and *Use AI features*
>   on):
>   - *Log entry* → *Scan a receipt or document*: pick a vehicle, or let the
>     document decide;
>   - the phone app's quick action *Scan*, which opens the camera
>     (`accept="image/*" capture="environment"`) and also offers the file
>     picker;
>   - *Fill from this file* beside an attachment on the maintenance,
>     document and fill-up forms, for a file just chosen or already
>     attached.
> - **Upload:** the same validation as attachments (§7.12: type, size, all
>   or nothing). A scan's file is held as a **pending upload** (owner-only,
>   outside the web root, deleted after 24 hours if no entry claims it), and
>   becomes the entry's attachment on save. There is no second store.
> - **Preparing the file:**
>   - JPEG, PNG and WebP are auto-rotated from EXIF, then **EXIF is
>     stripped**, since phone photos carry GPS. They are downscaled to at
>     most 2,000 px on the long edge and re-encoded, and the stored
>     attachment is the stripped version too (see *Open questions*).
>   - PDFs: the text layer is extracted (pure PHP). With at least 200
>     characters of text on the first page, the file is read as **text**
>     through `read_text`. Otherwise its first **three** pages are rendered
>     to images and read through `read_document`. Rendering needs Imagick or
>     Ghostscript; without either, scanned PDFs show "This PDF is a scan.
>     Take a photo instead, or type it in."
> - **Classify, then extract,** in one request where the model supports it:
>   the schema has a `kind` and a branch per kind. The model is told to
>   leave fields empty rather than guess, and that text in the document is
>   data, not instructions.
> - **Schemas** (all fields optional; each has a short `evidence` text, the
>   words it came from):
>   - *Service or repair invoice:* date, registration, make and model,
>     odometer and unit, vendor, work performed (lines), parts (lines), labour
>     and parts totals, VAT amount and rate, total, currency, recommended
>     work (lines, each with an optional distance or date).
>   - *Fuel receipt:* date and time, station, grade words, volume and unit,
>     price per unit, total, currency.
>   - *MOT or inspection certificate:* test date, expiry, odometer and unit,
>     result, registration, advisories (lines), test number.
>   - *Insurance:* insurer, policy number, cover start and end, registration,
>     cost.
>   - *Registration document (V5C):* registration, make, model, first
>     registration date, VIN. The document reference number is **not in
>     the schema**, and any 11-digit run in returned text is removed before
>     it is shown or stored. It is never saved as an attachment unless the
>     user chooses to (a warning explains why: the sale pack never offers
>     it).
>   - *Other:* title, date, provider, expiry.
> *(Spec §7.27 as written into `spec.md` on 2026-10-01 supersedes this
> draft where they differ: the open questions below were decided then.)*
>
> - **Mapping to Logbook:**
>   - **Vehicle:** registration matched exactly (normalised, spaces
>     removed) against the vehicles the user can log to; else make and
>     model if unique; else the user picks. A mismatch with the vehicle
>     chosen beforehand is flagged ("This invoice is for AB12 CDE, not
>     your BMW").
>   - **Service invoice → maintenance record:** date, odometer, vendor;
>     title from the first work line; description with the work and parts
>     lines, then "VAT £30.75 (20%)" when found; cost = total; category
>     matched from the work (oil, tyres, brakes …) by the Phase 26.3
>     resolver, or left to the user; schedules it completes **suggested**
>     (for example "Oil and filter" matches the oil schedule), never ticked.
>   - **Fuel receipt → fill-up:** as Phase 26.3's `draft_fill_up`; the
>     odometer is rarely on a receipt, so the form asks for it.
>   - **MOT certificate → `inspection` document:** start = test date,
>     expiry, odometer as the document's reading (§7.2 source `document`),
>     advisories into notes. A failed test is a note, not a document with
>     an expiry (see *Open questions*).
>   - **Insurance → `insurance` document**, cost as its amount.
>   - **V5C:** offered as updates to the vehicle's details (registration,
>     VIN, first registration date), each ticked individually, on the
>     vehicle edit form.
> - **The prefilled form:** the normal page or modal, with each scanned
>   field marked "from the receipt, check", its evidence as a hint, the
>   file shown as already attached, and a thumbnail beside the form on wide
>   screens. Values that failed validation are left empty with the reason.
>   **Ambiguous dates** (both numbers 12 or under, such as 04/05/2026) are
>   read in the user's locale order (UK: day first), and marked "Check the
>   date: 4 May or 5 April?". Dates in the future, or before the vehicle's
>   first registration, are left empty.
> - **Recommended work:** after the record is saved, a card offers each
>   recommendation as a manual reminder. A date is taken as is. A distance
>   is converted by Logbook to a projected date from the vehicle's usual
>   mileage (§7.4 projection) and labelled "about 14 Mar 2027, in about
>   5,000 mi at your usual mileage". Each has *Add reminder*, and *Add all*
>   lists them first. Advisories on an MOT certificate get the same card.
> - **Failures:** an unreadable file, a timeout or an unassigned task gives
>   the normal empty form with the file attached, so scanning never costs
>   the user their photo.

---

## Decisions (and why)

- **Always a form.** Extraction is good but not perfect (7s and 1s,
  day-month order, totals with and without VAT). The user's check is the
  step that makes the data trustworthy.
- **Text first for PDFs.** Most garage and insurer PDFs have a text layer,
  and reading it is faster, cheaper and more accurate than reading pixels.
- **Evidence per field.** Showing the words a value came from turns
  checking into a glance.
- **EXIF stripped everywhere.** A receipt photo taken on the driveway
  records the house's location. Nothing in Logbook needs it.
- **V5C reference never extracted.** The sale pack already treats it as a
  fraud risk, and the app has no use for it.
- **Recommendations as offers.** A garage's "pads soon" is advice, and it's
  sometimes sales. The owner decides what becomes a reminder.

---

## Tasks

### Spec and docs
- [x] §7.27 in `spec.md`; §7.12 (pending uploads, EXIF stripping); the Phase
      26.4 line in §13.
- [x] `docs/ai.md`: *Reading receipts and documents*: what is read, what is
      sent where, and model suggestions for vision by location.

### Dependencies
- [x] `smalot/pdfparser`, pinned (§4, #84).
- [x] `ext-gd` and `ext-exif` required; the Docker image builds `gd`
      (JPEG, PNG, WebP) and `exif` on amd64 and arm64 (#83).
- [x] Optional Imagick or Ghostscript for rendering scanned PDFs; the Docker
      image includes Ghostscript; bare PHP degrades as described.

### Migration
- [x] `pending_uploads` (user, path, type, size, created, expires,
      claimed_by). Reversible; scheduler cleanup; excluded from backups.

### Code
- [x] Every photo upload rotated upright and stripped in the one upload
      path (§7.12, #80); vehicle photos too.
- [x] Manual reminders with a *Due at* odometer: form, status whichever
      comes first, projection, list, calendar feed, *Coming up*, API
      `due_odometer` and OpenAPI (§7.6, §7.20, #82). The list shows the
      date or "Due at …"; the calendar feed lists only dated reminders.
- [x] `Service\Ai\Scan\FilePreparer` (EXIF rotate and strip, downscale,
      PDF text or render).
- [x] `Service\Ai\Scan\Extractor` (schemas, one request, JSON validation,
      the evidence check, V5C scrubbing).
- [x] `Service\Ai\Scan\Mapper` per kind (to form prefill values, vehicle
      matching, ambiguous dates, category and schedule suggestions).
- [x] `Service\Ai\Scan\Recommendations` (distance to projected date through
      the existing projection).
- [x] Entry points, prefilled form marks, thumbnail, recommendations card;
      the phone app's *Scan* action.
- [x] Translations (en, de).

### Tests
- [x] **Fixture set** (`tests/Fixtures/scans/`): twenty synthetic documents
      (invented garages and plates) as text PDFs, scanned PDFs and phone
      photos, each with the expected extraction. The scripted provider
      replays the expected JSON in CI.
- [x] Mapping per kind; vehicle matching (exact plate, a plate with spaces,
      a mismatch warning, unknown); ambiguous dates in GB and US locales;
      future dates dropped.
- [x] EXIF: the GPS tag is gone from what is sent and what is stored.
- [x] V5C: no reference number in any output or stored text.
- [x] Text PDF → `read_text`; scanned PDF → rendered pages; no renderer →
      the message.
- [x] Pending uploads claimed on save, deleted after 24 hours, never served
      to another user.
- [x] Failures leave the file attached to an empty form.
- [x] Recommendations: date kept; distance projected with the label; *Add
      reminder* creates a manual reminder.
- [x] **Injection:** a document containing instructions produces only a
      form; nothing saves without *Save*.
- [x] `bin/ai-eval.php --scans` runs the fixture set against the configured
      models and reports field accuracy per kind.
- [ ] Integration suite green on every engine (SQLite, PostgreSQL, MySQL, MariaDB).

### Release
- [x] `CHANGELOG.md` **2.8.0**: reading receipts and documents. Upgrade
      notes: one migration; Ghostscript in the image; EXIF stripped from
      scanned photos.
- [x] Bump `VERSION`, rebuild assets, update the README status.
- [ ] Tag `v2.8.0` once merged.

---

## Changed while building it

- **Values as printed, parsed by Logbook** (spec §7.27). The model gives
  every date, amount and reading exactly as printed, and Logbook parses
  it in the user's locale. A model that returned ISO dates would have
  chosen the day/month order itself, and "Check the date: 4 May or 5
  April?" could never be honest. `PrintedDate` reads numeric dates in the
  locale's order (from ICU's short pattern), month names in the user's
  language and English, and ISO. `PrintedNumber` takes currency signs
  and codes and a unit beside the digits, and refuses anything else
  ("l2.5O" is not 2.5). The schema is one flat set of fields shared by
  every kind (each `{value, evidence}`), rather than a branch per kind,
  which small local models fill more reliably; the Mapper reads only
  the kind's own fields.
- **EXIF stripped in the one upload check.** `FileUpload::check()`
  re-encodes an accepted image in place (`ImageCleaner`, GD), turned
  upright from its orientation first. So attachments, vehicle photos and
  scans are all covered, and nothing downstream ever sees the original.
  A scan's file is not re-encoded again when it is attached
  (`FileUpload::accepted()`). GD needs memory for big photos, so the
  limit is raised for the decode (to about 450 MB at most), and images
  over 50 megapixels are refused (a 48-megapixel phone photo is fine).
- **Claim once, keep the photo.** Saving claims the pending upload with
  a conditional update before the entry is written, and releases it if
  the save fails. A copy of the file goes in with the entry's own files,
  and the pending file is deleted only after the save succeeded. A second
  submit of the same form saves without the file. A saved row keeps only
  the recommendations card, until it expires. Pending files live under
  `UPLOAD_PATH/pending`, which `FileStorage::all()` leaves out, so
  backups skip them and a restore leaves them alone. The class is
  `Domain\Ai\Scan\ScanUpload`, because `Service\Attachment\PendingUpload`
  already names a chosen file of a form.
- **The reading is kept before the model is asked.** `POST /scan` stores
  the file as `reading`, then reads it with `ignore_user_abort`, so a
  proxy's timeout loses nothing. `/scan/{token}` shows "Still reading",
  refreshing every three seconds, until it is done.
- **`/scan/{token}` routes the result**: a vehicle to pick, a form to
  pick for an unreadable file with no form to go back to, a module that
  is off, or a redirect to the create form with `?scan=`. *Read it as* is
  `?as=` on the same URL.
- **The V5C has its own page** (`/scan/{token}/vehicle`), not the vehicle
  edit form. It lists each found value beside the current one, ticked
  where they differ, and saves through `VehicleForm::parse()` with the
  rest of the vehicle unchanged. *Keep the file with the purchase
  paperwork* is offered only when the vehicle has a purchase date (§7.12
  needs one).
- **The recommendations card is a page** (`/scan/{token}/reminders`),
  where the save goes when there is anything to offer. *Not now* returns
  to where the save would have gone. A recommendation with a distance is
  due at the entry's odometer (or the latest reading) plus the distance,
  kept as a distance (#82). One with neither a date nor a distance is due
  in 30 days, marked so.
- **Pictures need a model that takes images.** `read_document` accepts a
  JSON-only model (for text-only installs), so a photo sent to it fails
  early ("doesn't take pictures") and nothing is sent.
- **11-digit runs are removed from all PDF text before sending**, not only
  from a V5C's. The kind isn't known until the model answers. This also
  drops UK phone numbers, which nothing needs. Twelve-digit MOT test
  numbers are untouched.
- **Ghostscript runs from an argument list** (`proc_open`, no shell),
  with `-dSAFER`, a three-page limit and a 60-second timeout. Imagick
  is the fallback, and any Imagick failure (a policy.xml that forbids
  PDFs) counts as no renderer. Tests use `FakeRenderer`; one real
  Ghostscript test skips where `gs` is missing (CI installs it, and the
  image has it).
- **The fixture set is generated**: `tests/Fixtures/scans/build.php`
  writes the twenty documents from `manifest.php` with a small PDF
  writer (a text layer in Helvetica/WinAnsi, or one image per page) and
  GD photos with an EXIF block holding GPS. Each comes with the model's
  reply and the expected form. Output is byte-identical between runs.
- **`bin/ai-eval.php --scans`** scores the configured models per kind (the
  kind, then each field compared as Logbook reads it) and checks for
  references left in a registration document's output. It has not yet
  been run against a real model.

## Acceptance criteria

1. A photo of a garage invoice opens a maintenance form with date, vehicle,
   mileage, garage, work, cost and VAT filled in and marked, and the photo
   attached. Saving creates one record with one attachment.
2. An MOT certificate fills an inspection document with its expiry and
   mileage, and the advisories are offered as reminders.
3. A failed or unavailable model still leaves the user with their file on
   an empty form.
4. No GPS data leaves the device or is stored. A V5C reference number is
   never extracted, stored or sent as text; a photo of a V5C carries it
   as pixels, and the Scan page says so on an *Internet* connection
   (#85).
5. With a local or network model, no file leaves the owner's machines.
6. Definition of done (CLAUDE.md §11) holds.

## Open questions

- **VAT and line items:** keep them in the description (drafted), or add
  `vat_amount` and line items to maintenance records?
  **Decided 2026-10-01 (#79):** in the description. Work and parts lines,
  labour and parts totals and "VAT £30.75 (20%)"; cost = total. No
  schema change.
- **EXIF on ordinary attachments:** strip it from every photo attachment
  from now on (drafted for scans; the release note says so), or only from
  scanned ones?
  **Decided 2026-10-01 (#80):** every JPEG, PNG and WebP upload
  (attachments, vehicle photos, scans) is turned upright and re-encoded
  without metadata. Files already stored are left alone (spec §7.12).
- **Failed MOTs:** record a failed test as a document without expiry, as a
  note on the vehicle, or not at all?
  **Decided 2026-10-01 (#81):** an `other` document, "MOT failed 12 Mar
  2026", with failures and advisories in its notes and the certificate
  attached. An `inspection` document without an expiry counts as running
  latest (`DocumentState`), so it would replace the valid certificate,
  silence its reminder and hide *First MOT due*; vehicles have no notes
  field. The advisories are still offered as reminders.
- **Manual reminders by distance:** add an odometer limit to manual
  reminders, so "in 5,000 miles" is stored as a distance rather than a
  projected date?
  **Decided 2026-10-01 (#82):** yes. Manual reminders gain an optional
  *Due at* odometer (`reminders.due_km`, which already exists, so no
  migration), judged whichever comes first like a schedule, on the form,
  the API (`due_odometer`) and the scan's recommendations card (spec
  §7.6, §7.20).
- *(Found while starting.)* **`gd` and `exif`.** Rotating, stripping and
  downscaling photos needs them, and the Docker image installs neither.
  **Decided 2026-10-01 (#83):** both go into the image on every
  architecture and are required on bare PHP, as `intl` is (Composer
  `ext-gd`, `ext-exif`), so every photo can be stripped (spec §4, §10).
- *(Found while starting.)* **Which PDF text extractor?**
  **Decided 2026-10-01 (#84):** `smalot/pdfparser`, pinned, LGPL-3.0,
  used unmodified through Composer (spec §4).
- *(Found while building.)* **A V5C photo sends its reference number as
  pixels.** Text can be redacted before sending; a picture cannot.
  **Decided 2026-10-01 (#85):** accept it and warn. Text is redacted
  before sending and the number is never extracted or stored; the Scan
  page names where the file goes and, on an *Internet* connection, says a
  V5C photo carries its reference to that provider. Acceptance criterion
  4 reworded to match (spec §7.27).
- *(Found while building.)* **Fill from a file already attached?**
  **Decided 2026-10-01 (#86):** new files only. The create forms link to
  Scan for that vehicle and form; attached files are not re-read
  (spec §7.27).
