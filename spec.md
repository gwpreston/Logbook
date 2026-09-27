# Logbook Vehicle Tracker — Specification (`spec.md`)

Source of truth for what the app does and how it is structured. Companion:
`CLAUDE.md` (how to work in the repo) and the `phase-*.md` files (build order).
Update this document before adding or changing a feature.

---

## 1. Overview

A self-hosted web app for people who own one or more vehicles (cars and bikes)
and want everything about them in one place: vehicles, mileage, fuel,
maintenance, compliance documents, reminders, expenses and reports, on a single
at-a-glance dashboard. Self-hosted first — the owner's data stays on their own
machine.

**Primary promise:** *never miss a renewal or a service, and always know what
each vehicle costs.*

---

## 2. Goals and non-goals

**Goals**
- One place for all vehicle records, per vehicle and fleet-wide.
- Trustworthy numbers: correct units, currency, dates, and totals.
- Reminders that actually reach the user, not just sit in the app.
- Trivial to self-host: Docker with one volume, or a normal PHP 8.4 server.
- Runs on modest hardware (Raspberry Pi / small VPS).
- Data portability and backups the user controls.

**Non-goals (initially)**
- Live GPS / real-time telematics.
- Fleet-management for commercial operators (dispatch, driver assignment).
- A hosted multi-tenant SaaS. Single owner per instance to start; multi-user is
  a considered future phase (§13), not assumed in the core data model beyond a
  clean seam.

---

## 3. Target users

- Individuals with 1–10 personal vehicles (mixed cars and motorbikes).
- Home-lab / self-hosting enthusiasts who want control of their data.
- Households sharing a small set of vehicles (drives the eventual multi-user
  seam, not day-one roles).

---

## 4. Tech stack and rationale

| Concern | Choice | Why |
|---|---|---|
| Runtime | PHP 8.4 (8.5-clean) | Runs on any commodity PHP host; the user's target |
| Framework | Slim 4 (`^4.15`) + slim/psr7 | Micro-framework, PSR-standard, low overhead, easy to self-host |
| DI | PHP-DI | Slim's recommended container |
| DB access | Doctrine DBAL (not ORM) | Portable queries + schema across MySQL/Postgres without ORM weight |
| Migrations | Phinx | Framework-independent, native MySQL + Postgres, up/down |
| Databases | PostgreSQL + MySQL/MariaDB | User requirement; SQLite optional for zero-config demo |
| Templating | Twig | Server-rendered, autoescaped, fits bare-PHP hosting |
| JS | Alpine.js + Chart.js + SortableJS | Progressive enhancement, no SPA, no runtime Node |
| i18n | symfony/translation | ICU, pluralization, multi-locale |
| Auth | PHP sessions + Argon2id + slim/csrf | Standard, secure, no external IdP needed |
| Logging | Monolog | PSR-3 |
| Tests | PHPUnit + PHPStan + phpcs | Quality gates against both DBs |

**Decisions worth confirming** (defaults chosen; override in this section if you
disagree):
- *D1 — Server-rendered Twig + Alpine rather than an SPA.* Keeps the bare-PHP
  install trivial and maintenance low. Choose an SPA only if a richer offline
  experience is a hard requirement.
- *D2 — Doctrine DBAL rather than raw PDO.* Buys cross-database portability at
  the cost of one dependency. Raw PDO is viable but pushes MySQL/Postgres
  differences onto every query.

---

## 5. Architecture

- Front controller (`public/index.php`) → Slim app → middleware stack → Action.
- **Middleware order (outer→inner):** error handling → base-path → session →
  locale resolution → CSRF → auth guard (for protected routes) → routing.
- **Action → Service → Repository → DBAL → DB.** Twig renders the response.
- Reminders and report aggregation live in Services; a scheduled task
  (cron in bare install, entrypoint-scheduled in Docker) evaluates reminders and
  dispatches notifications.
- Feature toggles gate route registration and navigation so disabled modules are
  truly absent, not just hidden.

---

## 6. Data model

Canonical storage: **SI units** (litres, kilometres), **UTC** timestamps,
**DECIMAL** money. Conversion happens only at input/display.

**Vehicle**
- id, name/nickname, type (`car` | `bike`), make, model, year, registration,
  VIN (optional), fuel type (`petrol`|`diesel`|`ev`|`hybrid`|`lpg`|`other`),
  tank/battery capacity (optional), currency override (optional),
  photo (optional), purchase date/price (optional), sale date/price (optional),
  status (`active` | `archived`), created/updated (UTC).

**OdometerReading**
- id, vehicle_id, reading_km, recorded_at (UTC/date), source
  (`manual`|`fuel`|`maintenance`), note.
