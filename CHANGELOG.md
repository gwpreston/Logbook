# Changelog

All notable changes to Logbook are recorded here. Database changes are always
shipped as reversible migrations; any upgrade step beyond "pull and restart"
is called out explicitly.

## [Unreleased]

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

[Unreleased]: https://github.com/gwpreston16/Logbook/compare/v1.2.0...HEAD
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
