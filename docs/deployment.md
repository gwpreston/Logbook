# Deploying Logbook

Logbook runs two ways: as a **Docker image** with one persistent volume, or on
an **ordinary PHP 8.4 web server**. Both use the same environment variables
(see [`.env.example`](../.env.example) and `spec.md` §9).

- [Docker](#docker)
- [Bare PHP 8.4](#bare-php-84)
- [Running at a subpath / behind a reverse proxy](#subpath-and-reverse-proxies)
- [Installing on a phone (PWA)](#installing-on-a-phone-pwa)
- [Health check](#health-check)
- [Backups](#backups)
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

On first visit Logbook asks you to **create the owner account** (first-run
setup); until then every page redirects there. Do that straight after
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
| `SCHEDULER_ENABLED` | `true` | Run the reminder/notification task inside the container |
| `SCHEDULER_INTERVAL` | `900` | Seconds between scheduled-task runs |

The container runs the scheduled task itself (as `www-data`, every
`SCHEDULER_INTERVAL` seconds), so reminders go out without a host cron job.
Each run logs a line such as `Scheduled tasks: 1 account(s) checked, 2
reminder(s) sent, …`. To run it by hand:
`docker compose exec app setpriv --reuid=www-data --regid=www-data --init-groups php bin/run-scheduled-tasks.php -v`.

### Notifications

Reminders always show in the app. To also have them sent when they come due,
set one or more channels in the `.env` next to the compose file, then choose
which to use in **Settings → Reminders** (where *Send a test* checks them):

```dotenv
APP_URL=https://garage.example.com        # used for links in notifications and the calendar feed
# Email (SMTP)
MAIL_HOST=smtp.example.com
MAIL_USERNAME=logbook@example.com
MAIL_PASSWORD=app-password
MAIL_FROM="Logbook <logbook@example.com>"
MAIL_TO=you@example.com
# ntfy
NTFY_URL=https://ntfy.sh/a-long-unguessable-topic
# Gotify
GOTIFY_URL=https://gotify.example.com
GOTIFY_TOKEN=AbCdEf123
# Any JSON webhook (Home Assistant, n8n, …)
WEBHOOK_URL=https://ha.example.com/api/webhook/logbook
```

All variables are listed in `.env.example`; adding another kind of channel is
described in [notification-channels.md](notification-channels.md).

---

## Bare PHP 8.4

### Requirements

- **64-bit** PHP **8.4** (8.5 also works) with `intl`, `pdo`, and `pdo_pgsql` **or**
  `pdo_mysql` (`pdo_sqlite` for SQLite); Composer 2.
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
(see [Notifications](#notifications) above; the same `MAIL_*`, `NTFY_*`,
`GOTIFY_*` and `WEBHOOK_URL` variables go in `.env`) by a runner that should be
called every 15 minutes, as the web server user. In `/etc/cron.d/logbook`:

```cron
*/15 * * * *  www-data  cd /var/www/logbook && php bin/run-scheduled-tasks.php
```

(or `crontab -u www-data -e` with the same line minus the user field). It is
quiet unless something fails — cron mails any output — and logs a summary
line to `LOG_PATH` on every run; add `-v` to print it. A lock file
(`var/cache/scheduled-tasks.lock`) stops runs overlapping, and each reminder is sent
only once per status, so running it more often is harmless.

### Trying it locally without a web server

```bash
composer start                    # PHP built-in server on http://localhost:8090
```

For development only; it honours `APP_BASE_PATH` too.

---

## Subpath and reverse proxies

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

## Health check

`GET /health` (under the base path) returns JSON and never exposes details:

```json
{"status":"ok","checks":{"app":"ok","database":"ok"}}
```

Status **200** when everything is reachable, **503** otherwise; the cause is
written to the log. The Docker image uses it as its `HEALTHCHECK`.

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

Database changes always ship as reversible migrations. To go back: restore the
previous code, run `vendor/bin/phinx rollback -e production -t <version>`
(the version before the upgrade, from `vendor/bin/phinx status`), or restore
the backup from step 2 with the previous version.

New environment variables always have a default that keeps the old behaviour,
so an existing `.env` keeps working.
