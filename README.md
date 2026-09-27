# Logbook

A self-hosted logbook for your cars and bikes: vehicles, mileage, fuel,
maintenance, insurance and certificate renewals, reminders and costs, all on
your own server.

> **Status: Phase 0 (foundations).** The skeleton runs on Docker and plain
> PHP, against PostgreSQL, MySQL/MariaDB or SQLite, with a localized landing
> page and `/health`. Vehicle tracking starts in Phase 1. See
> [`spec.md`](spec.md) §13 for the roadmap.

## Quick start

**Docker + PostgreSQL** (no edits needed):

```bash
docker compose up -d
# → http://localhost:8080
```

MySQL instead: `docker compose -f docker-compose.mysql.yml up -d`.
Single container with SQLite: `docker build -t logbook . && docker run -d -p 8080:80 -v logbook_data:/data logbook`.

**Plain PHP 8.4** (web root = `public/`):

```bash
composer install --no-dev -o
cp .env.example .env        # set DB_* etc.
vendor/bin/phinx migrate -e production
```

Full instructions, including Apache/nginx configs, reverse proxies, subpaths
(`APP_BASE_PATH`), cron, backups and upgrades, are in
[docs/deployment.md](docs/deployment.md).

## Configuration

Everything is an environment variable (or a line in `.env`); all are
documented in [`.env.example`](.env.example). The most important are
`DB_DRIVER`/`DB_HOST`/`DB_NAME`/`DB_USER`/`DB_PASSWORD`, `APP_URL`,
`APP_BASE_PATH` and `APP_TIMEZONE`.

## Development

Stack: PHP 8.4 · Slim 4 · PHP-DI · Doctrine DBAL · Phinx · Twig ·
symfony/translation · Monolog · Alpine.js / Chart.js / SortableJS (vendored).
Read [`CLAUDE.md`](CLAUDE.md) (how we work) and [`spec.md`](spec.md) (what we
build) before contributing.

```bash
composer install
composer start               # http://localhost:8080 (PHP built-in server, SQLite by default)
composer test                # PHPUnit (TEST_DB_*; SQLite by default)
composer lint                # phpcs, PSR-12
composer analyse             # PHPStan, level max
composer cs-fix              # phpcbf
composer check               # lint + analyse + test
composer migrate -- -e development
composer rollback -- -e development
composer build-assets        # assets/ → public/assets (+ cache-busting manifest)
```

No local PHP? The dev compose stack has everything, including PostgreSQL,
MySQL and MariaDB for cross-engine testing:

```bash
docker compose -f docker-compose.dev.yml up -d           # app on :8080, code bind-mounted
docker compose -f docker-compose.dev.yml exec app composer check
bin/test-all-dbs.sh                                      # suite on SQLite, Postgres, MySQL, MariaDB
bin/smoke-test.sh pgsql                                  # production image end-to-end (after docker build -t logbook:local .)
```

### Tests and databases

The suite never touches `DB_*`. It uses `TEST_DB_*`, which defaults to a
throwaway SQLite file. CI runs lint, static analysis and the full suite
(migrate → full rollback → migrate → PHPUnit) against **PostgreSQL 17,
MySQL 8.4 and MariaDB 11.4** on PHP 8.4 and 8.5. It then builds the
multi-arch image and smoke-tests it at the root and at a subpath behind nginx.
A change that is red on any engine is not done.

### Front-end assets

Source files live in `assets/`; `composer build-assets` copies them to
`public/assets` and writes `manifest.json` (content hashes for cache busting).
The built output is **committed**, so installs never need Node, and CI fails
if it is stale. Third-party libraries in `assets/vendor` are pinned in
`package.json`. Refreshing them is a maintainer-only step (`npm ci && npm run vendor`).

The visual design (colours, type, spacing, radii) lives as CSS custom properties
at the top of `assets/css/app.css`, with a light and a dark set; components use
only those tokens. Fonts (Outfit, Plus Jakarta Sans) and icons (Material
Symbols Rounded, bundled into `assets/vendor/icons.svg`) are self-hosted, so
the app makes no third-party requests. To use a new icon, add its name to
`bin/vendor-assets.mjs`, run `npm run vendor`, and reference it with the
`ui.icon()` macro from `templates/macros/ui.twig`.

### Translations

UI strings live in `translations/messages+intl-icu.<locale>.php` (ICU
MessageFormat; English is the default and fallback). To add a language, copy
the English file and translate the values; it is detected automatically.
Templates use `{{ 'key'|trans }}` and never contain literal UI text.

## Layout

```
public/        web root: index.php + built assets only
src/           Action/ Domain/ Repository/ Service/ Support/ Middleware/
config/        settings, DI definitions, middleware stack, routes
db/            Phinx migrations and seeds
templates/     Twig
assets/        CSS/JS sources and vendored libraries
translations/  message catalogues
tests/         Unit/ and Integration/
docker/        Apache vhost, PHP ini, entrypoint, nginx example, dev DB init
bin/           CLI helpers (asset build, dev router, wait-for-db, scheduler, test scripts)
docs/          deployment guide
```
