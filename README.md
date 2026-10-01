# Logbook

A self-hosted logbook for your cars and bikes: vehicles, mileage, fuel,
maintenance, insurance and certificate renewals, reminders and costs, all on
your own server.

> **Status: v2.4.0.** First-run setup, secure sign-in (with a password, single
> sign-on through Authelia, Authentik or Keycloak, or the user a forward-auth
> proxy passes on), several people on one
> install (admins invite the others) with vehicles shared at View, Log or
> Manage, costs shared or not, each person's own reminders and units, vehicles (petrol, diesel, electric, self-charging and plug-in hybrids) with photos, variant, first registration date (and age), a *First MOT due* date suggested from it, purchase and sale paperwork and archiving, per-user units, currency,
> language and time zone; a History tab per vehicle (and for the fleet) with a
> printable service history that leaves costs off unless asked, and a sale pack for a buyer (summary, checkable mileage record, the paperwork as a ZIP); a mileage log with plausibility warnings; fuel / EV
> charging logs with full-to-full economy (L/100 km, mpg UK and US, km/L,
> kWh/100 km, mi/kWh), checks that flag tanks far from the usual (a mistyped odometer, a fill-up that was not full), prices and running costs, and the grade bought (E10 /
> E5, diesel blends, home or rapid charging) compared by price and economy; a categorised service
> history with recurring schedules ("every 10,000 mi or 12 months") that work
> out when each job is next due; insurance, pollution certificates,
> registration and inspections with their expiry (and the first MOT before
> there is a certificate); and receipts, invoices and
> certificates attached to any of them; tyres — what is fitted and stored,
> how far and how old each one is, its tread depth and when it will need
> replacing; and reminders for all of it, with
> lead times you choose, sent by email, ntfy, Gotify or a webhook when they
> come due, plus an optional monthly digest and a calendar feed; every cost
> rolled up into per-vehicle and fleet reports (by category, per month, per
> mile or km, any date range) with CSV export and a clean printout (or PDF)
> of every report, charts in black and grey beside their tables; valuations, depreciation and
> the total cost of ownership since you bought each vehicle (running costs
> plus what it has lost in value, exact once sold), leases and finance
> included; a *Coming up* view of the next 12 months (services, renewals,
> tyres and reminders, each at what it cost last time, plus a fuel
> estimate); a *Needs attention* list on each vehicle and the dashboard of
> what is wrong right now (overdue work, readings or fill-ups that look
> wrong, mileage or a valuation gone stale), each with its fix, and never
> a score; business trips and mileage claims (switched on when you need
> them: private mileage worked out from the odometer, HMRC's approved rates
> for UK users or your own, a printable claim with employer payments, and
> whether the allowance covers what the car costs to run); fuel insights (whether a dearer grade is worth it, from fills
> bought close together, cost per mile or km per tank and per charging
> type, and economy by month to show what winter costs); a dashboard of widgets you can
> rearrange; modules you can switch off; CSV import with a preview; one-click
> backup and restore of everything; an installable phone app that logs
> fill-ups and trips offline; a REST API with keys, so Home Assistant, Shortcuts,
> Grafana and Node-RED can read your garage and log fill-ups; in English and
> German. Coming from 1.x? 2.0.0 is a major version: read its upgrade notes in
> [`CHANGELOG.md`](CHANGELOG.md) first. See [`ROADMAP.md`](ROADMAP.md) for
> the plan and what may come next.

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
(`APP_BASE_PATH`), cron, installing on a phone, backups and upgrades, are in
[docs/deployment.md](docs/deployment.md).

## Documentation

| Guide | For |
|---|---|
| [docs/deployment.md](docs/deployment.md) | Docker and bare-PHP installs, reverse proxies and subpaths, the phone app, backups, upgrading |
| [docs/configuration.md](docs/configuration.md) | Every environment variable and its default |
| [docs/users-and-sharing.md](docs/users-and-sharing.md) | Several people on one install: admins, invitations, sharing a vehicle, costs, reminders per person, moving someone out |
| [docs/sso.md](docs/sso.md) | Single sign-on with Authelia, Authentik or Keycloak: setting up the client, linking accounts, groups, switching passwords off, the break-glass link; header sign-in behind a forward-auth proxy (nginx, Traefik, Caddy, the Authentik outpost) and how to deploy it safely |
| [docs/import.md](docs/import.md) | Importing CSV files: columns, units, what is skipped and why |
| [docs/api.md](docs/api.md) | The REST API: keys, values, paging and errors, with Home Assistant, Shortcuts, Grafana and Node-RED examples |
| [docs/sale-pack.md](docs/sale-pack.md) | The sale pack: what a buyer sees, what they never see, saving it as a PDF |
| [docs/trips.md](docs/trips.md) | Trips and mileage claims: logging, saved journeys, the business and private split, mileage rates, the claim report and what the figures mean |
| [docs/notification-channels.md](docs/notification-channels.md) | Email, ntfy, Gotify and webhooks; adding a channel |
| [docs/translations.md](docs/translations.md) | Adding or improving a language |
| [CHANGELOG.md](CHANGELOG.md) | What changed in each release, with upgrade notes |

## Configuration

