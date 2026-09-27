# Deploying Logbook

Logbook runs two ways: as a **Docker image** with one persistent volume, or on
an **ordinary PHP 8.4 web server**. Both use the same environment variables
(see [`.env.example`](../.env.example) and `spec.md` §9).

- [Docker](#docker)
- [Bare PHP 8.4](#bare-php-84)
- [Running at a subpath / behind a reverse proxy](#subpath-and-reverse-proxies)
- [Health check](#health-check)
- [Backups](#backups)
- [Upgrading](#upgrading)

---

## Docker

The image is PHP 8.4 + Apache, built for `linux/amd64` and `linux/arm64`
(Raspberry Pi 3/4/5 running 64-bit Raspberry Pi OS). 32-bit systems are not
supported: the migration tool requires 64-bit PHP. Everything the app writes lives under **`/data`**
(uploads, plus the database file when using SQLite). On start the entrypoint
waits for the database, applies pending migrations, then starts Apache.

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

---

## Bare PHP 8.4

### Requirements

- **64-bit** PHP **8.4** (8.5 also works) with `intl`, `pdo`, and `pdo_pgsql` **or**
  `pdo_mysql` (`pdo_sqlite` for SQLite); Composer 2.
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

Make `var/` (cache, logs, SQLite) and your `UPLOAD_PATH` writable by the web
server user, e.g. `chown -R www-data: var`. Keep `UPLOAD_PATH` **outside**
`public/`: vehicle photos (and, later, receipts) are served only through the
app to the signed-in owner. PHP's `upload_max_filesize` and `post_max_size`
must be at least `MAX_UPLOAD_MB` (default 10 MB); the Docker image sets 16M/20M.

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

Reminders and notifications (Phase 4) are processed by a runner that should be
called every 15 minutes. It is a harmless no-op until then, so you can install
the cron entry now:

```cron
*/15 * * * *  www-data  cd /var/www/logbook && php bin/run-scheduled-tasks.php
```

### Trying it locally without a web server

```bash
composer start                    # PHP built-in server on http://localhost:8080
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
    client_max_body_size 20m;
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

Back up **the database** and **the upload directory**:

- Docker: the `logbook_data` volume (`/data`), plus the database volume
  (`postgres_data` / `mysql_data`) — or better, a logical dump:
  `docker compose exec db pg_dump -U logbook logbook > logbook.sql`.
- Bare PHP: `UPLOAD_PATH`, your database (`pg_dump` / `mysqldump`), and `.env`.

In-app backup and restore arrives in Phase 6.

---

## Upgrading

1. Read [CHANGELOG.md](../CHANGELOG.md).
2. Back up (above).
3. Docker: pull or rebuild the image, then `docker compose up -d`. Migrations
   run automatically.
   Bare PHP: `git pull && composer install --no-dev -o && vendor/bin/phinx migrate -e production`.

Every migration can be rolled back (`vendor/bin/phinx rollback -e production`).
