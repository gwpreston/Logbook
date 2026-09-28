# Phase 10 — Vehicle history + multiple attachments + v1.2.0

**Goal:** answer "what has happened to this car?" on one page. A **History**
tab lists everything logged against a vehicle (fill-ups, service records,
documents, expenses and odometer readings) newest first. The vehicle's own
milestones (first registered, bought, sold) bookend the list. Fill-ups are
folded so they don't bury the service record, and a print view gives the
owner a service history to hand to a buyer.

Alongside it, attachments are improved and document mileage is recorded:
- Every entry that takes files takes **several at once**.
- Expenses and odometer readings can carry files too.
- A document can record the **odometer shown on it** (an MOT certificate
  does), so the rows where mileage matters most have one.

Released as **v1.2.0**.

Read `spec.md` (§5, §6 OdometerReading / ComplianceDocument / ExpenseEntry /
Attachment, §7.2, §7.5, §7.7, §7.8, §7.10, §7.12, §7.13) and `CLAUDE.md`
(§6, §7, §8, §9, §11) before starting.

**Prerequisites:** Phase 9.2 complete and green; v1.1.0 tagged.

---

## Scope

**In:**
- A History tab per vehicle and a fleet history page.
- One shared activity feed behind them and the dashboard's *Recent
  activity*.
- Folded fill-up runs, vehicle milestones, filter chips, one page per year,
  and a print view.
- Several files per save on every attachment input.
- Attachments on expenses and manual odometer readings.
- An optional odometer on documents that joins the mileage series.
- CSV, backups and translations.
- Release v1.2.0.

**Out:**
- **More than one vehicle photo.** A vehicle keeps a single photo: it is
  what the cards, tiles and pinned card show, and paperwork belongs to the
  entry it proves.
- **Attachments on service intervals and reminders.** They are plans, not
  events.
- **Drag-and-drop, paste and thumbnail previews** for uploads.
- **Server-side PDF generation** (§12). The print view relies on the
  browser's *Save as PDF*.
- **A pass / fail outcome on documents.**
- **A combined CSV of the history.** Each module already exports its own.
- **MOT history import and registration lookup** (§12).

---

## Design decisions (record in `spec.md` before building)

### History

- **One feed, three views.** The query behind *Recent activity* (§7.8)
  becomes a service, `ActivityFeed`. It takes vehicles, a local date range
  and kinds, and returns typed items. Three things call it:
  - the widget (latest eight);
  - the History tab;
  - the fleet history page.

  Nothing else lists entries across modules. Its rules carry over
  unchanged:
  - newest first by the owner's local date, then by when the entry was
    added;
  - readings written by a fill-up, service or document are left out (the
    entry itself is listed);
  - a switched-off module's entries are left out.
