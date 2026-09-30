# Phase 1 — Authentication + Garage

**Goal:** a real, usable app for one owner: set up an account, log in securely,
and manage a garage of vehicles — with the units / currency / timezone engine
that every later phase depends on established here.

Read `spec.md` (§6, §7.1, §7.9, §8) and `CLAUDE.md` (§8, §9) before starting.

**Prerequisites:** Phase 0 complete and green.

**Status: complete (2026-09-27).** Decisions recorded in `spec.md`:
- §6 User: instead of a single `unit_system`, users store distance, volume
  and consumption units separately (Metric/UK/US are presets), plus currency
  and theme — the design lets people mix km with mpg.
- §6 Session / §7.9: sessions are database-backed (portable, survive
  container restarts, allow "sign out other devices"); the cookie token is
  stored only as an HMAC keyed with `SESSION_SECRET`.
- §5: CSRF and the auth guard sit on route groups, not globally, so `/health`
  never creates sessions; the session itself is lazy.
- §8: the locale preference can carry a region (`en_GB`) for formatting while
  the `en` catalogue translates. New config `APP_CURRENCY` (§9).
- Verified locally with `bin/test-all-dbs.sh` on PostgreSQL 17, MySQL 8.4,
  MariaDB 11.4 and SQLite, and with `bin/smoke-test.sh` (now doing a real
  first-run setup and sign-in) against the production image.

---

## Scope

**In:** first-run setup, login/sessions/CSRF, change password, vehicle CRUD,
vehicle photo, archive/unarchive, user preferences (unit system, currency,
timezone, locale), and the shared Support helpers (units, money, dates).

**Out:** fuel, odometer, maintenance, compliance, reminders, reports, feature
toggles UI, general attachments (vehicle photo only for now). Multi-user (schema
is shaped for it; only one account is created/used).

---

## Tasks

### 1.1 User + settings tables
- [x] Migration: `users` (id, username, password_hash, display_name, locale,
      unit_system, currency, timezone, created_at) — shaped for multi-user but
      single-row in use.
- [x] Migration: `settings` (key, value JSON, scope) if not created in Phase 0.
- [x] Applies + rolls back on MySQL and Postgres.

### 1.2 First-run setup
- [x] If no user exists, all routes redirect to a setup screen that creates the
      initial account (username, password, display name, locale, unit system,
      currency, timezone).
- [x] After setup, the app is normal; setup is unreachable once a user exists.

### 1.3 Authentication + sessions
- [x] `password_hash()` with `PASSWORD_ARGON2ID`; verify on login.
- [x] Session middleware: `HttpOnly`, `SameSite=Lax`, `Secure` when HTTPS;
      regenerate session id on login (fixation protection).
- [x] Login, logout, and "change password" flows.
- [x] Auth-guard middleware protecting all app routes except setup/login/health.

### 1.4 CSRF
- [x] `slim/csrf` enabled; tokens embedded and validated on every state-changing
      form (setup, login where applicable, all vehicle forms, change password).

### 1.5 Support layer (used by all later phases)
- [x] **Units:** converter with canonical SI storage (litres, km) and display in
      metric/imperial; consumption in L/100km, **mpg UK, mpg US**, and km/L.
- [x] **Money:** value object; DECIMAL storage; `intl` formatting; per-vehicle
      currency override resolving over the user default; **zero is valid**.
- [x] **Dates:** helper using `DateTimeImmutable`; parse/display in user locale +
      timezone, store UTC. Unit-test round-trips across timezones and DST.
- [x] **Validation:** shared validator with clear messages; never rejects
      legitimate edge values.

### 1.6 Vehicle domain
- [x] `Vehicle` entity + enums (type car|bike, fuel type) per `spec.md` §6.
- [x] `VehicleRepository` (DBAL) and `VehicleService`.
- [x] Migration for `vehicles` (incl. status active|archived, currency override,
      purchase/sale fields, photo reference).

### 1.7 Vehicle CRUD (Actions + Twig)
- [x] Garage list (active by default; "show archived" toggle).
- [x] Add / edit / delete vehicle forms with validation.
- [x] Vehicle detail page shell (sections for later phases stubbed/empty).
- [x] **Archive / unarchive**: archived vehicles hidden from active views and
      excluded from fleet scope unless explicitly included; history retained.

### 1.8 Vehicle photo upload
- [x] Upload/replace/remove a photo; validate mime + size (`MAX_UPLOAD_MB`).
- [x] Store under `UPLOAD_PATH` (outside web root); serve via an authenticated
      handler, never a public path. (This handler is the seed for general
      attachments in Phase 3.)

### 1.9 Preferences
- [x] Settings page to change unit system, currency, timezone, locale, display
      name, password.
- [x] Changing locale/units/currency/timezone is reflected immediately across
      the UI.

### 1.10 i18n
- [x] All new strings translatable; English catalogue updated. No hard-coded
      strings in templates or Actions.

### 1.11 Tests
- [x] Unit: units converter (incl. UK vs US mpg), money (incl. zero + override),
      dates (timezone/DST round-trips), validation edge cases.
- [x] Integration: first-run setup, login/logout, session regeneration, change
      password, CSRF rejection, vehicle CRUD, archive/unarchive, photo upload.
- [x] All pass on **both** MySQL and Postgres.

---

## Deliverables
A single-owner app where you complete first-run setup, log in, manage vehicles
(with photos and archiving), and set unit/currency/timezone/locale preferences
that visibly drive formatting everywhere.

## Acceptance criteria
- [x] Fresh instance forces setup; second visit goes to login.
- [x] Secure login/logout/change-password; session id regenerates on login;
      CSRF blocks forged posts.
- [x] Vehicle add/edit/delete/archive all work; archived excluded from active
      views and fleet scope by default.
- [x] Photo upload stored outside web root and served only when authenticated.
- [x] Preference changes (units incl. mpg variant, currency, timezone, locale)
      reflect immediately; a zero-cost value is accepted; ≥3-decimal inputs
      round-trip.
- [x] Everything translatable; suite green on both DBs; works behind a subpath
      reverse proxy.

## Gotchas
- Build the Support layer (units/money/dates) properly here — Phases 2–5 all sit
  on it, and timezone/units mistakes here become the "wrong totals" bugs later.
- Keep the auth seam clean (current user resolved via middleware) so multi-user
  can slot in later without touching every Action.
- Resolve currency as: per-vehicle override → user default → app default.
