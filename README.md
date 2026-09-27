# Logbook

A self-hosted logbook for your cars and bikes: vehicles, mileage, fuel,
maintenance, insurance and certificate renewals, reminders and costs, all on
your own server.

> **Status: Phase 3 (maintenance and documents).** First-run setup, secure
> sign-in, vehicles with photos and archiving, per-user units, currency,
> language and time zone; a mileage log with plausibility warnings; fuel / EV
> charging logs with full-to-full economy (L/100 km, mpg UK and US, km/L,
> kWh/100 km, mi/kWh), prices and running costs; a categorised service
> history with recurring schedules ("every 10,000 mi or 12 months") that work
> out when each job is next due; insurance, pollution certificates,
> registration and inspections with their expiry; and receipts, invoices and
> certificates attached to any of them. Reminders arrive in Phase 4. See
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

On first visit you create the owner account; after that, units, currency,
language and time zone are per-user settings in the app.

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

### Local development with Docker (`bin/dev`)

No local PHP needed: `bin/dev` runs the dev stack (`docker-compose.dev.yml`)
with the source bind-mounted, so PHP and Twig changes show up on reload. It
starts the app on the database engine of your choice and can load sample data.
Requires Docker with Compose v2; on Windows run it from **Git Bash** (or as
`sh bin/dev …`).

```bash
bin/dev up                  # start on PostgreSQL → http://localhost:8080
bin/dev seed                # add sample data, then sign in as demo / logbook-demo
bin/dev down                # stop (your data is kept)
```

| Command | What it does |
|---|---|
| `bin/dev up [engine]` | Start the stack, wait until the app is ready and print its URL. Without an engine it uses the last one (PostgreSQL the first time). |
| `bin/dev db <engine>` | Switch the database: `pgsql`, `mysql`, `mariadb` or `sqlite`. Each engine keeps its own data and photos, so you can switch back and forth. |
| `bin/dev seed` | Add sample data: a demo owner (`demo` / `logbook-demo`, UK units, GBP) and five vehicles — four active (petrol, hybrid, electric, a motorbike) and one sold and archived — with a year of fill-ups (including partial fills, a missed fill-up and EV charges) and monthly odometer readings. Does nothing if an account already exists. |
| `bin/dev reset [--seed] [-y]` | Empty the current engine's database (full rollback + migrate) and delete its uploads, optionally re-seeding. Asks first unless `-y`. |
| `bin/dev status` | Show the engine, URL and containers. |
| `bin/dev logs` | Follow the app log (errors, failed sign-ins). |
| `bin/dev down [--volumes]` | Stop everything. `--volumes` also deletes all dev databases, uploads and the `vendor/` volume. |

Migrations are applied automatically whenever the app starts. The chosen
engine and port are remembered in `var/dev.env` (git-ignored). If port 8080 is
taken, pick another once: `APP_PORT=8081 bin/dev up`.

The sample data comes from a Phinx seed
([`db/seeds/DemoDataSeeder.php`](db/seeds/DemoDataSeeder.php)); it refuses to
run when `APP_ENV=production`. Without Docker, run it with
`vendor/bin/phinx seed:run -e development -s DemoDataSeeder`.

Other tasks run inside the app container:

```bash
docker compose -f docker-compose.dev.yml exec app composer check   # lint + analyse + test
bin/test-all-dbs.sh                                                 # suite on SQLite, Postgres, MySQL, MariaDB
bin/smoke-test.sh pgsql                                             # production image end-to-end (after docker build -t logbook:local .)
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
