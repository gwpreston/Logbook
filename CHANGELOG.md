# Changelog

All notable changes to Logbook are recorded here. Database changes are always
shipped as reversible migrations; any upgrade step beyond "pull and restart"
is called out explicitly.

## [Unreleased]

## [3.19.6] — 2026-10-23

Phase 53.14: **Claude scans and large imports from the known-issues log**
([docs/phases/phase-53.14.md](docs/phases/phase-53.14.md)). No
migration: pull and restart.

### Fixed
- **Reading a document with a Claude model works.** A Claude model whose
  *Test* had recorded JSON Schema output failed every scan ("The scan
  failed"): Anthropic refuses the length limits in the scan's schema and
  allows only 16 fields that may be null. A Claude model is now sent a
  simpler form of the same schema, every field present and `""` for
  "not shown", which Logbook reads back as before and checks with the
  same limits, so what a scan stores doesn't change. A scan whose JSON
  Schema request a provider refuses is tried once more as a tool call,
  and *Test* checks JSON output with the scan's own schema, so the way it
  records is one a scan works in (K132, #553).
- **A large fuel CSV imports in about a third of the time.** Committing
  5,000 fill-ups took 20 seconds on PostgreSQL in our test setup (41 on
  slower hardware), close to PHP's 30-second limit, where the page went
  blank and nothing was imported. The import no longer reads the file
  twice, looks each station up once, saves the fill-ups in one pass, and
  the request may run as long as a job's (`JOB_TIME_LIMIT`). The same
  file now takes about 7 seconds, and its preview under a second (K49).
- **A CSV import holds the file in memory once, not three times.** The
  file is read from disk a row at a time. With `MAX_UPLOAD_MB` raised a
  long way, a file too large for PHP's `memory_limit` is refused with a
  message instead of an error page (K129).

### Changed
- **The Reminders page shows *Upcoming* 25 at a time**, with *Sooner*
  and *Later* links (they work without JavaScript); the heading still
  gives the whole count, and overdue and due are always shown in full.
  Each part ticked to remind of its warranty is upcoming from the day
  it's ticked, so the list could run to hundreds (K19, #555).
- **The reminder job costs the same for every vehicle.** It reads each
  table once for all vehicles, as a page does, and adds new warranty
  reminders a hundred at a time (K19).

## [3.19.5] — 2026-10-23

Phase 53.13: **CSV import round trip and part warranty details from the
known-issues log**
([docs/phases/phase-53.13.md](docs/phases/phase-53.13.md)). No
migration: pull and restart.

### Fixed
- **Importing Logbook's own CSV export gives back what was written.**
  The export puts an apostrophe before text a spreadsheet would run as a
  formula (starting with `=`, `+`, `-`, `@`, a tab or a line break), and
  the import used to keep it: a station `@x` came back as `'@x`, and a
  new station `'@x` was added to the station list. Every module's import
  now takes that one apostrophe off, as a spreadsheet does, so a file
  saved again from a spreadsheet reads the same. This changes what an
  import stores: a cell such as `'=x` is imported as `=x`. An apostrophe
  before anything else (`'abc`) is kept (K48, #455).
- **A date written year first imports under every date order.** With
  the file's dates set to day-first or month-first, `2026-04-06` was
  refused with "Enter a valid date and time", though `2026-04-06 09:15`
  imported. It now imports at noon, as under ISO; a four-digit year
  first can't be mistaken for a day or a month (K57, #459).
- **Editing a part with no warranty has *Purchase date* chosen** under
  *Warranty starts from*, as a new part's form does, so a warranty added
  on edit is stored the same way as one added new (K71, #487).
- **The part page says when the warranty reminder is on:** "Reminder
  before it ends" in the warranty card when *Remind me before it ends*
  is ticked, and nothing about it when it isn't (K39, #547).

## [3.19.4] — 2026-10-22

Phase 53.12: **Tap targets, narrow screens and SQLite search from the
known-issues log**
([docs/phases/phase-53.12.md](docs/phases/phase-53.12.md)). No
migration: pull and restart.

### Fixed
- **Small controls are easier to tap on a phone.** Soft buttons such as
  *Review…* and *Mark declared*, the Economy tabs, back links, card
  links such as *All reminders*, the rows that open a section and the expense
  breakdown's links each take a tap at least 44 × 44 px wide, and look
  as they did. The Parts pages' fitting-row links and the insurer
  page's titles too (K16, K35, K55, #457).
- **A *Coming up* row with a cost** keeps its title readable at phone
  width and in a narrow dashboard column: "about £189.00 (last time) as
  of Oct 2025" wraps under its pill instead of squeezing the title to
  nothing, on the dashboard, a vehicle's overview and *Coming up* (K41).
- ***Mark all declared* with no date** on *For your insurer* says "Enter
  the date you told your insurer" under the date field and marks
  nothing. Before, a banner said the date mustn't be in the future
  (K16).
- **On SQLite, searching AI models and linking a fuel station by name**
  finds names with `_`, `%` or capitals such as `Ö`: `gpt_4` finds
  `gpt_4o` (and not `gpt-4o`), `50%` finds "Sale 50% off", `öl` finds
  "Ölhandel Müller", as on the other databases (K51).

## [3.19.3] — 2026-10-22

Phase 53.11: **Warranty reminders, Ask's figures and the sign-in log
from the known-issues log**
([docs/phases/phase-53.11.md](docs/phases/phase-53.11.md)). No
migration: pull and restart.

### Fixed
- **A "Warranty ends" reminder you marked *Checked it*** stays checked
  when its part is taken off and kept as a spare, and again when it is
  refitted. Before, a warranty with a distance limit opened again, and
  you could be notified again. Editing the warranty still opens it
  (K28).
- **A stored part's distance warranty** is paused while the part is off
  the vehicle, and its page says so: "In warranty until 3 Mar 2029,
  distance paused while off the vehicle", or "In warranty, paused while
  off the vehicle" when there is only a distance limit. A stored part
  with only a distance limit has no reminder; one with a time limit
  reminds by its date (K29, #448).
- **Ask Logbook's figure check** reads an answer's numbers the way your
  language writes them: `£1,459 per litre` in English is now marked
  "Logbook didn't provide this figure" when the price was 1.459, instead
  of passing. A value copied as the tool gave it (`1.459`) still
  matches. A figure written in another language's format is marked too
  (K47, #454).
- **Thousands grouped with a plain space** (`1 234,56 €`,
  `Cost 1 234.56`) are read as one figure by the check, as no-break
  spaces already were (K59).

### Security
- **A line break in a sign-in username** can't write a log line of its
  own: everything Logbook logs from outside is written on one line. Before,
  a crafted username could write a "failed sign-in" line with someone
  else's address, so fail2ban banned them instead of the sender (K45,
  #461).

### Documentation
- **The deployment guide** gives a fail2ban filter and jail for failed
  sign-ins, checked with `fail2ban-regex`, and says how to log to a file
  in Docker.

## [3.19.2] — 2026-10-21

Phase 53.10: **Scan and import edges from the known-issues log**
([docs/phases/phase-53.10.md](docs/phases/phase-53.10.md)). No
migration: pull and restart.

### Fixed
- **A CSV header naming its unit in common English words** sets the
  file's unit on the mapping page, whatever your own units:
  `Volume (litre)`, `(liter)`, `(ltr)`, `(UK gallon)`,
  `(imperial gallons)`, `(imp gal)`, `(US gallon)`, `(U.S. gal)`,
  `Odometer (mile)`,
  `(kilometers)` and the like. Before, only Logbook's own unit names and
  symbols were read, so a US-gallon owner's `Volume (litre)` file
  imported 10 litres as 37.854. A German `Menge (Gallonen)` is read as
  your gallon, as `(gal)` is (K124, #544).

### Security
- **The V5C update page** opens only for a scan read as a registration
  document: a *Scan the V5C* scan, the model's reading, or one you chose
  to *Read as* a registration document. A receipt's scan opened there by
  a crafted address is not found, so it can't be filed as a
  registration document (K136).

### Documentation
- **The 3.19.0 notes** say that after going back to 3.18.x, a *Scan the
  V5C* that couldn't be read in the last 24 hours opens as "not found"
  (K135).
- **The import guide** lists the unit words a CSV header may use.

## [3.19.1] — 2026-10-21

Phase 53.9: **Install, dev-stack and test-run edges from the known-issues
log** ([docs/phases/phase-53.9.md](docs/phases/phase-53.9.md)). No
migration: pull and restart. Bare-PHP installs: see the upgrade note.

### Fixed
- **A bare-PHP install without the mbstring extension** is now refused
  by `composer install`, which names `ext-mbstring`, instead of
  installing and then failing with "Call to undefined function
  mb_strcut()" at its first AI request, Ask thread, capped job output or
  scanned amount. The deployment guide lists it (K123, #528). Logbook
  no longer installs `symfony/polyfill-mbstring`, which stood in for the
  extension and let such an install through (#550).
- **`php bin/import-app.php`** on an install with no users yet says to
  finish first-run setup in the browser, instead of asking for `--as`
  as though there were more than one user. Tracktor and Fuelio both
  (K127).

### Development
- **`bin/smoke-test.sh`** runs its stack under `COMPOSE_PROJECT_NAME`
  when it is set (`logbook-smoke` otherwise) and keeps its response
  files in a folder of its own, so two runs with different names and
  ports no longer remove each other's containers and volumes (K121).
- **The dev stack** runs `composer install` on start when
  `composer.lock` has changed since `vendor/` was installed, so a pull
  that changes the lock reaches the `vendor` volume without a manual
  install or `bin/dev-setup.sh --reset`. The production image is
  unchanged (K128).
- **The tests that expect no session secret** pass in the dev stack's
  `app` container, which sets `SESSION_SECRET_FILE`, as they do in CI
  (K122).

### Upgrade notes
- **Bare PHP:** the `mbstring` extension is now required. Check with
  `php -m | grep mbstring` before upgrading; if it is missing, install it
  (Debian/Ubuntu: `apt install php8.4-mbstring`) and restart PHP, or
  `composer install` will stop. The Docker image already has it.
- **Dev stack:** the new check is part of the dev image, so it takes
  effect once the image is rebuilt (`bin/dev-setup.sh` does this, or
  `docker compose -f docker-compose.dev.yml up --build`; a plain `up`
  keeps the old image). The first start after that runs
  `composer install` once, since `vendor/` has no record of the lock it
  was installed from yet.

## [3.19.0] — 2026-10-20

Phase 53.8: **V5C privacy and cross-check accuracy**
([docs/phases/phase-53.8.md](docs/phases/phase-53.8.md)). See
[docs/reading-files.md](docs/reading-files.md). No migration: pull and
restart. Going back to 3.18.x: a *Scan the V5C* that couldn't be read in
the last 24 hours opens as "not found" instead of its reason; scan the
file again after upgrading to see it (Phase 53.10, K135).

### Added
- **Scan the V5C**, on a vehicle's page (the *Details* card) and its
  *Documents* tab, for those who can edit the vehicle. The file is read
  as that vehicle's registration certificate whatever OCR spots: its
  reference number is removed in every form (punctuation, OCR's
  look-alikes, wrapped lines) on every route, and on an internet
  connection only its text is sent, never the picture. With OCR off,
  failing or reading too little, nothing is sent, and the page says why
  (#511, K104, K108).

### Changed
- **A passing V5C marker no longer empties a document's figures.** One
  marker ("DVLA", "Document reference", OCR's "VSC") still keeps a photo
  from going to an internet connection as a picture, but the wider
  reference rule now needs "V5C" or "Registration Certificate", or two
  different markers. "Diagnostic VSC warning light" on an invoice, or
  "DVLA" on an insurance letter, keeps its amounts and dates (#513,
  K109).
- **The cross-check flags far fewer right values.** A value with digits
  is marked "Not found on the page" only where OCR read every word with
  a digit on its line at a confidence of 90 or more. On the fixture
  photos, right values marked fall from 29 of 57 to 2; the price is that
  a wrong digit is now marked only where OCR read its line that clearly
  too, so on those blurred photos 0 of 41 planted wrong digits were
  caught, against 39 before (#514, #533, K110, K141).
- **A fill-up whose figures don't add up** shows the sum once, under the
  total; the volume and the price say "Doesn't add up with the total."
  (#531, K106).
- Rolling back 3.18.0's migration now names each connection with *Send
  text, not images* on, and warns that pictures go to it once upgraded
  again (#532, K107).

### Documentation
- *Which model?* in [docs/ai.md](docs/ai.md#which-model) now says what
  each task needs from a model, what the test documents measured, and
  how to set up a local model to read photos.

### Fixed
- **Scanning with a local model in LM Studio** (or llama.cpp) no longer
  opens a nearly empty form: the answer's format now names every field
  (empty when not on the document), so such servers no longer let a model
  answer an empty `{}` for each field or leave the fields out (#534,
  K130).
- A V5C photo on a connection Settings last saw on your network, whose
  address is on the internet again, is treated as going to the internet:
  OCR first, and text only (#530, K112).
- A request to an AI connection goes to the address its location was
  checked against, so a name that answers one address to the check and
  another to the request can't skip the internet acknowledgement or the
  V5C text-only rule (K138).
- *Scan the V5C* for a vehicle you can't edit says why, also when you
  have no vehicle to log to (K137).
- Rolling back 3.18.0's migration no longer stops on a connection whose
  name contains something like `<options=…>` (K133).
- A date beside a V5C reference is kept, and the reference removed:
  `Issued 14/10/2026 12345 678901` used to lose the date and keep the
  reference's last six digits (K131).
- A V5C reference wrapped over lines is removed when a label or date
  shares its line (`Issued 01/03/2018 1234 5678` / `901`), or a date
  follows it (`901 14/10/2026`), keeping the date (K111).
- The cross-check no longer marks a value with no digits ("Petrol",
  "Shoreline Fuels Ltd") because letters in it looked like digits, and
  compares only a value's own digits (`EV70 LTR` needs `70`) (K113).
- A V5C's title is no longer counted as "not on the page" because its
  reference was removed from OCR's line (K117).
- A scanned PDF whose page size is inherited from its page tree, or
  scaled by `/UserUnit`, is rendered small, like one whose own page is
  oversized (K80).

## [3.18.3] — 2026-10-20

Phase 53.7: **API, webhook and MCP edges from the known-issues log**
([docs/phases/phase-53.7.md](docs/phases/phase-53.7.md)). No migration:
pull and restart.

### Fixed
- **Editing a modification's title, notes, cost or category** no longer
  sends an `entry.updated` webhook for each part it fitted or took off.
  A part is announced only when the edit changes its fitting or removal:
  a new date or mileage, a different outcome, sale price or replacement,
  or a part fitted or removed. The same holds for service records (K24).
- **A modification's `ETag`** (`GET /vehicles/{id}/modifications/{mod}`)
  now changes only with what its body shows: the modification, its
  revert, the fittings it made, and the name of each part. Editing a
  part's notes, price or warranty no longer makes an `If-Match` on the
  modification fail with 412 (K25, #507).
- **An MCP `tools/call`** checks only the tool it was asked for, instead
  of every tool: a `parts` call sends 9 database queries, not 54. A
  switched-off or unknown tool still answers `Unknown tool` (K69).
- **Multiplying or dividing very large decimals** (past what a PHP
  integer holds) is exact, through `brick/math`, instead of falling back
  to floating point and coming out 1–3 off in the last place. No figure
  a real entry has reaches it (K62).

### Upgrade notes
- Every modification's `ETag` changes once on upgrade, even if nothing
  about it changed. An API client holding a tag from 3.18.2 gets a 412
  on its next `If-Match` write to that modification: read it again,
  then retry. Rolling back to 3.18.2 brings the old tags back.

## [3.18.2] — 2026-10-20

Phase 53.6: **Import fixes from the known-issues log**
([docs/phases/phase-53.6.md](docs/phases/phase-53.6.md)). No migration:
pull and restart.

### Fixed
- **A CSV's volume unit named in its header** (`Volume (UK gallons)`,
  `Quantity (litres)`, `Volume (US gal)`) is now chosen on the mapping
  page, as `Odometer (Miles)` already was for distance. A gallon that
  doesn't say which, `(gal)` or `(gallons)`, is your gallon: your volume
  unit if you use gallons, else the UK gallon for a UK or Irish locale or
  pounds, else the US gallon, as for app imports. `(kWh)` and `(kg)`
  leave your own unit chosen (K2).
- **A CSV saved as UTF-16** (Excel's *Unicode text*) now imports with
  its text intact: `Łódź €` used to come in as `Aódz ¬`, and the first
  header kept the byte-order mark (K10).
- **`php bin/import-app.php <export.json>`** (Tracktor) with rows that
  have problems now stops with exit code 1 and writes nothing unless
  `--skip-invalid` is given, as the Fuelio command line and the web
  page do. Each problem names the export's field (`date`), not
  Logbook's (`filled_at`) (K65).
- **A damaged UTF-16 CSV** (cut off, so it ends in half a character) is
  refused as not a CSV, instead of importing with a stray `?` in its
  last cell, or failing with an error page on an install without the
  mbstring extension (K125).
- **`php bin/import-app.php`** (Fuelio and Tracktor) now exits 1 when
  rows have problems even if nothing else is left to import, for example
  when it is run again after `--skip-invalid`. It used to say "Nothing to
  import" and exit 0 (K126).

## [3.18.1] — 2026-10-19

Phase 53.5: **API edges and narrow-screen fixes from the known-issues
log** ([docs/phases/phase-53.5.md](docs/phases/phase-53.5.md)). No
migration: pull and restart.

### Fixed
- **Retrying `POST /vehicles/{id}/modifications`** that names one of the
  vehicle's stored parts in `parts_fitted` or `parts_removed` now answers
  with the modification already logged and `duplicate: true`, as a retry
  should. It used to answer 422 `part_id`, because the first call had
  already fitted or removed that part. A body with invalid fields is
  still a 422 (K27).
- **`POST` and `PATCH` on `/vehicles/{id}/schedules`** with a
  `baseline_odometer` or `interval_distance` that is too large now answer
  422 `validation.too_large` on that field, as `interval_km` does. They
  used to answer 500 (K50).
- **Fuel stations on a phone:** at 375 px, *Cheapest near me* and a
  station's page no longer scroll sideways as a whole page. Only their
  tables scroll, inside their boxes. This applies to every scrolling
  table with hidden labels (K52).
- **The reminders count on the active sidebar link** meets 4.5:1
  contrast in the light and dark themes with every accent. It sat on the
  link's accent tint and fell to 3.9:1 (K53).

## [3.18.0] — 2026-10-19

Phases 53.3 and 53.4: **OCR as a check on the vision model, text instead
of images, and reading without AI** ([docs/phases/phase-53.3.md](docs/phases/phase-53.3.md),
[docs/phases/phase-53.4.md](docs/phases/phase-53.4.md)). See
[docs/reading-files.md](docs/reading-files.md). One migration (a
connection option and three usage-log counts); it rolls back, but roll
back only with *Send text, not images* off everywhere, since older
releases send pictures on every connection.

### Added
- **Scanning without AI.** With OCR working and no model reading for you
  (none set up, `AI_ENABLED=false`, or your *Use AI features* off), *Scan*
  still shows and reads the file on this server by rules: nothing is
  sent anywhere and nothing is logged. A fuel receipt fills its volume,
  price and total only when they add up (else just the total), with the
  date, grade, currency and, when it is one of yours, the station; an
  invoice its date, total, registration and mileage, with its work
  lines copied into the description; a V5C its registration; anything else its date. Every
  field is marked *Read without AI, check*, and *Read it as* switches
  kind without reading again ([reading-files.md](docs/reading-files.md#reading-without-ai)).
- `bin/ai-eval.php --scans --via=rules`: the fixture set read without AI.
- **OCR checks the vision model.** With a vision model and OCR working,
  OCR reads the same photo alongside the model. A value whose words or
  digits aren't on the page where OCR read clearly is kept and marked
  "Not found on the page, check this carefully." The usage log counts the
  values found, marked and unchecked per scan.
- **The money adds up.** On a fuel receipt read any way, volume × price
  per unit is checked against the total (within 1p and the printed
  rounding); if it doesn't add up, the three fields show the sum.
- **Send text, not images**, a per-connection option, off by default:
  photos and scanned PDFs are read by OCR on this server and only their
  redacted text is sent. No image is ever sent on such a connection.
- **V5C photos on internet connections go as text** when OCR works: OCR
  reads first, and a photo with a V5C's markers is sent as its text,
  without the reference number, or not at all if OCR read too little.
- `bin/ai-eval.php --scans --crosscheck` (marks per kind and false marks)
  and `--ocr --enlarge=min:target` (compare OCR enlarge rules).

### Changed
- The *Read receipts* module is now `scan` (was `ai_scan`), listed on
  Settings → Modules whenever a model or OCR can read a file, AI set up
  or not. A saved `ai_scan` choice and `FEATURES_AI_SCAN` still apply;
  `FEATURES_SCAN` wins when both are set.
- A user with *Use AI features* off never has a file sent to a model,
  even with Scan open to them.
- The document's text sent to a model is fenced by markers it can't
  contain, so a `>>>` in a document no longer ends the block (K120).
- On a V5C (text with its markers) the reference is removed with
  punctuation between its groups (`1234-5678-901`, K93), and on the OCR
  route also with OCR's look-alikes (`9O1`) or wrapped over rows (K87). Numbers in another
  column of the same row are no longer removed with it (K88).
- OCR enlarges only text under 15 px (was 20 px): the eval found the
  wider rule lowered accuracy and read an MOT photo's 0s as 6s (K85).
- Checking a value's words against OCR text is much faster on long
  evidence (K89).
- On an internet connection that gets photos as text, the Scan page now
  warns that a V5C reference OCR misreads or splits is removed only when
  OCR recognises the V5C; otherwise it can still be sent (K108). The
  *Send text, not images* hint says so too.

### Fixed
- On a receipt in another currency (euros for a car kept in pounds),
  the "doesn't add up" line shows the receipt's currency, not the
  vehicle's; with no currency read, plain numbers (K115).
- A V5C reference wrapped onto the next row, under its first group and
  beside a label (`Document reference number 1234 5678` / `901`), is now
  removed from OCR text too, so it is never sent to a model or kept.
- An OCR run abandoned when a vision request failed is stopped before its
  temporary directory is removed, so its text is never left behind
  (K116).
- The Scan page works out how photos are read once, and reads each AI
  model and connection once per page: 11 queries instead of 26 (K118).
  OCR text no longer builds its layout twice (K119).
- A fill-up from a scan whose station name was left empty opens *Station
  and notes*, so the reason is visible (K90).
- The Scan page's text-recognition line wraps beside its icon (K95).
- A Tesseract that fails its version check is not asked for its
  languages too (K94).
- Scanned parts lines keep a size or a letter after a number when no
  price follows ("Tyre 205 x 55", "Fuse 10 A"; K96, K97), and `00,759`
  reads as 0.759 in every locale (K98).

### Upgrade notes
- One migration, adding columns only (well under a second on every
  engine). Every connection starts with *Send text, not images* off: turn
  it on per connection (Settings → AI → the connection → *Edit*).
- Rolling back drops each connection's *Send text, not images* and the
  per-scan cross-check counts; 3.17 then sends pictures on every
  connection. Upgrading again brings every connection back with the option
  **off**, so turn it on again where it was on.
- If you set `FEATURES_AI_SCAN` in your environment, it still works;
  rename it to `FEATURES_SCAN` when convenient.
- With OCR working, Scan now shows to users with AI off, and on installs
  with no AI. Switch the *Read receipts* module off if you don't want it.
- Backups from this release carry *Send text, not images*. A 3.17.0 backup
  restores the usual way: restore with 3.17.0, then upgrade.

## [3.17.0] — 2026-10-18

Phases 53.1 and 53.2: **reading text from images, and scanning photos
with a text-only model** ([docs/phases/phase-53.1.md](docs/phases/phase-53.1.md),
[docs/phases/phase-53.2.md](docs/phases/phase-53.2.md)). No migration:
pull and restart. The Docker image is about 80 MB larger (see *Upgrade
notes*).

### Added
- **Reading text from images (OCR).** Logbook can read the text in a
  photo or scanned PDF on its own server with Tesseract (in the Docker
  image, with English and German), or with a PaddleOCR service you run
  (`OCR_URL`; an optional `ocr` compose profile builds one). Settings →
  AI has a *Reading text from images* card and page showing whether it
  works, with a *Test it* button.
- **Scanning photos with a text-only model.** With no model that reads
  images, a photo or scanned PDF is read by OCR first and only its text
  goes to your text model, into the same forms as before. The picture is
  never sent, and runs of 11 digits (a V5C's reference number) are
  removed from the text first. A vision model, when set up, still comes
  first. See [docs/reading-files.md](docs/reading-files.md).
- **"Hard to read, check this"** under a scanned value whose words OCR
  could hardly read, and each value's words checked against what OCR
  read, allowing for its usual slips (0 and O, 1 and l).
- **How a file was read** on the prefilled form ("Read on this server
  (text recognition), then by *model* on *connection*"); the Scan page
  says beforehand that photos are read here first, and the task picker
  says so for a text-only *Reading receipts and documents* model.
- `bin/ai-eval.php --ocr` (OCR accuracy per kind, GD and Imagick) and
  `--scans --via=ocr` (the vision and OCR routes side by side).

### Changed
- **A scanned number that reads two ways**, one separator and three
  digits (`1,759`, `48.120`), is still read in your locale but marked
  "Check the number: 1759 or 1.759?".
- **A scanned value whose words aren't in a text PDF** now says why it
  was left empty, under its field.
- **Scanned parts lines** keep a quantity in the name ("Wiper blade ×2"),
  ignore a VAT note after the price, and read `1 234,56` as one price.
- **Scanned fill-ups** show their notes (evidence, checks, reasons) under
  volume, price and total, as other forms do.

### Fixed
- **A scanned or drafted fill-up keeps its date.** With JavaScript on, the
  form replaced the receipt's date and time with the time it opened; it
  now keeps what was read.
- A receipt's price printed with its unit (`1,759 €/l`, `£1.459/litre`)
  is read, and an unreadable volume or price says why.
- A PDF whose page gives an oversized size is rendered small instead of
  to a huge image, except where the size is inherited from the PDF's page
  tree or scaled by `/UserUnit` (still open).
- An AI or OCR request that runs past its lock no longer frees a later
  request's lock; the OCR lock covers a slow PDF's render.
- Settings no longer says Tesseract is ready when it is installed but
  can't run.
- `OCR_URL` with a query string or fragment is refused at start; a
  PaddleOCR answer over 4 MB is refused while it is read.
- OCR preparation keeps a tilted large photo within 4,000 px, writes an
  8-bit PNG with GD, and no longer loses a line of a faded photo with
  Imagick.

### Upgrade notes
- **Docker image size:** about 80 MB larger (78 MB uncompressed on arm64)
  for Tesseract and its English and German data.
- **New, all optional:** `OCR_ENGINE` (`auto`, `tesseract`, `paddleocr`
  or `off`), `TESSERACT_BINARY`, `OCR_URL`, `OCR_TIMEOUT`. See
  [docs/configuration.md](docs/configuration.md#ai).
- **PaddleOCR:** `docker compose --profile ocr up -d` builds and runs it;
  off by default.
- **Bare PHP:** `apt install tesseract-ocr tesseract-ocr-deu` to scan
  photos with a text-only model ([docs/reading-files.md](docs/reading-files.md#bare-php)).
- **Behaviour:** an install with only a text model (and Tesseract
  available) now reads photos instead of refusing them.

## [3.16.4] — 2026-10-17

Phase 52.2: **more quick wins from the known-issues log: Parts polish and
docs** ([docs/phases/phase-52.2.md](docs/phases/phase-52.2.md)). No
migration: pull and restart.

### Changed
- **A new part's *Warranty starts from*** has *Purchase date* chosen, so
  the form always shows where the warranty starts. With no bought-on date
  it still runs from the first fitting. Editing a part shows what's
  stored; the API and imports are unchanged.

### Fixed
- **Ask's and MCP's `parts` tool** words a warranty as the part page does:
  German no longer has an English "or" in it, and an ended warranty keeps
  its date ("Warranty ended 1 Jan 2025"). It also reads each vehicle's
  latest mileage once, not twice per part.
- **Ask's and MCP's `vehicle_summary`, and MCP's `costs` and
  `cost_per_distance`,** say in their descriptions that running costs
  leave modifications out and give `build_total` beside them;
  `vehicle_summary` adds a `note` when there is build spend.
- **The Parts tab** has no blank gap under *For your insurer*.
- **The demo banner** no longer prints, on *For your insurer* or any
  other page.

### Documentation
- Spec §8 *Printing reports* lists every printable page, and §7.24 says
  undeclared modifications aren't a *Needs attention* item. Two open
  questions logged for the owner: how a CSV import reads separators
  (#484) and who may set *Declared on* (#485).
- **The Parts guide** says a new part starts with *Purchase date* chosen,
  and gives the "Warranty ended 3 Mar 2028" wording.

### Upgrade notes
- **MCP and Ask:** the `parts` tool's warranty text has changed (an ended
  warranty now gives its date), and `vehicle_summary` may return a new
  `note` field. Nothing else in the tool results changed.

## [3.16.3] — 2026-10-17

Phase 52.1: **quick wins from the known-issues log**
([docs/phases/phase-52.1.md](docs/phases/phase-52.1.md)). No migration:
pull and restart.

### Fixed
- **A part sold by someone who can't see costs** is stored with no price,
  not £0, so the owner no longer sees "sold for £0". A sale entered with
  the price left blank is stored the same way.
- **The API's modification writes** (`POST` and `PATCH
  /vehicles/{id}/modifications`) return the `odometer_backwards` and
  `odometer_jump` warnings, as every other write does.
- **Ask's and MCP's `vehicle_summary`** counts only running costs in its
  12-month total, as `costs` and `cost_per_distance` do; modification
  spend is given beside it as `build_total`.
- **On the *For your insurer* page,** *Clear* works when the date field is
  blank or in the future.
- **The API's error for a parts list that's too long** now says the limit
  is 20.
- **`php bin/backup.php check` and `restore`** find a bare file name in
  `BACKUP_PATH`, as `docs/deployment.md` shows, and say "No such file"
  for a file that's missing instead of "This is not a ZIP file."

### Upgrade notes
- **Sales recorded before this release with no price** keep the £0 they
  were stored with and still show "Sold · £0": they can't be told apart
  from a sale really made for £0. Only sales recorded from now on are
  stored without a price. To clear an old one, clear *Sold for* on the
  record that took the part off.
- **API:** a part sold without a price reads back as `sold_price: null`,
  and the *part fittings* CSV leaves that cell empty. Older sales still
  read `"0.000"`, so accept both. Modification `POST` and `PATCH` may now
  return non-empty `warnings`.
- **MCP and Ask:** `vehicle_summary`'s
  `running_cost_last_12_months.total` no longer includes modification
  spend, so it can be lower than before for a modified vehicle. That
  spend is in the new `build_total`, given when there is some.

## [3.16.2] — 2026-10-17

Phase 52: **the README and the docs, for someone deciding.** A docs-only
patch release: no app change and no migration; pull and restart. Start at
the new [docs index](docs/README.md).

### Documentation
- **A shorter README** for anyone deciding whether to try Logbook: who it's
  for, screenshots, a quick start that ends with a vehicle and a fill-up,
  and the features in nine groups. The full feature list moved to
  [docs/features.md](docs/features.md), the development setup and release
  steps to [CONTRIBUTING.md](CONTRIBUTING.md), and the guides gained an
  index ([docs/README.md](docs/README.md)), a
  [getting-started guide](docs/getting-started.md), a common opening and a
  link back to the index (Phase 52). Screenshots are made from the demo
  data by `npm run screenshots`, and CI checks the docs' links, index and
  images. No app change.
- **API docs** (OpenAPI 1.26.1, descriptions only): an *Unknown values* rule:
  any list of codes the API, webhooks or MCP return may gain values in a
  release, and a client should accept ones it doesn't know (K8). A part's
  whole `warranty` object and the History `kinds` are listed (K20), and a
  demo install's `403 demo`, given before the key is checked, is in the
  error tables (K60). The 3.15.0 notes now say a rollback deletes every
  modification draft, and carry the release's date (K15).

## [3.16.1] — 2026-10-16

Phase 51.4: **import fixes** from the known-issues log
([docs/phases/phase-51.4.md](docs/phases/phase-51.4.md)). No migration:
pull and restart.

### Fixed
- **Fill-ups whose worked-out price, volume or total is too large** (a
  total of 99,999,999,999 over 0.001 L) now say so on that field, on the
  form, the API and the CSV import, instead of a server error (K9).
- **`php bin/import-app.php`** no longer imports the good rows of a Fuelio
  file when others have problems: it stops, as the web page does, unless you
  add the new `--skip-invalid`. Problems name Fuelio's columns (`Data`,
  not `filled_at`), fuel type codes Logbook doesn't recognise are listed,
  and the sections it doesn't read are listed there and on the web preview
  (K11, K12).
- **A Fuelio backup imported after its CSV** now attaches the photos to the
  fill-ups the CSV brought in (K21).
- **CSV import:** a file that isn't text (a Fuelio backup ZIP, an `.xlsx`, a
  PDF) is refused as not a CSV instead of being read as one (K61); "too
  large" names the size limit; demo or API fill-ups and readings stored with
  seconds count as already there when their export is imported back;
  quantities in miles and gallons are rounded once, as the forms round them
  (one value in about 1,200 came in 0.001 L out) (K21).

## [3.16.0] — 2026-10-16

Phase 51: **import from Tracktor.** Bring a whole Tracktor garage across in
one go: every vehicle, fill-up, service record, document and reminder, with
their attachments and vehicle photos. See
[docs/import-tracktor.md](docs/import-tracktor.md). Logbook isn't affiliated
with Tracktor.

### Added
- **Settings → Import from another app** takes a Tracktor JSON export
  (*Settings → Export* in Tracktor, without a password), and optionally a ZIP
  of Tracktor's uploads folder for the attachments and photos. Exports from
  Tracktor 1.x, with separate insurance and pollution certificates, are read
  too.
- **One mapping for the whole garage:** each Tracktor vehicle to one of yours
  (found again by Tracktor's own ids on a later import), a new one or none;
  the units from Tracktor's settings, with the UK or US gallon to choose and
  each vehicle's economy to check them by; one category for the service
  records; each document type to a Logbook one; reminders on or off; and
  **Make schedules**, which turns a repeating service reminder into a service
  schedule and gives a repeating document its next due date as its expiry.
- **The preview** shows a card per vehicle with every row and what happens to
  it, and warns when rows are already in another vehicle, when an existing
  vehicle has another currency, and for a bus, farm vehicle or yacht (which
  come across as cars). Everything lands in one transaction, or nothing does.
- **What comes across:** make, model, year, registration, VIN, fuel and type
  (motorcycles and scooters as bikes; a new vehicle takes Tracktor's currency
  if it isn't yours); fill-ups, with any two of amount, price and cost giving
  the third, and a mark where all three disagree by more than a cent; LPG in
  litres or gallons and CNG in kilograms or pounds; service records titled
  from the first line of their notes; documents; every reminder, completed
  ones marked done; the vehicle's odometer as a reading; files on their
  entries and the vehicle's photo. Colour and custom fields are listed as not
  imported, and notifications, notification providers (whose credentials stay
  in Tracktor) and settings are never read.
- **Importing a newer export later** adds only what's new, into the same
  vehicles, even if you've edited the imported entries.
- **Command line:** `php bin/import-app.php <export.json> [--files
  <folder|zip>]`, with `--create`, `--vehicle`, `--as`, `--schedules` and
  `--dry-run` as for Fuelio. The script picks the reader by the file.

### Changed
- The import page's economy line keeps "UK gallons" and "US gallons" (and
  German nouns) capitalised; it said "uk gallons".

### Notes
- No migration. Password-protected Tracktor exports are refused with "Export
  again from Tracktor without a password".

## [3.15.0] — 2026-10-16

Phase 50: **parts and modifications.** What was fitted, what came off, and
what the build has cost, kept apart from what the car costs to run; and, from
50.2, a reminder before a warranty runs out, a list of modifications for your
insurer, and parts wherever entries go. See [docs/parts.md](docs/parts.md).

### Added (Phase 50.2)
- **Warranty reminders.** Tick *Remind me before it ends* on a part (the part
  form, or behind *More* on a new part's row of a service record or
  modification) to be reminded before its warranty ends: due within your
  document lead time of the date, or your schedule lead distance of the
  mileage (projected from your driving). *Done* reads *Checked it*. It goes
  when you clear the tick, edit the warranty away, or the part is sold,
  scrapped or its warranty ends; ticking a time limit counted from a purchase
  date that has already passed is refused (any other warranty just stops
  reminding when it ends).
- **For your insurer.** A printable list of the modifications currently on
  the car, ECU tunes first, with when each was fitted, its parts and when you
  declared it ("Not declared yet" otherwise), never a cost. With *Manage*,
  **Mark declared** (a date you choose, for one or all) and **Clear**. A
  *Not declared* label on the *Parts* tab and a modification's page.
- **Sale pack:** *Include modifications* (off to start with): what has been
  changed, what was returned to standard and the parts kept with the car;
  never costs or prices.
- **Scanning:** a scanned service invoice's parts lines are offered as
  **Add as parts**, unticked, with a guessed name, part number and price to
  check; nothing is created without *Save*, and a warranty is never read from
  a line.
- **REST API** (OpenAPI 1.26.0): `modifications` and `parts` lists, single
  reads with an `ETag`, create with a safe retry (a modification with its parts
  in one save), `PATCH` and `DELETE` with `If-Match`, `fit`, `remove`,
  `revert` and `undo-revert`, files, and the History kinds
  `modification`, `modification_reverted`, `part_fitted` and `part_removed`.
  Webhooks for `modification` and `part`. See
  [docs/api.md](docs/api.md#parts-and-modifications).
- **Ask and MCP:** the `modifications` and `parts` tools and
  `draft_modification`; `cost_per_distance` returns `build_total` beside the
  rate so an answer can say both.
- **CSV:** `modifications.csv`, `parts.csv` and `part-fittings.csv` from the
  *Parts* tab (export only).

### Fixed (Phase 50.2 review)
- A price, cost or sale price that rounds past the column's range (for
  example 99999999999.9999) is refused as too large instead of failing with a
  500.
- A distance-limited warranty counts only while the part is on the car: a
  spare gets no reminder for it, and the limit is read at the mileage the part
  came off at.
- A reverted modification's `parts_fitted` and `parts_removed` (API and Ask)
  list only what the modification itself fitted and took off, not the revert's
  own fittings.
- The Parts card's *Build spend* and Ask's `modifications` `build_total` (all
  states) are net of parts sold as they came off, as Reports are.
- A part whose reminded warranty has ended stays editable; the refusal is
  only for newly ticking an ended warranty or changing it to another ended one.
- *For your insurer*: Enter in the date field changes nothing; *Mark all
  declared* with a date before any chosen modification refuses the lot;
  moving a modification's date past its declaration is refused on the page
  as well as the API; the long *Mark all* label wraps at 375 px.
- The `parts` tool in Ask and MCP no longer reads the whole mileage log per
  part (12 s over 20,000 readings, now 0.3 s); the modifications tool and API
  list read their fittings once for the page.
- The Reminders list shows "Part warranty" (and "Issue") instead of a raw key;
  a tampered scanned-rows count draws no more than 20 rows.
- API History's `next` cursor works across a reverted modification (the
  new kind name was longer than the cursor allowed); rolling back the
  warranty-reminder migration on SQLite also removes the reminders'
  delivery rows, so a later backup restores.
- API: a nameless `came_off` row is refused (422) rather than dropped; list
  errors name the row by its place in the body; a JSON body with a long
  string (a 2,200-character note, or any long non-ASCII text escaped as
  `\u…`) no longer fails with "must be a JSON object". A scanned line's quoted
  evidence no longer shows its price to a member without *Can see costs*.

### Upgrading (Phase 50.2)
- One small migration (`parts.warranty_reminder`, off for every existing
  part), reversible on every engine; rolling it back removes the warranty
  reminders it made, clears every part's *Remind me before it ends* tick
  (upgrading again leaves them all off, and *Checked it* marks on part
  reminders are gone), and deletes every Ask and MCP modification draft
  (pending, added or discarded), which the older version can't read.
- API: new paths, the History kinds above, the reminder source `part` and the
  webhook kinds `modification` and `part` are new enum values; a client that
  rejects unknown values should accept them. Modification and part files are
  cost files: listing them over the API needs *Can see costs*.
- A scanned parts line's price is only shown to a user who may see costs.

### Added (Phase 50.1)
- **Parts** on each vehicle: name, brand, part number, serial, supplier,
  price, condition, receipt and photos, and a **warranty** (months and/or a
  distance, from the purchase date or the fitting: "In warranty until 3 Mar
  2028 or 85,400 mi"). A part is fitted and removed at a date and mileage,
  more than once, with what happened to it when it came off (kept, sold,
  returned under warranty, scrapped), and its **distance on the vehicle**.
- **Modifications** (a remap, coilovers, a cat-back exhaust, wheels): title,
  category, date, mileage, cost, who fitted it, notes and photos. The same
  form fits new parts and records **what came off**, so a one-part
  modification is one save. **Revert** puts the car back to standard and
  keeps the modification in the record, marked *Reverted*.
- **Parts fitted** and **Parts removed** on the service record form too, so
  the clutch a garage fitted is a part with its warranty.
- A **Parts** tab after Issues, an overview card (current modifications,
  build spend, parts in warranty), *Modification* in *Log entry*, History
  under a *Parts* chip, and modifications in the printable service history.
- **Make this a modification** on a service record or an expense, for a
  build logged before this release, one at a time; a title or note longer than 120
  characters keeps its whole text in *Notes*.
- *Undo revert* (only on a modification you may edit) is refused, with a
  message, while a part the revert put back has come off again since: undo
  that first, so its sale or removal isn't erased.
- `FEATURES_PARTS` and the *Parts and modifications* module (on for
  existing installs, off on new ones).
- Part prices, modification costs and sale prices need *Can see costs*,
  even on a part or modification a member added: without it they aren't
  shown or asked for, and a member's edit keeps the owner's figures.

### Changed
- A modification's cost is a new cost group, ***Modifications***: counted in
  total spend, the Expenses tab, Reports and cost of ownership's total, and
  **never in a running cost per mile or per month** (Reports, the Expenses
  tab, cost of ownership's rates, true cost and *What changed*, the digest,
  the trips report's cost per business mile, the cost checks). A part sold as
  it came off is money back in the group of the record that fitted it.
- Reports' average per month is of running costs, so it leaves build spend
  out; the ownership CSV gets a final *Modifications (not a running cost)* column (added last, so existing positions don't move).
- The API's odometer readings may have the sources `modification`,
  `mod_revert`, `part_fitted` and `part_removed`, `GET /me` lists
  the `parts` module, and `/reports/costs` takes and returns the group
  `build`. `GET /vehicles/{id}/ownership`'s `running_costs` leaves modifications
  out while its `total` includes them. The rest of the API for parts comes
  with Phase 50.2.
- Ask and MCP: `costs` takes the category `build` and, with build spend,
  returns `running_total`, `build_total` and a note; its
  `average_per_month` (null for `category=build`) and `cost_per_distance`'s
  totals count running costs only. See [docs/mcp.md](docs/mcp.md).
- All text input (forms, the API, MCP and AI drafts, imports) is stored
  with its line breaks as `\n`: `\r\n` and `\r` become `\n`, and a line
  break counts once towards a limit. Text saved before the upgrade is
  unchanged.

### Upgrading
- One migration (three tables and four reading links), reversible on every
  engine. Rolling it back keeps the money: each modification becomes an
  *Other* expense owned by the vehicle's owner, with its date, cost and
  files, its title as the note (or
  the whole note *Make this a modification* kept, up to 500 characters),
  and readings from modifications and parts become ordinary ones. Its
  other notes, *fitted by*, category and revert details are not kept, nor a
  converted record's vendor or expense category. Parts, their fittings and
  sale prices are dropped, so total spend rises by any sale prices; part
  photos and receipts stay on disk but are no longer shown. On MySQL and
  MariaDB, take a backup before rolling back; a rollback that stops
  part-way can be run again.
- Backups carry the new tables. The schema version moves: restore an older
  backup with its own version, then upgrade.
- CSV: the ownership export's new column is the last one. An odometer CSV
  with `modification`, `mod_revert`, `part_fitted` or `part_removed`
  readings imports them as ordinary readings.
- API: the new reading sources, the `build` group and the attachment owner
  types `modification` and `part` are new enum values; a client that
  rejects unknown values should accept them. Text is returned with `\n`
  line breaks when it was saved from now on, even if sent with `\r\n`:
  compare after normalising line breaks. A maintenance
  `PATCH` or `DELETE` that would put a part's fittings out of order is
  refused with a 422 `validation_failed` (key `part.refused.*`).

## [3.14.0] — 2026-10-14

Phase 49: **getting started.** A new user is taken from an empty garage to a
vehicle with its mileage, key dates and servicing in about two minutes, on a
dashboard that shows what needs attention and what it costs, with fewer tabs
until they want them.

### Added
- **Set up your first vehicle (Phase 49.1).** `/vehicles/start`: five short,
  skippable, resumable steps (the vehicle, the mileage now, MOT/tax/insurance
  dates, a servicing interval, what to do next). It saves through the same
  services as the full forms, so what it makes is like anything else in the
  garage: the dates become documents with only an expiry date (and raise their
  usual reminders), servicing a schedule with a preset of 12 months or 10,000
  miles (16,000 km). With MOT history set up, *Look up* fills in the vehicle.
  A user with no vehicles is taken there after the welcome form; the empty
  garage offers it too, and so does accepting an invitation (not a password
  reset), and the vehicle's overview shows a getting-started
  card ("3 of 5 done") until complete or dismissed. Progress is kept per user.
- **A *Household* dashboard layout (Phase 49.2).** *Needs attention*, *Coming
  up*, *Spend this month*, *Last 12 months* and *Recent activity* (10 rows).
  It is the default for new users; *Reset layout* offers it and the full
  layout. Existing users keep theirs.
- **A shared user's first visit (Phase 49.2).** A card above the shared
  vehicle says what the share allows ("You can log entries; you can't see
  costs"), once, for shares created from this release.
- **A *Switch on* hint** in the *Log* chooser where a switched-off module's
  absence would surprise (*Incident*), shown to an admin only.

### Changed
- **New installs start with fewer modules (Phase 49.2).** Tyres, finance,
  incidents, issues, stations and trips are switched off by the first-run
  setup (saved once in Settings → Modules, which marks them *Switched off when
  Logbook was installed*); fuel, maintenance, documents, reminders and reports
  are on. A `FEATURES_*` variable you set still wins. German strings added with
  the English ones.

### Upgrade notes
- Pull and restart: no migration and no configuration change. Existing
  installs keep every module they have, existing users keep their dashboard
  layout, and nobody who already has an account is sent to the setup flow.
  Users created after the upgrade (added by an admin, first single sign-on,
  or an invitation accepted after it) start on the Household layout. Going
  back to 3.13.0 is safe: the new rows are ignored.

## [3.13.0] — 2026-10-14

Phases 48.1 and 48.2: **show the working.** Every headline figure says how old
the input it rests on is, and can be opened to show its inputs, its sum in
plain words and what Logbook did not assume.

### Added
- **"As of" dates (Phase 48.1).** Depreciation in the cost of ownership, equity and the cost of each *Coming up* item show the date of the valuation or the last time they come from when it isn't today. The API adds `as_of` (ownership) and `cost_as_of` (upcoming items), Ask adds `value_as_of` and `last_cost_as_of`, and the *Coming up* CSV gets a final *As of* column. No migration; nothing to do on upgrade. The column is added last, so existing positions don't move.
- **How it's worked out (Phase 48.2).** A closed *How it's worked out* line (open when you print) under: depreciation for a period, the cost of ownership and its cost per distance, true cost for a period and *What changed*, the finance settlement estimate, equity and interest so far, the economy of a fill-up's stretch, the Reports page's running cost per distance, the mileage claim and the *Coming up* totals. It lists each input with its date and a link to the entry it came from, then the sum in words with the real numbers. Depreciation says plainly that Logbook never estimates values or applies a depreciation rate: it only draws a straight line between values you entered. The Cost of ownership tab's *How it's worked out* card and *Was it worth it?* are on it now, their wording unchanged. Dashboard widgets and the overview's *Coming up* card link to the page that shows the working (*How?*) rather than carry it. The True cost report carries it for each vehicle's latest three years, *Since bought* and its latest two changes, so the page stays light; older years show their figures only.
- **`?include=working` in the API.** `ownership`, `true-cost`, `finance`, `finance/agreements`, `trips/claim` and a vehicle's `fuel` list add a `working` object beside the figure: `{inputs, steps, notes, result, result_value}`. It is additive (the API stays `v1`), and anything else in `include` is a 400 (`docs/api.md`).
- **Ask and MCP quote the working.** The `ownership`, `true_cost`, `finance`, `coming_up` and `trips_summary` tools return a `working` beside their figures, so "how did you get that?" is answered from its sums, held to the same grounding check (`docs/ai.md`, `docs/mcp.md`).

### Upgrade notes
- Pull and restart: no migration and no configuration change. A viewer without
  the vehicle's costs sees no working, as they see no figure.

## [3.12.1] — 2026-10-13

Phase 47.1: **a patch release** that builds the decisions the owner made on
2026-10-10 about questions left open from Phases 38 and 41.

### Changed
- **Distances start at the purchase date.** Readings dated before a vehicle's
  purchase date (an earlier owner's MOT mileages, for example) no longer count
  toward reports, cost per distance, true cost, cost of ownership,
  depreciation per distance, the dashboard's mileage, the fuel forecast,
  trips' business share, the digest or the API's reports. They stay in History
  and on the odometer list. A reading on the purchase day is the baseline.
- **Rolling back the MOT history migration removes its settings.** If you roll
  back from 3.7 or later and upgrade again, MOT history is off until an admin
  chooses a provider and enters the credentials again on Settings → MOT
  history. Before, the provider stayed on without credentials.
- **MOT history's *Look up*** is documented: anyone who may add a vehicle can
  use it, rate-limited per person (`docs/mot-history.md`).
- **Insights shows the latest 5 MCP drafts.** The rest are under *Show all (N)*,
  a disclosure that works without JavaScript, as *Your questions*. The
  dashboard's list is unchanged.
- **Discord messages escape Markdown** (#378): already in 3.9.0's notes; this
  release records the decision as built and logged here.

### Upgrade notes
- Pull and restart: no migration and no configuration change. Distances in
  reports are now measured from each vehicle's purchase date, so a vehicle
  bought within a report's period shows only what you drove since you bought
  it. Per-km cost of ownership and depreciation need a reading on the purchase
  day (the purchase mileage you enter when adding the vehicle is one).
  A period with no reading after the purchase on or before its start also shows
  no per-km figures now (True cost by year as well as Ownership); the old figure
  was measured from a reading taken before you bought the vehicle. The averages
  per month and per year are unchanged.

## [3.12.0] — 2026-10-13

Phase 47: **how old is the last backup?** Logbook now remembers when it
was last backed up, and tells admins when that was too long ago.

### Added
- **The last backup, on Settings → Backup**: "Last backup 43 days ago,
  14 Sep 2026 (downloaded, 182 MB)", or "No backup yet", with the backup
  schedule and a reminder that scheduled files sit on the same server.
  Downloads, scheduled backups and `bin/backup.php create` all count; the
  safety copy made before a restore doesn't. A restore keeps the newer of
  the two records, so restoring an old backup doesn't make the last backup
  look older.
- **A dashboard banner for admins** once the last backup is older than
  *Warn me after* (30 days by default; 7, 14, 30 or 90 days, or *Never*),
  or when there has never been one: "Your last backup was 43 days ago.",
  with *Back up now* and, while scheduled backups are off, *Turn on
  scheduled backups*. *Dismiss* hides it for 7 days; the next backup ends
  it. Members never see it, and the demo never shows it.
- **I back up another way**: for `pg_dump`, snapshots or restic on the
  data volume. The warning stops, and the Backup page says who set it and
  when.
- **A line in admins' monthly digest** while the banner would show. It
  sends a digest on its own, and the webhook JSON gains `backup`
  (`last_at`, `days`, `display`; `null` otherwise).
- **`GET /api/v1/instance/backup`**, for an admin's API key: the last
  backup, its age in days, the threshold and whether the warning holds, so
  Uptime Kuma or Home Assistant can alert. Other keys get `403 forbidden`.
  `/health` doesn't say, because it is public. `openapi.json` 1.25.0.

### Changed
- **New installs back up weekly**, keeping 7, from first-run setup.
  Installs set up earlier keep their schedule (*Off* unless changed).

### Upgrade notes
- Pull and restart: no migration and no configuration change.
- On first use, the newest `logbook-scheduled-…zip` or `logbook-backup-…zip`
  in `BACKUP_PATH` counts as the last backup. With none, admins of an
  install older than 30 days see the banner until they back up, tick *I
  back up another way*, or choose *Never*.

## [3.11.0] — 2026-10-13

Phase 46: **what a share allows, in plain words**, before you press
*Share*. Phase 45: **the licence**.

### Added
- **What a share allows.** Under each share on a vehicle's *Sharing* page,
  and under the form that adds one, two short lists: what the person can do
  and what they can't, in plain words. They name the surprises: documents
  carry the policy number and the registration, a Log user sees the amounts
  they typed themselves (but no totals or reports), and Manage always
  includes costs, exports and the sale pack. The lines come from the same
  rules the app enforces, and a test fails if they ever disagree. With
  JavaScript on they follow the level and ticks as you change them; without
  it, the saved share's summary shows, with *What each level allows* below
  the forms.
- **Quick picks**: *Driver* (Log, no costs, reminders on), *Co-owner*
  (Manage, reminders on) and *Viewer* (View, no costs) fill in the form. A
  share still stores only its level and ticks.
- **"Sam can now: …"** after a save that widens someone's access.
- **What you can do**, the same summary, on a shared vehicle's *Sharing*
  page for the person it is shared with.
- **Logbook is free software under the GNU Affero General Public License
  v3.0 or later** (`LICENSE`). You may run, study, change and share it; if
  you run a changed copy for others over a network, offer them its source.
  The licence covers every published version, including the releases
  tagged before the `LICENSE` file existed (`composer.json` said
  "proprietary" until now). The image carries the
  `org.opencontainers.image.licenses` label, and the API description names
  the licence.
- **`THIRD-PARTY-NOTICES.md`**, the licence of every PHP package, font,
  icon set and script Logbook ships, shipped in the image too.
- **`CONTRIBUTING.md`**: contributions are welcome under the same licence,
  with a `Signed-off-by` line on every commit (Developer Certificate of
  Origin).

### Changed
- **Make admin asks first**, on a page that says what an admin can do:
  manage users and modules, change server settings, and download or restore
  backups containing every vehicle on the install. The *Admin* tick on
  *Invite* and *Add user* says the same.

### Upgrade notes
- Pull and restart: no migration and no configuration change.
- `POST /settings/users/{id}/admin` still makes someone an admin; a GET of
  the same address now shows the confirmation page.

## [3.10.0] — 2026-10-10

Phase 44: **what the next three months will probably cost**, on *Coming
up* beside the items it adds up.

### Added
- **A *Next 3 months* total on *Coming up*.** This month (overdue work
  included) and the two after, planned and fuel together: the sum of the
  first three months the page already lists, so it always matches the
  table and the chart. Only the costs *Coming up* already shows (last
  time's price, never an average); with any item of unknown cost, or a
  shared vehicle whose costs you don't see, it reads "at least". Per
  currency, never converted; "—" while nothing in those months has a
  known cost. A fourth figure in the page's summary (now headed
  *Summary*, its 12-month total labelled *Next 12 months*), and a line
  above *Next 12 months* on the dashboard widget and the overview card.
- **Ask's `coming_up` returns the same total** (`next_3_months`, raw
  values and the display string), so the model quotes it rather than
  adding anything up.

### Changed
- **`coming_up`'s `horizon_months` counts calendar months**, as the
  page: this month and the n − 1 after. `horizon_months: 1` now ends on
  the last day of this month rather than the same day next month, so
  the items listed are the ones the total covers.

### Upgrade notes
- Pull and restart: no migration and no configuration change.
- MCP and Ask clients see the new `next_3_months` key in `coming_up`;
  existing keys are unchanged, but `horizon_end` is now a month end.

## [3.9.0] — 2026-10-10

Phase 43: **the monthly briefing**. The monthly digest now covers last
month and what Logbook spotted, as well as what's due.

### Changed
- **The monthly digest includes last month's figures and insights.**
  After what's due and *Needs attention*, it gives each vehicle's distance,
  spend and running cost per distance for last month, against its monthly
  average over the 12 months before ("about 10% more than your monthly
  average"), with a line for all vehicles (spend per currency, never
  converted); a month with nothing spent says so. The figures are the Reports page's for that month; spend and
  cost per distance only for people who may see the costs; one entry over
  half the month's spend is named. Then the computed insights and, with AI
  on, the AI insights made that day or the day before, marked "AI:"
  (never one with an unbacked figure; the digest never calls a model).
  *Needs attention* gains a line per vehicle with open issues.
- Ordered so a short channel such as Pushover keeps what's due first. A
  month with only last month's figures or insights now sends a digest.
- **Choose what it includes** in Settings → Reminders → *Include*: *Needs
  attention and open issues*, *Last month* and *Insights*, all on until you choose (stored
  as `digest_include` beside `digest`, so rolling back keeps the digest).
- The webhook's JSON gains `last_month`, `fleet`, `issues` and `insights`
  (empty on other events); `items` and `attention` are unchanged.

### Security
- **Discord messages escape Markdown** (#378, Phase 43's security
  review, low). The monthly digest now carries AI insight text, and
  Discord rendered Markdown in it, so a model's
  `[Renew here](https://…)` showed as a link that looked like Logbook's
  own. The title, body and "…and n more" line are now escaped as
  Mattermost's are; mentions still ping nobody, and the link to Logbook
  still opens.

### Upgrade notes
- Pull and restart: no migration and no configuration change.
- Everyone with the monthly digest on gets the briefing from the next
  1st, until they untick sections under *Include*. A month that used to
  send nothing can now send one, and a short channel such as Pushover
  may cut it with "…and N more" (what's due always comes first).
- Webhook consumers receive four new keys; existing ones are unchanged.
  The server's webhook (`WEBHOOK_URL`) gets the digest without spend,
  cost per distance or insights, since it receives every member's
  messages; personal webhooks get everything.
- Rolling back to 3.8 keeps the digest as it was (`digest` is still a
  boolean; 3.8 ignores `digest_include`). Saving reminder or notification
  settings while on 3.8 forgets that person's *Include* choices: after
  upgrading again their digest includes every section until they untick
  them.

## [3.8.0] — 2026-10-09

Phase 42: **fuel saving and economy up, worked out by Logbook**. Two
insights the AI used to be asked to work out are now computed from
Logbook's own figures, with or without AI, and AI insights keep to what no
single figure covers.

### Added
- **Could save about £x a year on fuel** (Insights page and dashboard
  widget): what filling at the cheapest station near your first place,
  counting the drive there, would save against your usual station's
  listed price today, at the litres you use a year. Without a fresh listed
  price it compares with what you've paid on average in the last 30 days;
  with under a year of fill-ups it scales what there is to a year. Shown
  from 20 a year in your currency, with Fuel stations and a price provider
  on, to people who may see the vehicle's costs; never for an electric
  car, and never in another currency. Links to *Cheapest near me*.
- **Economy is up about x%**: the *Needs attention* economy drift check,
  judged for an improvement (the same windows, threshold and seasonal
  test, by the vehicle owner's threshold), with the likely causes: a grade
  switch, new tyres, longer tanks. The two can never disagree.
- **`computed_insights`** for Ask and MCP: every computed insight with the
  figures behind it, so "How much could I save on fuel?" is answered from
  Logbook's own sum.
- `bin/ai-eval.php --insights` reports, per run, any AI insight figure no
  tool returned.

### Changed
- **AI insights no longer work out figures or repeat a computed insight.**
  The model is told the insights Logbook already shows and never to add,
  average, convert or project a number; it tags each observation with a
  topic and its vehicles. An AI insight with a figure no tool returned is
  now left out rather than highlighted (answers to your own questions
  still highlight one), and one repeating *Fuel saving* or *Economy up*
  for the same vehicle is left out.
- **Insights order:** *Shopping around*, *Fuel saving*, *Business
  mileage*, *Cheapest to run*, *Equity*, *Economy up*.
- **Faster pages with fuel prices on.** With every module on and a price
  provider synced, the dashboard sent 109 queries for one vehicle and 469
  for ten, and the Insights page 52 and 452; now 74 and 28, whatever the
  number of vehicles (stations, listed prices, places and trips are read
  once per page). The Insights page joins the page budgets at 60 queries,
  and the dashboard with every module on is held to 80.

### Upgrade notes
- Pull and restart. No migration, no configuration change and no change
  to the backup format. Of the AI insights made earlier today, any with a
  highlighted figure stop showing at once; the rest keep showing until
  the next set is made (they have no topic, so none is left out as a
  repeat).

## [3.7.2] — 2026-10-09

Phase 41.7: **a patch release**. The dashboard and a vehicle's overview read
each table once for all your vehicles, not once per vehicle per widget.

### Changed
- **A much faster dashboard and vehicle overview.** With ten vehicles the
  dashboard sent 812 database queries and an overview 274; both now send
  59, the same as with one vehicle, and the count no longer grows with the
  number of vehicles or entries. A page request remembers what it has read
  (settings, and each vehicle's fill-ups, readings, services, documents,
  expenses, valuations, finance agreements, incidents and tyres) and a
  write drops what it changed. Nothing any page shows has changed
  (compared page for page in a test); only GET and HEAD requests do this,
  so saving, jobs and imports read the database exactly as before.
- **Page budgets** are now part of the spec (§8) and held by a test: the
  dashboard and an overview run at most 60 queries, whatever the number
  of vehicles.

### Upgrade notes
- Pull and restart. No migration, no configuration change and no change
  to the backup format.

## [3.7.1] — 2026-10-09

Phase 41.6: **a patch release**. The small things Phase 41's merge review
found in MOT history, and three edge cases it could not prove.

### Fixed
- **Settings → MOT history** fits a phone: the *Test* button wraps inside
  its card instead of reaching the edge, and the "Sends …" hint keeps its
  shield beside the text.
- **A network error no longer carries the registration.** DVSA's request
  address ends in the registration or VIN; a transport error that quoted
  it was stored in the last call (shown to admins) and printed in the job
  output. The message is cut to DVSA's host.
- **A refresh keeps each defect with its own text.** When DVSA listed a
  test's defects in a different order, an issue link or *Not now* could
  land on another defect. Defects are matched by their text, not their
  position.
- **"Advised again" is never dated before the issue.** An older test, not
  yet reviewed, with the same text as an issue made from a later test no
  longer adds a note to it.
- **Fewer queries.** *Add all as issues* read the vehicle's tests, issues
  and documents again for every defect (11 queries a defect, 330 for 15
  tests and 30 defects); it now reads them once per call (213, of which
  180 are the issues' own creates). History counts a test's defects in
  the query that lists the tests instead of loading their text.

### Upgrade notes
- Pull and restart. No migration, no configuration change and no change
  to the backup format.

## [3.7.0] — 2026-10-09

Phase 41: **MOT history from DVSA**. A UK vehicle's official MOT record
in Logbook: every past test, its mileage, advisories and defects, and
whether a recall is outstanding. Off until an admin enables it; then each
owner chooses whether to fetch, since it sends their registration. See
[docs/mot-history.md](docs/mot-history.md).

### Added
- **Settings → MOT history** (admins): the **DVSA (UK)** provider with
  its four credentials (stored encrypted, or `env:NAME`, never shown
  back), *Test*, and the last call's result. Credentials are free to
  individuals from DVSA.
- **Fetch MOT history** on a vehicle's Documents tab and overview, for its
  owner, after a one-time confirmation of what is sent. *Refresh* and
  *Stop and remove*. A record for a different make is refused; a VIN is
  tried when the registration has no record, and a private plate is
  named.
- The **MOT history** page: each test with its result, expiry, mileage
  and defects (type in words and an icon), what you made from it, the
  recall status and DVSA's attribution. *Export CSV*
  (`mot-tests.csv`, one row per defect).
- Every read MOT odometer becomes a **mileage reading** (source *MOT*),
  checked like any other. When yours and an MOT's disagree, *Needs
  attention* says which is which and *Fix* opens yours.
- A **review card** after a fetch: passed tests as MOT documents (never
  twice; *Add all*, oldest first), a new car's first MOT due date,
  defects as issues (fails, major and dangerous open; advisories and
  minor ones watched until 30 days before the MOT expires), an advisory
  that comes back as a note on its issue, *Not now* and *Done*. A pass
  added there closes the old MOT reminder as done.
- An outstanding **recall** is a *Now* item in *Needs attention*.
- **Look up** beside the registration when adding a vehicle fills its
  blank fields from DVSA, with or without JavaScript.
- The **`mot_history` job** refreshes vehicles around their MOT (from 14
  days before it falls due to 60 days after, a new car by its first MOT
  due date) once a week each, and keeps DVSA's key in use. The overview
  notices a new result.
- MOT tests in **History** (under *Documents*), the **sale pack** (date,
  result, mileage), the **API** (`GET /vehicles/{id}/mot-tests`), **Ask**
  and **MCP** (`mot_history`), backups and `bin/export-user.php`.
- `bin/dev-setup.sh --with-sample-data` includes MOT history from a
  sample provider that sends nothing (development only).

### Upgrade notes
- One migration (`mot_tests`, `mot_defects`, `mot_history_secrets`, four
  columns on `vehicles` and `odometer_readings.mot_test_id`). Nothing to
  do: MOT history stays off until an admin enables it.
- Rolling the migration back deletes the MOT tests, their defects and
  their *MOT* mileage readings, so mileage figures return to what they
  were before the fetch; issues and MOT documents made from them stay, as
  your own entries, and are linked again after upgrading and fetching
  once more. It also drops the DVSA credentials: the provider setting
  stays, so enter them again in Settings → MOT history after upgrading
  again. A 3.6.0 backup restores only into 3.6.0; restore it there, then
  upgrade.
- To use it, apply for DVSA's free MOT history API credentials (about 5
  working days), then enter them in Settings → MOT history; see
  [docs/mot-history.md](docs/mot-history.md#getting-credentials-from-dvsa).
  The credentials are never in a backup.
- `docs/api/openapi.json` 1.24.0 adds the MOT history operation and the
  `mot_test` history kind, the `mot` reading source and the `mot_recall`
  *Needs attention* kind.

## [3.6.0] — 2026-10-08

Phase 40: **the issues log**, for the fault you've noticed and haven't
fixed yet, everywhere entries go. Logbook records your words and links
the fix; it never suggests what a fault is. See
[docs/issues.md](docs/issues.md).

### Added
- An **Issues** tab on each vehicle, after Maintenance, and an *Issue* in
  *Log entry*: what you noticed, when, the mileage (which joins the mileage
  log), details, an area, photos or PDFs, and **Affects safety**, your own
  tick, which lists the issue first and in red.
- Each issue is **open**, **watching** (with an optional look-again date or
  mileage) or **fixed**, with a timeline of your notes and every status
  change. Notes can be edited and deleted; the status lines can't.
- **Fixes** on the service record form: tick the issues a repair fixed and
  they're marked fixed on its date. From an issue, **Mark fixed** offers
  *Log the repair* (the form prefilled, the issue ticked), *Link an
  existing record*, or *Fixed without a record* with a note. Unticking or
  deleting the record reopens the issue; **It's back** reopens a fixed one
  and keeps its history.
- **Needs attention** lists every open issue (*Log the repair*, *Watch*)
  and every watched one whose look-again point has come (*Log the repair*,
  *Watch again*, *Reopen*), safety issues first.
- A look-again point raises a **reminder** ("Look again: …") that reaches
  your notification channels; *Looked at it* clears the point.
- An **Issues** card on the overview, an **Issues** chip in History
  (*Issue noticed*, *Issue fixed*), fixed issues with their fix in the
  printable service history, and **Include open issues** in the sale pack,
  off by default. `/issues` lists every open and watched issue; the *Needs
  attention* widget links to it.
- Settings → Modules: **Issues**, on by default (`FEATURES_ISSUES`).
- The demo Golf has an open knock, a watched brake-pipe advisory and
  grinding brakes fixed by its June pads.
- **Recommended work** from a scanned invoice or MOT certificate can
  become issues: *Add as issue*, *Watch* (with the line's own date or
  mileage to look again) and *Add all as issues*, beside *Add reminder*.
  The card shows for anyone who may add either, and each line says what it
  became.
- The **API**: `GET`/`POST /vehicles/{id}/issues`, one issue with its
  timeline and an `ETag`, `PATCH`, `DELETE`, `…/updates`, `…/fix` (service
  records, or without one), `…/reopen`, attachments, and `GET /issues`
  across your vehicles. `docs/api/openapi.json` describes them.
- **Webhooks** of kind `issue`: a note, a fix from either side, an unlink
  and a reopen are each `entry.updated` of the issue.
- **Ask** reads your issues (`issues`) and drafts one (`draft_issue`);
  asked what causes a fault, it says Logbook only records what you noted
  and suggests a qualified mechanic. AI insights may count issues, never
  guess a cause. **MCP** has both tools.
- **Export CSV** on the Issues tab (`/vehicles/{id}/export/issues.csv`).
- An issue's odometer adds no reading when the vehicle already has one
  that day at the same mileage (#319), so issues added from a service
  record's card don't repeat its reading.

### Upgrade notes
- One migration (`issues`, `issue_fixes`, `issue_updates`, and two links
  on `odometer_readings`). Nothing to do. The **Issues** module is on by
  default; switch it off in Settings → Modules or with
  `FEATURES_ISSUES=false`. Backups carry issues; rolling the migration
  back keeps their mileage as ordinary readings.
- `docs/api/openapi.json` 1.23.0 describes the issue operations.

## [3.5.0] — 2026-10-08

Phase 39: **the API does what the pages do**. Read everything a vehicle's
pages show, write, correct and delete what they let you, move files in
and out, and have Logbook tell other systems when something changes.

### Added

- **Reads** (Phase 39.1): every entry on its own (`GET …/fuel/{entry}`,
  `odometer`, `maintenance`, `documents`, `expenses`, `trips`,
  `incidents`, `schedules`, `valuations`) with an `ETag`; maintenance and
  document filters; schedules, valuations and ownership; the vehicle's
  and the fleet's history; the four reports (costs, cost per distance,
  fuel, mileage); tyre changes and sets; closed reminders; *Needs
  attention*; price alerts; every finance agreement. A vehicle now says
  how it left (`disposal`) and carries an `ETag`.
- **Reminder actions** (Phase 39.1): *done*, *dismiss* and *reopen*,
  safe to retry.
- **Edits and deletes** (Phase 39.2): `PATCH` (only the fields you send)
  and `DELETE` for every entry, through the edit and delete pages'
  rules, with optional `If-Match` (a stale tag is `412`, nothing
  written); the same on every other `PATCH` and `DELETE` below.
- **More writes** (Phase 39.2): vehicles (add, edit, archive, restore),
  valuations, schedules, tyre changes and tyres, saved journeys, station
  favourites, price alerts, hiding a *Needs attention* item, manual
  reminders, and finance (agreements, payments, settlement quotes and
  *End*).
- **Attachments** (Phase 39.3): list, upload (one file per request,
  `multipart/form-data`, field `file`), download and delete files on
  every kind of entry, trips and purchase and sale paperwork included,
  with the pages' checks and limits; an incident's photos as the pages
  serve them. The vehicle photo on `GET`, `POST` and `DELETE
  /vehicles/{id}/photo`.
- **Webhooks** (Phase 39.3): Settings → API keys → Webhooks. Logbook
  calls your address when an entry, vehicle or reminder changes, by any
  path (a form, an import, the API, Ask or MCP), with ids and links only,
  signed (`X-Logbook-Signature`). Retried after 1 minute, 5 minutes, 30
  minutes, 2 hours and 6 hours; paused after 50 failed tries in a row,
  and you're told through your notification channels. Up to 10 per
  person. *Send test*,
  *Pause*, *Resume*, *New secret*. Addresses follow the same rules as
  notification channels. The [API guide](docs/api.md#webhooks) shows how
  to check the signature in Python, JavaScript and Node-RED.
- `docs/api/openapi.json` 1.22.0 describes all of it, the webhook payload
  included.

### Fixed

- **Receipts and paperwork without *Can see costs*** (Phase 39.3, #303,
  #305): a share without cost access could open an expense's receipt, a
  valuation's quote or the purchase and sale paperwork by its address,
  though no page linked it. Those files now need *Can see costs*, or to
  be your own upload, on the pages and the API.
- **Editing an entry in miles or gallons** no longer nudges what you didn't
  change: saving a fill-up, reading, service record, document, incident,
  tyre change or trip with only the notes changed could move its odometer
  by a metre (40 800 km became 40 800.001 km) and its volume and price by
  their last decimal. A value saved as the form showed it now keeps the
  stored one.

### Upgrade notes
- Pull and restart. **One migration** adds the `webhooks` and
  `webhook_deliveries` tables.
- **Backups move to a new database version**: a 3.4.0 backup restores into
  3.4.0; restore it there, then upgrade.
- **Going back to 3.4.0:** run `vendor/bin/phinx rollback -e production -t
  20261105100000` **with 3.5.0** (Docker: `docker compose exec -u www-data
  app vendor/bin/phinx rollback -e production -t 20261105100000`) before
  switching to the old version or image; run with the old code it does
  nothing. Rolling back deletes every webhook and queued call: after
  upgrading again, add them again and give each receiver its new secret
  ([deployment](docs/deployment.md#upgrading)).
- New setting `WEBHOOKS_ENABLED` (default `true`; `false` queues and sends
  nothing). Webhooks are sent by the scheduler, so they arrive with the
  next pass; run passes more often to have them sooner
  ([deployment](docs/deployment.md#scheduled-tasks-cron)).
- `API_CORS_ORIGINS`: the preflight now allows `PUT`, `PATCH` and `DELETE`
  and the `If-Match` header, and responses expose `ETag`.
- Backups carry webhooks **without their signing secret**: a restored
  webhook is paused until you make a new secret (and give it to the
  receiver). Queued calls are not backed up.
- Existing API clients work unchanged: the API stays `v1`, and every
  change is additive.

## [3.4.0] — 2026-10-08

Phase 38: **Ask lives on Insights**. One place for what Logbook has
spotted and what you've asked it.

### Changed

- **Ask Logbook is on the Insights page** (Phase 38): the box at the top,
  then *Your questions* (your latest five conversations, *Show all* for
  the rest, *Delete* and *Delete all*) and *Keep conversations for*. Each
  conversation opens on its own page under Insights
  (`/insights/questions/…`) with everything the Ask page showed: sources,
  the grounding check, draft cards, feedback, *Copy* and the follow-up box.
- The header's *Ask* button on a phone, the dashboard link and the phone
  app's quick action open Insights with the box ready to type.
- **Drafts to review** (from an MCP client) are listed on Insights as well
  as the dashboard, with or without Ask, and the assistant's link goes
  there.

### Fixed

- **AI insights** (Phase 38 review): a failed day named the connection as
  a literal `{connection}`; it now names it.

### Removed

- **The Ask page and its sidebar entry** (Phase 38). Old links still
  land: `/ask` opens Insights (a suggestion's question kept),
  `/ask/threads/…` the conversation's page, and a question sent from a
  tab opened before the upgrade is put back in the box, not asked.

### Upgrade notes
- Pull and restart. No migration, no config change: conversations keep
  their ids, so bookmarks to them redirect. If you raised a proxy's
  timeout for `<base>/ask` ([deployment](docs/deployment.md)), raise it
  for `<base>/insights/questions` instead.

## [3.3.1] — 2026-10-07

Phase 37: **a patch release**. The provider choices on Settings → Fuel
prices are spaced apart like every other list of choices, and the fixes
from the 3.3.0 reviews are in.

### Fixed

- **Settings → Fuel prices** (Phase 37): the provider choices are spaced
  apart like every other list of choices, instead of their borders
  touching. The same applies to any list of option cards outside a
  fieldset.
- **Chips** (Phase 37, #265): every chip is 44 px tall, and a chosen chip
  shows a tick as well as its colour.
- **Receives** (Phase 37, #268): a channel with every box ticked now gets
  categories added in later versions too; channels saved that way under
  3.3.0 are converted by the upgrade.
- **Job failure alerts** (Phase 37): a demoted admin's held alerts are
  cleared (#266); a service that stopped answering during a run is
  skipped for the alert sent after it too (#267); two runs finishing
  together send held alerts once (#269).

### Upgrade notes
- Pull and restart. One data migration converts a *Receives* list with
  every box ticked, saved under 3.3.0, to "all"; no schema or config
  changes, and no change to the backup format. Rolling back leaves them
  as "all", which is what they meant.

## [3.3.0] — 2026-10-07

Phases 36.1–36.4: **your reminders, where you want them**. The email server
is set up in the app by an admin, and each person chooses their own
channels (email, ntfy, Gotify, a webhook, Telegram, Discord, Pushover,
Mattermost and Slack), what each receives and when, sets them up themselves
and tests each one.

**Read the upgrade notes first: email is off after upgrading until an admin
sets the server up in Settings → Delivery.** Three migrations; notification
secrets are encrypted with `SESSION_SECRET` and are not in backups.

### Added
- **Settings → Delivery** (admins, Phase 36.1): the email server is set up
  in the app, with *Send test email* using the typed values unsaved, and
  *Remove email server*. The password is stored encrypted (or as `env:NAME`),
  never shown again, kept out of backups and job output.
- `SESSION_SECRET_FILE`: the Docker image writes a random `SESSION_SECRET` to
  `/data/session-secret` on a **fresh** volume.
- **Settings → Account → Notifications** (Phase 36.2): each person's own
  Email, ntfy, Gotify and webhook channels, each with a status, the last
  result and *Send test* (with the typed values, unsaved). Tokens are
  encrypted, never shown again and never in backups. Nobody else, admins
  included, can see a person's channels.
- **Where members can send** (Settings → Delivery): the internet only, the
  internet and your network (default), or this server too. Link-local,
  unspecified and reserved addresses are always refused for members; every
  address is checked and pinned on each send.
- **Telegram, Discord, Pushover, Mattermost and Slack** (Phase 36.3), each
  with the person's own bot, application or webhook. Messages fit each
  service's limit (cut between reminders, with "…and N more" and the link),
  can't ping anyone or inject formatting, and the monthly digest arrives
  quietly. Telegram, Pushover and Slack tokens are checked when saved;
  Telegram's *Find my chat* finds the chat ID for you. The cards that send
  through someone else's servers say so.
- **What each channel receives** (Phase 36.4): every card on Account →
  Notifications, email's included, chooses among *Due*, *Overdue*,
  *Monthly digest*, *Price alerts* and (admins) *Job failures*. Everything
  is ticked until you change it.
- **Quiet hours** (Phase 36.4): one period per person, in their time zone,
  that can run past midnight. Nothing is sent inside it; the first run
  after sends what still applies then, one message per kind. Tests ignore
  both and say so.

### Changed
- Notification channels moved from Settings → Reminders to Account →
  Notifications; Settings → Reminders says where reminders go.
- A personal channel that fails 5 times in a row switches itself off and
  says so, once, through the person's other channels (email never does).
- No channel follows redirects any more (the server's webhook followed up
  to three).
- A personal channel's saved settings are checked against its rules before
  every send; one that no longer passes (after a restore, say) isn't sent,
  says so on its card, and doesn't count towards switching it off.
- Every price alert of one person's that fires in one check now goes as
  one message ("2 price alerts"); a single alert is unchanged.
- Within one job run, a service that fails 3 times without answering is
  skipped for the rest of the run (shown on its card, never counted
  towards switching it off), so one that is down for everyone costs three
  timeouts, not one per person.
- The webhook payload is unchanged.

### Deprecated
- `WEBHOOK_URL`: still the server's webhook, receiving every recipient's
  notifications; each person can now add their own.

### Removed
- **The `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`,
  `MAIL_ENCRYPTION`, `MAIL_FROM` and `MAIL_TO` variables.** Nothing is
  imported from them.
- **`NTFY_URL`, `NTFY_TOKEN`, `GOTIFY_URL`, `GOTIFY_TOKEN` and
  `GOTIFY_PRIORITY`** (Phase 36.2): imported once by the upgrade into every
  admin's own channels (and `NTFY_TOKEN` onto topics on the same server),
  then no longer read.

### Upgrade notes
- **Email is off after upgrading until an admin sets the server up in
  Settings → Delivery.** The page says so while any `MAIL_*` variable is
  still set; remove them afterwards. `MAIL_TO` is now the *Default
  recipient for admins* field there.
- Three migrations (`notification_secrets`, `notification_channels`, and
  `notification_channels.categories`).
- **Keep the `NTFY_*` and `GOTIFY_*` variables, and `SESSION_SECRET`, as
  they are until the new version has started once**: the upgrade imports
  them. Remove them afterwards (Settings → Delivery says while any is set).
  Without a `SESSION_SECRET` the tokens can't be encrypted, so those
  channels ask for their token again; nothing is copied in the clear.
- Members' channels that point somewhere the new *Where members can send*
  setting refuses are kept and shown as blocked.
- **Notification secrets are encrypted with `SESSION_SECRET` and are not in
  backups**: a restored install asks for the email server's password and
  each person's tokens again.
- `bin/dev-setup.sh` points Settings → Delivery at the development
  stack's Mailpit when no email server is saved.

## [3.2.0] — 2026-10-06

Phases 35.1 and 35.2: **show it, and run it where you already run
things**. A public demo that puts itself back, and tested guides for Caddy,
Traefik and Proxmox.

No migration and no change to backups: pull and restart. New optional
configuration: `DEMO_MODE`, `DEMO_PASSWORD` and `DEMO_RESET_HOURS`, all off
unless set.

### Upgrade notes
- None.

### Added
- **Reverse-proxy guide** (Phase 35.2,
  [docs/reverse-proxies.md](docs/reverse-proxies.md)): nginx, Apache, Caddy
  and Traefik in front of Logbook, at the root of a domain or at a subpath,
  with HTTPS; what to set (`APP_URL`, `APP_BASE_PATH`, `SESSION_SECURE`),
  exactly what Logbook trusts from a proxy (no forwarded header, the
  connecting address only), the health check, forward-auth exemptions,
  large uploads and timeouts, and the common failures. New examples for
  Caddy and Traefik in `docker/examples/`, each layered on the project's
  compose file, at the root and at a subpath, forwarding or stripping the
  prefix. CI runs those files unchanged over HTTPS on every change.
- **Proxmox VE guide** (Phase 35.2, [docs/proxmox-lxc.md](docs/proxmox-lxc.md)):
  Logbook in an LXC container, either with Docker in an unprivileged
  container (and Proxmox's own advice to prefer a VM, stated plainly) or with
  Debian 13's PHP 8.4, nginx and SQLite or PostgreSQL natively; container
  size, start at boot, putting it behind a proxy, a Proxmox backup *and*
  Logbook's own backup and why both, updating, and logs.
- **Demo mode** (Phase 35.1, [docs/demo-mode.md](docs/demo-mode.md)): a
  public demo that resets itself. With `DEMO_MODE=true` and a
  `DEMO_PASSWORD`, an **empty** database is seeded with the sample garage and
  one admin account, `demo`; the `demo_reset` job (every `DEMO_RESET_HOURS`,
  24 by default) and `bin/demo-reset.php` put it back, with every date moved
  to today, in one transaction that keeps the old data if anything fails.
  Only a database that was seeded as a demo (it carries a marker that is
  never in a backup) can ever be reset: the same variable on a database
  with real data changes nothing, logs an error and tells every admin.
  Visitors cannot reach users, backup, jobs, API keys, AI or fuel price
  providers, change the password, email address or avatar, upload a file, or
  make the app send anything: mail and every outbound request are refused.
  A banner on every page says when it resets, the sign-in page shows the
  credentials with a *Fill in* button, and every response says `noindex`.
  Every route is either blocked in the demo or listed as allowed, so a
  route added later must choose. The compose files pass the three new
  variables on. No migration.

### Fixed
- The compose files pass `SESSION_SECURE` on to the app, so setting it in
  `.env` next to them now has an effect (empty, the default, still follows
  `APP_URL`).

## [3.1.0] — 2026-10-06

Phases 34.1–34.3: **see it at a glance**. Registration plates that look
like plates, where the money went on the dashboard, and what is due as a
month.

No migration, no configuration change and no change to backups: pull and
restart.

### Upgrade notes
- The new dashboard widgets (*Calendar*, *Expense breakdown* and *Monthly
  spend*) are added to the end of an existing dashboard. Move or hide them
  under *Customise*; *Reset layout* puts them in their default places.

### Added
- **Reminders calendar** (Phase 34.3): Reminders gets a *Calendar* view
  beside the *List*, a month at a time. It shows exactly the reminders the
  list shows you, on their due dates, with the week starting on the day
  your language starts it and week numbers to match; an overdue line
  above the month with links to the oldest; reminders with no date yet
  under it; and done and dismissed ones muted (*Hide done and dismissed*
  takes them away). A day with more than three opens in full under the
  month with every action, and *Add reminder* with that date filled in.
  On a phone the month reads as an agenda of the days that have
  something on them. Every part works without JavaScript and follows the
  dashboard's vehicle chips.
- **Calendar widget** (Phase 34.3): a small month on the dashboard with
  the days that have open reminders marked by how urgent they are; each
  opens that day on the calendar. It follows the vehicle filter and goes
  with the Reminders module.
- **Expense breakdown and Monthly spend widgets** (Phase 34.2): two new
  dashboard widgets built from Reports' own figures. *Expense breakdown*
  shows where the money went by category, for *This month*, *Last 12
  months* or *This year* (a link, so it can be bookmarked), with a bar
  and whole-percentage shares that add up to 100%; each category opens
  Reports for it. *Monthly spend* shows the last 12 months as stacked
  bars with the average per month, and a table without JavaScript; each
  month opens Reports for that month. Both follow the vehicle filter,
  count only vehicles whose costs you may see, keep currencies apart and
  go with the Reports module. Saved layouts get them at the end; *Reset
  layout* puts them after *Spend this month*.

### Changed
- The light theme's *Tax* and *Other* chart colours are darker, so every
  category's bar and segment stands out from the card at 3:1 or better.
- **Registration plates** (Phase 34.1): a registration is drawn as a number
  plate in its owner's style: a yellow UK plate with a blue "UK" band for
  an owner whose locale is in GB, a white neutral plate for everyone else.
  A shared car looks the same to everyone. Plates keep their colours in
  both themes and every accent, keep a border in forced-colours mode, and
  copy as the registration only. The sale pack cover keeps its plate;
  every other print view, CSV, the API and Ask keep the text.
- The sample data adds an off-road trail bike with no registration, and
  the plug-in hybrid gets a personalised registration (`PHV 1`).

## [3.0.0] — 2026-10-05

Phases 33.1–33.4: **the new design, and accounts that look after
themselves**. Forgotten passwords by email, one confirmed email address
per account, pictures and admin controls; the sign-in pages, Settings,
the vehicle pages, Cost of ownership, Ask, a new Insights page and Fuel
stations redrawn to the new design.

**Why 3.0:** the sign-in flow and the account model change (self-service
password reset, one email address per user, sign-in by email), and the
app's navigation and Settings are reorganised, so links and habits from
2.x move. There is **no API change**: the API stays v1, and every page
keeps its address.

### Upgrade notes (from 2.16)
- **Three migrations**, applied on start as usual: the account's email
  address and picture columns (reminder email addresses move from
  Settings → Reminders onto the account, counted as confirmed, so every
  user keeps theirs), `vehicles.purchase_seller`, and the AI insights
  cache (`ai_insights`, never backed up). All three roll back.
- A **Forgotten your password?** link appears on the sign-in page once
  email is configured. Set `PASSWORD_RESET_ENABLED=false` to hide it.
- **Stations** is now **Fuel stations**; its addresses are unchanged.
- **Settings links have moved** into groups (and your own settings onto
  `/profile`); every page Settings links to keeps its address.
- The sample users of `./bin/dev-setup.sh --with-sample-data` no longer
  have fixed passwords: new ones are printed on every run.
- With AI set up, a new hourly job, `ai_insights`, makes each recent
  user's AI insights once a day. It never runs while Ask isn't set up.

Phase 33.4: **Cost of ownership, Ask, Insights and Fuel stations**. See
[docs/reports.md](docs/reports.md) and [docs/ai.md](docs/ai.md).

### Added
- **Cost of ownership** (Reports) is four summary cards (*Total cost*,
  *Per month* for the vehicles you still own, *Depreciation* with its
  share, and *Finance interest*: the interest and fees of HP, PCP and loan
  agreements paid so far) and a card per vehicle with a coloured bar of
  what its cost is made of (fuel, maintenance, insurance tax and MOT,
  other, depreciation), largest first. Vehicles in another currency get
  their own cards. Print and CSV are the table, as before.
- A **Cost of ownership tab** on each vehicle, after Expenses, for those
  who can see its costs: the total, per month, per mile or km and how long
  you have owned it, a row per part with its share, and how it is worked
  out.
- An **Insights page** (`/insights`, *Insights* in the sidebar): *Ask
  Logbook* at the top, then every insight, not just the dashboard's two.
  The dashboard widget's title links to it.
- **AI insights**, with AI set up: once a day the model looks through your
  records with Ask's read-only tools and writes up to four short
  observations, each marked *AI*, with its sources and the model that
  wrote it. Every figure goes through Ask's check, and one Logbook didn't
  provide is highlighted. Cached for the day, with *Refresh*. Nothing is
  made with AI off, and nothing is ever drafted.
- **Fuel stations** opens with **Prices nearby** when a price provider is
  on: the stations around your first place (or your location), by grade,
  cheapest or nearest first, each price against the area's average, and
  how much a tank would save against what you've paid lately. Each station
  has a favourite star, *Directions* (OpenStreetMap, or your phone's maps
  app) and *Log fill-up here*, which opens the fill-up form with the
  station chosen. A station not yet in Logbook is added when you star it
  or fill up there. *Your stations* follows, as before.

### Changed
- **Ask** is laid out as the new *Ask Logbook* card: a shorter box beside
  *Ask* (Enter sends, Shift+Enter starts a new line) and four new
  suggestions. Conversations, sources, drafts and feedback are unchanged,
  and Ask keeps its place in the menu.
- Tyres say **Fitted {month}** from when a tyre first went on the vehicle;
  a move or rotation no longer changes it.

Phase 33.1: **accounts**. See
[docs/users-and-sharing.md](docs/users-and-sharing.md).

### Added
- **Forgotten your password?** on the sign-in page, when email is set up:
  type a username or email address and a 60-minute link is emailed to the
  account's confirmed address. The answer and its timing are the same
  whatever was typed; requests are limited per address and emails per
  account. Setting the new password signs you in and signs out every other
  session, and a notice goes to your address. `PASSWORD_RESET_ENABLED=false`
  turns it off.
- **One email address per account**, on Settings → Account, confirmed by a
  24-hour link before it is used; changing it needs your current password
  and tells the old address. **Sign in with it** instead of your username
  (when only one account has it).
- **Admin controls** on Settings → Users: *Send reset email*, *Sign out
  everywhere*, and *Add user*, which makes the account at once and emails a
  link to choose a password.
- **Pictures**: upload one on Settings → Account; it is cropped square and
  saved without its metadata, and shown in the sidebar, on Settings →
  Users, sharing lists, "added by" and Ask. In backups and user exports.
- **Mailpit** in the development stack catches every email the app sends
  (`http://localhost:8025`).

### Changed
- The reminder email address moved from Settings → Reminders to Settings →
  Account. The upgrade moves everyone's address across, counted as
  confirmed; nothing to do.
- *Disable* and *Enable* on Settings → Users are now **Revoke access** and
  **Restore access**, with a confirmation page. They do the same as before.
- A user created by single sign-on keeps the provider's email address
  (confirmed when the provider says `email_verified`).
- `./bin/dev-setup.sh --with-sample-data` makes **new random passwords** for
  `demo` and `partner` on every run (printed, and kept in
  `var/dev-credentials`); there is no fixed demo password any more.

Phase 33.2: **sign-in and Settings to the new design**.

### Changed
- **Signed-out pages** (sign in, forgotten and reset password, setup,
  invitation, one-time sign-in, email confirmation) share one layout: the
  Logbook mark above one card. Password fields have a **show / hide**
  button, and new passwords show a live checklist of the rules (at least 8
  characters, both entries match). Without JavaScript both are left out.
- **Settings** is grouped: *Account*, *Reminders and
  notifications*, *Vehicles and driving*, *Your data*, *Developers*,
  *Administration* and *Installation*, each a link you can bookmark
  (`/settings#driving`). Tyres, trips, places, importing and API keys
  moved out of the reminders card into cards named for them. Every page
  Settings links to keeps its address.
- **Your profile** has a page of its own, `/profile`: click your name in
  the sidebar (or your picture in the top bar on a phone). Everything
  about you moved there from Settings: email address, picture, password,
  single sign-on, *Use AI* and *Sign out*, and your name, appearance,
  units and currency, language and region. Settings keeps a *Profile*
  link at the top.
- The **Metric / UK / US** quick-setup buttons show which one your units
  match, and react to hover and keyboard focus.
- **Stations** is now **Fuel stations** in the menu and page titles. Its
  addresses (`/stations`) are unchanged.
- **Settings** sits below **Ask** in the sidebar.

Phase 33.3: **the vehicle pages to the new design**. See [docs/finance.md](docs/finance.md) and
[docs/incidents.md](docs/incidents.md).

### Added
- **Finance is a tab** on each vehicle, between Incidents and Expenses,
  for those who can manage the vehicle and see its costs. It shows the
  current agreement as cards: the monthly payment with *Payment 18 of 36*
  and the end month, *Paid so far* and *Still to pay*, the figures, a
  *Purchase* card and *Value & equity*, then the schedule, extras and
  quotes, and earlier agreements below. The header's *Finance* button is
  gone; every finance address still works.
- **Bought from** and **Mileage when bought** on the vehicle form. The
  mileage joins the Mileage tab as a *Bought* reading on the purchase
  date.
- An **Insights** dashboard widget: up to two short observations worked
  out from your own figures (money saved by shopping around, business
  mileage you can claim, your cheapest vehicle to run, equity in a
  financed car), each linking to the page behind it.
- The trips tab's **Business and private** card: this tax year's split of
  the distance driven, as a bar with percentages, and a *Trips* count.
- A **Breakdown** incident type, and **Copy for insurance quote** on the
  claims history.
- **Current tyres** shows each tyre's depth with a tread bar (once it has
  been measured twice), when it was fitted, and a note with your own
  replace-at and legal-minimum settings. *Check tread* is a button.

### Changed
- The vehicle's name is the same size on every tab, and the tabs follow
  the new order: … Documents, Incidents, Finance, Expenses. The tab strip
  scrolls to the current tab and shows when there are more.
- The **dashboard's Your vehicles** shows three to a row on a wide screen.
- **Incidents** are cards with a type icon, the claim's status and a row
  of totals; an incident's page and the claims history (now with totals
  for the period) follow the same style.

### Fixed
- The edit link of a reading written by an incident opened a "not found"
  page.
- The trips tab said "no rates" when there were simply no business trips
  this tax year.

## [2.16.0] — 2026-10-05

Phase 32: **true cost per mile or km**. What each vehicle really costs to
run per distance, what that figure is made of, and why it changed from one
year to the next. See [docs/reports.md](docs/reports.md).

### Added
- **The breakdown** on the overview's *Cost of ownership* card: the cost
  per distance split into *Fuel*, *Maintenance*, *Insurance, tax and MOT*,
  *Other* and *Depreciation* (with insurance payouts on their own line), as
  a bar and a list that add up exactly, with a switch between *Since
  bought* and *Last 12 months*.
- **Depreciation for any period**: the value between two value points
  (purchase, valuations, sale) runs in a straight line. A period past the
  latest value is measured up to it and says so; nothing is extrapolated.
- **The *True cost per distance* dashboard widget**: every active vehicle
  ranked by cost per mile or km, by currency, with the change against the
  12 months before. It follows the vehicle chips and switches between the
  last 12 months and since bought.
- **Reports → True cost** (`/reports/true-cost`): each vehicle's cost per
  distance by calendar year, stacked by part, with partial years hatched
  and the table beside it; a fleet chart with one line per vehicle; CSV
  export and a printout. The vehicle's Expenses tab shows the same trend.
- **What changed**: each year against the year before, split into
  contributions that add up exactly. Each part, fuel price against economy
  (per energy, so a plug-in hybrid gets both), and the effect of driving
  more or fewer miles on insurance, tax and MOT and on depreciation.
- In the 12-month and yearly figures, a document with a start and an expiry
  date is spread over its cover, so a renewal counts for the months it
  covers.
- **Ask Logbook**: a `true_cost` tool, so "Why has my car got more
  expensive?" is answered from these figures (also offered to MCP clients).
- **API**: `GET /api/v1/vehicles/{id}/true-cost?period=` (`last_12_months`
  or `since_bought`), and `true_cost_per_distance` in the vehicle
  summary's costs.
- **Sample data**: the demo Golf now has a fill-up a month from 2021 and a
  valuation each spring, so its trend has four full years with
  depreciation, and 2024 shows a fuel price rise, better economy and less
  driving.

### Changed
- A vehicle that has **gained** value now shows its depreciation per
  distance as a negative figure ("−£0.02/mi") instead of none. Its cost of
  ownership per distance includes the gain instead of being marked
  "running costs only". No other cost of ownership figure changes.

### Upgrade notes
- No migration and no configuration change. The new dashboard widget is
  added to the end of existing dashboards; move or hide it under
  *Customise*.

## [2.15.1] — 2026-10-04

Phase 31.2: the Fuel stations module shows its icon.

### Fixed
- **Settings → Modules** showed a blank space instead of an icon beside
  *Fuel stations* (since 2.13.0). It now shows its pin icon like every
  other module.

### Upgrade notes
- None: no migrations, no configuration changes and no change to the backup
  format.

## [2.15.0] — 2026-10-04

Phase 31: **import from Fuelio**. Bring years of fill-ups, services,
costs and favourite stations across from Fuelio in one go, checked
against Fuelio's own economy before anything is written, and safe to
repeat with a newer export. Also **CNG** as a fuel. See
[docs/import.md](docs/import.md#from-fuelio).

### Added
- **Settings → Import from another app** (and *Coming from Fuelio?* on a
  vehicle's fill-up import): upload a Fuelio CSV export. Logbook reads
  every section (the vehicle, fill-ups, cost categories, costs, favourite
  stations, photos) and asks only what it can't work out: the vehicle (one
  you can manage, or a new one from the file), the units (read from the
  file's headers), the date order, where each cost category goes
  (Maintenance, Expenses or nowhere) and what each fuel code is.
- **The units' sanity line**: "With miles and litres, these fill-ups
  average 22.6 mpg (12.5 L/100 km). Fuelio's own figures agree." Miles read
  as kilometres, or gallons as litres, show up before the import.
- **A preview** per section with each row's outcome (*import*, *invalid*,
  *duplicate*, *already imported*, *not imported* with the reason: income,
  cost templates, a fuel or category set to *Don't import*), then **one
  transaction** for the whole file.
- **Stations**: a fill-up's station is matched by Fuelio's id to its
  favourite station, then by name, then within 150 m of one you have; the
  favourites come across with their positions as your favourites. A
  fill-up's own GPS position is used for that match only and never stored.
- **Re-importing**: each row is remembered by Fuelio's own id, so a newer
  export adds only the new rows, even after you've edited imported ones.
- **Service schedules** from repeating costs (optional).
- **`php bin/import-app.php`** for Fuelio backup ZIPs, which carry the
  fill-up photos and run to hundreds of megabytes: the same mapping and
  preview, `--dry-run`, `--vehicle`, `--create`, `--as` and `--schedules`.
  Photos are attached to their fill-ups, checked and stripped of their
  metadata like any upload. Archives are checked before anything is read:
  at most 50 entries, safe names only, sizes and compression ratios
  capped, and only Fuelio's own `pictures.data` opened inside.
- **CNG** as a fuel and a vehicle fuel type (bi-fuel with petrol): sold
  and logged in kg, economy in kg/100 km or mi/kg, its own series so a
  bi-fuel car's petrol and gas never mix, its own economy-drift check. The
  CSV import and export, the API, *Ask Logbook* and the scans take it.
- **API** (OpenAPI 1.20.0): `cng` for fuel and fuel type, `kg` as a
  volume unit, `kg_per_100km`, the `gas` energy kind and a `gas` series in
  the vehicle summary.
- `bin/tools/anonymise-import.php` turns real exports into test fixtures.
  English and German.

### Upgrade notes
- One migration (`import_sources`), included in backups and in
  `bin/export-user.php`; the schema version moves, so a 2.15 backup
  restores only into 2.15. No configuration.

## [2.14.0] — 2026-10-03

Phase 30.2: **live fuel prices and cheapest near me**. Once an admin
switches it on, Logbook downloads the UK's official Fuel Finder price list
to your own server and answers "where's the cheapest fuel near me?" there,
so your location is never sent anywhere. Results are ranked by what the
trip really costs: your usual fill plus the fuel to drive there and back.
See [docs/stations.md](docs/stations.md#fuel-prices).

### Added
- **Settings → Fuel prices** (admins): choose a provider (*Off* by
  default), enter its credentials (stored encrypted, or `env:NAME`; never
  shown again), the refresh (every 30, 60 or 120 minutes) and which E5
  grade the feed's E5 is, with the last sync and *Sync now*.
- **UK Fuel Finder**, the statutory open price feed (Open Government
  Licence v3.0): every UK station's listed prices for E10, E5, diesel,
  premium diesel, B10 and HVO. It needs a free client ID and secret from
  the Fuel Finder developer portal. The `fuel_prices` job fetches only what
  changed each run, with a full download once a day. Prices typed in
  pounds are corrected, implausible ones are skipped, and closed stations
  are left out.
- **Linking stations**: a station's page offers the feed's stations within
  150 m (*Is this the same station?*). Once linked, its address, position,
  hours and grades follow the feed unless *Keep my details* is ticked.
- **Listed prices** on a linked station's page beside what you paid, and
  as a dashed series on its price chart, from a history of each listed
  price kept for `PRICE_HISTORY_DAYS`.
- **Cheapest near me** (Stations, and the Fuel tab's *By station* card):
  from your current location (used for that search only), one of your
  places or a station; for a vehicle and grade; within 2 to 20 miles or
  km. Ranked by effective cost, each row opens the sum against the
  nearest: fuel saving, extra distance (straight line × 1.3), the fuel
  for it, and the actual saving.
- **Was it worth it?** after a fill-up at a linked station, compared with
  your usual station at the prices listed then, and the Fuel tab's
  *Shopping around* total for the last 12 months.
- **The fill-up form**: at a linked station, "Listed £1.379/L E10 95 at
  14:20 · Last time you paid £1.389" and *Use listed price* (never filled
  on its own).
- **Price alerts** on favourite stations: one notification through your
  channels when the listed price drops below yours, again after it goes
  back up.
- **Dashboard widget** *Cheapest fuel* near a place you choose.
- **API** (OpenAPI 1.19.0): `GET /api/v1/fuel-prices/near`, and `listed`
  on station responses. **Ask Logbook and MCP**: `cheapest_fuel` ("Where's
  the cheapest E10 near work?").
- **Sample data**: a made-up price provider (outside production only),
  eleven stations near the demo places, three of the demo's stations
  linked with a year of listed prices, and an alert.
- `bin/record-fuel-finder.php` records a trimmed real download for the
  tests. English and German.

### Fixed
- The station pages' star, map, merge, location, places and search icons
  were missing from the icon sprite and showed blank.

### Upgrade notes
- One migration: the provider tables, listed price history, price alerts
  and provider credentials, and `provider`, `provider_ref` and
  `keep_my_details` on stations. It rolls back.
- **Nothing changes until an admin enables a provider** on Settings →
  Fuel prices: no request is made and no listed price appears before
  then.
- The provider's copy of stations and prices and its credentials are
  never in backups; after a restore the next sync downloads everything
  again, and the credentials are entered again. Links, the price history
  and alerts are backed up.
- New setting `PRICE_HISTORY_DAYS` (default `1095`, at least 30).
- The schema version moves: restore an older backup with its own version,
  then upgrade.

## [2.13.0] — 2026-10-02

Phase 30.1: **fuel stations**. A fill-up's station is now a record, linked
from every fill-up, so Logbook can show where you usually fill up, what
you paid at each station per grade over time, and how far each one is
from your own places. All of it comes from your own fill-ups: nothing in
this release asks any outside service. See
[docs/stations.md](docs/stations.md).

### Added
- **Stations** (module `stations`, on by default, part of fuel): name,
  brand, address, postcode, country, position, grades sold (charging
  types for a public charger), opening hours and notes. Shared by
  everyone on the install; anyone adds and favourites them, the creator or
  an admin edits and merges them.
- **The fill-up form's station** is a search box: favourites first, then
  recent stations, then the rest, with *Add "…"* for a new one and "Last
  time here: £1.389/L E10 95, 12 Sep" under it (a hint, never a prefill).
  Without JavaScript, a list of favourites and recent stations plus
  *Other…*. Home charging is never a station.
- **Stations** in the menu: favourites first, then by last visit, with
  distances from your places, visits, and the average paid in the last 12
  months. **A station's page**: details, *Favourite*, *Open in maps*, what
  you paid per grade (visits, spend, the average weighted by volume, the
  cheapest) with a price chart, and your fill-ups there.
- **Settings → Places**: *Home*, *Work* and others, typed, copied from a
  station or from *Use my current location*. Straight-line distances, so
  labelled. Places are private: never in the API, Ask, MCP, print views,
  the sale pack or anyone else's pages.
- **Duplicates and merging**: pairs with the same brand and name, names
  one letter apart, or positions within 150 m; merging moves every
  fill-up and favourite and keeps old links working.
- **The Fuel tab's *By station* card**: a vehicle's top five stations by
  spend over the last 12 months.
- **CSV import** links or creates stations, and the preview says which;
  **receipt scans** choose the station or name a new one; **Ask drafts**
  mark a new station on the card.
- **API** (OpenAPI 1.18.0): fill-ups gain `station_id` beside `station`;
  writes take either; `GET /stations` and `GET /stations/{station}` give
  stations with what the key's user paid there. Nothing existing changed.
- **Ask Logbook and MCP**: `stations(query?, favourites_only?)` for "Where
  do I usually fill up?" and "What's the cheapest I've paid at Tesco?".
- **Sample data**: eight stations (two spellings of one, ready to merge),
  two favourites, Home and Work.
- English and German.

### Upgrade notes
- Two migrations: the station, favourite and place tables with
  `fuel_entries.station_id`, then a data migration that turns the station
  names on your fill-ups into stations. Both roll back; the station text
  on each fill-up is never changed.
- **One station per spelling.** Names that differ only in capitals or
  spacing become one station; different spellings ("Tesco Antrim",
  "Tesco, Antrim Rd") become two. **Stations → Duplicates** lists the
  likely pairs (names one letter apart, the same brand and name, or within
  150 m once you add positions), and *Merge* on any station's page joins
  any two.
- Home charging (grade *Home*) is left as text.
- New setting `FEATURES_STATIONS` (default `true`); stations are off
  whenever fuel is.
- The schema version moves: restore an older backup with its own version,
  then upgrade.

## [2.12.0] — 2026-10-02

Phases 29.1 and 29.2: **finance and lease agreements**. Type a hire
purchase, PCP, personal loan or lease agreement from its paperwork, and
Logbook works out the payment schedule, the payments left, what remains to
pay, a settlement estimate (or your lender's quote), the cost of credit,
the half-paid point and your equity. The interest, fees or rentals count
in your costs once each. For a PCP or lease it tracks your mileage against
the allowance and the excess charge you are heading for, and ending an
agreement (settling, completing, handing back, selling with finance owing)
leaves an exact lifetime cost. Figures, never financial advice. See
[docs/finance.md](docs/finance.md).

### Added
- **Finance agreements** (module `finance`, on by default): *Add finance*
  in the vehicle's header opens a form with the fields of each type, a
  consistency check that warns when the figures don't add up to the
  agreement's total, and an offer to set (HP, PCP) or clear (lease) the
  purchase price. The agreement number is optional and shown as its last
  4 characters everywhere but the edit form.
- **The agreement page:** payments remaining, remaining to pay (exact),
  the next payment, the settlement estimate or a valid lender's quote,
  the cost of credit (estimated while it runs, exact once ended), the
  half-paid point, equity against a valuation from the last 12 months,
  and the schedule with *Mark missed* and *Mark paid late*, extra
  payments and settlement quotes. Printable; the schedule exports as CSV.
  The overview gains a *Finance* card.
- **Costs counted once:** credit charges (never capital) or rentals join
  the cost ledger as *Finance and lease* lines derived from the
  agreement, in Reports, the Expenses tab, cost of ownership and cost per
  distance. Manual finance expenses in the months an agreement covers are
  flagged as possibly counted twice.
- **Mileage against the allowance** (PCP and leases): the allowance over
  the agreement, the distance so far, the allowance used to date, and the
  projected distance at the end with the excess charge it would bring,
  on the agreement page and the card.
- **End agreement:** settled early, completed, handed back (PCP) or lease
  ended, with the excess mileage and damage charges logged as expenses.
  While a vehicle has an agreement, *Archive* opens a page with *Sold*
  (an active HP or PCP warns and offers *Settled from the sale*),
  *Returned to the lender*, *Returned to the lessor* and *Just archive*.
  A PCP handed back is a sale at its optional final payment.
- ***Coming up*** shows the next 12 months' payments as one line per
  vehicle and a final payment as its own item; **reminders** for the final
  payment and *Agreement ends: decide what to do* 90 days before a PCP or
  lease ends; ***Needs attention*** lists a missed payment (*Now*) and a
  PCP or lease heading more than 2% over its allowance.
- The **Finance** dashboard widget, `GET /api/v1/vehicles/{id}/finance`
  (OpenAPI 1.17.0; `/me` lists the module; *Coming up* and reminders carry
  the new sources) and the Ask and MCP tool `finance(vehicle)`.
- Agreements join the CSV export (`/vehicles/{id}/export/finance.csv`),
  backups and `bin/export-user.php`.
- Sample data: the Kia on a lease, the Corolla on a PCP heading over its
  mileage, and the written-off Fiesta's HP settled early.

### Changed
- Only people who manage a vehicle **and** see its costs see its finance.
  Others who see its costs get the derived lines (and *Coming up* lines)
  as plain *Finance and lease* entries, so everyone's totals match.
- An archived vehicle can be *Returned to the lender* or *Returned to the
  lessor*, labelled as such on its card, overview, ownership report,
  History and CSV.
- The demo bike now carries the stale valuation that the Corolla had.
- New variable: `FEATURES_FINANCE` (see
  [`docs/configuration.md`](docs/configuration.md)).

### Upgrade notes
- Two migrations (the finance tables; `vehicles.disposal` widened for the
  new disposals). Both roll back; rolling the second back turns
  *Returned to the lender* and *the lessor* into *Sold*, keeping the
  sale, and removes finance reminders.
- Existing *Finance and lease* expenses are unchanged. When you add an
  agreement, the overlap warning lists the ones it may count twice, so
  you can delete them or keep them and stop counting the agreement.

## [2.11.0] — 2026-10-02

Phases 28.1 and 28.2: **background jobs you can see, and knowing when a new
version is out**. Reminders, the monthly digest, cleanup and backups are
now named jobs on **Settings → Jobs**, with when each last ran, its output
and *Run now*. Admins are warned on the dashboard when nothing has run for
too long, and hosts without cron can run jobs on page visits or from a
URL. An optional **update check** tells admins when a new Logbook is out;
it is off until switched on and never installs anything. See
[Background jobs](docs/deployment.md#background-jobs) and
[Update check](docs/deployment.md#update-check).

### Added
- **Settings → Jobs** (admins): each job (*Reminders*, *Monthly digest*,
  *Cleanup*, *Backup* and *Update check*) with its schedule, its last run
  (when, what started it, how it went, a one-line summary) and when it
  runs next, and **Run now**. Under it, the recent runs. A run's page
  shows its full output, with passwords, keys and tokens masked as
  `••••`, and updates while the run goes on. A job never runs twice at
  once; a run that finds its job busy says which run holds it.
- **Scheduler health:** when nothing has run for twice
  `SCHEDULER_INTERVAL` (30 minutes by default), Settings → Jobs and a
  notice on the dashboard say so, with the exact cron line for this
  install and the other ways to fix it. `/health` reports the last pass
  (`"scheduler": {"last_pass": …, "stale": …}`) without changing its
  status code.
- **Failure alerts:** when a job fails twice in a row, admins see a notice
  on the dashboard until it works again, and are sent a `job_failed`
  notification once, through their own channels.
- **Without cron:** *On page visits* runs due jobs in the background while
  someone uses Logbook, and *External URL* runs them when a service such
  as cron-job.org or Uptime Kuma calls a secret URL. Both are off by
  default.
- **Scheduled backups:** *Off*, *Daily* or *Weekly* on the *Backup* job,
  keeping the last 7 (1–60). They are written to `BACKUP_PATH` and listed
  on the Backup page with *Download*; your own backups are never deleted.
- `php bin/run-job.php <job>` runs one job and prints its output;
  `php bin/run-job.php --list` lists them.
- **The update check** (Settings → Updates, admins): once a day, at a
  minute chosen for your install, Logbook asks `api.github.com` for the
  latest stable release of `UPDATE_CHECK_REPO`. Nothing about your data
  is sent. When a newer version is out, admins see a banner on the
  dashboard with the release notes, that version's upgrade steps and the
  command for Docker (or "Back up, then follow the upgrade steps" on bare
  PHP). *Dismiss* hides it for that version; the next release brings it
  back. *Check now* checks at once. Errors and rate limits show on the
  page, never as a banner or a failure alert. First-run setup offers
  *Tell me when a new version is out*, unticked.
- New variables: `JOB_TIME_LIMIT`, `UPDATE_CHECK_REPO` and
  `UPDATE_CHECK_ALLOWED` (see [`docs/configuration.md`](docs/configuration.md)).
  The Docker image sets `LOGBOOK_DOCKER=1`.

### Changed
- Cleanup now runs **hourly** and also deletes invitation links closed
  (used, revoked or expired) over 90 days ago, and job runs past 90 days
  or each job's last 50.
- `bin/run-scheduled-tasks.php` is now a scheduler pass over the jobs. It
  stays quiet unless given `-v` and keeps its exit codes, so existing cron
  lines need no change.

### Upgrade notes
- One migration (`job_runs`). It rolls back.
- The **update check is off** until an admin switches it on in Settings →
  Updates. `UPDATE_CHECK_ALLOWED=false` removes it entirely, for installs
  that must never call out.
- Bare installs without cron can now use the *On page visits* or
  *External URL* trigger on Settings → Jobs instead.

## [2.10.0] — 2026-10-02

Phases 27.1 and 27.2: **incidents, damage and insurance claims**. Record
what happened to a vehicle, what was fixed and what the insurer did. An
incident **links** the repairs, expenses and tyre changes it caused rather
than copying their costs, so nothing is counted twice. The **claims
history** answers the question every insurance quote asks: the last five
years of incidents, on every vehicle, sold ones included. When the insurer
settles a **total loss**, *Archive* records the car as written off with
the settlement as its sale. Insurer **letters** and repair **estimates**
can be scanned into the incident they're about. See
[`docs/incidents.md`](docs/incidents.md).

### Added
- **Incidents** (an *Incidents* tab on each vehicle, and *Log incident* in
  the *Log entry* chooser): the date and time, place, type, fault, driver,
  odometer, damaged areas, severity and write-off category, photos, the
  other party (folded away) and the claim (status, insurer and policy,
  claim number, excess, payout, no-claims effect, latest update). The
  insurer is filled in from the policy current on the date.
- **Photos are kept as taken**, with their date and GPS, for the insurer.
  Only those who may see an incident's details get the original; anyone
  else gets a stripped copy, and so does the sale pack's ZIP.
- **Linked records:** *Add a repair*, *Add an expense* and *Add a tyre
  change* from the incident page, *Link a record*, and *Part of an
  incident* on those forms. The incident page adds up *Linked costs*,
  *Payouts received* and *Net cost to you*, exactly as Reports counts them.
- **Claims history** (`/incidents/history`): every incident on every
  vehicle you can see, archived and sold ones included, filtered by years,
  dates, vehicle, driver, fault or claims only, printable and as CSV. The
  other party is never included.
- Incidents in **History** (an *Incidents* chip; linked records say what
  they were part of), **Reports** (an *Incidents* section; spend is
  unchanged), **cost of ownership** (net of insurance payouts, with the
  line), the **sale pack** (*Include incidents*: what was damaged and what
  fixed it, never the claim; a write-off is never hidden), the overview's
  write-off badge and **Needs attention** ("Claim 4417 with Aviva: no
  update for 34 days").
- **Total loss:** for a vehicle with a settled claim and a write-off
  category, *Archive* offers **Written off**, with the sale date and price
  filled in from the settlement. The settlement counts once, as the sale
  price, and not again as a payout ("Settlement counted as the sale
  price"). The car is labelled "Written off 14 Mar 2025" on its card, its
  page, the ownership report and History. *Restore* clears it.
- **Repair estimates:** a field on the incident, shown as "Estimate, not
  counted in costs", and never in any cost figure.
- **Reading claim letters and estimates:** two new scan kinds. A letter
  with a claim number that matches an incident opens that incident's edit
  form with only the changed fields marked (status, payout, write-off,
  latest update), and is attached on save; otherwise *Log incident* opens.
  *Update from a letter* on the incident page needs no match. An estimate
  goes on the most recent open incident, which you can change. "Settled"
  and "payment issued" read as *Settled*, "declined" as *Declined*;
  anything unclear is left for you.
- **API:** `GET` and `POST /api/v1/vehicles/{id}/incidents` (a retry never
  logs twice) and `GET /api/v1/incidents/history` (OpenAPI 1.15.0).
  **Ask Logbook** answers "Have I had any claims in the last five years?"
  and drafts incidents; over **MCP** read-and-write keys get the same
  draft.
- Incidents in the CSV export, backups and `bin/export-user.php`.

### Changed
- Saving a sale date on a vehicle marks it *Sold*; clearing the date clears
  it. A vehicle archived before 2.10.0 has no reason recorded and is shown
  as before.

### Upgrade notes
- Two migrations (`incidents` with its links; the vehicle's disposal, the
  repair estimate and a pending scan's incident). Both roll back; archived
  vehicles stay archived.
- The `incidents` module is **on by default**. Switch it off in Settings →
  Modules or with `FEATURES_INCIDENTS=false`. Its data is kept, and a car
  already written off keeps its label.
- Incident photos are kept **as uploaded**, location included, unlike
  every other photo. They leave Logbook stripped (the sale pack ZIP, and
  to anyone who may not see the incident's details).
- **Backups move to a new schema version** (incidents, the disposal and
  the estimate are included). A 2.10.0 backup restores into 2.10.0.
  Restore an older backup with its own version, then upgrade.

## [2.9.0] — 2026-10-01

Phase 26.5: **the MCP server**. Claude Desktop, Claude Code, an IDE agent
or a local assistant can now use Logbook's tools with **its own model**,
through the Model Context Protocol at `/mcp`. It needs no AI connection in
Settings → AI. It is authorised with an API key, so it sees exactly what
the key's user sees. [`docs/mcp.md`](docs/mcp.md) sets it up.

### Added
- **The MCP endpoint** at `<your URL>/mcp` (Streamable HTTP). It speaks
  the current, stateless protocol version `2026-07-28` and the earlier
  `2025-11-25` and `2025-06-18`, without sessions. Every response is
  checked against the specification's own schemas in the test suite.
- **The Ask read tools** for every key (costs, fuel, mileage, maintenance,
  documents, tyres, trips, *Coming up*, *Needs attention* and more), with
  the same figures as *Ask Logbook* and a full link to the page in
  Logbook.
- **Writes for read-and-write keys:** `log_fill_up` and `add_reading`
  write through the API's write path. A retry never logs twice. Service
  records, documents, expenses, tread checks and reminders become
  **drafts**, kept 7 days and listed on the dashboard under **Drafts to
  review**, each with *Add*, *Edit* and *Discard*. They work without AI
  set up.
- **Resources** (`logbook://vehicles`, `logbook://me` and each vehicle's
  summary) and **prompts** (*Monthly summary*, *Before a service*, *Sale
  checklist*).
- Tool descriptions and prompts in the key user's language (English,
  German).
- Settings → API keys shows the MCP address. Each tool call, resource read
  and prompt appears in the AI usage log as *MCP clients*, with the key's
  name and never the content.

### Changed
- The forward-auth examples in [`docs/sso.md`](docs/sso.md) and
  `docker/nginx/forward-auth-example.conf` also exempt `/mcp`, as they do
  `/api/`.
- `API_CORS_ORIGINS` also covers `/mcp`. An `Origin` that isn't listed is
  refused there.

### Upgrade notes
- One migration (`ai_drafts.source`, marking drafts from MCP). It rolls
  back.
- One new, optional variable: `MCP_ENABLED` (default `true`). `/mcp` is a
  404 when it, or `API_ENABLED`, is `false`. Nothing is reachable without
  an API key.
- Behind forward auth (Authelia, Authentik), exempt `/mcp` as you exempt
  `/api/`.

## [2.8.0] — 2026-10-01

Phase 26.4: **reading receipts and documents**. Photograph a garage
invoice, a fuel receipt or an MOT certificate, or choose a PDF, and
Logbook fills in the right form with each value marked and the words it
came from beside it. You check it and save it; the file is attached to the
entry. **Nothing is saved until you press Save**, and a model that fails
still leaves you the form with your file attached.

### Added
- **Scan a receipt or document** from *Log entry*, the phone app's *Scan*
  shortcut (it opens the camera) and *Fill from a file* on the service
  record, document and fill-up forms. The Scan page says which connection
  reads the file and where it runs.
- **One request reads and sorts the file**: a service or repair invoice
  becomes a service record (VAT, labour and parts in the details, a
  category from the work, a schedule it may complete suggested); a fuel or
  charging receipt a fill-up; an MOT certificate an *Inspection* document
  with its expiry and mileage (a failed test an *Other* document that
  never replaces the current MOT); insurance an *Insurance* document; a
  V5C a page of ticked updates to the vehicle; anything else an *Other*
  document. *Read it as* another kind maps the same reading again.
- **Logbook reads the values, not the model**: dates in your day/month
  order, with "Check the date: 4 May or 5 April?" when a date reads both
  ways; amounts, readings and units converted to yours; a date in the
  future or before the vehicle's first registration left empty with the
  reason. The vehicle is matched by its plate, with a warning when the
  document is for another one.
- **Recommended work and MOT advisories** are offered as reminders after
  saving, one press each or *Add all*; a distance ("in about 5,000 miles")
  is kept as a distance.
- **Manual reminders due at an odometer**, a date, or both, whichever
  comes first, on the form and in the API (`due_odometer`; OpenAPI
  1.13.0).
- Text PDFs are read as text (`smalot/pdfparser`), photos and scanned PDFs
  as pictures on the vision model; scanned PDFs are turned into pictures
  with Ghostscript (in the Docker image) or Imagick.
- `bin/ai-eval.php --scans` scores the configured models on twenty
  synthetic documents, per kind.

### Changed
- **Every photo you upload is now turned upright and stored without its
  EXIF data**, GPS position included: attachments, vehicle photos and
  scans. Files stored before 2.8.0 are left as they were.

### Upgrade notes
- One migration (`pending_uploads`). It rolls back. Scanned files wait 24
  hours for their entry, then are deleted; they are not in backups.
- **PHP's `gd` (JPEG, PNG, WebP) and `exif` extensions are now
  required.** The Docker image has them on amd64 and arm64. On bare PHP,
  install them first (`apt install php8.4-gd`); Composer refuses to
  install without them.
- The Docker image now includes **Ghostscript**, for scanned PDFs. On bare
  PHP it is optional (`GHOSTSCRIPT_BINARY`, default `gs`); without it, a
  scanned PDF asks for a photo instead.
- Reading files follows the *Read receipts* module (Settings → Modules)
  and needs a model for *Reading receipts and documents* (one that takes
  images, for photos) or *Reading text PDFs* on Settings → AI.

## [2.7.0] — 2026-10-01

Phase 26.3: **adding entries by message**. Tell *Ask Logbook* what you did
("Filled the BMW with 51 litres of E10 at £1.39, mileage 72,341") and it
drafts the entry as a card for you to check. Logbook, not the model, works
out the total, the dates and the units, and validates the draft with the
same code as the form. **Nothing is saved until you press Add.**

### Added
- **Draft tools** in *Ask* for a fill-up or charge, an odometer reading,
  a service record, a document, an expense, a tread check and a manual
  reminder. Vehicles, grades ("super unleaded" → E5 98) and categories
  are matched by Logbook, with a question back when unsure. Dates are
  worked out in your time zone ("yesterday", "last Tuesday", "two weeks
  before the MOT expires"), and numbers are read as your forms read them
  ("51,5" in German).
- **Draft cards** under the answer. Each field is shown as Logbook
  formatted it, values it worked out are marked, and the form's warnings
  are shown. *Add* saves the entry as the form would; *Edit* opens the
  normal form prefilled, marked "from your message"; *Discard* drops it.
  After *Add*, *Undo* works for 10 seconds while the entry is untouched.
- **API writes** for the same kinds: `POST /api/v1/vehicles/{id}/maintenance`,
  `/documents`, `/expenses`, `/tyres/checks` and `/reminders`. They use the
  forms' validation, are safe to retry, and are in the OpenAPI
  description (1.12.0).
- `bin/ai-eval.php` gains 30 drafting cases, and checks that no entry is
  written without *Add*.

### Upgrade notes
- One migration (`ai_drafts`). It rolls back. Drafts are not in backups
  and expire after an hour.
- No new configuration. Drafting follows the *Draft entries* module
  (Settings → Modules, on by default once AI is set up) and the module of
  each kind of entry. It needs *Log* on the vehicle, or *Manage* for a
  reminder.

## [2.6.0] — 2026-10-01

Phases 26.1 and 26.2: **AI, with the model you choose, and Ask Logbook**.
Logbook can now use a language model wherever it runs: on this server,
on a computer on your network, or with a cloud provider. The first
feature that uses it, *Ask Logbook*, answers questions in plain words from
your own records. **Everything is off until an admin connects a model**;
until then Logbook looks and behaves exactly as 2.5.0.

### Added
- **AI connections** (Settings → AI, admins only): any number, through
  four adapters with no SDK: OpenAI-compatible (Ollama, llama.cpp, LM
  Studio, vLLM, OpenAI, OpenRouter, Groq, Mistral, Together, DeepSeek and
  other gateways), Ollama's own API, Anthropic and Google Gemini. Presets
  fill the address; extra headers for a model behind a proxy; per
  connection timeout, TLS check (or a CA bundle), largest request and a
  monthly token cap.
- **Where data goes, shown:** each connection is labelled *This server*,
  *Your network* or *Internet* from what its address resolves to, checked
  again on every request. An internet connection sends nothing until an
  admin agrees, naming the host; changing the address asks again.
- **Keys** are encrypted with a key derived from `SESSION_SECRET`, or read
  from an environment variable (`env:NAME`), and never shown again after
  saving.
- **Models** listed from the provider or typed by name, with their
  capabilities (tools, images, JSON output) from the provider and
  confirmed by **Test** (a short reply, a tool call, an image, JSON).
- **Tasks:** each AI job has one model (questions; receipts and
  documents; text PDFs), so text can stay local while receipts go to a
  vision model. No automatic fallback: a failure is shown, never sent
  elsewhere.
- **Limits and a usage log:** one request at a time per user, the
  monthly cap, and calls, tokens, times and failures per connection and
  task, without questions or answers (unless `AI_LOG_CONTENT=true`).
- **Use AI features** in Settings → Account, on for everyone once AI is set
  up, and three modules (*Ask Logbook*, *Actions*, *Scan*).
- **Ask Logbook** (`/ask`, in the sidebar, the phone's header, on the
  dashboard and in the phone app's quick actions): ask "How much did I
  spend on fuel last year?", "When did I last change the oil on the
  Golf?" or "Which car costs me the most per mile?". The model may only
  call fixed **read-only tools** over Logbook's own services (costs, cost
  per distance, fuel, maintenance, last done, coming up, documents, tyres,
  mileage, cost of ownership, trips, needs attention), as the asking user:
  it sees only the vehicles and amounts you see in the app, never writes
  SQL and can't change anything.
- **Sources** under every answer, each tool call in words with its key
  figures and a link to the page showing the same thing; and a **grounding
  check** that highlights any figure in the answer that Logbook didn't
  provide.
- **Conversations** for follow-ups ("and last year?"), kept 30 days after
  their last message by default (1, 7, 30 or 90 on the Ask page), with
  *Delete* and *Delete all*; *Helpful* / *Not right* on each answer.
- Reports has a **Costs** filter (fuel, maintenance, compliance or other),
  in the totals, chart, table and CSV.
- An optional `ai` profile in `docker-compose.yml` runs Ollama beside
  Logbook.
- `bin/ai-eval.php` asks 40 questions of the configured model against the
  demo data and reports tool accuracy, figures, grounding and time.

### Upgrade notes
- Two migrations (`ai_connections` and its tables; `ai_threads`,
  `ai_messages`, `ai_progress`, `ai_feedback`). Run them as usual; both
  roll back.
- Nothing else to do. AI stays off until an admin adds a connection and
  gives *Answering questions* a model in Settings → AI. `AI_ENABLED=false`
  switches it off whatever is configured.
- **Privacy:** with a model on this server or your network, nothing
  leaves it. With an internet connection, the question and the records
  needed to answer it are sent to that provider. Backups carry
  connections, models and tasks, never keys, the usage log or Ask
  conversations. See [`docs/ai.md`](docs/ai.md).
- New settings: `AI_ENABLED`, `AI_LOG_CONTENT` and
  `AI_ALLOW_INSECURE_TLS` (see `docs/configuration.md`).

## [2.5.0] — 2026-10-01

Phase 25: **trend and cost checks**. *Needs attention* now spots a car
getting slowly thirstier, and a price or a service cost typed with a digit
too many. They are plain statistics on your own entries: no AI, no
network, and no figure is changed by them.

### Added
- **Economy drift:** the last five tanks (or charges) at least 10% worse
  than your 12-month average, judged per series, so a plug-in hybrid's
  fuel and electricity are apart (electricity at 15%, since it swings more
  with the weather). Once a year of data exists, the same months a year
  earlier must be worse too, so a normal winter raises nothing; before
  that the item says it may be the time of year. It lists the likely
  causes Logbook can see: a grade switch, new tyres, winter, an overdue
  service, shorter tanks than usual.
- **Fuel price outliers:** a fill-up more than 35% above or below what you
  paid for the same grade within 30 days (home and rapid charging apart;
  your other vehicles in the same currency when this one has too few).
  A free fill-up or charge is never flagged. Ten times or a tenth of the
  usual asks "an extra or missing digit?".
- **Maintenance cost outliers:** a record from the last 12 months more
  than 3× the category's earlier median and at least 100 above it.
- *Fix* opens the fill-up or record; *Looks right* (or *Hide* on a drift)
  keeps it hidden until it changes, or until the next tank for a drift.
- Five more thresholds on Settings → Reminders, *Needs attention* card,
  the vehicle owner's: drift (liquid and electricity), price, cost
  multiple and cost floor.
- The monthly digest and its webhook include the new checks.

### Upgrade notes
- No migration and no configuration. Thresholds saved before 2.5.0 keep
  their values; the new ones start at their defaults.

## [2.4.0] — 2026-10-01

Phase 24: **Needs attention**, one short list of what is wrong right now,
with the fix one tap away. It is deliberately not a health score: the
list is facts Logbook already works out, in a fixed order, and it
disappears when nothing is wrong.

### Added
- **Needs attention** on each vehicle's overview, first and hidden when
  empty: *Now*, overdue work from *Coming up* (services past a limit,
  expired documents, worn or old tyres, overdue manual reminders, the first
  MOT); then *Check*, data that looks wrong (readings the Mileage tab flags,
  unconfirmed unusual fill-ups as one line, mileage not updated where
  distance-based services or tyre wear need it, business trips beyond the
  mileage log, a stale valuation). Each item has its *Now* or *Check* label
  in words and its fix: *Log it* (prefilled), *Fix*, *Review*, *Add
  reading*, *Add valuation*, and *Dismiss* through its reminder.
- **Hide** on a reading, stale-mileage or stale-valuation check that is
  genuinely fine. It stays hidden only while the data it judged is
  unchanged, per user.
- A **Needs attention** dashboard widget across the vehicle filter, and a
  *Needs attention* marker on the garage cards and *Your vehicles* tiles.
- **Thresholds** on Settings → Reminders: *Mileage not updated after*
  (default 60 days) and *Valuation is stale after* (default 12 months).
  A shared vehicle uses its owner's. The Ownership card's stale-value hint
  follows the same setting.
- The **monthly digest** lists your *Check* items after what is due, and
  is sent in a month with checks even when nothing is due. The webhook's
  JSON gains an `attention` list; `items` is unchanged.

### Changed
- *Done* and *Dismiss* on a reminder return to the page they were pressed
  on (the reminder list, or the overview's card).
- The demo data has an overdue service on the motorbike and an
  18-month-old valuation on the Corolla.

### Fixed
- *Coming up* (and so the overview and dashboard) no longer fails for a
  vehicle whose fill-ups all cost 0: a fuel cost of 0 per distance
  overflowed an exact decimal multiplication.

### Upgrade notes
- **One migration** adds `attention_hidden` (the checks each user has
  hidden). It rolls back cleanly on every engine, and rolling back also
  removes the `attention.thresholds` settings. Backups include the table;
  a 2.4.0 backup restores only into 2.4.0.
- The **Needs attention** widget is appended to dashboards you have
  already arranged. Move it with *Customise*. New layouts put it first.
- The digest may now arrive in a month where nothing is due, when
  something needs checking. Switch the digest off in Settings → Reminders
  if you would rather not.

## [2.3.0] — 2026-10-01

Phases 23.1 and 23.2: sign in with the identity provider you already run.
Logbook can be an OpenID Connect client of Authelia, Authentik, Keycloak or
any standard provider, or trust the user a forward-auth proxy (Authelia,
an Authentik outpost) passes on. Both only change how someone proves who
they are, never what they can see. **Everything is off until configured.**
Guide: [docs/sso.md](docs/sso.md).

### Added
- **Sign in with OpenID Connect** (`OIDC_*`): a *Sign in with {name}*
  button, the authorization code flow with PKCE, `state` and `nonce`, and
  the ID token checked in full (signature against the provider's keys with
  RS256, PS256, ES256 or EdDSA only; issuer, audience, `azp`, expiry, issue
  time, nonce). Discovery and keys are cached for a day under `var/cache`.
- **Linking accounts:** *Link {name} account* in Settings → Account, or
  `OIDC_LINK=username` for providers whose usernames only admins set.
  Optionally `OIDC_AUTO_CREATE` creates a member on first sign-in, who sees
  a short welcome form. `OIDC_ALLOWED_GROUPS` and `OIDC_ADMIN_GROUPS` (the
  last admin is never demoted).
- **Header sign-in** (`AUTH_PROXY_*`) behind a forward-auth proxy: a plain
  username header such as `Remote-User`, trusted **only** from the
  connecting addresses in `AUTH_PROXY_TRUSTED` (never `X-Forwarded-For`).
  Alternatively, Authentik's signed `X-authentik-jwt`, checked with HS256
  and the proxy provider's client secret, which needs no trusted network.
  Linking by username (the default) or from a *Link your proxy account*
  banner while signed in. Optional creation of new users and admin from
  groups. The session follows the header: another user's header switches
  it, and a missing header ends a session the header started. A password
  session survives requests without one, so direct LAN access still works.
  *Sign out* goes on to `AUTH_PROXY_LOGOUT_URL`. Worked configurations for
  nginx `auth_request`, Traefik `forwardAuth`, Caddy `forward_auth` and the
  Authentik outpost, each exempting the API, the calendar feed and
  `/health` ([docker/nginx/forward-auth-example.conf](docker/nginx/forward-auth-example.conf)).
- **`AUTH_LOCAL_LOGIN=false`** leaves only single sign-on, with a
  break-glass link from the command line for when the provider is down:
  `php bin/auth.php login-link <username>` (10 minutes, one use).
- **Settings → Users** shows each user's sign-in methods (*Password*, the
  provider, *Proxy*), lets an admin remove a linked account, and shows the
  redirect URI to register and whether header sign-in is on.
- **Passwords for SSO users:** *Set a password* in Settings → Account.
- **API:** `GET /api/v1/journeys` lists the key user's saved journeys (for
  Shortcuts).

### Security
- A half-set configuration **stops the app at start** (web and command
  line) with a message naming the variable. Above all, a proxy header
  without `AUTH_PROXY_TRUSTED` stops it, rather than trusting everyone.
- Header sign-in is safe only when the app is reachable **only through
  the proxy** and the proxy **sets the header on every request**. Read the
  warning in [docs/sso.md](docs/sso.md#header-sign-in) before switching it
  on. A client's `Remote_User` (underscore) is never read as `Remote-User`:
  Logbook reads the server's `HTTP_*` variables, which Apache 2.4 (the
  Docker image) and nginx with php-fpm (by default) never fill from an
  underscore name. PHP's built-in development server does, so never put it
  behind a real proxy.
- With the JWT mode, `AUTH_PROXY_JWT_SECRET` can mint tokens, and a
  captured token works until it expires. Set `AUTH_PROXY_TRUSTED` as well
  when you can.
- Failed SSO sign-ins and refused proxy headers are logged with the client
  address (at most once per address per hour for headers), for fail2ban.

### Upgrade notes
- **One migration** adds `user_identities` and makes `users.password_hash`
  nullable (a user who signs in only with SSO has none). It rolls back
  cleanly on every engine, except that rollback is refused while any user
  has no password. The message names them; give each a password first.
  Rolling back also removes open break-glass links. Header sign-in needs no
  migration of its own.
- **Nothing changes until you configure it:** without `OIDC_ISSUER` or an
  `AUTH_PROXY_*` header, sign-in is exactly as before. First-run setup
  always creates a local admin with a password.
- **Backups move to a new schema version** (linked accounts are included).
  A 2.3.0 backup restores into 2.3.0. Restore an older backup with its own
  version, then upgrade.

## [2.2.0] — 2026-09-30

Phase 22: trips and business mileage claims. Log the business journeys you
may claim for, and Logbook works out your private mileage, the claim at the
approved rates, what your employer paid, and whether the allowance covers
what the car costs to run. Guide: [docs/trips.md](docs/trips.md).

### Added
- **Trips** (a new module, **off until you switch it on** in Settings →
  Modules or with `FEATURES_TRIPS=true`). Each vehicle gets a *Trips* tab
  after *Mileage*. A trip has:
  - a date, from, to, and *Return journey* (the distance one way is
    doubled);
  - a distance, or the odometer at the start and end, which must agree to
    within 0.5;
  - *Business trip* (ticked by default, and it needs a purpose),
    passengers, notes and files (a toll receipt).

  A trip adds no reading to the mileage log. A trip longer than the log says
  the car drove that day gets a warning after saving, never a refusal. In
  the UK and in German the form reminds you that commuting is not business
  mileage.
- **Saved journeys and *Log again*.** *Save as a journey* keeps the places
  and distance. Choosing a saved journey fills the form in place (or
  reloads it filled in without JS). *Log again* copies everything but the
  date and odometers, so a regular journey takes two taps. Manage them in
  **Settings → Trips**.
- **Business and private mileage.** Business is the sum of your business
  trips. Private is the rest of the mileage log's distance, so private
  journeys never need logging. Both show on the Trips and Mileage tabs for
  the tax year. When the readings are too far apart to tell, you see "—"
  and a note.
- **Mileage rates**, dated and yours. Each trip uses the set in effect on
  its date: a car rate with a threshold and the rate after it, bike and
  passenger rates, and optional employer rates. UK users are given HMRC's
  approved rates once: 45p, then 25p after 10,000 miles, from 6 April 2011,
  and 55p, then 25p, from 6 April 2026, with bikes at 24p and passengers at
  5p. The tax year starts on 6 April in the UK and 1 January elsewhere, and
  can be changed.
- **The claim report** (`/trips/claim`): your own business trips for a tax
  year or any dates, on all or some vehicles.
  - Each trip has its rate. The trip that crosses the threshold is split
    across both rates.
  - Totals show the distance at each rate, passengers and the total
    approved amount.
  - With employer rates it shows *Paid by employer* and the difference.
  - Beside each vehicle, what it costs to run per mile next to the claim
    value per business mile.
  - It prints with your name, the vehicles, the period, the rates and their
    source, your declaration and a signature line. It exports as
    `mileage-claim-2026-27.csv`.
- **Business mileage** in Reports (distance, business share, claim value,
  cost per business mile, the fleet total) and a dashboard widget (this tax
  year so far, and "6,418 mi until the £0.25 rate").
- **Log trip** in *Log entry* and in the phone app's quick actions. The
  trip form and your saved journeys also work offline, like a fill-up.
- **Trips in History** under their own *Trips* chip only.
- **CSV export and import of trips**, with duplicates skipped by date,
  places and distance.
- **API:**
  - `GET/POST /api/v1/vehicles/{id}/trips`: a POST is safe to retry, and
    logs a saved journey with `journey_id`.
  - `GET /api/v1/trips/claim`.
  - `/me` lists the `trips` module.

  See [docs/api.md](docs/api.md#trips).

### Privacy
- A trip belongs to its driver, who is the person claiming it. Drivers with
  *Log* or *View* see only their own trips and receipts. The owner and
  *Manage* see everyone's. A claim includes only your own trips.
- Trips are never listed under *Everything*, in *Recent activity*, in the
  printed history or in the sale pack and its ZIP.

### Upgrade notes
- **One migration** adds `trips`, `saved_journeys` and `mileage_rate_sets`.
  It rolls back cleanly on every engine. Rolling back also deletes trip
  attachments (the files stay under `UPLOAD_PATH`) and the trip settings.
- **Nothing changes until an admin switches trips on.** The module is off
  for everyone after upgrading, including installs that saved Settings →
  Modules before.
- **UK users get HMRC's rates to check and edit** the first time they open
  Settings → Trips, the claim or the Trips tab. They are ordinary rows: no
  release will change them. When HMRC announces new rates, add a set from
  the date they apply.
- **Backups move to a new schema version.** A 2.2.0 backup restores into
  2.2.0. Restore an older backup with its own version, then upgrade.

## [2.1.0] — 2026-09-30

Phases 20, 21.1 and 21.2: small things that make everyday use smoother, and
a reminder for the one MOT nobody has paperwork for yet.

### Added
- **First MOT due** (Phase 21.2). A new car's first MOT is the one reminder
  nobody has a certificate for. The vehicle form has an optional *First MOT
  due* date under *First registered*. It is suggested from the first
  registration date by the region of your language setting: 3 years for
  Great Britain and Germany, 4 years for France, Ireland, Italy and Spain.
  The hint mentions that Northern Ireland tests at 4 years, and the date is
  always yours to change. With JS the date fills in as you type *First
  registered*. Without JS, adding a vehicle with the field blank sets the
  suggestion and says so. A suggestion that has already passed is never
  offered. The date drives a **First MOT** reminder on your document lead
  time. It is sent, put in the digest and in the calendar feed like any
  other reminder, and shows in *Coming up*, on the overview, on the
  Documents tab and on the sale pack's *Inspection* line. Logging the first
  MOT certificate marks it done, and from then on the certificate's expiry
  drives MOT reminders, as before. After that the field shows as read-only
  text.
- **Drag and drop files** (Phase 21.1) onto every file input: attachments,
  the vehicle photo, purchase and sale paperwork, CSV import and backup
  restore. Dropped files are added to what you already chose, with a list
  to remove them from, the same type, size and count limits before
  submitting, and announcements for screen readers. Without JS it is the
  plain file input, as before.
- **Sale pack cover page** (Phase 21.1): *Include the vehicle photo* (off by
  default) prints a cover page with the photo before the summary. The photo
  is never in the paperwork ZIP.
- A hint on purchase paperwork to keep the registration certificate (V5C) as
  a *Registration* document instead.
- API: `first_inspection_due_on` on the vehicle, and `first_inspection` as a
  source of *Coming up* items (`GET /upcoming`, the summary's
  `next_due`) and of reminders. A client that switches on `source` should
  expect the new value.

### Changed
- **Tyre forms open in a modal on desktop** (Phase 21.1): editing a tyre, a
  tyre change and a set, and their delete confirmations, like every other
  entry form. Each is still its own page for phones, deep links and JS off.
- **The monthly digest is on for new users** (Phase 21.1): accounts created
  by first-run setup or an invitation get it on. It is still only sent when
  a channel is set up and something is due.
- **Phase files moved to `docs/phases/`** (Phase 20), beside a log of every
  phase's open questions and what was decided
  ([docs/phases/open-questions.md](docs/phases/open-questions.md)). The test
  suite now checks that every link in the repository's Markdown resolves.
  No change to the app.

### Upgrade notes
- **One migration** adds the empty `vehicles.first_inspection_due_on`
  column. It rolls back cleanly on every engine. Rolling back also deletes
  the *First MOT* reminders and the prompt settings.
- **Existing users keep their digest choice.** Nobody who upgraded starts
  getting a monthly email they didn't turn on.
- **Existing vehicles get no date.** Nothing is guessed. Instead, each
  vehicle with *First registered* set, no MOT certificate and a suggestion
  still to come shows a one-time card on its overview: "Set a reminder for
  the first MOT? Suggested: 14 Jun 2027", with *Set it* and *Not needed*.
  Either answer, or saving the vehicle's edit form, puts it away for good.

## [2.0.0] — 2026-09-30

Phase 19: multiple users and vehicle sharing. A household on one install:
everyone has their own account, vehicles, units, language and reminders,
and a vehicle can be shared at a level its owner chooses. Your partner can
log fill-ups on the Mini without seeing its running costs or being able to
delete its service history. Guide: [docs/users-and-sharing.md](docs/users-and-sharing.md).

**Read the upgrade notes below before upgrading.** Nothing you use today
changes, but backups, notifications and what an install can hold do, so
this is a major version.

### Added
- **Users.** Admins and members. **Settings → Users** (admins) lists
  everyone with their role, last sign-in and status, and can *invite*
  someone (a one-time link shown once, valid for 7 days, which can also be
  emailed to an address that is not kept), *make* or *remove* an admin,
  *disable* (signed out everywhere, keys and calendar feed stop at once) and
  *enable*, *reset a password* (a one-time link), *revoke* open links, and
  *delete* (refused while they own vehicles, which an admin can transfer
  from the same page). There is always an active admin. Opening an
  invitation asks for a password, units, currency, language and time zone
  and signs the new user in.
- **Sharing.** On a vehicle, **Sharing** adds people by username at
  **View**, **Log** (also add entries and change their own) or **Manage**
  (also edit the vehicle and every entry, schedules, valuations, import,
  export, the sale pack), with **Can see costs** (always on for Manage) and
  **Send them its reminders**. The owner can also **transfer** the vehicle,
  keeping Manage access unless they untick it; anyone with a share can
  **leave**. Shared vehicles appear under **Shared with you** in the garage,
  naming the owner and your level, and join your dashboard, History,
  Reports and *Coming up*.
- **Who added what:** every fill-up, reading, service record, document,
  expense, tyre change, valuation and file records who added it; once a
  vehicle is shared, lists and history say **Added by …** (a deleted user's
  entries show as *a former user*).
- **Your own amounts:** someone who may not see a vehicle's costs still sees
  the amounts of the entries they added, on the pages and in the API, and
  nothing else. Fleet figures say how many vehicles they leave out
  ("Excludes 1 vehicle shared without costs").
- **Reminders per person:** a vehicle's reminders go to its owner and to
  those it is shared with who asked for them, each in their own language,
  units and time zone, once per status. Settings → Reminders takes your own
  **ntfy topic URL** and **Gotify token** as well as your email address.
  The webhook's payload names the `user`.
- **`php bin/export-user.php <username>`**: a backup-format ZIP of one
  user's vehicles and settings, which restores as an install of their own.
- Sample data (`bin/dev-setup.sh --with-sample-data`) has a second user,
  `partner` / `logbook-demo`, with Log access to the self-charging hybrid
  (without costs, logging its fill-ups) and View access to the Golf.

### Changed
- Entry edit and delete pages are open to Log access for one's own entries;
  others' entries show without edit links. CSV export and import need
  Manage. The Expenses tab is open to anyone who can see the vehicle, with
  the amounts hidden from those who may not see its costs.
- Buttons and links on a vehicle's pages show only what you may do.
- A shared vehicle's reminders are always judged by its owner's lead times
  and time zone, and its money stays in the owner's currency.
- The calendar feed and the monthly digest cover your own vehicles and those
  you asked to be reminded about.
- `MAIL_TO`, and the instance's ntfy topic and Gotify token, are the
  **admins'** defaults only; members receive email, ntfy and Gotify at their
  own address, topic or token.

### Upgrade notes
- **Take a backup first** (Settings → Backup, or `php bin/backup.php
  create`).
- **One migration**, applied on start in Docker or with
  `vendor/bin/phinx migrate -e production` on bare PHP. It adds the admin
  and disabled flags, the shares, invitation links, reminder deliveries and
  who added each entry.
- **Your existing account becomes an admin** and is named as the author of
  everything already there. What 1.x already notified is recorded as sent,
  so nothing is sent again. Nothing else changes until you invite someone.
- **`MAIL_TO` is now the admins' default only.** With one account (an
  admin) nothing changes. Members you invite need their own address (or
  ntfy topic, or Gotify token) in Settings → Reminders.
- **Rolling back is refused once a second user exists**, with a message
  naming `php bin/export-user.php`: export and delete the others first.
  With one user it rolls back cleanly.
- **2.0.0 backups do not restore into 1.x** (the schema version moves).
  Invitation links are not in backups.
- `SESSION_SECRET` also keys invitation links: changing it disables open
  ones.
- **Custom notification channels** need one new method,
  `reaches(Recipient $recipient): bool` (can it deliver to this person?);
  see [docs/notification-channels.md](docs/notification-channels.md).

## [1.10.0] — 2026-09-30

Phases 18.1 and 18.2: one access policy, and a REST API. Home Assistant,
Apple Shortcuts, Android automations, Grafana, Node-RED and OBD tools can
read your garage and log fill-ups and odometer readings with an API key.

### Added
- **REST API** under `/api/v1` ([docs/api.md](docs/api.md)), described by
  an OpenAPI 3.1 file your install serves at `/api/v1/openapi.json`.
  - **Read:** vehicles, a per-vehicle summary for sensors (odometer, economy,
    last fill-up, 12-month cost per mile or km, what is due next, reminder
    counts, documents, tyres, and the same figures as text in your units),
    fill-ups with the economy of each tank and its economy-check flag,
    odometer readings, service records, documents, expenses, tyres, *Coming
    up* and reminders. Lists are newest first, paged, and filtered by date.
  - **Write:** log a fill-up (any two of volume, price and total, in litres
    or UK or US gallons, miles or km) and add an odometer reading, checked
    exactly as the forms check them, with the plausibility and economy
    warnings returned. Retrying the same fill-up (same time and odometer)
    returns the existing one instead of doubling it.
  - Values are canonical (km, litres, L/100 km, the vehicle's currency) as
    exact decimal strings, instants in UTC, errors as problem details with a
    stable code, and amounts left out for anyone who may not see costs.
- **API keys:** Settings → API keys creates a named key that can read, or
  read and log; it is shown once, only its hash is stored, it can be
  revoked at once, and the list shows when each was last used. A key sees
  exactly what its user sees. `bin/api-key.php` creates, lists and revokes
  keys on the command line. 20 failed keys from one address in 10 minutes
  block it for 10 minutes.
- Guides for a Home Assistant REST sensor, an Apple Shortcut that logs a
  fill-up, a Grafana economy chart and a Node-RED flow.
- `API_ENABLED` (default `true`) and `API_CORS_ORIGINS` (default none).

### Changed
- **Access policy:** who may do what with a vehicle (view, see costs, log,
  manage, own) and with the install (modules, backup, restore) is decided in
  one place. Every `/vehicles/{id}` and `/reminders/{id}` route declares what
  it needs; a vehicle you cannot see answers 404, one you can see but not
  change this way a 403 page that says so. The garage, dashboard, History,
  *Coming up*, Reports, reminders, the calendar feed and the scheduler all
  take their vehicles from it.
- Every amount shown for a vehicle sits behind a cost-visibility check, and
  Reports, the Ownership report and *Coming up* count only vehicles whose
  costs can be seen, so a later policy can hide costs without touching the
  pages again.
- A test fails the build for any route that does not say what access it
  needs, and another for any amount a template shows outside a cost check.
- The CSV import's duplicate rule is shared with the API's safe retries.
- The restore page says when a backup holds API keys, which keep working
  only with the same `SESSION_SECRET`.

### Upgrade notes
- **One migration** (the `api_keys` table), applied on start in Docker or
  with `vendor/bin/phinx migrate -e production` on bare PHP. It rolls back
  cleanly.
- **New optional variables:** `API_ENABLED` (the API is on by default; every
  call needs a key, so nothing is reachable until you create one; set
  `false` to switch it off entirely) and `API_CORS_ORIGINS`.
- **`SESSION_SECRET`** now also keys the API key hashes: changing it (or
  restoring a backup into an install with another one) disables every API
  key, as it already signed everyone out and disabled calendar feed links.
- Apache with **PHP-FPM**: the `Authorization` header must reach PHP.
  `public/.htaccess` hands it over; a hand-written vhost without it needs
  `CGIPassAuth On` ([docs/deployment.md](docs/deployment.md#the-rest-api-behind-a-proxy)).
  The Docker image needs nothing.

## [1.9.0] — 2026-09-29

Phases 17.1 and 17.2: the sale pack, and printable reports. A buyer's view
of the car with its paperwork, and a clean paper or PDF copy of every
report, without a PDF library.

### Added
- **Printable reports:** a *Print* button on Reports (fleet or one
  vehicle), the Ownership report, *Coming up* (fleet or one vehicle), the
  Fuel tab and the Mileage tab; the browser's *Save as PDF* makes the PDF.
  Each printout starts with a header saying what it is, for which vehicle
  (name and registration) or all vehicles, the period (the report's range,
  *Coming up*'s months, or the first to the latest record), your units and
  the date printed. The filters, chips, tabs and buttons stay off the
  paper; their choices are in the header.
- **Charts on paper** are drawn again in black and grey, their series told
  apart by solid, dashed and dotted lines, point shapes, and solid, striped,
  dotted and cross-hatched bars, so none depends on colour. Each is sized to
  the page and printed with its table: the economy, price and mileage
  charts gain one on paper, listing every point.
- Printouts are black on white in the dark theme and with any accent;
  cards and table rows are not split across pages, table headers repeat
  on each page and totals print once at the end; wide tables print
  smaller instead of being cut off. On the Fuel tab both the economy and
  the cost per mile or km trends print, and economy check flags print as
  their words.
- The sale pack's mileage chart now fills the page width when printed, at
  the same proportions as the report charts, instead of keeping its screen
  width and printing squashed.
- **Prepare for sale** on every vehicle (and *Sale pack* in the History
  toolbar): a buyer's view to print or save as a PDF. A summary page (what
  the car is, how long you have owned it and how far it has gone since, the
  current mileage, servicing, when the MOT runs out, the tyres, what is due
  next, how much paperwork there is); a mileage record of readings a buyer
  can check (services, inspections, tyre changes and dashboard photos,
  never fill-ups) with where each came from and a chart; and the history
  grouped as service and repairs, inspections and certificates, and tyres,
  with the full timeline as an option. UK owners get a pointer to
  gov.uk/check-mot-history; nothing is fetched.
- **The paperwork as a ZIP:** service invoices, MOT certificates and
  dashboard photos by default, purchase paperwork and insurance if you tick
  them, each file readably named ("2024-03-12 Service - Kwik Fit.pdf") with
  a `contents.txt`. *Choose files* drops single files, with or without
  JavaScript. Registration documents, sale paperwork, valuations, fill-ups
  and expenses are never offered. The ZIP is streamed from your uploads with
  no temporary copy and needs no PHP extension.
- A notice (never printed) when a reading in the mileage record looks
  wrong, with a link to the entry.
- Purchase and sale prices, fuel, expenses, valuations and ownership costs
  are never in the pack; *Show the cost of work* adds the cost of work only.
- The demo Golf has a service history back to its purchase, invoices,
  both MOT certificates and a dashboard photo from the day it was bought.
  Its brake pads record no longer reads below the fill-up before it.
- [docs/sale-pack.md](docs/sale-pack.md).

### Upgrading
- Pull and restart. No migrations, no new configuration, and the backup
  format is unchanged.

## [1.8.0] — 2026-09-29

Phase 16: fuel insights. Is the dearer fuel worth it, what does a mile
really cost, and how much does winter take?

### Added
- **Grade verdict** on the *By grade* card: for each petrol or diesel grade
  with enough data, how much more or less it costs per mile or km than the
  grade you use most (by volume over the last 12 months), for example
  "E5 98 costs about 10% more per mile than E10 95 — 7% more per litre,
  3% more fuel used". The price difference comes only from fill-ups of the
  two grades bought within 30 days of each other (the median of those
  pairs, at least three), never from all-time averages, which mostly
  measure fuel prices changing. The economy part is the existing
  *Economy by grade*. The card says what the verdict rests on, and says
  exactly what is missing when there is not enough data.
- **Cost per mile or km for each charging type** in the charging card:
  each type's cost per kWh × the car's average consumption.
- **Cost per distance trend:** the *Economy trend* card switches between
  *Economy* and *Cost per mile/km* (the fuel used in each tank, costed at
  the price of that fuel, plus a running average). The switch is plain
  links (`?trend=cost`) that work without JavaScript, survive a refresh
  and respect `APP_BASE_PATH`; without JavaScript the points are a table.
- **Economy by month:** a table of months by year (the last five) with an
  average weighted across every year, and a chart of the average with this
  year and last as lines. Each full-to-full stretch is shared between the
  months it spans by time, in your time zone; months with under 200 km of
  driving show "—", and stretches over 92 days are left out. A plug-in
  hybrid gets one card for fuel and one for charging.
- The demo data's Golf now alternates E10 and E5 97 and uses a little more
  fuel in winter, so it shows a verdict and a seasonal dip.

### Changed
- Nothing that was already shown changes value: average economy, the
  headline fuel cost per distance, the economy trend, economy checks,
  reports and *Coming up* are all as before.

### Upgrade notes
- No migrations and no configuration changes. Nothing new is stored and the
  backup format is unchanged; a 1.7.0 backup restores into 1.8.0 (and the
  other way round).

## [1.7.0] — 2026-09-29

Phase 15: *Coming up*, the next 12 months of maintenance, renewals and
fuel.

### Added
- **Coming up** (`/upcoming`): everything the app already knows is due in
  the next 12 months (this month and the 11 after), per vehicle and for the
  fleet. Services from your maintenance schedules, repeated as often as they
  fall due in the year (a 6-monthly service shows twice, whichever of the
  months or the mileage comes first each time); document renewals, repeated
  at the document's own term; tyres wearing out or reaching your age limit;
  and your own reminders. Overdue work is listed first, once; a service due
  at a mileage that cannot be placed on the calendar yet is listed under
  *Date not known yet*.
- Each item shows what it cost **last time**, from your own records: the
  service record that last completed the schedule, the current document's
  price, or the tyres' share of the service record that fitted them. When
  there is no such record the cost is shown as unknown and counted, never
  guessed.
- A **fuel estimate** per vehicle and month: your average daily distance
  times the last 12 months' fuel cost per mile or km (it needs 90 days of
  fill-ups). A plug-in hybrid's petrol and electricity are covered together.
- Month-by-month and 12-month totals per currency (never converted):
  planned, fuel and the two together, "at least" while some item's cost is
  unknown. A stacked bar chart (a table without JavaScript), the dashboard's
  vehicle chips and a CSV export.
- A *Coming up* card on each vehicle's overview and a *Coming up* dashboard
  widget, each with the next five items and the 12-month total.
- It reads the same due dates your reminders use, but not the reminders
  themselves: it works with the Reminders module off, a dismissed reminder's
  service still appears, and viewing it never changes a reminder or sends a
  notification. A switched-off module's items leave it.

### Upgrade notes
- No migrations and no configuration changes. Nothing new is stored and the
  backup format is unchanged; the database schema is the same as 1.6.0's,
  so a backup restores into either version.
- The new dashboard widget is added to saved layouts at the end; move or
  hide it under *Customise*. Without a saved layout it comes second, after
  *Upcoming reminders*.

## [1.6.0] — 2026-09-29

Phases 14.1 and 14.2: valuations, depreciation and the total cost of
ownership.

### Added
- **Cost of ownership** (Phase 14.2): what each vehicle has really cost
  since you bought it, on a new overview card beside *Ownership*. Running
  costs (every ledger line since the purchase date, by group) plus
  depreciation, as a total and per mile or km and per month. Each part is
  worked out over its own period and the two are added: running costs to
  today, depreciation to the date of the latest value, and the total says
  so ("depreciation to 1 Mar 2026"). A sold vehicle's figures run to the
  sale date and are exact ("Lifetime, sold 12 Mar 2026").
- Nothing is shown as complete when a part is missing. Without a purchase
  price or a value the card is titled *Running costs since …* and has no
  total. Rates that lack their depreciation part are marked "running costs
  only". Per distance needs the mileage log to reach back to the start of
  ownership, and the card says when it doesn't. There are no rates for the
  first 90 days or before any cost is logged.
- **Reports → Cost of ownership** (`/reports/ownership`): every vehicle
  side by side, grouped by currency (never converted), with a fleet row,
  the reports' vehicle and *include archived* filters, and a CSV export.
  Part of the Reports module; the overview card stays when it is off.
- **Finance and lease**, a new expense category for loan interest, lease
  and PCP payments. The form reminds you not to log the payments that pay
  off a purchase price you have already entered. CSV import accepts it by
  code or label.
- Demo data: the EV is leased (monthly payments, no purchase price) and the
  sold Fiesta has nine years of services, road tax and mileage, so it shows
  exact lifetime figures.
- **Valuations.** A small log of what each vehicle is worth (a dealer's
  part-exchange offer, an online valuation, an insurer's figure) at
  *Valuations* on the overview's *Ownership* card, with a screenshot or PDF
  attached. Nothing is fetched from an online service: a value is always
  one someone quoted.
- **Depreciation** on the overview's *Ownership* card: what the vehicle has
  lost (or gained) from the purchase price to the latest valuation, or to
  the sale price once sold, as an amount and a percentage, per year and per
  mile or km. Both rates are measured to the value's own date, not today, and
  per distance appears only when the mileage log reaches back to the
  purchase. Nothing is extrapolated; a valuation more than a year old says
  so. With two or more values, a small value-over-time chart (a table
  without JavaScript).
- Valuations appear in History (under *Everything*) and *Recent activity*
  as "Valued at £9,800", never in the amount column, and **never in the
  print view**: a service history handed to a buyer does not carry your own
  valuations. They are not costs and stay out of expenses and reports.
- Valuations export to CSV (Date, Amount, Currency, Source, Notes) and are
  included in backups with their files.

### Changed
- The overview's *Currency* and *Added* rows moved from *Ownership* to
  *Details*. The *Ownership* card now leaves out rows that are not set and
  is hidden until the vehicle has a purchase, a sale or a valuation.

### Unchanged, on purpose
- Every existing figure: costs, reports, economy and mileage are as they
  were. The dashboard's *Running cost* tile (last 12 months) and cost of
  ownership (since bought) answer different questions, and both stay.
- Depreciation per distance uses the same "mileage reaches back to the
  purchase" rule as before, now shared with cost of ownership.

### Upgrade notes
- One migration: a new table `vehicle_valuations`, whose files use a new
  attachment owner type, `valuation`. It runs automatically on start (Docker) or
  with `vendor/bin/phinx migrate` (bare PHP). Nothing in existing data
  changes.
- The *Finance and lease* expense category is a new code (`finance`) in an
  existing column: no migration.
- No configuration changes.
- Backups record the database schema, so a backup made with 1.5.x cannot be
  restored into this version (restore it with its own version first, then
  upgrade), and a backup from this version cannot be restored into 1.5.x.
- **Going back to 1.5.0:** roll back first, while still on this version,
  with `vendor/bin/phinx rollback -e production -t 20261009100000`. The
  valuations and their attachment rows are removed (the files stay under
  `UPLOAD_PATH`); everything else is unchanged.

## [1.5.0] — 2026-09-29

Phase 13: economy checks.

### Added
- **Economy checks.** Each full-to-full tank (or charge) of at least 100 km
  is compared with the median of the vehicle's previous ten, from its sixth
  on, in fuel used per distance, so mpg UK, mpg US, L/100 km and km/L all
  agree. A tank that used at least 25% more or 20% less than usual (35% more
  or 26% less for charging, which swings more with the seasons) is flagged
  with the likely cause and links to the fill-ups to check. Most flags are
  typing mistakes, so the hints send you to the data first.
- **A mistyped reading shows as a pair.** One odometer typed too high makes
  one tank look thrifty and the next thirsty; both flags name the fill-up
  they share and say when the two tanks are normal taken together.
- **Looks right** confirms a genuine one (a winter trip with a roof box) and
  keeps it quiet until that tank's figures change; *Undo* brings the flag
  back. A plain form: works without JavaScript.
- Where flags show: beside the economy on the Fuel tab, with "N fill-ups to
  check" in the summary and a *To check* list (`?check=1`); in the notice
  after saving a fill-up; above the fill-up's edit form; beside the economy
  on the dashboard's *Recent fuel*; and as a count after a CSV import of
  fill-ups ("3 imported fill-ups look unusual").
- The demo Golf has a mistyped odometer and a confirmed thirsty January tank.
- German translations for all of the above.

### Unchanged, on purpose
- Every average, trend, cost per distance and report figure is exactly as
  before: flagged tanks still count until you correct them. No notification
  or reminder is ever sent for a flag. Flags never appear in History, the
  print view, reports or the garage.

### Upgrade notes
- One migration: a nullable column `fuel_entries.economy_confirmed`, empty
  for every existing fill-up. It runs automatically on start (Docker) or
  with `vendor/bin/phinx migrate` (bare PHP). Every existing figure is
  unchanged.
- No configuration changes.
- Backups record the database schema, so a backup made with 1.4.x cannot
  be restored into this version (restore it with its own version first,
  then upgrade), and a 1.5.0 backup cannot be restored into 1.4.x.
- **Going back to 1.4.0:** roll back first, while still on this version,
  with `vendor/bin/phinx rollback -e production -t 20261008100000`. Only the
  *Looks right* confirmations are lost.

## [1.4.0] — 2026-09-29

Phase 12: buyer-first print, ownership paperwork and a dated starting
mileage.

### Added
- **Purchase and sale paperwork.** The vehicle form (add and edit, page and
  modal) takes files under the purchase fields and under the sale fields:
  the purchase invoice, the sale receipt, a V5C slip. They show as a
  paperclip on the *Bought* and *Sold* rows in History and the fleet
  history, beside the dates on the overview's *Ownership* card, and as file
  names under those rows in the print view. Up to 10 files per save for
  both together, all or nothing, as on every other form. The files need
  their date: adding sale paperwork without a sale date is refused, and so
  is clearing a date while files are attached. Archiving keeps them;
  deleting the vehicle deletes them.
- **As of** beside *Current odometer* when adding a vehicle, defaulting to
  today: the date the figure was read (on the MOT certificate, at the
  sale). An earlier date writes the reading at local noon on that day, so it
  sits in order with fill-ups logged before and after it. A date before the
  first registration is saved with a warning.
- German translations for all of the above.

### Changed
- **The print view hides costs by default.** *Show costs* starts unticked,
  so the printout is the copy you can hand to a buyer; purchase and sale
  prices are hidden with the costs. Tick it for your own copy. An old link
  never shows costs it used to hide.
- **Average per year since first registered** is now measured to the date
  of the latest reading, not to today, so a starting reading dated months
  back, or a vehicle that has not been driven for a while, is no longer
  understated. The figure changes for vehicles whose latest reading is not
  recent. *Age* is still measured to today.
- The overview no longer lists the latest fill-ups: *Recent history* lists
  them with everything else, the Fuel tab lists them all, and the economy,
  cost per distance and spend figures stay at the top of the overview. Its
  *Add fill-up* and *Add reading* buttons went with the card; *Log entry*
  covers both.
- The demo data's sold Fiesta has a sale receipt.

### Upgrade notes
- One migration, which changes no column: attachments gain the owner types
  `purchase` and `sale` (the column already takes any short code). It
  exists to move the schema version, because 1.3.x cannot read those owner
  types. It runs automatically on start (Docker) or with
  `vendor/bin/phinx migrate` (bare PHP). Nothing in existing data changes.
- No configuration changes.
- Backups record the database schema, so a backup made with 1.3.x cannot
  be restored into this version (restore it with its own version first,
  then upgrade), and a 1.4.0 backup cannot be restored into 1.3.x.
- **Going back to 1.3.0:** roll back first, while still on this version,
  with `vendor/bin/phinx rollback -e production -t 20261007100000`. The
  purchase and sale files are unlinked (their files stay under
  `UPLOAD_PATH`); everything else is unchanged.

## [1.3.0] — 2026-09-29

Phases 11.1 and 11.2: tyres, then tread depth, wear and tyre reminders.

### Added
- **Tyres tab** on every vehicle, after Maintenance: the tyres on the
  vehicle (a card per position: brand, model, size, season, age and
  distance), those in storage grouped by set with where the set is kept,
  retired tyres with how far they went, and every tyre change.
- **Tyre changes:** *Tyres already on the vehicle* (to start with), *Fit
  tyres* (one description for a pair, a DOT code per tyre, and what happens
  to the ones they replace), *Swap set* (summers off into a set, winters
  on), *Rotate*, *Repair* and *Remove* (into storage or retired, with a
  reason). Cars have front left / right, rear left / right and a spare;
  motorbikes front and rear.
- **Age from the DOT code** (`2323` = week 23 of 2023) and **distance per
  tyre** from the mileage log, leaving out time as a spare or in storage.
  A retired tyre shows its lifetime distance and, when its fitting was
  costed, its cost per distance ("£4.90 per 1,000 mi").
- **Costs stay in maintenance:** a cost typed on a tyre form writes a
  `tyres` service record, or a change can be linked to one already logged.
  It is counted once, under maintenance, everywhere.
- Tyre changes in **History** (a *Tyres* chip), the **print view** (with a
  *Tyres fitted* header block) and *Recent activity*; a *Tyres* card on the
  overview; *Tyre change* in *Log entry*; a new mileage source *Tyres*.
- CSV export of tyres and of tyre changes; German translations.
- **Tread depth** in millimetres or 32nds of an inch (a new *Tread depth*
  unit beside the others in Settings; the US preset picks 32nds). Recorded
  when tyres are fitted (*Tread depth when new*), already on the vehicle,
  swapped or removed, and with a new **Check tread**: one depth per fitted
  tyre, from the Tyres tab or *Log entry*. A reading more than 0.5 mm
  deeper than the last is saved with a notice to check it.
- **Wear estimate** for every fitted tyre, from its own distance (time in
  storage or as the spare leaves it out): depth now, distance left to the
  replace-at depth and roughly when, always labelled as an estimate ("about
  3.4 mm now · about 6,000 mi left · around Mar 2027"). The overview's
  *Tyres* card shows each tyre's depth and the soonest distance left.
- **Settings → Tyres:** replace-at (and a winter replace-at for cars),
  legal minimum and an age limit from the DOT date (6 years by default;
  0 turns it off), for cars and motorbikes. Flags say *Below the legal
  minimum* when a measured depth is, and *May be below the legal minimum —
  check it* when only the estimate is.
- **One tyre reminder per vehicle** for worn or ageing tyres ("Tyres: front
  left and front right worn", "Tyres: rear due in about 800 mi"), due
  within the service lead time and distance, through every channel, the
  digest and the calendar feed. Daily driving updates it quietly; a new
  check, fit or swap opens it again. The Tyres tab shows the same verdict
  as a badge, reminders module or not.
- Depths in tyre history ("Checked tread: 5.1–6.3 mm"), the print view's
  *Tyres fitted* block (measured depths only: estimates are never printed)
  and both tyre CSV exports.

### Upgrade notes
- **Tyres (11.1):** four new tables (`tyre_sets`, `tyres`, `tyre_changes`,
  `tyre_change_lines`), a new nullable column
  `odometer_readings.tyre_change_id` and a new reading source `tyre`.
- **Tread depth (11.2):** a new column `users.depth_unit` and a nullable
  `tyre_change_lines.tread_mm`. Owners whose fuel volume unit is US gallons
  are set to 32nds of an inch; everyone else to millimetres. New tyre
  change kind `check` and reminder source `tyre`.
- The migrations run automatically on start (Docker) or with
  `vendor/bin/phinx migrate` (bare PHP). Nothing in existing data changes,
  and every existing figure is unchanged.
- New optional `FEATURES_TYRES` (default on), like the other modules; see
  `.env.example` and `docs/configuration.md`.
- Backups record the database schema, so a backup made with 1.2.x cannot
  be restored into this version: restore it with its own version first,
  then upgrade. (Every release that adds a column moves the schema
  version.)
- **Going back to 1.2.1:** roll back first, while still on this version,
  with `vendor/bin/phinx rollback -e production -t 20261005100000`. Tread
  checks' readings and every tyre reading become ordinary manual readings,
  so no mileage is lost; tread checks, tyre reminders, the tyre thresholds
  and the tyre tables are removed.

## [1.2.1] — 2026-09-29

Phase 10.2: tall vehicle photos.

### Fixed
- A tall (portrait) vehicle photo no longer stretches the dashboard's pinned
  vehicle card on wide screens. The card is as tall as its content and the
  photo is cropped to fit, as a landscape photo already was.

### Upgrade notes
- None: no migrations, no configuration changes and no change to the backup
  format. Stored photos are untouched.

## [1.2.0] — 2026-09-28

Phase 10: vehicle history, multiple attachments and the document odometer.

### Added
- **History tab** on every vehicle, second after Overview: fill-ups,
  service records, documents, expenses and odometer readings in one list,
  newest first, one year per page with month headings and *Newer* /
  *Older* links. The vehicle's own milestones — *First registered*,
  *Bought* and *Sold* — bookend it. Back-to-back fill-ups fold into one row
  ("4 fill-ups · 2 Sep – 17 Sep · £284.10") that opens to show them. Chips
  narrow it to *Service*, *Fuel*, *Documents*, *Expenses* or *Mileage*.
  Every row opens its entry, and saving brings you back to the same page.
- **Fleet history** (`/history`), reached from *View all* on the
  dashboard's *Recent activity*: the same list across every active vehicle,
  with the dashboard's vehicle chips.
- **Print view** of a vehicle's history, for printing or *Save as PDF*: a
  service history to hand to a buyer, with the vehicle's details, every
  entry and the names of its files. Choose what to include (everything but
  fuel by default) and whether to show costs. It prints black on white in
  either theme.
- *Recent history* on the vehicle overview (the latest five, *Full
  history →*).
- **Several files at once** on every attachment input (up to 10 per save,
  each up to `MAX_UPLOAD_MB`). One bad file saves nothing and the message
  names it.
- **Attachments on expenses and manual odometer readings** (a parking
  receipt, a penalty notice, a photo of the dashboard).
- A **paperclip with the number of files** on the Fuel, Maintenance,
  Mileage and Expenses lists, *Recent activity* and History.
- **Odometer on documents**: the reading an MOT certificate shows. It joins
  the mileage log (source *Document*, at noon on the document's start date,
  which it needs), moves and goes with the document, and is in the
  documents CSV export and import.
- German translations for everything new (*Verlauf*, *Fahrzeughistorie*,
  *Gekauft*, *Verkauft*, *# Tankvorgänge*, *# Ladevorgänge*, …).

### Changed
- *Recent activity* reads the same feed as History: the same entries in
  the same order, plus paperclips; an EV charge is named *Charge*.
- Paperclip counts read "2 files" rather than "2 attachments".

### Upgrade notes
- Two new nullable columns, `compliance_documents.odometer_km` and
  `odometer_readings.compliance_document_id`, and a new reading source
  `document`. The migration runs automatically on start (Docker) or with
  `vendor/bin/phinx migrate` (bare PHP). Existing data and every existing
  figure are unchanged.
- **Bare PHP:** a save now sends up to 10 files. Check `max_file_uploads`
  (at least 10; PHP's default is 20) and that `post_max_size` allows
  10 × `MAX_UPLOAD_MB` (100 MB by default). The Docker image sets both.
- Backups record the database schema, so a backup made with 1.1.0 cannot be
  restored into this version: restore it with 1.1.0 first, then upgrade.
- **Going back to 1.1.0:** roll back first, while still on 1.2.0, with
  `vendor/bin/phinx rollback -e production -t 20261004100000`. Document
  readings become ordinary manual readings, so no mileage is lost. Files
  attached to expenses and readings are unlinked (1.1.0 cannot show them;
  the files stay under `UPLOAD_PATH`): download any you need first.
- No configuration changes.

## [1.1.0] — 2026-09-28

Phase 9.1 (vehicle details) and Phase 9.2 (plug-in hybrids).

### Added — Phase 9.1: vehicle details
- **Variant / trim** on each vehicle ("1.5 EcoBoost ST-Line X", "xDrive30d M
  Sport"): free text, shown after year, make and model on the garage cards,
  the vehicle header, the dashboard's vehicle tiles and pinned vehicle card,
  the delete page and the vehicle pickers. Long variants are cut short with
  "…" where space is tight; hover or long-press shows the whole line.
- **First registered**: the date on the registration document (V5C /
  logbook). The vehicle's overview shows it with the vehicle's age
  ("7 yrs 6 mo"), and the Mileage tab adds the *average per year since
  first registered*. A model year more than a year after the registration
  year is saved with a warning to check both.
- **Current odometer** when adding a vehicle: fill it in and the vehicle
  starts with a mileage reading, so the garage card, the dashboard and the
  Mileage tab have a figure straight away instead of "—" until the first
  fill-up. It is an ordinary reading on the Mileage tab, edited there if it
  was wrong. The edit form shows the current reading with an *Add reading*
  link.
- German translations for everything new (*Variante / Ausstattung*,
  *Erstzulassung*, *Aktueller Kilometerstand*, …).

### Added — Phase 9.2: plug-in hybrids
- **Hybrid** and **Plug-in hybrid** are now two fuel types. *Hybrid* is a
  self-charging or mild hybrid that fills with petrol only; *Plug-in hybrid*
  fills with petrol and charges from a plug. The vehicle form explains both
  under the fuel type, and the garage cards, vehicle header and dashboard
  show *Plug-in hybrid* where it applies.
- A hybrid's fill-up form now leads with petrol only. Charging is still
  there under *Other fuels*, and *Used on this vehicle* still lists a
  charging type the vehicle has used. A plug-in hybrid's form is what every
  hybrid's was: petrol and electricity, each remembering its own last grade.
- The capacity field reads *Tank capacity*, or *Battery capacity* for an
  electric vehicle (it was *Tank or battery capacity*).
- The sample data (`bin/dev-setup.sh --with-sample-data`) adds a plug-in
  hybrid with half a year of home charges and petrol fills beside the
  self-charging Corolla: six vehicles in all.
- German: *Hybrid (Voll- oder Mildhybrid)*, *Plug-in-Hybrid*, *Tankinhalt*,
  *Akkukapazität*.

### Changed
- Figures are unchanged: fill-ups still record petrol or electricity, never
  the kind of hybrid, so economy, cost, grades and CSV import and export
  work as before.

### Upgrade notes
- **Check your hybrids.** On upgrade, every *Hybrid* with at least one
  electricity fill-up (charge) logged in Logbook, archived vehicles
  included, becomes a *Plug-in hybrid*. Every other hybrid stays a *Hybrid*.
  The upgrade decides from what you logged, so a plug-in hybrid you never
  logged a charge for stays a *Hybrid*: change it on its edit page. The
  migration is reversible; rolling back turns every plug-in hybrid back into
  a hybrid.
- New nullable columns `vehicles.variant` and `vehicles.first_registered_on`
  (9.1). No other schema change. Both migrations run automatically on start
  (Docker) or with `vendor/bin/phinx migrate` (bare PHP). Existing vehicles
  are otherwise unchanged and every existing figure stays the same.
- Backups record the database schema, so a backup made with 1.0.0 cannot be
  restored into this version. Restore it with 1.0.0 first, then upgrade; the
  upgrade then sorts its hybrids as above.
- **Going back to 1.0.0** needs the rollback first, while still on 1.1.0:
  1.0.0 does not know plug-in hybrids and fails on any page that lists one.
  Run `vendor/bin/phinx rollback -e production -t 20261002100000` (Docker:
  `docker compose exec app vendor/bin/phinx rollback -e production -t
  20261002100000`), then switch to the 1.0.0 image or code.
- No configuration changes.

## [1.0.0] — 2026-09-28

Phase 8: fuel grades, and the first stable release. Everything in the
roadmap's core phases is done.

### Added — Phase 8: fuel grades
- **Which fuel went in**: a fill-up can record the petrol grade (E10 or E5
  and its octane, E85, ethanol-free E0), the diesel blend (B7, B7 premium,
  B10, B20, B100, HVO / XTL) or, for electric vehicles, how it was charged
  (at home, public AC, DC, rapid DC or ultra-rapid DC). It is optional: leave
  it as "grade not recorded" whenever the receipt does not say.
- **One fuel picker** on the fill-up form, grouped: *Used on this vehicle*
  (your usual choices from the last 12 months, so it is one tap on a phone),
  then the fuels that fit the vehicle, *Other fuels*, and *More grades*. US
  pump grades (Regular 87, Mid 89, Premium 91+, E15) are listed with petrol
  for owners whose language setting is for the US or Canada, E20 for India;
  everyone else finds them under *More grades*. The last grade you bought is
  preselected (for a plug-in hybrid, separately for petrol and charging). It
  works without JavaScript, in the pop-up and offline.
- **Default grade** per vehicle (add / edit vehicle), used until the vehicle
  has a fill-up with a grade; *Home charging* is the usual choice for an EV.
- **Badges** like the ones on pumps and chargers: a circle for petrol, a
  square for diesel, a rhombus for LPG and a hexagon for charging, with the
  short name ("E10 95", "B7", "Rapid"). They appear in the fuel list, the
  vehicle overview, the dashboard's *Recent fuel* and *Recent activity*, and
  the Expenses list.
- **By grade** on the Fuel tab: fills, amount bought, average price and — once
  there are two full-to-full stretches driven on one grade — an indicative
  economy per grade. For electric vehicles it is **By charging type**: the
  share of energy and cost per kWh of home, AC and rapid charging (free
  charges count at 0), then the blended cost per kWh.
- The **price trend** shows one line per grade, so E5 against E10, or home
  against rapid charging, can be compared.
- CSV: the fuel export has *Grade* and *Grade code* columns; import accepts
  the code, the name or the short name ("E10", "B7", "Rapid", "Home") in your
  language or English. Files without the column import as before.
- German translations for everything new ("Super E10", "Laden zu Hause", …).

### Changed
- Existing fill-ups are unchanged and show "Not recorded"; average economy
  and every other figure is exactly as before. Economy by grade is a view of
  the same full-to-full stretches, credited to the fuel burned over each
  (what went in at its start), never to the fill that closed it.
- Import: when a file has several columns that could fill a field, the one
  named like Logbook's own export wins.

### Upgrade notes
- New nullable columns `fuel_entries.grade` and `vehicles.default_grade`,
  added by a reversible migration that runs automatically on start (Docker)
  or with `vendor/bin/phinx migrate` (bare PHP). Nothing is backfilled.
- Backups record the database schema, so a backup made with 0.7.0 cannot be
  restored into 1.0.0. Restore it with 0.7.0 first, then upgrade.
- No configuration changes.
- Docker images are published as `1.0.0`, `1.0`, `1` and `latest`. From now
  on `latest` is the newest release; the newest development build is
  `master`.

## [0.7.0] — 2026-09-28

Phase 7: design alignment and dashboard enhancements.

### Added — Phase 7: design alignment and dashboard enhancements
- **Forms in a pop-up on desktop**: on a wide screen, adding or editing a
  vehicle, fill-up, odometer reading, service record, service interval,
  document or expense opens in a window over the page. Mistakes are shown
  right there; saving closes it and shows the usual confirmation. On phones,
  without JavaScript, or if anything goes wrong, the form opens as its own
  page as before, and every form still has its own address.
- **"+ Log entry"** (sidebar, and the "+" in the phone tab bar) replaces
  "Log fill-up": choose fill-up, odometer reading, service record, expense,
  document or service interval, then the vehicle (skipped when you have one).
  Choices for switched-off modules are left out. It works offline for
  fill-ups, like the fill-up form.
- **Sidebar**: the *Reminders* link shows how many reminders are overdue or
  due soon, and a *Vehicles* list shows each active vehicle with a red, amber
  or green dot. The dot's meaning ("1 overdue, 1 due soon", "All up to date")
  is shown as a tooltip and read out by screen readers.
- **Dashboard vehicle filter**: with two or more vehicles, chips under the
  greeting show one vehicle at a time. Every widget then covers that vehicle
  only, and a card is pinned at the top with its economy (last 12 months),
  running cost per mile or km, spend over the last 12 months and what is due
  next, plus *Log fill-up*, *Add reading* and *Open vehicle*. The choice is
  part of the address, so it can be bookmarked.
- **New dashboard widgets**: *Mileage* (this month, this year and monthly
  average, with a bar chart of the last 12 months) and *Recent activity* (the
  last eight things logged, of every kind). Saved layouts gain them at the end.
- **Expenses tab**: a *Last 12 months* chart beside *By category*. It always
  covers the last 12 months, whichever period is picked above.
- **Garage cards** show an "N due" badge on the photo, the current odometer
  and the average economy, in your units. *Your vehicles* on the dashboard is
  now a row of photo tiles with the same badge.
- **Accent colour** (Settings → Appearance): Blue, Teal, Indigo or Purple for
  buttons, links, highlights and charts, in light and dark themes. Overdue,
  due soon and OK keep their red, amber and green, and number plates stay
  yellow.
- **Version**: shown in the sidebar and on Settings ("Logbook v0.7.0"), and
  returned by `/health` (`"version"`).
- German translations for everything new.

### Changed
- The dashboard's default order now starts with *Upcoming reminders*, *Spend
  this month* and *Recent fuel*, then *Your vehicles*. A layout you have
  already arranged is kept.
- Every vehicle tab has *Edit*, *Archive* and *Delete* in the same place, and
  *Export CSV* / *Import CSV* next to the add button on the right (the
  *Mileage* tab now matches the others).
- Fuel trend charts, and Reports' *By category* and *By vehicle*, sit side
  by side on wide screens. *By vehicle* names are no longer links; each has a
  car or motorbike icon.
- Development: `bin/dev`, `docker-compose.dev.yml` and `composer start` now
  default to port **8090** (was 8080). `APP_PORT` still overrides, and a port
  already remembered in `var/dev.env` is kept. Production defaults are
  unchanged.

### Upgrade notes
- New column `users.accent` (default `blue`), added by a reversible
  migration that runs automatically on start (Docker) or with
  `vendor/bin/phinx migrate` (bare PHP).
- Backups record the database schema, so a backup made with 0.6.0 cannot be
  restored into 0.7.0. Restore it with 0.6.0 first, then upgrade.
- The release number now comes from the `VERSION` file in the app's root; keep
  it when copying the app to a bare-PHP server.

## [0.6.0] — 2026-09-28

Phase 6: feature toggles, import, backup and polish.

### Added — Phase 6: feature toggles, import, backup and polish
- **Modules** (Settings → Modules): switch off fuel, maintenance, documents,
  reminders or reports. A switched-off module disappears everywhere — menus,
  vehicle tabs and overview, dashboard, reports, reminders and notifications,
  the calendar feed — and its pages answer "not found". Its data is kept:
  switch it back on and everything is as it was. `FEATURES_*` set the
  defaults until the setting is saved.
- **CSV import** ("Import CSV" next to "Export CSV" on each vehicle tab):
  upload a file, match its columns (familiar names are matched for you), pick
  its date order, units and time zone, then preview every row before
  anything is saved. Rows are checked exactly like the forms check what you
  type; rows with problems are listed with their row number and reason, and
  are only skipped when you say so. Entries already logged are recognised, so
  importing a file twice changes nothing, and odometer readings that imported
  fill-ups or services already created are never doubled. Logbook's own
  exports import back exactly, in any units; so do files from spreadsheets
  (semicolons, day-first dates, Windows-1252 text). See `docs/import.md`.
- **Backup and restore** (Settings → Backup and restore): download everything
  — database, photos and attachments — as one ZIP; restore one after checking
  it and confirming, with an automatic safety backup of the current data
  first. Backups restore onto any supported database (SQLite → PostgreSQL
  works). `php bin/backup.php create | check | restore` does the same from the
  command line or cron.
- **Installable app (PWA)**: add Logbook to a phone's home screen. The
  fill-up form works offline: a fill-up saved without a connection is kept on
  the phone and sent when it is back online (with a *Review* option if the
  server rejects it). Works at a subpath too.
- **German** translation, complete. The language is picked from your settings
  or the browser.
- Guides: `docs/configuration.md` (every variable), `docs/import.md`,
  `docs/translations.md`; the deployment guide now covers the phone app,
  backups (including nightly cron backups and moving between databases) and a
  step-by-step upgrade procedure.

### Changed
- Accessibility: the green, amber and red status labels in the light theme
  are slightly darker, to meet WCAG AA contrast on their tinted backgrounds.
  Core pages are checked automatically for labels, headings, names, contrast
  and translations in every language.
- Settings → Reminders shows only the lead times while the reminders module
  is off.

### Upgrade notes
- No database changes.
- New optional variables: `BACKUP_PATH` (default `var/backups`; Docker
  `/data/backups`) and `MAX_RESTORE_MB` (default 256).
- The Docker image now includes PHP's `zip` extension and accepts uploads up
  to 256 MB (for restoring backups; attachments are still capped by
  `MAX_UPLOAD_MB`). Behind nginx, raise `client_max_body_size` if you want to
  restore large backups through the browser.
- Bare PHP: install `php-zip` to use backups (`composer` lists it under
  "suggest"); nothing else needs it.

## [0.5.0] — 2026-09-28

Phase 5: expenses, reports and dashboard.

### Added — Phase 5: expenses, reports and dashboard
- **Expenses**: every fill-up, and every maintenance job and document with a
  cost, now counts as an expense automatically — nothing to enter twice and
  nothing counted twice. Add anything else (parking, tolls, road tax,
  cleaning, fines…) on the vehicle's new **Expenses** tab; free is fine.
- **Reports** (new *Reports* page): the whole garage or one vehicle over this
  month, the last 3 or 12 months, this year, all time or any dates you pick.
  Total spend, running cost per mile or km (from your mileage log), distance
  driven, average per month, spend by category and per month (table and
  chart) and per vehicle. Sold (archived) vehicles are left out unless you
  tick "Include archived vehicles". Vehicles in different currencies get
  separate totals — amounts are never converted.
- **CSV export** of any report, and of each vehicle's fuel, mileage,
  maintenance, documents and expenses ("Export CSV" on each tab). Values are
  in your units with the unit in the header, precise enough to import back.
- **Dashboard**: widgets for your vehicles, upcoming reminders, spend this
  month, recent fuel, efficiency trend and documents. *Customise* moves or
  hides them (drag and drop too); the layout is saved to your account.
- On phones, Reports takes Settings' place in the bottom bar; Settings moves
  to the top bar.
- `FEATURES_*` variables now take effect for the dashboard and reports
  (e.g. `FEATURES_COMPLIANCE=false` hides the documents widget and leaves
  document costs out of reports).

### Upgrade notes
- New table `expense_entries`, created by a reversible migration that runs
  automatically on start (Docker) or with `vendor/bin/phinx migrate` (bare
  PHP). Existing costs appear in reports straight away; nothing is copied.

## [0.4.0] — 2026-09-27

Phase 4: reminders and notifications.

### Added — Phase 4: reminders and notifications
- **Reminders** (new *Reminders* page in the navigation): every maintenance
  schedule and every document with an expiry date becomes a reminder,
  grouped as overdue, due soon and upcoming. Mark done, dismiss or reopen in
  one click; logging the work or renewing the document clears it. Add your
  own reminders too ("Pay road tax on 1 Oct"). The home page shows what needs
  attention.
- **Lead times** in Settings → Reminders: how many days (and, for
  maintenance, how many miles or km) before something counts as due. The
  vehicle pages use the same lead times.
- **Notifications**: reminders are sent when they come due and again if they
  become overdue — never more — by email (SMTP), [ntfy](https://ntfy.sh),
  [Gotify](https://gotify.net) and/or any JSON webhook. Several at once
  arrive as one message. Choose channels and your email address in Settings,
  and send a test from there. Optional monthly "what's due this month" digest.
- **Calendar feed**: subscribe to your reminders from any calendar app
  (iCal / webcal), with an alert at each lead time. The secret link can be
  replaced or turned off.
- **Scheduled task**: `bin/run-scheduled-tasks.php` now does the work. The
  Docker image runs it every 15 minutes by itself (`SCHEDULER_ENABLED`,
  `SCHEDULER_INTERVAL`); bare-PHP installs add one cron line.
- Notification channels are pluggable: adding one (Telegram, Discord, …) means
  implementing one interface — see `docs/notification-channels.md`.

### Upgrade notes
- New table `reminders`, created by a reversible migration that runs
  automatically on start (Docker) or with `vendor/bin/phinx migrate` (bare
  PHP).
- Bare PHP: install the cron entry from docs/deployment.md (if you added it
  earlier, it now does something). Docker: nothing to do.
- New optional variables: `MAIL_TO`, `GOTIFY_URL`, `GOTIFY_TOKEN`,
  `GOTIFY_PRIORITY`, `SCHEDULER_ENABLED`, `SCHEDULER_INTERVAL`. The compose
  files now pass the notification variables through from `.env`. Set
  `APP_URL` to your public address so links in notifications and the
  calendar feed work.
- Calendar feed links are keyed with `SESSION_SECRET`: changing it disables
  existing links (create a new one in Settings).

## [0.3.0] — 2026-09-27

Phase 3: maintenance and documents.

### Added — Phase 3: maintenance and documents
- Vehicle pages gain two tabs: **Maintenance** and **Documents**. The
  overview shows what maintenance is due next and where each document stands.
- Service history: log services, repairs, tyres, brakes and more with date,
  optional odometer (it joins the mileage log), cost — free work at 0 is
  fine — garage and details. Newest first, filterable by category.
- Recurring schedules ("every 10,000 mi or 12 months, whichever comes
  first"): the next due date and odometer are worked out from the last time
  it was logged (or the "last done" you enter), the distance is placed on
  the calendar from your average mileage, and each schedule shows whether it
  is on track, due soon or overdue. "Log it" pre-fills the entry.
- Documents: insurance, pollution certificates (PUC), registration,
  inspections (MOT) and anything else, with provider, number, validity dates
  and cost. Create and edit both work (regression-tested). Expiring and
  expired documents are flagged; a renewal replaces the old document.
- Attachments: add receipts, invoices and certificates (PDF, JPEG, PNG or
  WebP, up to `MAX_UPLOAD_MB`) to fill-ups, maintenance and documents. Files
  are checked by content, stored outside the web root and served only to
  you; deleting an entry or a vehicle deletes its files.
- The demo seed now includes schedules, a service history and documents.

### Changed
- Vehicle photos and attachments share one upload check and one
  authenticated file handler.

### Upgrade notes
- New tables `maintenance_schedules`, `maintenance_entries`,
  `compliance_documents` and `attachments`, and a nullable
  `maintenance_entry_id` column on `odometer_readings`, created by reversible
  migrations that run automatically on start (Docker) or with
  `vendor/bin/phinx migrate` (bare PHP). No configuration changes;
  attachments live under the existing `UPLOAD_PATH` — include it in backups.

## [0.2.0] — 2026-09-27

Phase 2: mileage and fuel.

### Added — Phase 2: mileage and fuel
- Vehicle pages have tabs — Overview, Mileage and Fuel — each its own URL
  (works without JavaScript and survives a hard refresh). The overview shows
  the current odometer, average economy, fuel cost per distance, total spend
  and the latest fill-ups.
- Mileage log: add, edit and delete odometer readings (typed in your distance
  unit and time zone; stored in km and UTC). Fill-ups add their reading to the
  same series automatically. Current reading, monthly average, distance
  logged, a trend chart, and warnings — never refusals — for readings that go
  backwards or jump implausibly (over 2,000 km a day).
- Fuel log: add, edit and delete fill-ups with date and time, odometer, fuel,
  volume, price per unit and total — any two work out the third, exactly —
  plus "partial fill" and "missed the previous fill-up" flags, station and
  notes. Economy is measured full tank to full tank, so partial fills and
  missing receipts never distort it; figures are recalculated on every view.
  Shown in L/100 km, km/L, mpg (UK) and mpg (US), with average price, cost
  per distance, total spend, and economy and price trend charts.
- Electric vehicles use the same log in kWh, with efficiency in kWh/100 km
  or mi/kWh (following your distance unit). A plug-in hybrid's petrol and
  charging figures are kept apart.
- A "Log fill-up" button in the sidebar and in the middle of the mobile tab
  bar: one tap to the form with a single vehicle, a vehicle picker with more.
- Long logs are paginated (25 per page).
- The demo seed (`bin/dev seed`) now includes a year of fill-ups and readings.

### Upgrade notes
- Two new tables (`fuel_entries`, `odometer_readings`), created by reversible
  migrations that run automatically on start (Docker) or with
  `vendor/bin/phinx migrate` (bare PHP). No configuration changes.

## [0.1.0] — 2026-09-27

First release: Phases 0 and 1 (foundations, accounts and garage).

### Added — Phase 1: accounts and garage
- First-run setup: a fresh instance asks for the owner account (username,
  password, display name, units, currency, language, time zone) and is
  unreachable once an account exists.
- Sign in / sign out / change password. Argon2id hashes; database-backed
  sessions (`HttpOnly`, `SameSite=Lax`, `Secure` over HTTPS, scoped to
  `APP_BASE_PATH`, 30-day idle expiry, new id on sign-in); changing the
  password signs out other devices; failed sign-ins are logged for fail2ban.
- CSRF protection (slim/csrf) on every form, with a friendly "form expired"
  page; oversized uploads are reported as "too large".
- Garage: add, edit, delete (with a confirmation page) and archive/restore
  vehicles — cars and motorbikes, fuel type, tank or battery capacity, VIN,
  purchase and sale details, and a per-vehicle currency. Archived vehicles are
  hidden from active views and left out of fleet totals, history kept.
- Vehicle photos (JPEG, PNG, WebP up to `MAX_UPLOAD_MB`), checked by content,
  stored under `UPLOAD_PATH` with random names and served only to the
  signed-in owner.
- Settings: display name, theme (System/Light/Dark, also from the quick
  toggle), distance / fuel volume / fuel economy units (L/100 km, km/L,
  mpg UK and mpg US; Metric/UK/US presets), default currency, language with
  regional formats (e.g. English (United Kingdom)) and time zone. Changes apply
  on the next page.
- Units, money and dates engine for later phases: SI storage with conversion
  at the edges, exact decimal money (zero is valid, ≥3 decimals), calendar
  dates vs UTC instants, DST-safe local-time parsing.
- New configuration: `APP_CURRENCY` (default `GBP`). `SESSION_SECRET` is now
  used (optional).

### Development
- `bin/dev`: start/stop the Docker dev stack, switch between PostgreSQL,
  MySQL, MariaDB and SQLite (each keeps its own data), reset the database and
  load sample data (`DemoDataSeeder`: a demo owner and five vehicles).
- Shell scripts in `bin/` are now committed as executable.

### Database
- New tables `users`, `sessions` and `vehicles` (reversible migrations,
  tested on PostgreSQL, MySQL, MariaDB and SQLite). No manual upgrade steps.

### Added — Phase 0: foundations
- Slim 4 application skeleton with PHP-DI, Doctrine DBAL, Twig, Monolog and
  symfony/translation (ICU); English catalogue as default and fallback.
- PostgreSQL, MySQL/MariaDB and SQLite support. Every database session is
  pinned to UTC.
- Phinx migrations; baseline `settings` table.
- `GET /health` (200/503 JSON) for monitoring and the Docker `HEALTHCHECK`.
- Subpath hosting via `APP_BASE_PATH`, working whether the reverse proxy
  forwards or strips the prefix; deep links survive a hard refresh.
- Friendly, translated error pages; exception details only with `APP_DEBUG`,
  always HTML-escaped.
- Docker image (PHP 8.4 + Apache; amd64 and arm64, 64-bit only) with a single `/data`
  volume, auto-migration on start, and compose files for PostgreSQL, MySQL and
  development.
- Bare-PHP support: `.htaccess`, Apache/nginx/Caddy examples, cron runner
  placeholder, `composer start` dev server.
- Quality gates: phpcs (PSR-12), PHPStan (level max), PHPUnit. CI runs them on
  PostgreSQL, MySQL and MariaDB (PHP 8.4 and 8.5) and smoke-tests the image.

### Added — design system and app shell
- Design tokens (colour, typography, spacing, radii) for light and dark themes,
  following the OS by default with a JS toggle that is remembered per browser.
- Responsive shell: sidebar on wide screens, top bar and bottom tab bar on
  phones; Logbook logo and wordmark.
- Self-hosted Outfit and Plus Jakarta Sans fonts and a Material Symbols icon
  sprite (no CDN requests); base components for cards, lists, buttons, chips,
  forms, pills and alerts.

[Unreleased]: https://github.com/gwpreston16/Logbook/compare/v3.19.6...HEAD
[3.19.6]: https://github.com/gwpreston16/Logbook/compare/v3.19.5...v3.19.6
[3.19.5]: https://github.com/gwpreston16/Logbook/compare/v3.19.4...v3.19.5
[3.19.4]: https://github.com/gwpreston16/Logbook/compare/v3.19.3...v3.19.4
[3.19.3]: https://github.com/gwpreston16/Logbook/compare/v3.19.2...v3.19.3
[3.19.2]: https://github.com/gwpreston16/Logbook/compare/v3.19.1...v3.19.2
[3.19.1]: https://github.com/gwpreston16/Logbook/compare/v3.19.0...v3.19.1
[3.19.0]: https://github.com/gwpreston16/Logbook/compare/v3.18.3...v3.19.0
[3.18.3]: https://github.com/gwpreston16/Logbook/compare/v3.18.2...v3.18.3
[3.18.2]: https://github.com/gwpreston16/Logbook/compare/v3.18.1...v3.18.2
[3.18.1]: https://github.com/gwpreston16/Logbook/compare/v3.18.0...v3.18.1
[3.18.0]: https://github.com/gwpreston16/Logbook/compare/v3.17.0...v3.18.0
[3.17.0]: https://github.com/gwpreston16/Logbook/compare/v3.16.4...v3.17.0
[3.16.4]: https://github.com/gwpreston16/Logbook/compare/v3.16.3...v3.16.4
[3.16.3]: https://github.com/gwpreston16/Logbook/compare/v3.16.2...v3.16.3
[3.16.2]: https://github.com/gwpreston16/Logbook/compare/v3.16.1...v3.16.2
[3.16.1]: https://github.com/gwpreston16/Logbook/compare/v3.16.0...v3.16.1
[3.16.0]: https://github.com/gwpreston16/Logbook/compare/v3.15.0...v3.16.0
[3.15.0]: https://github.com/gwpreston16/Logbook/compare/v3.14.0...v3.15.0
[3.14.0]: https://github.com/gwpreston16/Logbook/compare/v3.13.0...v3.14.0
[3.13.0]: https://github.com/gwpreston16/Logbook/compare/v3.12.1...v3.13.0
[3.12.1]: https://github.com/gwpreston16/Logbook/compare/v3.12.0...v3.12.1
[3.12.0]: https://github.com/gwpreston16/Logbook/compare/v3.11.0...v3.12.0
[3.11.0]: https://github.com/gwpreston16/Logbook/compare/v3.10.0...v3.11.0
[3.10.0]: https://github.com/gwpreston16/Logbook/compare/v3.9.0...v3.10.0
[3.9.0]: https://github.com/gwpreston16/Logbook/compare/v3.8.0...v3.9.0
[3.8.0]: https://github.com/gwpreston16/Logbook/compare/v3.7.2...v3.8.0
[3.7.2]: https://github.com/gwpreston16/Logbook/compare/v3.7.1...v3.7.2
[3.7.1]: https://github.com/gwpreston16/Logbook/compare/v3.7.0...v3.7.1
[3.7.0]: https://github.com/gwpreston16/Logbook/compare/v3.6.0...v3.7.0
[3.6.0]: https://github.com/gwpreston16/Logbook/compare/v3.5.0...v3.6.0
[3.5.0]: https://github.com/gwpreston16/Logbook/compare/v3.4.0...v3.5.0
[3.4.0]: https://github.com/gwpreston16/Logbook/compare/v3.3.1...v3.4.0
[3.3.1]: https://github.com/gwpreston16/Logbook/compare/v3.3.0...v3.3.1
[3.3.0]: https://github.com/gwpreston16/Logbook/compare/v3.2.0...v3.3.0
[3.2.0]: https://github.com/gwpreston16/Logbook/compare/v3.1.0...v3.2.0
[3.1.0]: https://github.com/gwpreston16/Logbook/compare/v3.0.0...v3.1.0
[3.0.0]: https://github.com/gwpreston16/Logbook/compare/v2.16.0...v3.0.0
[2.16.0]: https://github.com/gwpreston16/Logbook/compare/v2.15.1...v2.16.0
[2.15.1]: https://github.com/gwpreston16/Logbook/compare/v2.15.0...v2.15.1
[2.15.0]: https://github.com/gwpreston16/Logbook/compare/v2.14.0...v2.15.0
[2.14.0]: https://github.com/gwpreston16/Logbook/compare/v2.13.0...v2.14.0
[2.13.0]: https://github.com/gwpreston16/Logbook/compare/v2.12.0...v2.13.0
[2.12.0]: https://github.com/gwpreston16/Logbook/compare/v2.11.0...v2.12.0
[2.11.0]: https://github.com/gwpreston16/Logbook/compare/v2.10.0...v2.11.0
[2.10.0]: https://github.com/gwpreston16/Logbook/compare/v2.9.0...v2.10.0
[2.9.0]: https://github.com/gwpreston16/Logbook/compare/v2.8.0...v2.9.0
[2.8.0]: https://github.com/gwpreston16/Logbook/compare/v2.7.0...v2.8.0
[2.7.0]: https://github.com/gwpreston16/Logbook/compare/v2.6.0...v2.7.0
[2.6.0]: https://github.com/gwpreston16/Logbook/compare/v2.5.0...v2.6.0
[2.5.0]: https://github.com/gwpreston16/Logbook/compare/v2.4.0...v2.5.0
[2.4.0]: https://github.com/gwpreston16/Logbook/compare/v2.3.0...v2.4.0
[2.3.0]: https://github.com/gwpreston16/Logbook/compare/v2.2.0...v2.3.0
[2.2.0]: https://github.com/gwpreston16/Logbook/compare/v2.1.0...v2.2.0
[2.1.0]: https://github.com/gwpreston16/Logbook/compare/v2.0.0...v2.1.0
[2.0.0]: https://github.com/gwpreston16/Logbook/compare/v1.10.0...v2.0.0
[1.10.0]: https://github.com/gwpreston16/Logbook/compare/v1.9.0...v1.10.0
[1.9.0]: https://github.com/gwpreston16/Logbook/compare/v1.8.0...v1.9.0
[1.8.0]: https://github.com/gwpreston16/Logbook/compare/v1.7.0...v1.8.0
[1.7.0]: https://github.com/gwpreston16/Logbook/compare/v1.6.0...v1.7.0
[1.6.0]: https://github.com/gwpreston16/Logbook/compare/v1.5.0...v1.6.0
[1.5.0]: https://github.com/gwpreston16/Logbook/compare/v1.4.0...v1.5.0
[1.4.0]: https://github.com/gwpreston16/Logbook/compare/v1.3.0...v1.4.0
[1.3.0]: https://github.com/gwpreston16/Logbook/compare/v1.2.1...v1.3.0
[1.2.1]: https://github.com/gwpreston16/Logbook/compare/v1.2.0...v1.2.1
[1.2.0]: https://github.com/gwpreston16/Logbook/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/gwpreston16/Logbook/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/gwpreston16/Logbook/compare/v0.7.0...v1.0.0
[0.7.0]: https://github.com/gwpreston16/Logbook/compare/v0.6.0...v0.7.0
[0.6.0]: https://github.com/gwpreston16/Logbook/compare/v0.5.0...v0.6.0
[0.5.0]: https://github.com/gwpreston16/Logbook/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/gwpreston16/Logbook/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/gwpreston16/Logbook/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/gwpreston16/Logbook/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/gwpreston16/Logbook/releases/tag/v0.1.0
