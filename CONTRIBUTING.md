# Contributing to Logbook

Thank you for helping. Bug reports, ideas and code are all welcome. This file
covers how to report problems, the terms code is accepted under, and how to
set up, test and release Logbook.

- [Issues](#issues)
- [Code](#code)
- [Development](#development)
- [Local development with Docker](#local-development-with-docker-bindev-setupsh)
- [Tests and databases](#tests-and-databases)
- [Front-end assets](#front-end-assets)
- [Translations](#translations)
- [Docs and screenshots](#docs-and-screenshots)
- [Repository layout](#repository-layout)
- [Releasing](#releasing)

## Issues

Open an issue for a bug or an idea. For a bug, say which version you run
(at the foot of the sidebar, and in Settings), how you installed it (Docker or bare PHP,
which database) and the steps that show it. Please don't post your data,
API keys or anything from `.env`.

Report a security problem privately, through GitHub's *Report a
vulnerability* on the repository's Security tab, not in a public issue.

## Code

Contributions are accepted under the project's licence, the GNU Affero
General Public License v3.0 or later ([`LICENSE`](LICENSE)). There is no
contributor licence agreement: you keep the copyright in your work and
license it to everyone under the same terms as the rest of Logbook.

### Sign off every commit

Every commit in a pull request must carry a `Signed-off-by` line with your
real name and an email address you can be reached at:

```
Signed-off-by: Your Name <you@example.com>
```

`git commit -s` adds it for you. By signing off you certify the
[Developer Certificate of Origin 1.1](https://developercertificate.org/):
in short, that you wrote the change or otherwise have the right to submit
it under the project's licence, and that you understand it and your
sign-off are recorded publicly. To sign off commits you have already
made, use `git rebase --signoff master` and force-push your branch.

### Before you open a pull request

Read [`CLAUDE.md`](CLAUDE.md) (how the code is organised and the rules every
change follows) and [`spec.md`](spec.md) (what the app does). In short:

- A feature needs a `spec.md` entry first, and larger work belongs to a
  phase in [`docs/phases/`](docs/phases/).
- Run `composer lint`, `composer analyse` and `composer test`. A change has
  to work on SQLite, PostgreSQL and MySQL/MariaDB; `bin/test-all-dbs.sh`
  runs the suite against each one.
- New text in the interface goes in `translations/`, never in a template.
- A new dependency must have a licence on the allowlist in
  `bin/third-party-notices.php`. Run `composer notices` to update
  [`THIRD-PARTY-NOTICES.md`](THIRD-PARTY-NOTICES.md); CI checks it.
- A change to the README or `docs/` passes `php bin/tools/check-docs.php`
  (see [Docs and screenshots](#docs-and-screenshots)).

## Development

Stack: PHP 8.4 · Slim 4 · PHP-DI · Doctrine DBAL · Phinx · Twig ·
symfony/translation · Monolog · Alpine.js / Chart.js / SortableJS (vendored).

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

## Local development with Docker (`bin/dev-setup.sh`)

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
| `--with-sample-data` | Add sample data: a demo owner (`demo`, an admin, `demo@example.test`, UK units, GBP) and seven vehicles — six active (petrol, self-charging hybrid, plug-in hybrid with a personalised registration, electric, a motorbike, and an off-road trail bike with no registration) and one sold and archived, with its sale receipt, a valuation and nine years of services and mileage so it shows exact lifetime cost-of-ownership figures; the electric car is leased, with monthly payments — with a year of fill-ups (the Golf's going back to 2021, one a month, with a valuation each spring, for its true cost trend; including partial fills, a missed fill-up, EV charges, a mistyped odometer the economy check flags and a thirsty winter tank confirmed as right) and monthly odometer readings, and a year of tyres: the Golf's summers, winters fitted in November and stored as *Winter wheels* in March, worn fronts replaced (linked to their service record), a repair, a rotation and a damaged tyre replaced, with tread depths and three checks so the fronts show a wear estimate and a *due* tyre reminder; the motorbike's rear replaced once and checked since; MOT history from a built-in sample provider (development only; it sends nothing): the Golf's six tests with an outstanding recall and a tyre advisory watched and advised again, the other cars' tests waiting on the review card, and the electric car's first MOT due date; and a member (`partner`, `partner@example.test`) with Log access to the self-charging hybrid without costs, whose recent fill-ups they logged, and View access to the Golf. Both get **new random passwords on every run**, printed in the summary and kept in `var/dev-credentials` (mode 600, git-ignored; `--status` prints it). On a database that already has them, only the passwords change; on one with other accounts nothing is added. |
| `--postgres`, `--mysql`, `--mariadb`, `--sqlite` | Which database engine to run. PostgreSQL is the default. Each engine keeps its own data and photos, so you can switch back and forth. |
| `--reset` | Empty the chosen engine's database (full rollback + migrate) and delete its uploads. With `--stop`, delete every dev database, the uploads and the `vendor/` volume instead. Asks first unless `--yes`. |
| `--stop`, `--down` | Stop the containers instead of starting them. |
| `--status` | Show the containers and whether the app responds. |
| `--logs` | Follow the app log (errors, failed sign-ins). |
| `--port <number>` | Serve the app on this port (default 8090). Also accepted as `--port=<number>` or `APP_PORT=<number>`. 8080 is refused: it belongs to the production stack. |
| `-y`, `--yes` | Do not prompt before anything destructive. |

The `vendor/` volume follows `composer.lock`: when a pull changes the lock,
the next start runs `composer install` (`docker/dev/composer-install.sh`),
so `--stop --reset` isn't needed for that. The check is in the dev image,
which `bin/dev-setup.sh` rebuilds on each start.

Every email the app sends in development (password resets, invitations,
reminders, digests) can be caught by **Mailpit**: open `http://localhost:8025`.
If another project already uses 8025, the script picks the next free port and
prints it (or ask for one with `MAILPIT_PORT=8026 ./bin/dev-setup.sh`). The
script points **Settings → Delivery** at it (server `mailpit`, port `1025`,
encryption *None*, From `logbook@localhost`) whenever no email server is
saved yet; one you saved yourself is left alone.
Try *Forgotten your password?* as `demo` and the email appears there.

The dev stack's own scheduler loop is off (`SCHEDULER_ENABLED: "false"` in
`docker-compose.dev.yml`), so it never holds `var/cache/scheduled-tasks.lock`
while a test runs the scheduler. To watch reminders go out, set it to
`"true"` and restart the stack, or run `php bin/run-scheduled-tasks.php -v` in the container.

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

The smoke test also runs from Git Bash on Windows with Windows `curl`: it
passes `curl` the uploaded file as a Windows path (`cygpath -m`), and leaves
every other path to Git Bash's own conversion. Keep it that way: don't set
`MSYS_NO_PATHCONV=1`, since `curl -o` then can't write to `/tmp`.

The smoke test runs its stack as compose project `logbook-smoke`. To run
two at once, give each its own name and ports, so neither removes the
other's containers and volumes:

```bash
COMPOSE_PROJECT_NAME=smoke-two APP_PORT=18180 PROXY_PORT=18181 bin/smoke-test.sh pgsql
```

## Tests and databases

The suite never touches `DB_*`. It uses `TEST_DB_*`, which defaults to a
throwaway SQLite file. A PostgreSQL or MySQL run must set `TEST_DB_NAME`
to a database of its own, never the one `DB_NAME` names: the bootstrap
refuses to start otherwise, so a run can't empty the app's data. CI runs lint, static analysis and the full suite
(migrate → full rollback → migrate → PHPUnit) against **PostgreSQL 17,
MySQL 8.4 and MariaDB 11.4** on PHP 8.4 and 8.5. It then builds the
multi-arch image and smoke-tests it at the root and at a subpath behind nginx.
A change that is red on any engine is not done.

## Front-end assets

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

## Translations

UI strings live in `translations/messages+intl-icu.<locale>.php` (ICU
MessageFormat; English is the default and fallback, German ships complete). To
add a language, copy the English file and translate the values; it is detected
automatically — see [docs/translations.md](docs/translations.md). Templates use
`{{ 'key'|trans }}` and never contain literal UI text; the test suite checks
keys, placeholders and templates.

## Docs and screenshots

The README is for someone deciding whether to try Logbook; the details live
in [`docs/`](docs/README.md), here and in [`CHANGELOG.md`](CHANGELOG.md)
(spec §10 *README and docs*). The docs are English Markdown, read on GitHub.

- A new guide opens with one line on what it covers and one on who it's
  for, has a table of contents when it has more than four sections, ends
  with a link back to [`docs/README.md`](docs/README.md), and is listed
  there.
- A new feature goes in [`docs/features.md`](docs/features.md), in its
  group, linking to its guide.
- `php bin/tools/check-docs.php` (run in CI) fails on a broken relative
  link, anchor or image in the README, this file or `docs/`, a guide
  missing from the index, or an image in `docs/images/` that nothing uses.

The screenshots in `docs/images/` are made from the demo seed by a
maintainer-only script, never from a real install. It needs Docker and Node:

```bash
npm ci && npx playwright install chromium
npm run screenshots                # builds the image, then captures every page
npm run screenshots -- --no-build  # reuse logbook:screenshots
```

It starts the image on SQLite with the demo seed, stores a kept *Ask
Logbook* conversation from Logbook's own tools (no model is called), and
captures each page at 1440×900 and 390×844, light and dark, as
`<page>-<desktop|phone>-<light|dark>.png`. It fails when an image is over
300 KB. To add a page, add it to the list at the top of
[`bin/screenshots.mjs`](bin/screenshots.mjs).

## Repository layout

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
bin/           CLI helpers (asset build, backup, API keys, dev router, wait-for-db, scheduler, screenshots, test scripts)
docs/          user guides (index in docs/README.md), screenshots in docs/images/, the OpenAPI file in docs/api/;
               build phases in docs/phases/
```

## Releasing

A release is a version bump, its changelog and a tag; CI builds and pushes
the multi-arch image for the tag.

1. Re-make the screenshots (`npm run screenshots`) and look over the
   changed images.
2. `VERSION`: the new version.
3. `CHANGELOG.md`: the release's section, with upgrade notes for anything
   beyond "pull and restart".
4. README: the version in the status line (it links to `CHANGELOG.md`;
   no feature list there).
5. `docs/features.md`: the release's new features, in their groups, each
   linking to its guide.
6. `ROADMAP.md`: the phase's row ✅.
7. Merge, then tag `vX.Y.Z` on `master` and publish the GitHub release
   from the changelog section.