- **What is listed.** Each row has an icon, the kind, a one-line summary,
  the amount (in the vehicle's currency, as the Expenses list shows it), the
  odometer when the entry has its own reading, and a paperclip with the
  number of files. Each row links to its edit page.

  | Kind | Dated by | Summary |
  |---|---|---|
  | Fill-up | `filled_at`, as a local date | grade badge, volume (owner's unit; kWh for a charge) |
  | Service record | `performed_on` | category, title, vendor |
  | Document | `start_on`, else the day added (as the cost ledger does) | title, else the type label; "expires {date}" |
  | Expense | `spent_on` | category, note (truncated) |
  | Odometer reading | `recorded_at`, as a local date (manual readings only) | note |

  **Not listed:**
  - service intervals and reminders (the work appears once it is logged);
  - readings owned by another entry;
  - attachments as rows of their own.
- **Milestones** are derived from the vehicle on every read, never stored
  (like its age):
  - *First registered* (`first_registered_on`);
  - *Bought* (purchase date);
  - *Sold* (sale date).

  Each appears only when its date is set. On their day, *First registered*
  and *Bought* sort below everything else (they happened first) and *Sold*
  above everything.

  The price goes in the summary ("Bought for £12,500"), not the amount
  column, which stays for costs. Purchase and sale prices are not part of
  the cost ledger, and the column must not suggest they are.

  Milestones have no odometer (the vehicle stores none). They link to the
  vehicle's edit page.
- **Fill-up runs fold.** Two or more fill-ups of the same vehicle with
  nothing else between them show as one row that expands to the fill-ups
  themselves. The summary reads like "4 fill-ups · 2 Sep – 17 Sep ·
  £284.10":
  - it adds the volume when the fill-ups share a unit ("168.4 L");
  - it counts fill-ups and charges apart for a plug-in hybrid ("3 fill-ups
    · 2 charges").

  The run is a `<details>` element, so it works without JS. A single
  fill-up is not folded; with the *Fuel* chip chosen nothing folds. A run
  never crosses a year, and only the History pages fold (the widget lists
  plainly).
- **Kind chips** under the toolbar: *Everything* (default), *Service*,
  *Fuel*, *Documents*, *Expenses*, *Mileage*. They behave like the
  dashboard's vehicle chips:
  - each is a link (`?kind=service`), one chosen at a time, with
    `aria-current` on the chosen one;
  - a switched-off module's chip is hidden;
  - an unknown value falls back to *Everything*.

  Milestones show under *Everything* only.
- **One calendar year per page** in the owner's time zone. Each page has a
  year heading, month subheadings, and *Newer* / *Older* links to the
  nearest year that has anything, skipping empty years.
  - The default page is the year of the newest item.
  - A year between the first and newest item's years with nothing in it
    renders "Nothing logged in {year}".
  - `?year=` outside that range falls back to the default.

  A year bounds every query: fill-ups by the UTC instants of the local
  year's start and end, the rest by date. So a page costs the same after
  ten years of history as after one — this matters on a Pi.
- **Tab placement.** History is the second tab
  (`/vehicles/{id}/history`), after Overview, with the shared vehicle
  header. Its toolbar shows the title and *Print*; there is no import,
  export or add button (the *Log entry* chooser covers adding). Like
  Mileage and Expenses it is core and cannot be switched off.
- **Overview** gains a *Recent history* card: the latest five items and a
  *Full history →* link.
- **Fleet history** (`/history`) is the same page across every active
  vehicle, with each row naming its vehicle.
  - The dashboard's vehicle chips (`?vehicle=`; an unknown or archived id
    falls back to all) sit beside the kind chips.
  - Fill-up runs fold per vehicle, and every total is in its own vehicle's
    currency.
  - It is reached from the *Recent activity* widget's title (*View all*),
    keeping the dashboard's `?vehicle=`. It is not a navigation item.
  - An archived vehicle's history is on its own History tab.
- **Returning after an edit.** A row's link opens the edit form (a modal on
  desktop, `data-modal`). Saving brings the owner back to the same History
  page and year, through a `return` parameter checked like the sign-in
  redirect (local paths only, no open redirects). Without the parameter the
  forms redirect as they do today.
- **Print view** (`/vehicles/{id}/history/print`) shows the vehicle's whole
  history on one page, for printing or the browser's *Save as PDF*: a
  service history to hand to a buyer or a garage.
  - **Options** (a plain GET form): the kinds to include, defaulting to
    everything except fuel (a buyer wants the services, not 400 receipts),
    and whether to show costs (default on; off also hides the purchase and
    sale prices).
  - **Header block:** name, descriptive line, registration, VIN, first
    registered with age, current odometer, and the date printed (owner's
    date format).
  - **Rows:** every row is listed, with no folding and no year pages, and
    each entry's attachment file names under it.
  - **Print CSS:** hides the app shell, keeps rows from splitting across
    pages, and prints black on white whatever the theme or accent.
  - **Print button:** calls `window.print()` with JS; without JS the
    browser's own print does the same.
  - Archived vehicles can print theirs.

### Attachments

- **Several files per save.** Every attachment input uses one shared
  partial: `<input type="file" name="attachments[]" multiple>` with the four
  accepted types, and the files already attached listed with delete links
  as today. The inputs are on the fill-up, service record, document,
  expense and manual reading forms.
  - **Limit:** up to 10 files per save, or PHP's `max_file_uploads` if that
    is lower (PHP drops extra files silently, so the app's limit must sit
    at or under it). Each file must be within `MAX_UPLOAD_MB`.
  - **Hint:** states both limits ("Up to 10 files, each up to {mb} MB").
  - **JS:** choosing more than the limit is refused before submitting.
  - No new configuration.
- **All or nothing, as now** (§7.12).
  - Every file is checked before any is stored. One rejected file fails the
    whole save with a message naming it ("receipt.heic: not a PDF, JPEG,
    PNG or WebP file"); nothing is written and the typed values are kept.
  - Files are written first, then their rows are inserted in the entry's
    transaction. If the transaction fails, the files just written are
    deleted.
- **Expenses and odometer readings take files** (a parking receipt, a
  penalty notice, a photo of the dashboard). `owner_type` gains `expense`
  and `odometer`.
  - Only manual readings take files. A reading owned by a fill-up, service
    or document belongs to that entry, whose files are shown instead.
  - Deleting the expense or reading deletes its files. Deleting a vehicle
    already deletes every file by `vehicle_id`.
- **A vehicle keeps one photo.** This is unchanged, deliberately.
- **A paperclip wherever an entry is listed:** History, the Fuel,
  Maintenance, Documents, Mileage and Expenses lists (ledger lines show
  their source's files), and *Recent activity*.
  - It is an icon with the count and a text alternative ("2 files").
  - Counts come from one grouped query per page, never one per row.

### Document odometer

- **Optional odometer on documents** (`compliance_documents.odometer_km`,
  `decimal(12,3)`, nullable), in the owner's distance unit and parsed like a
  reading.
  - Hint: "The reading on the certificate, if it shows one (an MOT
    certificate does)."
  - It needs a start date. Without one it is refused with "Add the date it
    was issued to record the odometer."
  - The documents list shows it when set.
- **It joins the mileage series exactly as a service record's does:**
  - a reading with source `document` and `compliance_document_id`
    (nullable, `ON DELETE CASCADE`), placed at local noon on `start_on`;
  - written in the document's transaction, and moved or removed when the
    document is edited;
  - checked for plausibility with the usual warning, never blocked.

  The Mileage tab labels its source *Document*.
- **Rollback keeps the mileage:** `document` readings become `manual` ones
  (link cleared) before the new columns are dropped.

---

## Tasks

### 10.0 Spec first
Per `CLAUDE.md` §12, update `spec.md` before building:
- [ ] §5 — the `return` parameter for forms opened from History.
- [ ] §6 OdometerReading — source `document`, `compliance_document_id`.
      ComplianceDocument — `odometer_km`. Attachment — owner types
      `expense` and `odometer`; several per save.
- [ ] §7.2 — document readings in the series; paperclip on manual readings.
- [ ] §7.5 — the document odometer field, its hint and the start-date rule.
- [ ] §7.7 — expense attachments; paperclip on ledger lines.
- [ ] §7.8 — *Recent activity* reads the shared feed; *View all* link.
- [ ] §7.12 — several files per save, the limit, all-or-nothing, the new
      owner types.
- [ ] §7.13 — document odometer in CSV; backup schema note.
- [ ] New §7.15 Vehicle history — everything under *History* above.
- [ ] §13 and `ROADMAP.md` gain a Phase 10 row; `CHANGELOG.md`
      `[Unreleased]` entry.

### 10.1 Activity feed
- [ ] Extract the *Recent activity* query into `ActivityFeed`:
  - input: a typed query (vehicle ids, local date range, kinds, limit);
  - output: typed items (kind, local date, added at, vehicle, summary
    parts, amount, odometer, attachment count, source link).
- [ ] Per-source queries bounded by the range, using their existing
      indexes. Documents are loaded per vehicle and filtered in PHP: the
      "else the day added" rule needs the owner's time zone, and a vehicle
      has few documents.
- [ ] Milestones built from the vehicle row, placed as above.
- [ ] Attachment counts in one grouped query on
      `(vehicle_id, owner_type, owner_id)` for the page's items.
- [ ] Newer / older year with items: per source, the earliest item after /
      latest item before the page's range (indexed), converted to the
      owner's local year.
- [ ] *Recent activity* switched to the feed: the same items in the same
      order (its existing tests still pass), plus the paperclip.

### 10.2 History tab
- [ ] Route, Action and template; shared vehicle header and list toolbar;
      second tab on every vehicle page.
- [ ] Year heading, month subheadings, and each month's rows as an ordered
      list with `<time datetime>`.
- [ ] Kind chips and year navigation. States for "Nothing logged in
      {year}" and "Nothing logged yet" (with *Log entry*).
- [ ] Fill-up runs as `<details>`, with a summary that reads sensibly to a
      screen reader.
- [ ] Rows link to their edit pages with `data-modal` and `return`;
      milestones link to the vehicle's edit page.
- [ ] Works for archived vehicles, at a subpath (deep-link refresh
      included) and without JS.

### 10.3 Fleet history, overview and dashboard
- [ ] `/history` with vehicle and kind chips; rows name their vehicle; runs
      fold per vehicle.
- [ ] *Recent activity* widget: the title links to `/history`, keeping
      `?vehicle=`.
- [ ] Overview *Recent history* card (latest five, *Full history →*).

### 10.4 Print view
- [ ] Route, Action and GET options form (kinds, show costs).
- [ ] Header block, every row, and attachment file names.
- [ ] Print stylesheet: no shell, black on white in both themes and every
      accent, rows kept whole, sensible page margins.
- [ ] *Print* button (JS only; hidden without it).

### 10.5 Document odometer: domain + migration
- [ ] Check how `odometer_readings.source` and `attachments.owner_type` are
      stored. If either has a length limit, check constraint or native
      enum on any engine, widen it in this migration (as 9.2 checked
      `vehicles.fuel_type`).
- [ ] Migration: `compliance_documents.odometer_km` and
      `odometer_readings.compliance_document_id` (foreign key, cascade).
      `down()` first turns `document` readings into `manual` ones, then
      drops both columns. Reversible on SQLite, PostgreSQL, MySQL and
      MariaDB.
- [ ] `ComplianceDocument` entity, repository and `Row` mapping; the
      odometer source enum gains `Document`.
- [ ] The document service writes, moves and removes the reading in the
      document's transaction through the odometer service (the same path
      maintenance uses).
- [ ] Form field, validation and hint; the documents list shows the
      odometer.
- [ ] If renewing a document pre-fills the form from the previous one, the
      odometer is not copied.

### 10.6 Multiple attachments
- [ ] Audit today's three attachment inputs (fill-up, service record,
      document): field name, `multiple`, and how the Action reads them.
      Whichever does it best becomes the shared partial and the one parser
      for `attachments[]`; no second upload path (§7.12).
