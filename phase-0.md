# Phase 0 — Foundations

**Goal:** a running, empty-but-correct skeleton that both deployment paths
(Docker and bare PHP 8.4) can start, wired to MySQL **and** PostgreSQL, with the
quality gates and cross-cutting plumbing in place. No domain features yet — this
phase exists so every later phase inherits a working, testable base.

Read `spec.md` (§4, §5, §9, §10, §11) and `CLAUDE.md` before starting.

**Prerequisites:** none — this is the first phase.

**Status: complete (2026-09-27).** Decisions recorded in `spec.md` §4 (stack
additions, `Logbook\` namespace, Apache image), §5 (middleware order, base
path), §6.1 (portable storage conventions) and §9 (config variables).
Phinx environments are `development`/`production` (`DB_*`) and `testing`
(`TEST_DB_*`); the engine is chosen by `DB_DRIVER`/`TEST_DB_DRIVER` rather
than by separate `mysql`/`pgsql` environments.

---

## Scope

**In:** repo skeleton, Slim + DI + DBAL + Phinx wiring, Twig, i18n scaffold,
base-path handling, `/health`, config/env, Docker + bare-PHP run paths, CI on
both databases, quality tooling.

**Out:** auth, vehicles, any domain tables beyond a migration harness. (Those
start in Phase 1.)

---

## Tasks

### 0.1 Repository skeleton
- [x] Create the directory layout from `CLAUDE.md` §3 (`public/`, `src/…`,
      `config/`, `db/migrations`, `db/seeds`, `templates/`, `assets/`,
      `translations/`, `tests/`, `docker/`).
- [x] `composer.json`: PHP `>=8.4`, PSR-4 autoload for the app namespace,
      and named scripts: `start`, `test`, `lint`, `analyse`, `cs-fix`.
- [x] Add deps: `slim/slim ^4.15`, `slim/psr7`, `php-di/php-di`,
      `doctrine/dbal`, `robmorgan/phinx`, `twig/twig`, `symfony/translation`,
      `monolog/monolog`, `slim/csrf`. Dev: `phpunit/phpunit`, `phpstan/phpstan`,
      `squizlabs/php_codesniffer`.

### 0.2 App bootstrap
- [x] `public/index.php` front controller only: build container, create app,
      register middleware + routes, run.
- [x] `config/settings.php` (env-driven), `config/dependencies.php` (DI defs),
      `config/routes.php`, `config/middleware.php`.
- [x] Error-handling middleware: friendly HTML errors in prod, detailed in dev;
      logs via Monolog. Escapes output (guards against the XSS class fixed in
      Slim's own error renderer).

### 0.3 Database layer (both engines)
- [x] DBAL connection factory reading `DB_DRIVER`/host/port/name/user/password;
      supports `pgsql`, `mysql`, and optional `sqlite`.
- [x] Register the connection in DI as a shared service.
- [x] Confirm a trivial round-trip query works on MySQL and Postgres.

### 0.4 Migrations (Phinx)
- [x] `phinx.php` config sharing the same env vars; environments for `mysql` and
      `pgsql` (and `testing`).
- [x] One baseline migration (e.g. a `schema_info`/no-op or the `settings`
      table) proving `migrate` **and** `rollback` work on both engines.
- [x] Document `phinx migrate` / `rollback` / `seed:run` in `composer` scripts.

### 0.5 Templating + assets
- [x] Twig with autoescape on; base layout (`templates/layout.twig`) with
      responsive, mobile-first shell and a nav placeholder.
- [x] Asset strategy that needs **no runtime Node**: commit built CSS/JS or
      provide a build target; document both. Serve from `public/assets`.
- [x] Include Alpine.js, Chart.js, SortableJS as vendored/bundled assets (loaded
      but unused this phase).

### 0.6 i18n scaffold
- [x] Wire `symfony/translation` with English as default + fallback.
- [x] Locale-resolution middleware (from user later; from `APP_LOCALE`/Accept-
      Language now).
- [x] `translations/en.*` catalogue; a Twig `trans` helper; prove one translated
      string renders. No hard-coded UI strings.

### 0.7 Base-path + reverse-proxy correctness
- [x] Base-path middleware honouring `APP_BASE_PATH` so the app works at a
      subpath (e.g. `/tracktor`).
- [x] All URL generation and asset links go through the base path.
- [x] Verify **hard refresh (F5) on a deep route works** behind a proxy at a
      subpath (the known failure mode). Add a deep test route to prove it.

### 0.8 Health check
- [x] `GET /health` returns app status + DB connectivity (200 when both ok,
      503 otherwise), JSON.

### 0.9 Docker
- [x] `Dockerfile` for PHP 8.4 (FPM or built-in server + a webserver), multi-arch
      including ARM (Raspberry Pi).
- [x] Entrypoint: run pending migrations, then start the server.
- [x] Single persistent volume at `/data` (uploads; SQLite if used).
- [x] `docker-compose.yml` (app + Postgres) and a MySQL variant; zero-edit
      `up` using defaults.

### 0.10 Bare-PHP path
- [x] Document web root = `public/`, `composer install`, `phinx migrate`, and a
      cron placeholder for the later scheduled task.
- [x] Provide Nginx and Apache vhost + reverse-proxy example snippets.

### 0.11 Config
- [x] `.env.example` with every variable from `spec.md` §9, sensible defaults,
      committed. Real `.env` git-ignored.

### 0.12 Quality gates + CI
- [x] phpcs (PSR-12), PHPStan config at the highest passing level.
- [x] PHPUnit bootstrap with a `testing` DB env.
- [x] CI matrix: run `lint`, `analyse`, and `test` **against MySQL and
      Postgres**; build the Docker image. Red on either DB = failing.

---

## Deliverables
A repo that starts via `docker compose up` (Postgres and MySQL variants) and via
bare PHP, serves a localized landing page and `/health`, works at a subpath with
working deep-link refresh, and has green CI on both databases.

## Acceptance criteria
- [x] `docker compose up -d` works unedited for both DB variants; `/health` is
      green.
- [x] Bare-PHP run path works following the docs.
- [x] `migrate` and `rollback` succeed on MySQL and Postgres.
- [x] App serves at `/` and at a subpath; F5 on a deep route does not break.
- [x] `lint` + `analyse` pass; test suite passes on both DBs in CI.
      *(Verified locally via `bin/test-all-dbs.sh` on PostgreSQL 17, MySQL 8.4,
      MariaDB 11.4 and SQLite; `.github/workflows/ci.yml` runs the same matrix
      and still needs its first run on GitHub.)*
- [x] No hard-coded UI strings; the sample string is translated via the catalogue.

## Gotchas
- Set DBAL types deliberately now (boolean/datetimetz/decimal/json) so Phase 1
  tables don't drift between engines.
- Get base-path handling right here; retrofitting it after routes exist is
  painful.
- Store timestamps UTC from the very first migration.
