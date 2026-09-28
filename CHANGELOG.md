# Changelog

All notable changes to Logbook are recorded here. Database changes are always
shipped as reversible migrations; any upgrade step beyond "pull and restart"
is called out explicitly.

## [Unreleased]

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

[Unreleased]: https://github.com/gwpreston16/Logbook/compare/v0.4.0...HEAD
[0.4.0]: https://github.com/gwpreston16/Logbook/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/gwpreston16/Logbook/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/gwpreston16/Logbook/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/gwpreston16/Logbook/releases/tag/v0.1.0