- [ ] Validate every file, then store. Clean up on failure; the error
      names the file; typed values are kept.
- [ ] Effective limit = min(10, `max_file_uploads`), shown in the hint and
      checked with JS before submitting.
- [ ] Modal: `FormData` carries every file; the full-page fallback is
      unchanged.
- [ ] Offline: the cached `/fuel/new` form is rebuilt with the new partial.
      Check the queue: if it keeps files today, it keeps several; if not,
      it still doesn't (what works offline does not change).
- [ ] Docker `php.ini`: `max_file_uploads` at least 20, and `post_max_size`
      large enough for 10 × `MAX_UPLOAD_MB`. `docs/deployment.md` tells
      bare-PHP owners the same.

### 10.7 Attachments on expenses and readings
- [ ] Owner types `expense` and `odometer`.
- [ ] Attachment partial on the expense add / edit form and the manual
      reading add / edit form; readings owned by another entry have none.
- [ ] Deleting an expense or reading deletes its files through its service.
- [ ] Paperclip counts on every list named above.

### 10.8 CSV + backup
- [ ] Documents export gains *Odometer* (owner's distance unit, unit in the
      header). Import accepts it as optional and writes readings as the
      form does; files without the column import as before. Readings are
      never doubled (the existing duplicate rule covers them).
      `docs/import.md` updated.