Everything is an environment variable (or a line in `.env`); all are
documented in [`.env.example`](.env.example) and
[docs/configuration.md](docs/configuration.md). The most important are
`DB_DRIVER`/`DB_HOST`/`DB_NAME`/`DB_USER`/`DB_PASSWORD`, `APP_URL`,
`APP_BASE_PATH` and `APP_TIMEZONE`. Reminders are sent through whichever
notification channels you configure (`MAIL_*`, `NTFY_*`, `GOTIFY_*`,
`WEBHOOK_URL`; see [docs/notification-channels.md](docs/notification-channels.md)).

On first visit you create the first admin account; after that, units,
currency, language and time zone are per-user settings in the app, and
**Settings → Users** invites the rest of the household
([docs/users-and-sharing.md](docs/users-and-sharing.md)). **Settings → Modules**
switches off what you don't use (fuel, maintenance, documents, reminders,
reports), and **Settings → Backup and restore** downloads or restores
everything in one file (`php bin/backup.php` does the same from cron).

## Development

Stack: PHP 8.4 · Slim 4 · PHP-DI · Doctrine DBAL · Phinx · Twig ·
symfony/translation · Monolog · Alpine.js / Chart.js / SortableJS (vendored).
Read [`CLAUDE.md`](CLAUDE.md) (how we work) and [`spec.md`](spec.md) (what we
build) before contributing.

```bash
composer install
composer start               # http://localhost:8090 (PHP built-in server, SQLite by default)
composer test                # PHPUnit (TEST_DB_*; SQLite by default)
composer lint                # phpcs, PSR-12
composer analyse             # PHPStan, level max
composer cs-fix              # phpcbf
composer check               # lint + analyse + test
composer migrate -- -e development
composer rollback -- -e development
composer build-assets        # assets/ → public/assets (+ cache-busting manifest)
```

### Local development with Docker (`bin/dev-setup.sh`)

No local PHP needed: `bin/dev-setup.sh` runs the dev stack (`docker-compose.dev.yml`)
with the source bind-mounted, so PHP and Twig changes show up on reload. It
starts the app on the database engine of your choice and can load sample data.
Requires Docker with Compose v2 and `curl`. It checks both before doing
anything (on macOS it starts Docker Desktop if it isn't running), and it won't
start if something else already holds the app port. On Windows run it from
**Git Bash** (or as `bash bin/dev-setup.sh …`).

```bash
./bin/dev-setup.sh                     # set up and start on PostgreSQL → http://localhost:8090
./bin/dev-setup.sh --with-sample-data  # ...and add sample data (sign in as demo / logbook-demo, or partner / logbook-demo)
./bin/dev-setup.sh --mysql             # use MySQL instead (or --mariadb, --sqlite)
./bin/dev-setup.sh --port 8081         # serve on another port
./bin/dev-setup.sh --reset             # start over from an empty database
./bin/dev-setup.sh --stop              # stop the stack, keeping all data
./bin/dev-setup.sh --stop --reset      # stop it and delete the data too
```

| Option | What it does |
|---|---|
| `--with-sample-data` | Add sample data: a demo owner (`demo` / `logbook-demo`, UK units, GBP) and six vehicles — five active (petrol, self-charging hybrid, plug-in hybrid, electric, a motorbike) and one sold and archived, with its sale receipt, a valuation and nine years of services and mileage so it shows exact lifetime cost-of-ownership figures; the electric car is leased, with monthly payments — with a year of fill-ups (including partial fills, a missed fill-up, EV charges, a mistyped odometer the economy check flags and a thirsty winter tank confirmed as right) and monthly odometer readings, and a year of tyres: the Golf's summers, winters fitted in November and stored as *Winter wheels* in March, worn fronts replaced (linked to their service record), a repair, a rotation and a damaged tyre replaced, with tread depths and three checks so the fronts show a wear estimate and a *due* tyre reminder; the motorbike's rear replaced once and checked since; and a member (`partner` / `logbook-demo`) with Log access to the self-charging hybrid without costs, whose recent fill-ups they logged, and View access to the Golf. Skipped if an account already exists. |
| `--postgres`, `--mysql`, `--mariadb`, `--sqlite` | Which database engine to run. PostgreSQL is the default. Each engine keeps its own data and photos, so you can switch back and forth. |
| `--reset` | Empty the chosen engine's database (full rollback + migrate) and delete its uploads. With `--stop`, delete every dev database, the uploads and the `vendor/` volume instead. Asks first unless `--yes`. |
| `--stop`, `--down` | Stop the containers instead of starting them. |
| `--status` | Show the containers and whether the app responds. |
| `--logs` | Follow the app log (errors, failed sign-ins). |
| `--port <number>` | Serve the app on this port (default 8090). Also accepted as `--port=<number>` or `APP_PORT=<number>`. 8080 is refused: it belongs to the production stack. |
| `-y`, `--yes` | Do not prompt before anything destructive. |

Migrations are applied automatically whenever the app starts. The dev stack
runs on port 8090 so it never collides with the production stack on 8080 (the
script refuses 8080). If 8090 is taken, pick another for the run:
`./bin/dev-setup.sh --port 8081`.

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
MessageFormat; English is the default and fallback, German ships complete). To
add a language, copy the English file and translate the values; it is detected
automatically — see [docs/translations.md](docs/translations.md). Templates use
`{{ 'key'|trans }}` and never contain literal UI text; the test suite checks
keys, placeholders and templates.

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
bin/           CLI helpers (asset build, backup, API keys, dev router, wait-for-db, scheduler, test scripts)
docs/          deployment, configuration, users and sharing, import, API (and its OpenAPI file), sale pack, trips,
               notification and translation guides; build phases in docs/phases/
```