- Fuel and maintenance entries create/reference readings so mileage is one
  coherent series (see #230-style requirement).

**FuelEntry**
- id, vehicle_id, date, odometer_km, volume_litres, price_per_unit,
  total_cost, is_partial (bool), is_missed_previous (bool, for gap handling),
  station/notes, fuel_type (defaults to vehicle's). Derived: distance since last
  full, consumption, cost/distance.

**MaintenanceEntry**
- id, vehicle_id, date, odometer_km, category (service, repair, tyres, brakes,
  battery, other — extensible), title, description, cost (**0 allowed**), vendor,
  attachments.

**MaintenanceSchedule** (recurring)
- id, vehicle_id, category, interval_km (optional), interval_months (optional),
  last_done_at/odometer, next_due (computed) → feeds reminders.

**ComplianceDocument**
- id, vehicle_id, type (`insurance`|`pollution/PUCC`|`registration`|`inspection`
  |`other`), provider, policy/number, start_date, expiry_date, cost, attachments.
  Editing an existing document must work (guards against the known
  "can't update compliance entry" bug).

**Reminder**
- id, vehicle_id, source (schedule | compliance | manual), title, due_date,
  lead_time_days, status (`upcoming`|`due`|`overdue`|`dismissed`|`done`),
  channel(s) notified, last_notified_at.

**ExpenseEntry** (fuel and maintenance costs roll up here; plus ad-hoc)
- id, vehicle_id, date, category, amount, note, source ref.

**Attachment**
- id, owner_type, owner_id, filename, mime, size, stored_path, uploaded_at.

**User**
- id, username, password_hash (Argon2id), display name, locale, unit_system,
  timezone, created_at. (Single row day-one; table shaped for multi-user later.)

**Setting / FeatureToggle**
- key, value (JSON), scope (global | user). Drives enabled modules and defaults.

---

## 7. Feature specifications

### 7.1 Garage (vehicles)
Add/edit/delete vehicles; upload a photo; set per-vehicle fuel type and currency.
**Archive** sold vehicles: hidden from active views, history retained, excluded
from fleet totals unless "include archived" is toggled.

### 7.2 Odometer
First-class mileage log with manual entries plus readings derived from fuel and
maintenance. History table + trend chart. Warn on implausible readings (large
jumps, going backwards) without blocking.

### 7.3 Fuel
Log fill-ups with date, odometer, volume, price/unit, total (any two derive the
third), partial-fill flag, and a "missed previous fill-up" flag so consumption
math stays correct across gaps. Show per-fill and rolling consumption
(L/100km, mpg UK, mpg US, km/L), price trend, and cost/distance. Handle EVs
(kWh + efficiency) via the same shape.

### 7.4 Maintenance
Full service history per vehicle, categorised. **Recurring schedules**
("every 10,000 km or 12 months") that compute the next due point and raise
reminders. Attach invoices/receipts. Cost of 0 is valid.

### 7.5 Compliance
Track insurance, pollution/PUCC, registration, inspection with expiry dates and
documents. Create **and edit** must both work. Expiries feed reminders.

### 7.6 Reminders
Surface everything upcoming/due/overdue with configurable lead time. In-app list
+ dashboard widget. Outbound delivery (§7.11). Dismiss / mark done. Optional
iCal/webcal feed so items appear in the user's calendar.

### 7.7 Expenses and reports
Per-vehicle and fleet cost breakdowns over time (fuel vs maintenance vs
compliance vs other). Cost/distance and cost/month. Date-range filter. Simple,
readable reports; export to CSV/PDF (PDF may be a later phase).

### 7.8 Dashboard
At-a-glance fleet overview built from rearrangeable widgets (drag via SortableJS,
layout persisted per user): fleet summary, upcoming reminders, recent fuel,
spend this month, efficiency trend, compliance status. Widgets respect feature
toggles.

### 7.9 Authentication and sessions
Username/password login, Argon2id, secure sessions, logout, change password.
First-run setup creates the initial account. CSRF on all forms.

### 7.10 Feature toggles
Global settings to enable/disable modules (e.g. hide compliance if not needed).
Disabled modules are removed from nav, routes, and dashboard.

### 7.11 Notifications (reminder delivery)
In-app always; plus at least one outbound channel — email (SMTP) and/or a
webhook such as ntfy — configurable. Optional digest ("what's due this month").
Extensible channel interface so more can be added.

### 7.12 Attachments
Upload receipts, invoices, insurance/cert PDFs and images against fuel,
maintenance, and compliance entries. Stored outside web root, served via an
authenticated handler; type/size validated.

### 7.13 Import / export and backup
CSV import and export per module (also eases migration from spreadsheets and
other apps). One-click **backup and restore** of the whole dataset from within
the app — important given data lives on the user's own box.

### 7.14 Internationalisation
All user-facing strings translatable via symfony/translation. English default
and fallback. Locale controls translation, number/date/currency formatting.
Ship the framework so translations are easy to add; do not hard-code strings.

---

## 8. Cross-cutting requirements

- **Units:** per-user metric/imperial; canonical SI storage; both UK and US mpg.
- **Currency:** configurable + per-vehicle override; `intl` formatting; DECIMAL
  storage; zero is valid.
- **Dates/timezone:** locale + timezone aware display, UTC storage,
  `DateTimeImmutable`, explicit tests. (Primary defence against wrong totals.)
- **Decimal precision:** ≥3 decimals for fuel price/volume.
- **Validation:** clear errors; never reject legitimate edge values.
- **Accessibility:** keyboard navigation, labels, contrast, focus states.

---

## 9. Configuration (environment variables)

Documented in `.env.example`; sensible defaults so `docker compose up` works
unedited.

- `APP_URL`, `APP_BASE_PATH` (subpath support), `APP_TIMEZONE`, `APP_LOCALE`
- `DB_DRIVER` (`pgsql`|`mysql`|`sqlite`), `DB_HOST`, `DB_PORT`, `DB_NAME`,
  `DB_USER`, `DB_PASSWORD`
- `SESSION_SECRET`, `SESSION_SECURE`
- `UPLOAD_PATH`, `MAX_UPLOAD_MB`
- `MAIL_*` (SMTP), `NTFY_URL` / webhook settings
- `FEATURES_*` defaults (optional)

---

## 10. Deployment

- **Docker:** single image `ghcr.io/<owner>/<app>:latest`; one persistent volume
  for `/data` (uploads + SQLite if used); compose examples for app + Postgres and
  app + MySQL. Multi-arch build including ARM (Raspberry Pi).
- **Bare PHP 8.4:** document web root = `public/`, Composer install, Phinx
  migrate, cron entry for the reminder/notification task, and Nginx/Apache
  vhost + reverse-proxy examples.
- **Health check:** `/health` endpoint (app + DB connectivity) for monitoring.

---

## 11. Non-functional requirements

- **Reverse proxy:** must work behind one, including at a **subpath**; deep-link
  **hard refresh (F5)** must not break (correct base-path handling and server
  routing — a known failure mode to avoid).
- **Performance:** responsive on a Raspberry Pi with a few vehicles and years of
  history; paginate long lists; index common queries.
- **Backups:** user-controllable; document the volume/DB to back up; in-app
  backup/restore (§7.13).
- **Reliability:** migrations safe and reversible; upgrades documented with a
  changelog; no silent breaking DB changes.
- **Security:** see `CLAUDE.md` §9.

---

## 12. Future / optional (not in core phases)

- REST API with API keys (OpenAPI documented) for scripting/Home Assistant.
- Multi-user with roles (admin/editor/viewer) and per-vehicle sharing.
- OIDC/SSO (Authelia, Authentik, Keycloak) and reverse-proxy header auth.
- Tyre-life tracking, trip/journey log (business vs personal for mileage claims),
  personal fuel-tank entity, VIN decode/registration lookup, PDF reports,
  OBD-II / vehicle-API mileage import.

---

## 13. Build phases (each becomes a `phase-*.md`)

Each phase must be independently runnable and leave the app working. Detailed
task breakdowns live in the per-phase files; this is the map.

- **Phase 0 — Foundations.** Repo skeleton, Slim + DI + DBAL wiring, Phinx set
  up against MySQL + Postgres, Twig, base layout, config/env, Docker + bare-PHP
  run paths, `/health`, CI running tests on both DBs, i18n scaffold, base-path
  middleware.
- **Phase 1 — Auth + Garage.** First-run setup, login/sessions/CSRF, vehicle
  CRUD, photos, archive, units/currency/timezone plumbing.
- **Phase 2 — Odometer + Fuel.** Mileage log + charts; fuel entries with full
  consumption/cost math (partial fills, gaps, EV); the shared units engine.
- **Phase 3 — Maintenance + Compliance.** Service history, recurring schedules,
  compliance documents with create/edit, attachments.
- **Phase 4 — Reminders + Notifications.** Reminder engine, lead times, in-app +
  email/ntfy delivery, scheduled task, optional iCal feed.
- **Phase 5 — Expenses + Reports + Dashboard.** Cost roll-ups, reports, CSV
  export, draggable widget dashboard.
- **Phase 6 — Feature toggles + Import/backup + polish.** Toggles, CSV import,
  backup/restore, PWA, accessibility pass, translations, docs.

---

## 14. Definition of done

A phase (or change) is done when it meets every item in `CLAUDE.md` §11:
PHP 8.4 clean, lint + static pass, tested on **both** MySQL and Postgres,
migrations reversible on both, strings translatable, config documented, works
behind a subpath reverse proxy, and both Docker and bare-PHP run paths work.