- [ ] Backups: the new columns, the `document` readings and the new owner
      types round-trip. The schema version moves, so the upgrade note
      repeats the rule (restore an older backup with its own version
      first, then upgrade).

### 10.9 i18n
- [ ] English and German for every new label, hint, message and count:
  - *History* / *Verlauf*; *Vehicle history* / *Fahrzeughistorie*;
    *Bought* / *Gekauft*; *Sold* / *Verkauft* (*Erstzulassung* already
    exists);
  - fill-up and charge counts with ICU plurals (*# Tankvorgänge*,
    *# Ladevorgänge*) and *# files* / *# Dateien*;
  - *Nothing logged in {year}*;
  - the print options;
  - the upload limit hint and the per-file errors;
  - the document odometer hint and rule.

### 10.10 Release v1.2.0
- [ ] `VERSION` → `1.2.0`; the sidebar, Settings and `/health` show it.
- [ ] `CHANGELOG.md` `[1.2.0]`, with upgrade notes:
  - two nullable columns;
  - a new reading source;
  - several files per save (bare PHP: check `max_file_uploads` and
    `post_max_size`);
  - the backup schema rule;
  - no configuration changes.
- [ ] `ROADMAP.md`: Phase 10 row ✅.
- [ ] Tag `v1.2.0`; image published as `1.2.0`, `1.2`, `1` and `latest`.

### 10.11 Tests
- [ ] **Unit (feed):**
  - Ordering by local date, then by when added.
  - Year edges: a fill-up at 00:30 on 1 January in `Europe/Berlin`
    (23:30 UTC on 31 December) is on the new year's page.
  - Documents without a start date are placed on the day added.
  - Derived readings are left out; switched-off modules are left out.
- [ ] **Unit (milestones):** each is shown only with its date. On a shared
      day, *First registered* and *Bought* sit below the day's entries and
      *Sold* above. Prices appear in the summary, never in the amount
      column.
- [ ] **Unit (folding):**
  - One fill-up is not folded; two or more are.
  - A run is broken by a service, a reading or a milestone.
  - Runs fold per vehicle in the fleet view.
  - A plug-in hybrid run counts fill-ups and charges apart.
  - Nothing folds under the *Fuel* chip, and a run never crosses a year.
- [ ] **Unit (navigation and chips):**
  - Newer / older skip empty years; the default is the newest item's year.
  - An empty year in range shows its empty state; an out-of-range year
    falls back.
  - Chips are hidden for switched-off modules; an unknown kind falls back.
- [ ] **Unit (uploads and documents):**
  - Effective upload limit against a lower `max_file_uploads`.
  - A document odometer without a start date is refused; its reading
    lands at local noon on the start date.
- [ ] **Integration (history):**
  - History tab and print view (options respected, costs hidden when
    unticked).
  - Fleet history with both chips.
  - *Recent activity* returns the same items as before.
  - `return` accepts local paths only.
  - Archived vehicles' history and print.
- [ ] **Integration (attachments):**
  - Upload three files at once to a fill-up, service record, document,
    expense and manual reading, as a page and in the modal.
  - Eleven files are refused.
  - One bad file of three stores nothing (no rows, no files on disk).
  - Files on expenses and readings are served only to the owner, and are
    deleted with their entry.
  - Derived readings show no attachment input.
- [ ] **Integration (document odometer):**
  - The reading is created, moved and removed with its document, and
    deleted with it; plausibility warns without blocking.
  - CSV round-trip with and without the column.
  - Migration up / down, with `document` readings surviving rollback as
    `manual`; migrate → rollback → migrate is stable.
  - Backup and restore with several attachments and the new owner types.
- [ ] Pass on **both** MySQL and Postgres (plus MariaDB and SQLite via
      `bin/test-all-dbs.sh`); smoke test at a subpath.

---

## Deliverables
A History tab that tells each vehicle's story in one list, from first
registration to the latest fill-up. It comes with a fleet-wide view, a
printable service history, and attachments that take several files at once
on every entry that can carry them, expenses and readings included.
Documents such as an MOT record the mileage they show. Released as
Logbook v1.2.0.

## Acceptance criteria
- [ ] Every vehicle has a History tab listing fill-ups, service records,
      documents, expenses and manual readings in date order, with
      milestones where their dates are set.
- [ ] Back-to-back fill-ups fold into one expandable row that works
      without JS; nothing else is ever hidden.
- [ ] Kind chips, year pages and the fleet page work without JS, respect
      switched-off modules, and survive a hard refresh at a subpath.
- [ ] The print view produces a clean service history in either theme,
      with or without costs.
- [ ] Every attachment input takes up to 10 files at once; one bad file
      saves nothing; expenses and manual readings take files; the vehicle
      keeps one photo.
- [ ] A document's odometer joins the mileage series and is removed with
      it; rollback keeps the mileage.
- [ ] *Recent activity* shows the same items as before; every existing
      figure is unchanged.
- [ ] `/health`, sidebar and Settings show v1.2.0; changelog and roadmap
      updated.
- [ ] Suite green on both DBs; translatable (en + de); Docker and bare-PHP
      paths both work.

## Gotchas
- **One feed.** A second cross-module query for History would drift from
  *Recent activity* on the first rule change. Extract it, then reuse it.
- **Instants vs dates at year edges.** Fill-ups are UTC instants: bound a
  page with the local year's start and end converted to UTC, never with
  the year of `filled_at` in SQL (which is also engine-specific).
- **PHP drops files silently** past `max_file_uploads`, and drops the whole
  body past `post_max_size` (already reported as "too large"). The app's
  limit must sit under the first, or files vanish without an error.
- **Orphan files.** Validate everything before storing anything, and delete
  what was written if the transaction fails.
- **Paperclip N+1.** One grouped count per page. A per-row query is slow on
  a Pi by the second year of fill-ups.
- **Only manual readings take files.** A fill-up's reading is the fill-up's;
  giving it its own files would create two places for the same receipt.
- **Print must not inherit the theme.** Dark tokens print as pale text on
  no background; the print stylesheet sets its own colours.
- **The document odometer needs a date.** No start date means no reading,
  and so a refused field rather than a reading placed on the day it was
  added.
- **Milestones are derived, like age.** Don't add an events table; the
  vehicle row already holds the dates.

## Open questions
- **Overview:** once *Recent history* is there, does the overview still
  need its list of latest fill-ups? The Fuel tab lists them all.
- **Purchase and sale paperwork.** A V5C fits a *Registration* document, but
  the purchase invoice or sale receipt has nowhere to go. Owner types for
  the vehicle's purchase and sale would give the *Bought* and *Sold* rows a
  paperclip without turning the vehicle photo into a gallery.
- **Print defaults for a buyer:** costs on (the owner's copy) or off (the
  buyer's)? Either way it is one tick.
- **MOT history import.** The DVSA MOT history API returns each test's
  date, result and odometer, which would fill a UK car's history in one go.
  It needs an API key per install; it sits with registration lookup in §12.
