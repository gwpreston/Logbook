# CLAUDE.md

Guidance for any AI/coding agent (and humans) working in this repository.
Read this **and** `spec.md` before writing code. `spec.md` is the source of
truth for *what* to build; this file governs *how*.

---

## 1. Project in one paragraph

A self-hosted app for tracking one or more vehicles (cars and bikes). One owner
keeps their whole "garage" in one place: vehicles, odometer history, fuel
fill-ups, maintenance, insurance and pollution-certificate expiry, reminders,
expenses and reports, on a rearrangeable dashboard. Self-hosted first: it must
run both as a Docker image with a single persistent volume **and** on an
ordinary PHP 8.4 web server. Data stays local.

---

## 2. Tech stack (pinned — do not swap without updating `spec.md`)

- **Language:** PHP 8.4 (must also run clean on 8.5).
- **Framework:** Slim Framework 4 (`slim/slim ^4.15`) with `slim/psr7`.
- **DI container:** `php-di/php-di`.
- **DB access:** `doctrine/dbal` for portable queries + schema abstraction
  (NOT the full ORM). Thin repository classes on top. Raw PDO only where DBAL
  genuinely can't express something, and then behind a repository.
- **Migrations:** `robmorgan/phinx` — one migration set that must apply cleanly
  to **both** MySQL and PostgreSQL.
- **Databases:** PostgreSQL and MySQL/MariaDB. SQLite is allowed only as an
  optional zero-config default for the Docker quick-start if it costs little;
  Postgres/MySQL are the primary targets and every feature must work on both.
- **Templating:** Twig (`twig/twig`) — server-rendered pages.
- **Frontend JS:** progressive enhancement only. Alpine.js for interactivity,
  Chart.js for charts, SortableJS for the draggable dashboard. No SPA
  framework, no build step that the bare-PHP install can't skip.
- **i18n:** `symfony/translation` (ICU messages, pluralization). English is the
  default/fallback locale.
- **Auth:** PHP sessions via PSR-7 session middleware; `password_hash()` with
  `PASSWORD_ARGON2ID`. CSRF via `slim/csrf`.
- **Finance arithmetic:** `brick/math` (`BigDecimal`) where the scaled-int
  `Support\Number\Decimal` helper would overflow (Phase 29.1).
- **Logging:** `monolog/monolog` (PSR-3).
- **Testing:** PHPUnit 11+. **Lint/static:** PHP_CodeSniffer (PSR-12) +
  PHPStan (max level it can pass).

If current docs are needed for any of the above, look them up online rather
than guessing an API.

---

## 3. Repository layout (target)

```
/public            # web root — index.php front controller ONLY
/src
  /Action          # one invokable class per route (no logic in routes file)
  /Domain          # entities, value objects, enums (Vehicle, FuelEntry, ...)
  /Repository      # DBAL-backed data access, one per aggregate
  /Service         # business logic (efficiency calc, reminders, reports)
  /Support         # units, currency, dates, validation helpers
  /Middleware      # auth, CSRF, locale, base-path, error handling
/config            # settings.php, dependencies.php, routes.php, middleware.php
/db
  /migrations      # Phinx migrations
  /seeds           # Phinx seeds
/templates         # Twig
/assets            # css, js, images (served from /public/assets when built)
/translations      # <locale>.xlf or .php message catalogues
/tests             # Unit + Integration (run against both DBs in CI)
/docker            # Dockerfile bits, entrypoint, compose files
/docs              # user guides (deployment, configuration, API, ...)
  /phases          # one file per build phase, plus the open-questions log
```

Keep `public/` tiny: it exposes only the front controller and built static
assets. Nothing else should be web-reachable.

---

## 4. Commands

```bash
composer install                 # deps
composer start                   # local dev server (php -S) on :8090
composer test                    # PHPUnit
composer test:coverage           # PHPUnit with line coverage (pcov or Xdebug) -> var/coverage/
composer coverage:check          # fail if overall coverage is below tests/coverage-floor.txt
composer lint                    # phpcs (PSR-12)
composer analyse                 # phpstan
composer cs-fix                  # phpcbf

vendor/bin/phinx migrate -e development
vendor/bin/phinx rollback -e development
vendor/bin/phinx seed:run -e development

docker compose up -d             # full stack (app + db)
docker compose -f docker-compose.dev.yml up   # dev with hot reload of templates
bin/dev-setup.sh [--mysql|--mariadb|--sqlite] [--with-sample-data] [--reset] [--stop] # dev stack helper (see README)
```

Wire these up in `composer.json` scripts so they exist as named commands.

---

## 5. Architecture & conventions

