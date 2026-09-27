# Phase 0 — Foundations

**Goal:** a running, empty-but-correct skeleton that both deployment paths
(Docker and bare PHP 8.4) can start, wired to MySQL **and** PostgreSQL, with the
quality gates and cross-cutting plumbing in place. No domain features yet — this
phase exists so every later phase inherits a working, testable base.

Read `spec.md` (§4, §5, §9, §10, §11) and `CLAUDE.md` before starting.

**Prerequisites:** none — this is the first phase.

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
- [ ] Create the directory layout from `CLAUDE.md` §3 (`public/`, `src/…`,
      `config/`, `db/migrations`, `db/seeds`, `templates/`, `assets/`,
      `translations/`, `tests/`, `docker/`).
- [ ] `composer.json`: PHP `>=8.4`, PSR-4 autoload for the app namespace,
      and named scripts: `start`, `test`, `lint`, `analyse`, `cs-fix`.
- [ ] Add deps: `slim/slim ^4.15`, `slim/psr7`, `php-di/php-di`,
      `doctrine/dbal`, `robmorgan/phinx`, `twig/twig`, `symfony/translation`,
      `monolog/monolog`, `slim/csrf`. Dev: `phpunit/phpunit`, `phpstan/phpstan`,
      `squizlabs/php_codesniffer`.

### 0.2 App bootstrap
- [ ] `public/index.php` front controller only: build container, create app,
      register middleware + routes, run.
- [ ] `config/settings.php` (env-driven), `config/dependencies.php` (DI defs),
      `config/routes.php`, `config/middleware.php`.
- [ ] Error-handling middleware: friendly HTML errors in prod, detailed in dev;
      logs via Monolog. Escapes output (guards against the XSS class fixed in
      Slim's own error renderer).

### 0.3 Database layer (both engines)
- [ ] DBAL connection factory reading `DB_DRIVER`/host/port/name/user/password;
      supports `pgsql`, `mysql`, and optional `sqlite`.
- [ ] Register the connection in DI as a shared service.
- [ ] Confirm a trivial round-trip query works on MySQL and Postgres.

### 0.4 Migrations (Phinx)
- [ ] `phinx.php` config sharing the same env vars; environments for `mysql` and
      `pgsql` (and `testing`).
- [ ] One baseline migration (e.g. a `schema_info`/no-op or the `settings`
      table) proving `migrate` **and** `rollback` work on both engines.
- [ ] Document `phinx migrate` / `rollback` / `seed:run` in `composer` scripts.

### 0.5 Templating + assets
- [ ] Twig with autoescape on; base layout (`templates/layout.twig`) with
      responsive, mobile-first shell and a nav placeholder.
- [ ] Asset strategy that needs **no runtime Node**: commit built CSS/JS or
      provide a build target; document both. Serve from `public/assets`.
- [ ] Include Alpine.js, Chart.js, SortableJS as vendored/bundled assets (loaded
      but unused this phase).

### 0.6 i18n scaffold
- [ ] Wire `symfony/translation` with English as default + fallback.
- [ ] Locale-resolution middleware (from user later; from `APP_LOCALE`/Accept-
      Language now).
- [ ] `translations/en.*` catalogue; a Twig `trans` helper; prove one translated
      string renders. No hard-coded UI strings.

### 0.7 Base-path + reverse-proxy correctness
- [ ] Base-path middleware honouring `APP_BASE_PATH` so the app works at a
      subpath (e.g. `/tracktor`).
- [ ] All URL generation and asset links go through the base path.
- [ ] Verify **hard refresh (F5) on a deep route works** behind a proxy at a
      subpath (the known failure mode). Add a deep test route to prove it.

### 0.8 Health check
- [ ] `GET /health` returns app status + DB connectivity (200 when both ok,
      503 otherwise), JSON.

### 0.9 Docker
- [ ] `Dockerfile` for PHP 8.4 (FPM or built-in server + a webserver), multi-arch
      including ARM (Raspberry Pi).
- [ ] Entrypoint: run pending migrations, then start the server.
- [ ] Single persistent volume at `/data` (uploads; SQLite if used).
- [ ] `docker-compose.yml` (app + Postgres) and a MySQL variant; zero-edit
      `up` using defaults.

### 0.10 Bare-PHP path
- [ ] Document web root = `public/`, `composer install`, `phinx migrate`, and a
      cron placeholder for the later scheduled task.
- [ ] Provide Nginx and Apache vhost + reverse-proxy example snippets.

### 0.11 Config
- [ ] `.env.example` with every variable from `spec.md` §9, sensible defaults,
      committed. Real `.env` git-ignored.

### 0.12 Quality gates + CI
- [ ] phpcs (PSR-12), PHPStan config at the highest passing level.
- [ ] PHPUnit bootstrap with a `testing` DB env.
- [ ] CI matrix: run `lint`, `analyse`, and `test` **against MySQL and
      Postgres**; build the Docker image. Red on either DB = failing.

---

## Deliverables
A repo that starts via `docker compose up` (Postgres and MySQL variants) and via
bare PHP, serves a localized landing page and `/health`, works at a subpath with
working deep-link refresh, and has green CI on both databases.

## Acceptance criteria
- [ ] `docker compose up -d` works unedited for both DB variants; `/health` is
      green.
- [ ] Bare-PHP run path works following the docs.
- [ ] `migrate` and `rollback` succeed on MySQL and Postgres.
- [ ] App serves at `/` and at a subpath; F5 on a deep route does not break.
- [ ] `lint` + `analyse` pass; test suite passes on both DBs in CI.
- [ ] No hard-coded UI strings; the sample string is translated via the catalogue.

## Gotchas
- Set DBAL types deliberately now (boolean/datetimetz/decimal/json) so Phase 1
  tables don't drift between engines.
- Get base-path handling right here; retrofitting it after routes exist is
  painful.
- Store timestamps UTC from the very first migration.
