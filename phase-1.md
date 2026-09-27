# Phase 1 — Authentication + Garage

**Goal:** a real, usable app for one owner: set up an account, log in securely,
and manage a garage of vehicles — with the units / currency / timezone engine
that every later phase depends on established here.

Read `spec.md` (§6, §7.1, §7.9, §8) and `CLAUDE.md` (§8, §9) before starting.

**Prerequisites:** Phase 0 complete and green.

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
- [ ] Migration: `users` (id, username, password_hash, display_name, locale,
      unit_system, currency, timezone, created_at) — shaped for multi-user but
      single-row in use.
- [ ] Migration: `settings` (key, value JSON, scope) if not created in Phase 0.
- [ ] Applies + rolls back on MySQL and Postgres.

### 1.2 First-run setup
- [ ] If no user exists, all routes redirect to a setup screen that creates the
      initial account (username, password, display name, locale, unit system,
      currency, timezone).
- [ ] After setup, the app is normal; setup is unreachable once a user exists.

### 1.3 Authentication + sessions
- [ ] `password_hash()` with `PASSWORD_ARGON2ID`; verify on login.
- [ ] Session middleware: `HttpOnly`, `SameSite=Lax`, `Secure` when HTTPS;
      regenerate session id on login (fixation protection).
- [ ] Login, logout, and "change password" flows.
- [ ] Auth-guard middleware protecting all app routes except setup/login/health.

### 1.4 CSRF
- [ ] `slim/csrf` enabled; tokens embedded and validated on every state-changing
      form (setup, login where applicable, all vehicle forms, change password).

### 1.5 Support layer (used by all later phases)
- [ ] **Units:** converter with canonical SI storage (litres, km) and display in
      metric/imperial; consumption in L/100km, **mpg UK, mpg US**, and km/L.
- [ ] **Money:** value object; DECIMAL storage; `intl` formatting; per-vehicle
      currency override resolving over the user default; **zero is valid**.
- [ ] **Dates:** helper using `DateTimeImmutable`; parse/display in user locale +
      timezone, store UTC. Unit-test round-trips across timezones and DST.
- [ ] **Validation:** shared validator with clear messages; never rejects
      legitimate edge values.

### 1.6 Vehicle domain
- [ ] `Vehicle` entity + enums (type car|bike, fuel type) per `spec.md` §6.
- [ ] `VehicleRepository` (DBAL) and `VehicleService`.
- [ ] Migration for `vehicles` (incl. status active|archived, currency override,
      purchase/sale fields, photo reference).

### 1.7 Vehicle CRUD (Actions + Twig)
- [ ] Garage list (active by default; "show archived" toggle).
- [ ] Add / edit / delete vehicle forms with validation.
- [ ] Vehicle detail page shell (sections for later phases stubbed/empty).
- [ ] **Archive / unarchive**: archived vehicles hidden from active views and
      excluded from fleet scope unless explicitly included; history retained.

### 1.8 Vehicle photo upload
- [ ] Upload/replace/remove a photo; validate mime + size (`MAX_UPLOAD_MB`).
- [ ] Store under `UPLOAD_PATH` (outside web root); serve via an authenticated
      handler, never a public path. (This handler is the seed for general
      attachments in Phase 3.)

### 1.9 Preferences
- [ ] Settings page to change unit system, currency, timezone, locale, display
      name, password.
- [ ] Changing locale/units/currency/timezone is reflected immediately across
      the UI.

### 1.10 i18n
- [ ] All new strings translatable; English catalogue updated. No hard-coded
      strings in templates or Actions.

### 1.11 Tests
- [ ] Unit: units converter (incl. UK vs US mpg), money (incl. zero + override),
      dates (timezone/DST round-trips), validation edge cases.
- [ ] Integration: first-run setup, login/logout, session regeneration, change
      password, CSRF rejection, vehicle CRUD, archive/unarchive, photo upload.
- [ ] All pass on **both** MySQL and Postgres.

---

## Deliverables
A single-owner app where you complete first-run setup, log in, manage vehicles
(with photos and archiving), and set unit/currency/timezone/locale preferences
that visibly drive formatting everywhere.

## Acceptance criteria
- [ ] Fresh instance forces setup; second visit goes to login.
- [ ] Secure login/logout/change-password; session id regenerates on login;
      CSRF blocks forged posts.
- [ ] Vehicle add/edit/delete/archive all work; archived excluded from active
      views and fleet scope by default.
- [ ] Photo upload stored outside web root and served only when authenticated.
- [ ] Preference changes (units incl. mpg variant, currency, timezone, locale)
      reflect immediately; a zero-cost value is accepted; ≥3-decimal inputs
      round-trip.
- [ ] Everything translatable; suite green on both DBs; works behind a subpath
      reverse proxy.

## Gotchas
- Build the Support layer (units/money/dates) properly here — Phases 2–5 all sit
  on it, and timezone/units mistakes here become the "wrong totals" bugs later.
- Keep the auth seam clean (current user resolved via middleware) so multi-user
  can slot in later without touching every Action.
- Resolve currency as: per-vehicle override → user default → app default.
