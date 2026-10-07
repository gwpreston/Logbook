# Logbook

A self-hosted logbook for your cars and bikes: vehicles, mileage, fuel,
maintenance, insurance and certificate renewals, reminders and costs, all on
your own server.

> **Status: v3.3.0.** First-run setup, secure sign-in (with a password or your email address, a forgotten-password link by email, single
> sign-on through Authelia, Authentik or Keycloak, or the user a forward-auth
> proxy passes on), several people on one
> install (admins invite the others) with vehicles shared at View, Log or
> Manage, costs shared or not, each person's own reminders and units, vehicles (petrol, diesel, LPG, CNG, electric, self-charging and plug-in hybrids) with photos, variant, first registration date (and age), a *First MOT due* date suggested from it, purchase and sale paperwork and archiving, per-user units, currency,
> language and time zone; a History tab per vehicle (and for the fleet) with a
> printable service history that leaves costs off unless asked, and a sale pack for a buyer (summary, checkable mileage record, the paperwork as a ZIP); a mileage log with plausibility warnings; fuel / EV
> charging logs with full-to-full economy (L/100 km, mpg UK and US, km/L,
> kWh/100 km, mi/kWh, kg/100 km and mi/kg for CNG), checks that flag tanks far from the usual (a mistyped odometer, a fill-up that was not full), prices and running costs, and the grade bought (E10 /
> E5, diesel blends, home or rapid charging) compared by price and economy; a categorised service
> history with recurring schedules ("every 10,000 mi or 12 months") that work
> out when each job is next due; insurance, pollution certificates,
> registration and inspections with their expiry (and the first MOT before
> there is a certificate); and receipts, invoices and
> certificates attached to any of them; tyres — what is fitted and stored,
> how far and how old each one is, its tread depth and when it will need
> replacing; and reminders for all of it, with
> lead times you choose, sent by email, ntfy, Gotify, Telegram, Discord,
> Pushover, Mattermost, Slack or a webhook when they come due, as a list or a month calendar, plus an optional monthly digest
> and a calendar feed; every cost
> rolled up into per-vehicle and fleet reports (by category, per month, per
> mile or km, any date range) with CSV export and a clean printout (or PDF)
> of every report, charts in black and grey beside their tables; valuations, depreciation and
> the total cost of ownership since you bought each vehicle (running costs
> plus what it has lost in value, exact once sold), and the true cost per
> mile or km split into fuel, maintenance, insurance, tax and MOT, other
> costs and depreciation, ranked on the dashboard and traced year by year
> with what changed and why ([docs/reports.md](docs/reports.md)), leases and finance
> included; a *Coming up* view of the next 12 months (services, renewals,
> tyres and reminders, each at what it cost last time, plus a fuel
> estimate); a *Needs attention* list on each vehicle and the dashboard of
> what is wrong right now (overdue work, readings or fill-ups that look
> wrong, mileage or a valuation gone stale, economy slowly getting worse
> over the last few tanks, a price or a service cost with a digit too
> many), each with its fix and the likely causes Logbook can see, and never
> a score; business trips and mileage claims (switched on when you need
> them: private mileage worked out from the odometer, HMRC's approved rates
> for UK users or your own, a printable claim with employer payments, and
> whether the allowance covers what the car costs to run); fuel insights (whether a dearer grade is worth it, from fills
> bought close together, cost per mile or km per tank and per charging
> type, and economy by month to show what winter costs); a dashboard of widgets you can
> rearrange; modules you can switch off; CSV import with a preview, and your
> whole history from Fuelio (its CSV export, or a backup with the fill-up
> photos on the command line), previewed with Fuelio's own economy beside
> Logbook's and safe to repeat with a newer export; one-click
> backup and restore of everything; an installable phone app that logs
> fill-ups and trips offline; a REST API with keys, so Home Assistant, Shortcuts,
> Grafana and Node-RED can read your garage and log fill-ups and other entries; optional AI with
> the model you choose, on this server, your network or a cloud provider
> (off until an admin connects one): *Ask Logbook* answers questions in plain
> words from your own records, through read-only tools, with a source and a
> link for every figure and a check that flags any number Logbook didn't
> provide, and an *Insights* page of patterns worked out from your figures and, with AI on, a few the model finds each day; it drafts fill-ups, readings, services, documents, expenses, tread
> checks and reminders from a sentence as cards you check and add, and reads a
> photo or PDF of an invoice, receipt or certificate into the right form for you
> to check, with the file attached (photos are always stored without their
> location data); an MCP server, so Claude Desktop or another assistant can
> use the same tools with its own model, log fill-ups and readings, and leave
> other entries as drafts for you to add; incidents, damage and insurance
> claims, with repairs linked so they count once, a five-year claims history
> for insurance quotes, insurer letters read into the incident, and a car
> archived as written off with its settlement as the sale; background jobs
> you can see and run from Settings, with a warning when they stop and ways
> to run them without cron, and scheduled backups; an optional check for new
> versions (off until you switch it on); hire purchase, PCP, loan and lease
> agreements typed from the paperwork, with the payments left, what remains
> to pay, a settlement estimate or the lender's quote, the cost of credit
> counted once in your costs, equity, mileage against the allowance with the
> excess charge it is heading for, and ending one by settling, handing back
> or selling with finance owing (figures, never advice); fuel stations as
> records, with what you paid at each per grade over time, favourites first
> when you log a fill-up and what you paid there last time, straight-line
> distances from your own private places, and duplicates to merge; live
> listed fuel prices from the UK's Fuel Finder feed once an admin switches
> it on (downloaded to your server, so your location never leaves it), with
> *Prices nearby* on the Fuel stations page (each against the area's average, with what a tank would save and directions), *Cheapest near me* ranked by what the trip really costs, *Was it worth
> it?* after a fill-up, the listed price on the fill-up form and price
> alerts on favourite stations; a public demo mode that resets itself;
> tested recipes for Caddy and Traefik and a guide for Proxmox containers;
> the email server set up in the app, and each person's own notification
> channels (email, ntfy, Gotify, Telegram, Discord, Pushover, Mattermost,
> Slack or a webhook) with a test on each; in English and German. Coming
> from 3.2? Email is off after upgrading until an admin sets it up in
> Settings → Delivery: read the 3.3.0 upgrade notes. Coming from 2.x? 3.0.0 is a major version (no API change): read its upgrade notes in
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
| [docs/deployment.md](docs/deployment.md) | Docker and bare-PHP installs, reverse proxies and subpaths, the phone app, background jobs, backups, the update check, upgrading |
| [docs/reverse-proxies.md](docs/reverse-proxies.md) | nginx, Apache, Caddy and Traefik in front of Logbook, at the root or a subpath, with HTTPS: tested examples, what to set, what Logbook trusts from a proxy, common failures |
| [docs/proxmox-lxc.md](docs/proxmox-lxc.md) | Proxmox VE: Logbook in an LXC container, with Docker or PHP 8.4 natively; size, proxy, backups, updates |
| [docs/configuration.md](docs/configuration.md) | Every environment variable and its default |
| [docs/users-and-sharing.md](docs/users-and-sharing.md) | Several people on one install: admins, invitations, sharing a vehicle, costs, reminders per person, moving someone out |
| [docs/ai.md](docs/ai.md) | AI: connecting a model on this server, your network or the internet (Ollama, llama.cpp, LM Studio, vLLM, OpenAI, Anthropic, Gemini, OpenRouter), where data goes, keys, tasks, limits and which model to pick; *Ask Logbook*: what it answers, sources, the grounding check, conversations and privacy; adding entries by message; reading receipts, documents and insurer letters |
| [docs/sso.md](docs/sso.md) | Single sign-on with Authelia, Authentik or Keycloak: setting up the client, linking accounts, groups, switching passwords off, the break-glass link; header sign-in behind a forward-auth proxy (nginx, Traefik, Caddy, the Authentik outpost) and how to deploy it safely |
| [docs/import.md](docs/import.md) | Importing CSV files and Fuelio exports: columns, units, what is skipped and why |
| [docs/api.md](docs/api.md) | The REST API: keys, values, paging and errors, with Home Assistant, Shortcuts, Grafana and Node-RED examples |
| [docs/mcp.md](docs/mcp.md) | The MCP server: Claude Desktop, Claude Code and other assistants, keys and scopes, on your network or behind your reverse proxy, drafts to review |
| [docs/sale-pack.md](docs/sale-pack.md) | The sale pack: what a buyer sees, what they never see, saving it as a PDF |
| [docs/trips.md](docs/trips.md) | Trips and mileage claims: logging, saved journeys, the business and private split, mileage rates, the claim report and what the figures mean |
| [docs/finance.md](docs/finance.md) | Finance and lease agreements: entering one from the paperwork, the schedule, what each figure means, estimates and your lender's quote, mileage against the allowance, ending an agreement and selling with finance owing, costs counted once, who can see it |
| [docs/stations.md](docs/stations.md) | Fuel stations: your station names as stations, choosing one on a fill-up with what you paid last time, what you paid at each, favourites, your private places and straight-line distances, merging duplicates, chargers, who sees and changes what; live fuel prices (UK Fuel Finder), Cheapest near me and effective cost, Was it worth it?, price alerts, adding a provider adapter |
| [docs/incidents.md](docs/incidents.md) | Incidents, damage and insurance claims: logging, photos kept as taken, linking repairs so costs count once, the claim and repair estimates, reading insurer letters, archiving a car as written off, the claims history for insurance quotes and what the sale pack shows |
| [docs/demo-mode.md](docs/demo-mode.md) | Running a public demo that resets itself: seeding an empty database, the reset schedule, what visitors can and cannot do, why it cannot wipe a real instance, stopping being a demo |
| [docs/notification-channels.md](docs/notification-channels.md) | Email, ntfy, Gotify, Telegram, Discord, Pushover, Mattermost, Slack and webhooks; adding a channel |
| [docs/translations.md](docs/translations.md) | Adding or improving a language |
| [CHANGELOG.md](CHANGELOG.md) | What changed in each release, with upgrade notes |

## Configuration

Everything is an environment variable (or a line in `.env`); all are
documented in [`.env.example`](.env.example) and
[docs/configuration.md](docs/configuration.md). The most important are
`DB_DRIVER`/`DB_HOST`/`DB_NAME`/`DB_USER`/`DB_PASSWORD`, `APP_URL`,
`APP_BASE_PATH` and `APP_TIMEZONE`. The one exception is the email server,
which an admin sets up in the app, in **Settings → Delivery** (with *Send
test email*). Each person chooses where their reminders go in **Settings →
Account → Notifications**: email, ntfy, Gotify, Telegram, Discord, Pushover,
Mattermost, Slack or a webhook of their own (see
[docs/notification-channels.md](docs/notification-channels.md)); an admin
decides in Settings → Delivery where members' channels may send.

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
composer test:coverage       # PHPUnit with line coverage (needs pcov or Xdebug) → var/coverage/
composer coverage:check      # overall coverage against tests/coverage-floor.txt (CI also checks 80% of a PR's changed lines)
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
./bin/dev-setup.sh --with-sample-data  # ...and add sample data (fresh passwords for demo and partner, printed at the end)
./bin/dev-setup.sh --mysql             # use MySQL instead (or --mariadb, --sqlite)
./bin/dev-setup.sh --port 8081         # serve on another port
./bin/dev-setup.sh --reset             # start over from an empty database
./bin/dev-setup.sh --stop              # stop the stack, keeping all data
./bin/dev-setup.sh --stop --reset      # stop it and delete the data too
```

| Option | What it does |
|---|---|
| `--with-sample-data` | Add sample data: a demo owner (`demo`, an admin, `demo@example.test`, UK units, GBP) and seven vehicles — six active (petrol, self-charging hybrid, plug-in hybrid with a personalised registration, electric, a motorbike, and an off-road trail bike with no registration) and one sold and archived, with its sale receipt, a valuation and nine years of services and mileage so it shows exact lifetime cost-of-ownership figures; the electric car is leased, with monthly payments — with a year of fill-ups (the Golf's going back to 2021, one a month, with a valuation each spring, for its true cost trend; including partial fills, a missed fill-up, EV charges, a mistyped odometer the economy check flags and a thirsty winter tank confirmed as right) and monthly odometer readings, and a year of tyres: the Golf's summers, winters fitted in November and stored as *Winter wheels* in March, worn fronts replaced (linked to their service record), a repair, a rotation and a damaged tyre replaced, with tread depths and three checks so the fronts show a wear estimate and a *due* tyre reminder; the motorbike's rear replaced once and checked since; and a member (`partner`, `partner@example.test`) with Log access to the self-charging hybrid without costs, whose recent fill-ups they logged, and View access to the Golf. Both get **new random passwords on every run**, printed in the summary and kept in `var/dev-credentials` (mode 600, git-ignored; `--status` prints it). On a database that already has them, only the passwords change; on one with other accounts nothing is added. |
| `--postgres`, `--mysql`, `--mariadb`, `--sqlite` | Which database engine to run. PostgreSQL is the default. Each engine keeps its own data and photos, so you can switch back and forth. |
| `--reset` | Empty the chosen engine's database (full rollback + migrate) and delete its uploads. With `--stop`, delete every dev database, the uploads and the `vendor/` volume instead. Asks first unless `--yes`. |
| `--stop`, `--down` | Stop the containers instead of starting them. |
| `--status` | Show the containers and whether the app responds. |
| `--logs` | Follow the app log (errors, failed sign-ins). |
| `--port <number>` | Serve the app on this port (default 8090). Also accepted as `--port=<number>` or `APP_PORT=<number>`. 8080 is refused: it belongs to the production stack. |
| `-y`, `--yes` | Do not prompt before anything destructive. |

Every email the app sends in development (password resets, invitations,
reminders, digests) can be caught by **Mailpit**: open `http://localhost:8025`.
If another project already uses 8025, the script picks the next free port and
prints it (or ask for one with `MAILPIT_PORT=8026 ./bin/dev-setup.sh`). The
script points **Settings → Delivery** at it (server `mailpit`, port `1025`,
encryption *None*, From `logbook@localhost`) whenever no email server is
saved yet; one you saved yourself is left alone.
Try *Forgotten your password?* as `demo` and the email appears there.

Migrations are applied automatically whenever the app starts. The dev stack
runs on port 8090 so it never collides with the production stack on 8080 (the
script refuses 8080). If 8090 is taken, pick another for the run:
`./bin/dev-setup.sh --port 8081`.

The sample data comes from a Phinx seed
([`db/seeds/DemoDataSeeder.php`](db/seeds/DemoDataSeeder.php)); it refuses to
run when `APP_ENV=production`. Without Docker, run it with
`vendor/bin/phinx seed:run -e development -s DemoDataSeeder`; it then makes
up the passwords and prints them (or set `DEMO_PASSWORD` and
`PARTNER_PASSWORD`). `bin/tools/dev-sample-passwords-test.sh` checks the
whole round trip against the Docker stack.

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