- **PSR-12** coding style, **PSR-4** autoloading (`Tracktorish\` or the agreed
  namespace), **PSR-7/15** for HTTP and middleware, **PSR-11** for the container.
- **No business logic in `config/routes.php`.** Routes map a path to an Action
  class. Actions are thin: validate input, call a Service, return a response.
- **Services** hold logic and are unit-testable without HTTP or a real DB where
  practical. **Repositories** are the only place SQL lives.
- Everything is **constructor-injected** via PHP-DI. No `new` on collaborators
  inside Actions/Services; no service locator / global state.
- Enums for fixed sets (fuel type, unit system, reminder status). Value objects
  for money and quantities (see §8).
- Return typed results; avoid untyped arrays crossing service boundaries.

---

## 6. Database rules (this is where cross-DB apps break)

- All queries go through **DBAL's query builder** or parameterised DBAL calls —
  **never** string-concatenated SQL, **always** bound parameters.
- **No DB-specific SQL** (no raw `AUTO_INCREMENT`, no `SERIAL`, no
  `ILIKE`, no MySQL backtick-only syntax) outside a clearly documented
  platform branch. Prefer DBAL types and Phinx column helpers that emit the
  right thing per platform.
- **Booleans, timestamps, decimals, JSON** differ between MySQL and Postgres —
  use DBAL types (`boolean`, `datetimetz_immutable`, `decimal`, `json`) and let
  it map them. Store money as `DECIMAL`, never float.
- Every migration must `migrate` **and** `rollback` cleanly on **both** engines.
  CI runs the suite against MySQL and Postgres; a change that only passes on one
  is not done.
- Timestamps stored in **UTC**. Never store a local-time string.

---

## 7. Frontend rules

- Server-render with Twig. The app must be fully usable with JS disabled for
  core flows (add/edit/list); JS enhances (charts, drag-to-arrange, inline
  validation).
- Mobile-first, responsive, accessible: keyboard-navigable, labelled inputs,
  sufficient contrast, focus states, `aria` where needed.
- Ship a **PWA**: web manifest + service worker so quick fuel entry works on a
  phone, including a fast "add fill-up" path.
- Bundle assets so the bare-PHP install needs no Node at runtime. Commit built
  assets or provide a make target; document both.

---

## 8. Cross-cutting invariants (enforce everywhere)

- **Units:** user-selectable metric/imperial. Store canonical SI internally
  (litres, kilometres); convert only at the edges (display/input). Consumption
  displayable as L/100km, mpg (UK **and** US — they differ), and km/L.
- **Currency:** configurable, per-vehicle override. Format via `intl`. Store
  minor units or `DECIMAL`; never float. A cost of **0 is valid** — do not
  reject it.
- **Dates/timezone:** parse and display in the user's locale + timezone, store
  UTC. Use `DateTimeImmutable`. This is the #1 source of "wrong totals" bugs —
  test it explicitly.
- **Decimal precision:** allow at least 3 decimals for fuel price and volume.
- **Validation:** reject genuinely invalid input with clear messages; never
  reject legitimate edge values (zero cost, partial fill, first-ever odometer).

---

## 9. Security

- Argon2id password hashing; secure session cookie flags (`HttpOnly`,
  `SameSite=Lax`, `Secure` when behind HTTPS); session fixation protection on
  login.
- CSRF token on every state-changing form.
- All DB input parameterised (see §6). Escape all Twig output (autoescape on).
- No secrets in the repo. Config from environment / `.env` (git-ignored);
  ship `.env.example`.
- File uploads (receipts/certs): validate type and size, store outside web root,
  serve through an authenticated handler.

---

## 10. Configuration

Everything a self-hoster needs is an environment variable, documented in
`.env.example` and `spec.md` §Config. At minimum: DB driver/host/port/name/user/
password, app URL, **base path** (for subpath reverse proxying), timezone,
default locale, session secret, upload path, mail/notification settings.
Sensible defaults so `docker compose up` works with zero edits.

---

## 11. Definition of done (every change)

1. Runs on PHP 8.4, passes `lint` and `analyse`.
2. Has tests; suite passes against **both** MySQL and Postgres.
   **Coverage:** at least **80%** of the `src/` lines a change adds or
   modifies are covered, and overall `src/` line coverage stays at or above
   the floor in `tests/coverage-floor.txt` (never below 80; raise it when
   `composer coverage:check` says so). CI enforces both on the SQLite row.
   Templates, JS and migrations aren't measured, so test them as before.
3. Migrations apply and roll back on both engines.
4. New user-facing strings are translatable (no hard-coded English in templates).
5. New config is in `.env.example` and documented.
6. Works behind a reverse proxy at a subpath; deep-link hard refresh works.
7. Docker image builds (multi-arch incl. ARM) and the bare-PHP path still works.
8. The phase's open questions are decided, or carried into
   `docs/phases/open-questions.md`.

---

## 12. Phases and open questions

- Phase files live in `docs/phases/` (`phase-<n>.md`). `ROADMAP.md` lists
  them; `spec.md` §13 summarises them. A new phase gets its file there
  before any code.
- **Before starting a phase**, read `docs/phases/open-questions.md` and the
  *Open questions* of every earlier phase file. For each one still open:
  1. Check whether the app already answers it (spec, code, tests). If it
     does, record that in the log with where, and move on.
  2. If it doesn't and the answer would change behaviour, data, UI or
     configuration, **ask the owner** before acting. Give the options and a
     recommendation, then wait for the decision.
  3. Once decided, update `spec.md` first, add the work to the current or a
     new phase, mark the question *Decided* in its phase file (with the
     date and the decision) and in the log.
- Do not guess an answer to an open question, and do not silently drop
  one. A question that no longer applies is marked *Obsolete* with the
  reason.
- When writing a phase file, anything not yet decided goes under *Open
  questions* rather than into the tasks.

---

## 13. Do not

- Do not introduce an SPA framework or a runtime Node dependency.
- Do not write DB-engine-specific SQL outside a documented abstraction.
- Do not store money as float or timestamps as local strings.
- Do not put logic in routes or templates.
- Do not add a feature without a matching `spec.md` entry — update the spec first.
- Do not deliver "everything at once": build in the phases defined in `spec.md`,
  keeping each phase runnable.
