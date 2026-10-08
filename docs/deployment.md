# Deploying Logbook

Logbook runs two ways: as a **Docker image** with one persistent volume, or on
an **ordinary PHP 8.4 web server**. Both use the same environment variables
(see [`.env.example`](../.env.example) and `spec.md` §9).

- [Docker](#docker)
- [Bare PHP 8.4](#bare-php-84)
- [Running at a subpath / behind a reverse proxy](#subpath-and-reverse-proxies)
  — tested Caddy and Traefik recipes, and what Logbook trusts from a proxy:
  [reverse-proxies.md](reverse-proxies.md)
- Proxmox VE containers (Docker or native PHP): [proxmox-lxc.md](proxmox-lxc.md)
- [Installing on a phone (PWA)](#installing-on-a-phone-pwa)
- [Health check](#health-check)
- [Backups](#backups)
- [Running a public demo](#running-a-public-demo)
- [Upgrading](#upgrading)

Every environment variable, with its default, is listed in
[configuration.md](configuration.md).

---

## Docker

The image is PHP 8.4 + Apache, built for `linux/amd64` and `linux/arm64`
(Raspberry Pi 3/4/5 running 64-bit Raspberry Pi OS). 32-bit systems are not
supported: the migration tool requires 64-bit PHP. Everything the app writes lives under **`/data`**:
uploads (`/data/uploads`), safety and command-line backups (`/data/backups`),
and the database file when using SQLite. On start the entrypoint waits for the
database, applies pending migrations, then starts Apache.

### With PostgreSQL (default)

```bash
docker compose up -d          # http://localhost:8080
```

### With MySQL / MariaDB

```bash
docker compose -f docker-compose.mysql.yml up -d
```

### Zero-config (SQLite on the volume)

```bash
docker build -t logbook .
docker run -d --name logbook -p 8080:80 -v logbook_data:/data logbook
```

Both compose files work unedited. For a real deployment, put overrides in a
`.env` file next to the compose file — at minimum a strong `DB_PASSWORD`, and
ideally a `SESSION_SECRET` (it keys the hashes of session ids stored in the
database; changing it later signs everyone out):

```dotenv
DB_PASSWORD=a-long-random-password
SESSION_SECRET=output-of-openssl-rand-hex-32
APP_URL=https://garage.example.com
APP_TIMEZONE=Europe/London
```

The database containers are not published on host ports; only the app is.

On first visit Logbook asks you to **create the first account**, an admin
(first-run setup); until then every page redirects there. Admins invite
everyone else from Settings → Users ([users-and-sharing.md](users-and-sharing.md)). Do that straight after
deploying, before exposing the app publicly. Behind HTTPS, sessions use
`Secure` cookies automatically when `APP_URL` starts with `https://` (or set
`SESSION_SECURE=true`).

Failed sign-ins are logged at `notice` level, e.g.
`Failed sign-in for "admin" from 203.0.113.9`, so a tool such as fail2ban
can watch the log (`LOG_PATH`) and ban repeat offenders. Behind a reverse
proxy, make sure the web server logs/sees the real client address (Apache
`mod_remoteip`, nginx `real_ip`).

| Container variable | Default | Purpose |
|---|---|---|
| `MIGRATE_ON_START` | `true` | Apply pending migrations at start-up |
| `DB_WAIT_TIMEOUT` | `60` | Seconds to wait for the database before failing |
| `SCHEDULER_ENABLED` | `true` | Run scheduler passes inside the container |
| `SCHEDULER_INTERVAL` | `900` | Seconds between passes (also read by the app on every install) |

The container runs the scheduler itself (as `www-data`, every
`SCHEDULER_INTERVAL` seconds), so reminders go out without a host cron job.
Each pass is recorded on **Settings → Jobs**, labelled *Docker*. To run one
by hand:
`docker compose exec app setpriv --reuid=www-data --regid=www-data --init-groups php bin/run-scheduled-tasks.php -v`.
See [Background jobs](#background-jobs).

### Notifications

Reminders always show in the app. To also have them sent when they come due:

1. An admin sets up email in **Settings → Delivery** (the SMTP server, with
   *Send test email*), and there chooses **where members can send**: the
   internet only, the internet and your network (the default), or this
   server too.
2. Each person sets up their own channels in **Settings → Account →
   Notifications**: email to their confirmed address, ntfy, Gotify or a
   webhook, each with *Send test*.

Set `APP_URL` so the links in notifications and the calendar feed point at
the address people use:

```dotenv
APP_URL=https://garage.example.com
```

**Upgrading to v3.3.0:** `NTFY_URL`, `NTFY_TOKEN`, `GOTIFY_URL`,
`GOTIFY_TOKEN` and `GOTIFY_PRIORITY` are imported once into every admin's
own channels by the upgrade, then no longer read: keep them set (and
`SESSION_SECRET` unchanged) until the new version has started once, then
remove them. `WEBHOOK_URL` still works as the server's webhook but is
deprecated, and no longer follows redirects. Adding another kind of channel
is described in [notification-channels.md](notification-channels.md).

With email set up, people can also reset a forgotten password from the
sign-in page, and admins can email reset links and add users directly
([users-and-sharing.md](users-and-sharing.md#forgotten-passwords)). Set
`APP_URL` so the links in those emails point at the address people use.
`PASSWORD_RESET_ENABLED=false` turns the sign-in page's link off.

In development, `docker-compose.dev.yml` runs **Mailpit** and points the app
at it, so every email lands at `http://localhost:8025` instead of going
anywhere. It is never part of `docker-compose.yml` or
`docker-compose.mysql.yml`.

---

## Bare PHP 8.4

### Requirements

- **64-bit** PHP **8.4** (8.5 also works) with `intl`, `sodium`, `gd` (with
  JPEG, PNG and WebP), `exif`, `pdo`, and `pdo_pgsql` **or** `pdo_mysql`
  (`pdo_sqlite` for SQLite); Composer 2. `gd` and `exif` are required from
  2.8.0: every photo upload is turned upright and stripped of its EXIF
  (Debian/Ubuntu: `apt install php8.4-gd`; `exif` is usually built in).
- Optional: **Ghostscript** (`apt install ghostscript`) or Imagick, to read
  scanned PDFs when [reading receipts](ai.md#reading-receipts-and-documents).
- `zip` (`php-zip`) for backup and restore; everything else works without it.
- PostgreSQL 13+ or MySQL 8.0+ / MariaDB 10.6+ (or SQLite for a trial).
- Apache 2.4 with `mod_rewrite`, or nginx + php-fpm.

No Node.js is needed: built CSS/JS is committed in `public/assets`.

### Install

```bash
git clone <repo-url> /var/www/logbook && cd /var/www/logbook
composer install --no-dev --optimize-autoloader
cp .env.example .env              # then edit: DB_*, APP_URL, APP_TIMEZONE, …
vendor/bin/phinx migrate -e production
```

Make `var/` (cache, logs, SQLite, `var/backups`) and your `UPLOAD_PATH`
writable by the web server user, e.g. `chown -R www-data: var`. Keep
`UPLOAD_PATH` and `BACKUP_PATH` **outside** `public/`: vehicle photos and
attachments (receipts, invoices, certificates) are served only through the app
to the signed-in owner, and a backup contains everything. PHP's
`upload_max_filesize` must be at least `MAX_UPLOAD_MB` (default 10 MB) for
attachments and CSV imports, and at least `MAX_RESTORE_MB` (default 256 MB) to
restore a backup through the browser (larger ones restore with
`bin/backup.php`). A save takes up to 10 attachments at once, so
`post_max_size` must also allow 10 × `MAX_UPLOAD_MB` (100 MB by default), and
`max_file_uploads` should be at least 10 (PHP's default is 20): PHP drops files
past it without an error, so Logbook takes at most that many per save. The
Docker image sets `upload_max_filesize = 256M`, `post_max_size = 260M` and
`max_file_uploads = 20`.

PHP must support **Argon2id** password hashing (`PASSWORD_ARGON2ID`), which
distribution and official Docker builds of PHP 8.4 include; check with
`php -r 'var_dump(defined("PASSWORD_ARGON2ID"));'`.

The **web root must be `public/`** — never the project directory. Nothing else
is meant to be reachable over HTTP.

### Apache

`public/.htaccess` already routes requests to the front controller.

Own virtual host:

```apache
<VirtualHost *:80>
    ServerName garage.example.com
    DocumentRoot /var/www/logbook/public

    <Directory /var/www/logbook/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

Inside an existing site at `/logbook` (set `APP_BASE_PATH=/logbook`):

```apache
Alias /logbook /var/www/logbook/public
<Directory /var/www/logbook/public>
    AllowOverride All
    Require all granted
    FallbackResource /logbook/index.php
</Directory>
```

### nginx + php-fpm

Own server block:

```nginx
server {
    listen 80;
    server_name garage.example.com;
    root /var/www/logbook/public;
    index index.php;

    location / {
        try_files $uri /index.php$is_args$args;
    }

    location = /index.php {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
    }

    # Only the front controller is executable.
    location ~ \.php$ {
        return 404;
    }
}
```

Inside an existing server at `/logbook` (set `APP_BASE_PATH=/logbook`):

```nginx
location /logbook/ {
    alias /var/www/logbook/public/;
    try_files $uri @logbook;
}

location @logbook {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME /var/www/logbook/public/index.php;
    fastcgi_pass unix:/run/php/php8.4-fpm.sock;
}

location = /logbook {
    return 301 /logbook/;
}
```

### Scheduled tasks (cron)

Reminders are brought up to date and sent through the notification channels
(see [Notifications](#notifications) above: channels are set up in the app) by a runner that should be
called every 15 minutes, as the web server user. In `/etc/cron.d/logbook`:

```cron
*/15 * * * *  www-data  cd /var/www/logbook && php bin/run-scheduled-tasks.php
```

(or `crontab -u www-data -e` with the same line minus the user field). It is
quiet unless something fails — cron mails any output — and logs a summary
line to `LOG_PATH` on every run; add `-v` to print it. A lock file
(`var/cache/scheduled-tasks.lock`) stops runs overlapping, and each reminder is sent
only once per status, so running it more often is harmless.

No cron on your host? See [Background jobs](#background-jobs): Logbook can
run its jobs on page visits, or when an external service calls a secret URL.

The same pass sends entry webhooks ([API guide](api.md#webhooks)), so a
change reaches a webhook with the next pass, and a failed call is retried
no sooner than its next retry time (1 minute, 5 minutes, 30 minutes, 2
hours, 6 hours) and no later than the pass after it. To have them arrive
sooner, run passes more often: every minute in cron (`* * * * *`), or
`SCHEDULER_INTERVAL=60` in Docker. Jobs that aren't due skip, so this
costs little.

### Trying it locally without a web server

```bash
composer start                    # PHP built-in server on http://localhost:8090
```

For development only; it honours `APP_BASE_PATH` too. It runs four
workers (`PHP_CLI_SERVER_WORKERS=4`), so *Ask Logbook*'s progress polls are
answered while a question runs; on Windows, where the built-in server has
one worker, the progress line waits until the answer.

---

## Subpath and reverse proxies

**[reverse-proxies.md](reverse-proxies.md)** has tested Caddy and Traefik
recipes (root and subpath, automatic HTTPS), what to set for each proxy,
what Logbook trusts from one, and the common failures. Running on Proxmox?
See [proxmox-lxc.md](proxmox-lxc.md).

Set `APP_BASE_PATH` to the public prefix, e.g. `/logbook`. Every link and asset
URL the app generates then carries the prefix, and deep links survive a hard
refresh.

The proxy may either **forward the prefix** (`/logbook/vehicles` reaches the
app unchanged) or **strip it** (`/vehicles` reaches the app); Logbook accepts
both. Forwarding is recommended. One caveat: don't pick a base path equal to a
top-level app route (such as `/health`).

nginx in front of the Docker container ([full example](../docker/nginx/subpath-example.conf)):

```nginx
location /logbook/ {
    proxy_pass http://127.0.0.1:8080;          # no trailing slash: prefix forwarded
    proxy_set_header Host              $host;
    proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    client_max_body_size 260m;                  # restoring a backup (MAX_RESTORE_MB)
}
```

Apache (`mod_proxy_http`):

```apache
ProxyPass        /logbook/ http://127.0.0.1:8080/logbook/
ProxyPassReverse /logbook/ http://127.0.0.1:8080/logbook/
RequestHeader set X-Forwarded-Proto "https"
```

Caddy:

```caddy
garage.example.com {
    handle /logbook/* {
        reverse_proxy 127.0.0.1:8080
    }
}
```

Behind HTTPS, set `APP_URL=https://…` so cookies are marked `Secure`.

To check a set-up, open **`<your URL>/diagnostics/deep/link`** and press F5.
The page should reload.

The web app manifest (`<base>/manifest.webmanifest`) and service worker
(`<base>/sw.js`) are served by the app itself with the base path built in, so
a subpath install is installable and works offline like one at the root; the
proxy needs no extra rules. Browsers only allow service workers over HTTPS (or
on `localhost`).

### The REST API behind a proxy

The API ([api.md](api.md)) lives under `<base>/api/v1` and needs no extra
proxy rules, with two things to know:

- **The `Authorization` header must reach PHP.** Proxies pass it on. Apache
  with mod_php (the Docker image) does too; Apache with **PHP-FPM** drops it
  unless told otherwise. `public/.htaccess` and the image's vhost hand it over
  (`RewriteRule ^ - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]`); with
  `FallbackResource` and no `.htaccess`, add `CGIPassAuth On` to the
  `<Directory>` block. nginx's `fastcgi_params` passes it already. If every
  call answers 401 `missing_key` although the key is sent, this is why.
- **Failed keys are counted per client address** (20 in 10 minutes blocks that
  address for 10 minutes). Behind a proxy every request comes from the proxy's
  address, so a client guessing keys also delays good keys until the block
  ends. Keep keys in the tools that use them and they never fail.

### Ask Logbook behind a proxy

A question to *Ask Logbook* ([ai.md](ai.md#ask-logbook)) is one request
that lasts as long as the model takes: with a slow local model and
several lookups, that can be a few minutes (Logbook stops starting new
model calls after 240 seconds). Many proxies give up on a request after
60 seconds. Logbook keeps working when they do: the page polls for
progress and opens the answer once it is saved. Without JavaScript the
browser shows the proxy's timeout page instead, so for slow models raise
the timeouts for `<base>/insights/questions` (until v3.4, `<base>/ask`):

- **nginx** in front of Logbook: `proxy_read_timeout 600s;` (and with
  php-fpm, `fastcgi_read_timeout 600s;`) in a `location <base>/insights/questions` block,
  or for the whole site.
- **php-fpm:** `request_terminate_timeout` in the pool must be 0 (the
  default) or at least 600 s. Logbook raises PHP's own `max_execution_time`
  for the request.
- **Apache** (the Docker image): its `Timeout` defaults to 300 seconds,
  which is enough; behind another proxy, raise that proxy's timeout.
- **Cloudflare** and similar CDNs cut requests at about 100 seconds on
  their free plans; the page still finds the answer by polling.

---

## Installing on a phone (PWA)

Logbook is a Progressive Web App. Open it in the phone's browser over HTTPS,
sign in, then choose **Install app** (Chrome/Edge on Android) or **Share → Add
to Home Screen** (Safari on iOS). It opens full-screen like an app, with a
*Log fill-up* shortcut on Android.

Offline, the fill-up form still opens (once it has been visited online, or the
vehicle picker has been shown). A fill-up saved without a connection is kept on
the phone — the page says so — and sent automatically when the connection
returns. If the server rejects it (say, a typo in the odometer), it stays on
the phone and the fill-up page offers **Review**. Attachments cannot be added
offline. Other pages show an "offline" notice until the connection is back.

---

## Background jobs

Logbook's background work is a set of **jobs**, run in **passes**:

| Job | Runs | Does |
|---|---|---|
| Reminders | every pass | brings reminders up to date and sends those that became due |
| Monthly digest | every pass | each person's monthly summary, on their first pass of a month |
| Cleanup | hourly | the AI usage log, old drafts and scans, Ask threads, invitation links closed over 90 days ago, old job runs |
| Backup | off, daily or weekly | a backup into `BACKUP_PATH` (see [Scheduled backups](#scheduled-backups)) |
| Update check | daily, once switched on | asks GitHub for the latest release (see [Update check](#update-check)) |

**Settings → Jobs** (admins) shows when each job last ran and how, when it
runs next, the recent runs, and **Run now** for each. A run's page has its
full output (secrets are masked as `••••`). Locks keep a job from ever
running twice at once, whatever starts it; a run that finds its job busy is
recorded as *Skipped: already running*, with a link to the run holding it.

From the command line, as the web server user:

```bash
php bin/run-job.php --list        # the jobs and their schedules
php bin/run-job.php reminders     # run one now; prints its output
```

### How passes start

Use any one of these, or several; they never overlap.

- **Docker:** the container runs a pass every `SCHEDULER_INTERVAL` seconds.
- **Cron** (bare PHP): see [Scheduled tasks (cron)](#scheduled-tasks-cron).
  Settings → Jobs shows the exact line for your install.
- **On page visits** (Settings → Jobs → *How jobs run*, off by default):
  when a pass is due, the next signed-in page quietly asks the server to run
  one. Nobody waits for it, but nothing runs while nobody uses Logbook, so
  reminders may be late on quiet days.
- **External URL** (off by default): Logbook gives you a secret address,
  `https://your-logbook/cron/<token>`, shown once. Have a service that calls
  a URL on a schedule (cron-job.org, Uptime Kuma, a router's scheduler) call
  it every 15 minutes. It runs only the jobs that are due, answers `200`
  with a one-line summary, `429` if called again within a minute, and `404`
  for a wrong token. *Make a new URL* replaces the token at once. The URL
  works only where `SESSION_SECRET` is unchanged.

### Checking that jobs run

If no pass has finished for twice `SCHEDULER_INTERVAL` (30 minutes by
default), Settings → Jobs and the dashboard tell admins that reminders aren't
being sent automatically, with the three ways to fix it. The dashboard notice
can be dismissed for 24 hours. `/health` also reports the last pass (see
[Health check](#health-check)).

When a job fails twice in a row, admins see a notice on the dashboard and are
sent one notification through their own channels (Settings → Reminders →
Notifications) until the job works again.

### Scheduled backups

On the *Backup* job's row in Settings → Jobs: **Off** (the default),
**Daily** or **Weekly**, and how many to keep (7 by default, 1–60). Files are
written to `BACKUP_PATH` as `logbook-scheduled-YYYYMMDD-HHMMSS.zip` (UTC), and
only files with that name are ever deleted: your own backups and pre-restore
backups are left alone. Settings → Backup lists them to download. They are on
the same server as the data, so still copy them somewhere else.

## Health check

`GET /health` (under the base path) returns JSON and never exposes details:

```json
{"status":"ok","checks":{"app":"ok","database":"ok"},"scheduler":{"last_pass":"2026-10-02T09:15:00Z","stale":false}}
```

Status **200** when everything is reachable, **503** otherwise; the cause is
written to the log. The Docker image uses it as its `HEALTHCHECK`.
`scheduler` gives the last scheduler pass (UTC) and whether it is overdue
(`stale`), for monitoring; it never changes the status code.

---

## Backups

Everything Logbook knows is in **the database** and **the upload directory**
(`UPLOAD_PATH`). Logbook can back both up into a single ZIP file, and restore
it — onto the same or a different database engine.

### From the app

**Settings → Backup and restore → Download backup** gives you
`logbook-backup-<date>-<time>.zip`. Keep copies somewhere other than the server.

To restore, choose the file under *Restore a backup*. Logbook checks the whole
archive first and shows what it contains; nothing changes until you tick
**Replace all data with this backup** and press *Restore*. Then it:

1. saves a **safety backup** of the current data to `BACKUP_PATH`
   (`pre-restore-<date>-<time>.zip`; Docker: `/data/backups`),
2. replaces every table in one transaction (an error leaves the old data),
3. replaces the uploaded photos and attachments,
4. signs everyone out. Sign in with the account and password **from the backup**.

A backup can only be restored by the Logbook version whose database it matches
(the version is shown on the confirmation page). To restore an older backup,
install that version, restore, then upgrade as usual.

### From the command line (and cron)

```bash
php bin/backup.php create                      # → BACKUP_PATH/logbook-backup-….zip
php bin/backup.php create /mnt/nas/logbook.zip
php bin/backup.php check  logbook-backup.zip   # validate only
php bin/backup.php restore logbook-backup.zip --yes
```

Run it as the web server user so files keep the right owner. Docker:
`docker compose exec -u www-data app php bin/backup.php create`. A nightly
backup from the host's cron:

```cron
30 3 * * *  www-data  cd /var/www/logbook && php bin/backup.php create
```

Old files in `BACKUP_PATH` are not deleted automatically; prune them from the
same cron job, e.g. `find /var/www/logbook/var/backups -name '*.zip' -mtime +30 -delete`.
Or let Logbook do both: [Scheduled backups](#scheduled-backups) keep only the
newest few of their own files.

### What else to keep

- `.env` (or your compose overrides) — in particular **`SESSION_SECRET`**: a
  restored database with a different secret still works, but everyone signs in
  again, calendar feed links must be re-created and API keys stop working
  (create new ones in Settings → API keys).
- Your reverse-proxy and TLS configuration.

### Moving to another database engine

Backups are engine-independent: make a backup on the old install (for example
the SQLite quick start), start a fresh install on PostgreSQL or MySQL (same
Logbook version), and restore the backup there — with `bin/backup.php restore`,
or in the browser after creating a throwaway account at first-run setup (the
restore replaces it with your real one). Classic dumps (`pg_dump`, `mysqldump`) of the database plus a copy of
`UPLOAD_PATH` also remain a perfectly good backup.

---

## Update check

Logbook can tell admins when a new version is out. It is **off** until an
admin switches it on in **Settings → Updates** (or ticks *Tell me when a new
version is out* at first-run setup), because it is the one request Logbook
makes to a third party without being set up to:

- Once a day, at a minute chosen at random for your install, the
  `update_check` job asks `api.github.com` for the latest release of
  `UPDATE_CHECK_REPO` (default `gwpreston16/Logbook`). Nothing about your
  data is sent; GitHub sees your server's address and the app's version.
  Only stable releases count.
- When a newer version is out, admins see a banner on the dashboard with
  the release notes, this section's steps for that version, and the
  command for Docker or the reminder to back up first on bare PHP.
  *Dismiss* hides it for that version; the next release shows it again.
  *Show update banner* off keeps the result on Settings → Updates only.
- *Check now* on that page runs the check at once. If GitHub rate-limits
  the check, nothing is sent again until the limit is over.
- Nothing is ever downloaded or installed: upgrading stays the steps below.

`UPDATE_CHECK_ALLOWED=false` removes the option entirely, for installs that
must never call out. A fork sets `UPDATE_CHECK_REPO` to its own repository.

## Running a public demo

`DEMO_MODE=true` with a `DEMO_PASSWORD` turns an **empty** installation into a
demo that puts itself back to its sample data every day, and keeps a stranger
away from everything that could hurt the server or its owner: users, backups,
jobs, API keys, AI, outbound mail and requests, and file uploads. It never
touches a database that holds real data. How to run one, what visitors can and
cannot do, and how to stop being a demo are in [demo-mode.md](demo-mode.md).

## Upgrading

1. Read [CHANGELOG.md](../CHANGELOG.md), especially each release's **Upgrade
   notes** between your version and the new one (new variables, anything
   beyond "pull and restart").
2. Back up: `php bin/backup.php create` (Docker:
   `docker compose exec -u www-data app php bin/backup.php create`), and copy
   the file off the server.
3. Upgrade:
   - Docker: pull or rebuild the image, then `docker compose up -d`. The
     entrypoint applies new migrations before Apache starts.
   - Bare PHP: `git pull && composer install --no-dev -o && vendor/bin/phinx migrate -e production`.
     If PHP runs with opcache, reload php-fpm/Apache afterwards.
4. Check `<your URL>/health` and sign in.

Database changes always ship as reversible migrations. To go back, roll the
database back **with the new code still in place**, then switch to the old
code or image: the old code doesn't have the new migration files, so its
rollback finds nothing to undo and leaves the newer database behind (backups
it then makes won't restore anywhere).

- Bare PHP: `vendor/bin/phinx rollback -e production -t <version>` (the
  version before the upgrade, from `vendor/bin/phinx status`), then restore
  the previous code.
- Docker: `docker compose exec -u www-data app vendor/bin/phinx rollback -e production -t <version>`
  in the new container, then switch to the previous image.

Or start an empty database with the previous version and restore the backup
from step 2. A rollback drops what only the newer version stores (each
release's upgrade notes say what).

New environment variables always have a default that keeps the old behaviour,
so an existing `.env` keeps working.
