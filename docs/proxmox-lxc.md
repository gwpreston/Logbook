# Logbook on Proxmox VE (LXC)

Two ways to run Logbook in a Proxmox container, each from a new container to
a backed-up, updatable install:

1. **[Docker in an unprivileged container](#route-1-docker-in-an-unprivileged-container)**:
   the same image and compose file as the README's quick start. Start here
   unless you have a reason not to.
2. **[PHP 8.4 natively](#route-2-php-84-natively)**: Debian's own PHP,
   nginx and either SQLite or PostgreSQL in the container. Lighter, with no
   Docker inside a container.

> **Tested on:** *not yet.* This guide was written on 2026-10-06 from the
> current Proxmox VE, Debian and Docker documentation. The Proxmox VE
> version, template and date it was first run on will be written here.

Commands starting with `pct` or `pveam` run in a shell **on the Proxmox
host** (the web UI's *Shell*, or SSH). All others run **inside the
container**, as root (`pct enter <id>`). Nothing here pipes a download into a
shell. Replace `200` with a free container id throughout.

- [Before you start](#before-you-start)
- [Route 1: Docker in an unprivileged container](#route-1-docker-in-an-unprivileged-container)
- [Route 2: PHP 8.4 natively](#route-2-php-84-natively)
- [Putting it behind your proxy](#putting-it-behind-your-proxy)
- [Backups](#backups)
- [Updating](#updating)
- [Logs and a shell](#logs-and-a-shell)

---

## Before you start

**Size.** For one household:

| | Docker route | Native route |
|---|---|---|
| Disk | 16 GB (the image is built in the container) | 8 GB |
| Memory | 2048 MB, swap 512 MB | 1024 MB, swap 512 MB |
| Cores | 2 | 1–2 |

Uploads (receipts, photos) and backups grow from there. Proxmox can grow
the disk later (*Resources → Root disk → Resize*).

**A template.** Debian 13 (trixie) ships PHP 8.4, so it suits both routes.
On the host:

```bash
pveam update
pveam available --section system | grep debian-13
pveam download local debian-13-standard_13.1-2_amd64.tar.zst   # the name the list shows
```

Use the exact file name `pveam available` printed; the version part changes.
Proxmox VE 9 offers Debian 13 templates. On Proxmox VE 8 the list may only
offer Debian 12, which is fine for the Docker route; for the native route see
[PHP on Debian 12](#php-on-debian-12).

---

## Route 1: Docker in an unprivileged container

**A caveat first.** Proxmox's own documentation recommends a virtual
machine for application containers: *"for use cases demanding maximum
isolation and the ability to live-migrate, nesting containers inside a
Proxmox QEMU VM remains a recommended practice"*
([Proxmox VE: Linux Container](https://pve.proxmox.com/wiki/Linux_Container)).
Docker in an LXC works, and many people run it, but a Proxmox or kernel
upgrade can break it and live migration isn't possible. If you'd rather
follow Proxmox's advice, make a Debian VM and follow the README's quick start
inside it; the rest of this route (backups, updates) still applies.

### 1. Create the container

On the host:

```bash
pct create 200 local:vztmpl/debian-13-standard_13.1-2_amd64.tar.zst \
  --hostname logbook --unprivileged 1 --features nesting=1,keyctl=1 \
  --cores 2 --memory 2048 --swap 512 --rootfs local-lvm:16 \
  --net0 name=eth0,bridge=vmbr0,ip=dhcp --onboot 1
pct start 200
pct enter 200
```

- `nesting=1` lets Docker (and systemd) create their own namespaces;
  `keyctl=1` lets an unprivileged container use the `keyctl()` call Docker
  needs. Both are on *Options → Features* in the web UI.
- `--onboot 1` starts it with the host (*Options → Start at boot*).
- `local-lvm` and `vmbr0` are the defaults of a new Proxmox install; use your
  own storage and bridge names. A fixed address
  (`ip=192.168.1.50/24,gw=192.168.1.1`) is easier to put behind a proxy than
  DHCP.

The web UI's *Create CT* does the same: leave *Unprivileged container*
ticked, then tick *keyctl* and *nesting* under *Options → Features* before
starting it.

### 2. Install Docker and the Compose plugin

Inside the container, from Docker's own apt repository
([Docker: install on Debian](https://docs.docker.com/engine/install/debian/)):

```bash
apt update && apt full-upgrade -y
apt install -y ca-certificates curl git
install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/debian/gpg -o /etc/apt/keyrings/docker.asc
chmod a+r /etc/apt/keyrings/docker.asc
tee /etc/apt/sources.list.d/docker.sources <<EOF
Types: deb
URIs: https://download.docker.com/linux/debian
Suites: $(. /etc/os-release && echo "$VERSION_CODENAME")
Components: stable
Architectures: $(dpkg --print-architecture)
Signed-By: /etc/apt/keyrings/docker.asc
EOF
apt update
apt install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
docker run --rm hello-world
```

`docker info | grep 'Storage Driver'` should say `overlay2` (or
`overlayfs`). If it says `vfs`, Docker works but every image takes its full
size again for each layer: put the container's disk on LVM-thin or a
directory storage, or use a VM.

### 3. Install Logbook

```bash
git clone https://github.com/gwpreston16/Logbook.git /opt/logbook
cd /opt/logbook
git checkout v3.2.0          # or the newest release
cp .env.example .env
```

Edit `.env` (`nano .env`). The compose file sets the database variables
itself; what matters here:

```dotenv
DB_PASSWORD=a-long-random-password
SESSION_SECRET=paste-the-output-of: openssl rand -hex 32
APP_URL=https://logbook.example.com
APP_TIMEZONE=Europe/London
```

`APP_URL` is the address people will use, through your proxy
([below](#putting-it-behind-your-proxy)). Then:

```bash
docker compose up -d
docker compose ps            # app and db "healthy" after a minute or two
```

The first start builds the image, which takes a few minutes. Open
`http://<container address>:8080/` and create the first account straight
away (until then, anyone who can reach the page can).

The data lives in Docker volumes on the container's own disk
(`/var/lib/docker/volumes/logbook_*`), so a Proxmox backup of the container
includes it.

The container runs Logbook's scheduled tasks itself: no cron is needed.

---

## Route 2: PHP 8.4 natively

### 1. Create the container

The same as route 1 without `keyctl` and with less memory and disk
(`nesting=1` is the web UI's default for unprivileged containers; Debian's
systemd uses it):

```bash
pct create 200 local:vztmpl/debian-13-standard_13.1-2_amd64.tar.zst \
  --hostname logbook --unprivileged 1 --features nesting=1 \
  --cores 2 --memory 1024 --swap 512 --rootfs local-lvm:8 \
  --net0 name=eth0,bridge=vmbr0,ip=dhcp --onboot 1
pct start 200
pct enter 200
```

### 2. PHP 8.4, nginx and Composer

Debian 13 ships PHP 8.4 itself:

```bash
apt update && apt full-upgrade -y
apt install -y git unzip cron nginx composer \
  php8.4-fpm php8.4-cli php8.4-intl php8.4-gd php8.4-mbstring \
  php8.4-xml php8.4-zip php8.4-curl
php -v                       # PHP 8.4.x
php -r 'var_dump(defined("PASSWORD_ARGON2ID"));'   # bool(true)
php -m | grep -E 'exif|sodium'                     # both listed (they're in php8.4-common)
```

Then one database driver:

- **SQLite**, for one household: `apt install -y php8.4-sqlite3`. One file,
  nothing else to run or back up.
- **PostgreSQL** in the same container, if you'd rather: `apt install -y
  postgresql php8.4-pgsql`, then:

  ```bash
  runuser -u postgres -- psql -c "CREATE ROLE logbook LOGIN PASSWORD 'a-long-random-password'"
  runuser -u postgres -- createdb -O logbook logbook
  ```

Optional: `apt install -y ghostscript` to read scanned PDFs
([ai.md](ai.md#reading-receipts-and-documents)).

#### PHP on Debian 12

Debian 12 (bookworm) ships PHP 8.2, which Logbook can't use. Use a Debian 13
template if your Proxmox offers one. If not, PHP 8.4 for Debian 12 comes from
Ondřej Surý's repository, [packages.sury.org/php](https://packages.sury.org/php/):
download its signing key and add its source as its README says, then the
`apt install` lines above work unchanged. That is a third-party repository
you then rely on for PHP security updates.

### 3. Install Logbook

```bash
git clone https://github.com/gwpreston16/Logbook.git /var/www/logbook
cd /var/www/logbook
git checkout v3.2.0          # or the newest release
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader
cp .env.example .env
```

In `.env`, for SQLite:

```dotenv
APP_URL=https://logbook.example.com
APP_TIMEZONE=Europe/London
DB_DRIVER=sqlite
DB_NAME=var/logbook.sqlite
SESSION_SECRET=paste-the-output-of: openssl rand -hex 32
LOG_PATH=var/log/logbook.log
```

or for PostgreSQL, `DB_DRIVER=pgsql`, `DB_HOST=127.0.0.1`, `DB_NAME=logbook`,
`DB_USER=logbook` and the password you chose. Uploads and backups stay in
`var/uploads` and `var/backups` (outside the web root). Then:

```bash
mkdir -p var/log var/uploads var/backups
chown -R www-data:www-data var
runuser -u www-data -- vendor/bin/phinx migrate -e production
chown root:www-data .env && chmod 640 .env
```

PHP's upload limits must allow backups and attachments
([deployment.md](deployment.md#install)). In
`/etc/php/8.4/fpm/conf.d/99-logbook.ini`:

```ini
upload_max_filesize = 256M
post_max_size = 260M
max_file_uploads = 20
```

### 4. nginx

`/etc/nginx/sites-available/logbook`, the server block from
[deployment.md → nginx + php-fpm](deployment.md#nginx--php-fpm) with two
changes: `server_name _;` (the proxy in front decides the name) and
`client_max_body_size 260m;` inside the `server` block. Then:

```bash
ln -s /etc/nginx/sites-available/logbook /etc/nginx/sites-enabled/logbook
rm /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx php8.4-fpm
```

Open `http://<container address>/` and create the first account straight
away.

### 5. Scheduled tasks

Reminders, notifications and scheduled backups need the runner every 15
minutes ([deployment.md](deployment.md#scheduled-tasks-cron)). In
`/etc/cron.d/logbook`:

```cron
*/15 * * * *  www-data  cd /var/www/logbook && php bin/run-scheduled-tasks.php
```

**Settings → Jobs** shows each pass, labelled *cron*.

---

## Putting it behind your proxy

Logbook in the container speaks plain HTTP: on port 8080 (Docker route) or 80
(native). HTTPS comes from a reverse proxy in front, which can be:

- **another container** already running your proxy (nginx Proxy Manager,
  Caddy, Traefik): point it at `http://<container address>:8080` (or `:80`);
- **the Proxmox host itself**: possible, but keep the host for Proxmox;
- **inside the same container**: on the Docker route, use the Caddy or
  Traefik example as it is ([reverse-proxies.md](reverse-proxies.md#caddy)),
  which also keeps the app's own port closed.

Whichever you choose, set `APP_URL` (and `APP_BASE_PATH` at a subpath) as
[reverse-proxies.md](reverse-proxies.md#what-to-set-on-logbook) says, and
restart (`docker compose up -d`, or nothing on the native route: `.env` is
read on each request). Logbook sees every request coming from the proxy's
address; the guide says what that means.

With the proxy elsewhere, only it should reach the container's port. Proxmox's
firewall can allow it: *Container → Firewall → Add*, source the proxy's
address, destination port 8080 (or 80), then *Options → Firewall: Yes*.

---

## Backups

Keep **both** of these:

1. **A Proxmox backup of the container** (*Datacenter → Backup → Add*, or
   `vzdump 200 --mode snapshot --storage <backup storage>` on the host). It
   brings back the whole container in one step: the system, Docker or PHP,
   the configuration and the data. Snapshot mode needs storage that can take
   snapshots (LVM-thin, ZFS, Ceph); otherwise use `--mode suspend` or
   `stop`. Proxmox Backup Server, if you have one, is the best target.
2. **Logbook's own backup** (one ZIP of the database and uploads,
   [deployment.md → Backups](deployment.md#backups)). It restores onto any
   install, version for version, and onto another database engine, without
   Proxmox; it is the one to keep off the host. Turn on **scheduled backups**
   on Settings → Backup and restore, or by hand:

   ```bash
   # Docker route
   cd /opt/logbook && docker compose exec -u www-data app php bin/backup.php create
   # Native route
   cd /var/www/logbook && runuser -u www-data -- php bin/backup.php create
   ```

   They land in `/data/backups` in the app container (Docker) or
   `var/backups` (native). Copy them off the container.

Why both: a container backup taken while the database writes can need the
database to recover on restore, and it only restores onto Proxmox; Logbook's
backup is consistent and portable, but doesn't bring back the container
around it.

---

## Updating

Read the [changelog](../CHANGELOG.md) first, then back up (Logbook's backup,
or a Proxmox snapshot: *Snapshots → Take Snapshot*).

**Docker route:**

```bash
cd /opt/logbook
git fetch --tags && git checkout v3.2.1      # the new release
docker compose up -d                         # rebuilds the image; migrations run at start
docker image prune -f
```

**Native route:**

```bash
cd /var/www/logbook
git fetch --tags && git checkout v3.2.1
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader
runuser -u www-data -- vendor/bin/phinx migrate -e production
systemctl reload php8.4-fpm
```

Then open `<your URL>/health` and sign in. Keep the container itself up to
date with `apt update && apt full-upgrade` (for Docker, that also updates
Docker).

---

## Logs and a shell

A shell in the container: `pct enter 200` on the host, or the container's
*Console* in the web UI.

- **Docker route:** `cd /opt/logbook && docker compose logs -f app` (the app,
  Apache and the scheduler) and `docker compose logs db`.
- **Native route:** `tail -f /var/www/logbook/var/log/logbook.log`, nginx's
  `/var/log/nginx/error.log`, and `journalctl -u php8.4-fpm`.
- Either way, **Settings → Jobs** shows whether scheduled tasks run, and
  `<your URL>/health` whether the app and database answer.
