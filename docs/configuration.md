# Configuration reference

Logbook is configured entirely through environment variables. Put them in a
`.env` file in the project root (bare PHP) or next to the compose file
(Docker), or set them in the environment; a real environment variable always
wins over `.env`, and an empty value counts as unset. The annotated template is
[`.env.example`](../.env.example); `spec.md` §9 is the source of truth.

Nothing is required: every variable has a default, so `docker compose up`
works unedited. For a real deployment set at least `DB_PASSWORD`, `APP_URL` and
`SESSION_SECRET`.

Per-user settings — language, time zone, units, currency, theme, lead times,
which notification channels to use — are chosen in the app, not here. The
`APP_*` defaults below only seed the first-run form and signed-out pages.

## Application

| Variable | Default | Meaning |
|---|---|---|
| `APP_ENV` | `production` | `production`, `development` or `testing`. Production caches templates and translations. |
| `APP_DEBUG` | on in development only | Detailed error pages. Never on a public server. |
| `APP_URL` | `http://localhost:8080` | Public URL, no trailing slash. Used for links in notifications and the calendar feed; `https://` also turns on secure cookies. |
| `APP_BASE_PATH` | *(empty)* | Subpath behind a reverse proxy, e.g. `/logbook`. See [deployment.md](deployment.md#subpath-and-reverse-proxies). |
| `APP_TIMEZONE` | `UTC` | Default time zone (IANA name) for the first-run form and signed-out pages. Data is always stored in UTC. |
| `APP_LOCALE` | `en` | Default language when the browser's `Accept-Language` matches no catalogue. Shipped: `en`, `de`. |
| `APP_CURRENCY` | `GBP` | Default currency (ISO 4217) for the first-run form. |

## Database

| Variable | Default | Meaning |
|---|---|---|
| `DB_DRIVER` | `sqlite` | `pgsql`, `mysql` (also MariaDB) or `sqlite`. The compose files set `pgsql` / `mysql`. |
| `DB_HOST` | `127.0.0.1` | Database host. |
| `DB_PORT` | `5432` / `3306` | Per driver. |
| `DB_NAME` | `logbook` | Database name; for SQLite the file path (Docker default `/data/logbook.sqlite`, bare PHP `var/logbook.sqlite`). |
| `DB_USER` | `logbook` | |
| `DB_PASSWORD` | *(empty)* | Set a strong one. |

## Sessions

| Variable | Default | Meaning |
|---|---|---|
| `SESSION_SECRET` | *(empty)* | Key for hashing session ids, calendar-feed tokens, API keys and invitation links at rest, and for encrypting saved passwords and keys (AI, fuel prices, the email server). Generate with `openssl rand -hex 32`. Changing it signs everyone out, disables calendar feed links, open invitation and reset links, and every API key, and saved passwords must be entered again. |
| `SESSION_SECRET_FILE` | *(empty; Docker: `/data/session-secret`)* | A file to read the secret from when `SESSION_SECRET` is empty. On a **fresh** Docker volume (no users yet) the image writes a random secret there, readable only by the app; an install that already has users is never given one (it would sign everyone out), so set `SESSION_SECRET` yourself there. Keep the file with your backups. |
| `SESSION_SECURE` | true when `APP_URL` is `https://` | Send the session cookie over HTTPS only. |

## Single sign-on

OpenID Connect sign-in with Authelia, Authentik, Keycloak or another
provider. See [sso.md](sso.md). Setting `OIDC_ISSUER` switches it on; an
issuer without a client id or secret, scopes without `openid` or an
unknown `OIDC_LINK` stop the app at start with a message naming the
variable. The redirect URI to register is
`{APP_URL}{APP_BASE_PATH}/auth/oidc/callback`.

| Variable | Default | Meaning |
|---|---|---|
| `OIDC_ISSUER` | *(empty: off)* | The provider's issuer URL, exactly as its discovery document states it (Authentik's ends in `/`). |
| `OIDC_CLIENT_ID` | *(empty)* | Required with `OIDC_ISSUER`. |
| `OIDC_CLIENT_SECRET` | *(empty)* | Required with `OIDC_ISSUER`: a confidential client. |
| `OIDC_PROVIDER_NAME` | `SSO` | Button text: *Sign in with {name}*. |
| `OIDC_SCOPES` | `openid profile email` | Space- or comma-separated; must include `openid`. Add `groups` where the provider needs it for the groups claim. |
| `OIDC_USERNAME_CLAIM` | `preferred_username` | The claim used for username linking and for new users' usernames. |
| `OIDC_GROUPS_CLAIM` | `groups` | The claim holding group names (a list, or one string). |
| `OIDC_LINK` | `explicit` | `explicit`: an account reaches a user only once that user links it on their Profile. `username`: also a user with the same username and no linked account yet. Only safe where usernames at the provider are set by admins alone. |
| `OIDC_AUTO_CREATE` | `false` | Create a member (no password) on first sign-in for an account nobody has. |
| `OIDC_ALLOWED_GROUPS` | *(empty: everyone)* | Comma-separated: only members of these groups may sign in with SSO. |
| `OIDC_ADMIN_GROUPS` | *(empty)* | Comma-separated: admin is set from these groups at every SSO sign-in, both ways. The last admin is never demoted. Empty: admin stays as set in the app. |
| `OIDC_LOGOUT` | `false` | Also sign out at the provider. Register `{APP_URL}{APP_BASE_PATH}/login` there as the post-logout redirect URI. |
| `AUTH_LOCAL_LOGIN` | `true` | Password sign-in. `false` leaves only SSO. First-run setup still creates a local admin, and `php bin/auth.php login-link <username>` still works. |
| `PASSWORD_RESET_ENABLED` | `true` | *Forgotten your password?* on sign-in: a 60-minute reset link emailed to the account's confirmed address. Offered only when email (Settings → Delivery) is set up and password sign-in is on; `false` hides it anyway. See [users-and-sharing.md](users-and-sharing.md#forgotten-passwords). |

## Header sign-in

Sign-in from a forward-auth proxy such as Authelia or an Authentik outpost
(Phase 23.2). See [sso.md](sso.md#header-sign-in), and read its warning
first: the app must be reachable only through the proxy. Off unless
`AUTH_PROXY_HEADER` or `AUTH_PROXY_JWT_HEADER` is set (never both). A header
without what it needs, an invalid trusted entry, an unknown
`AUTH_PROXY_LINK` or a logout URL that isn't http(s) stops the app, on the
web and on the command line, with a message naming the variable.

| Variable | Default | Meaning |
|---|---|---|
| `AUTH_PROXY_HEADER` | *(empty: off)* | The header holding the username, e.g. `Remote-User` (Authelia) or `X-authentik-username`. Letters, digits and dashes only. |
| `AUTH_PROXY_TRUSTED` | *(empty)* | Comma-separated IP addresses and CIDR ranges the proxy connects from, e.g. `172.18.0.0/16, 10.0.0.5, fd00::/8`. **Required** with `AUTH_PROXY_HEADER`; optional with the JWT, and enforced when set. Only the connecting address counts, never `X-Forwarded-For`. |
| `AUTH_PROXY_NAME_HEADER` | *(empty)* | Display name for a user created by header sign-in. |
| `AUTH_PROXY_EMAIL_HEADER` | *(empty)* | Reminder email address for a user created by header sign-in. |
| `AUTH_PROXY_GROUPS_HEADER` | *(empty)* | Groups, separated by `,` (Authelia) or `\|` (Authentik), for the two groups variables below. |
| `AUTH_PROXY_JWT_HEADER` | *(empty: off)* | Instead of the plain header: Authentik's signed `X-authentik-jwt`. Username, name, email and groups then come from its claims. |
| `AUTH_PROXY_JWT_SECRET` | *(empty)* | Required with the JWT header: the proxy provider's client secret (HS256, at least 32 characters). It can mint tokens: keep it secret. |
| `AUTH_PROXY_JWT_ISSUER` | *(empty)* | Required with the JWT header: the application's issuer, exactly (e.g. `https://authentik.example.com/application/o/logbook/`). |
| `AUTH_PROXY_JWT_AUDIENCE` | *(empty)* | Required with the JWT header: the proxy provider's client ID. |
| `AUTH_PROXY_LINK` | `username` | `username`: a proxy account reaches the user with the same username (and no proxy account yet). `identity`: only once that user links it from the banner while signed in. |
| `AUTH_PROXY_AUTO_CREATE` | `false` | Create a member (no password) for a proxy account nobody has. |
| `AUTH_PROXY_ALLOWED_GROUPS` | *(empty: everyone)* | Comma-separated: only members of these groups may sign in through the proxy. |
| `AUTH_PROXY_ADMIN_GROUPS` | *(empty)* | Comma-separated: admin is set from these groups whenever header sign-in signs someone in, both ways. The last admin is never demoted. |
| `AUTH_PROXY_LOGOUT_URL` | *(empty)* | Where *Sign out* sends a session that came from the header, e.g. `https://auth.example.com/logout`. Without it, a page explains that sign-out happens at the proxy. |

## REST API

See [api.md](api.md).

| Variable | Default | Meaning |
|---|---|---|
| `API_ENABLED` | `true` | The JSON API under `/api/v1`. Every call needs an API key (Settings → API keys, or `bin/api-key.php`), so it is closed until you make one. `false` makes every API address a 404. |
| `API_CORS_ORIGINS` | *(empty)* | Comma-separated origins (`https://ha.example.com:8123`) whose pages may call the API from the browser. Empty: CORS off. Home Assistant sensors, Shortcuts, Grafana and Node-RED call from a server and need nothing here. |

## MCP server

See [mcp.md](mcp.md).

| Variable | Default | Meaning |
|---|---|---|
| `MCP_ENABLED` | `true` | The MCP server at `/mcp`, for Claude Desktop and other MCP clients. It uses API keys, so it is closed until you make one, and it is a 404 when this or `API_ENABLED` is `false`. A browser-based client's origin goes in `API_CORS_ORIGINS`. |

## AI

See [ai.md](ai.md). Connections, keys, models and tasks are set by an admin
on **Settings → AI**, not here; nothing is sent to any model until then.

| Variable | Default | Meaning |
|---|---|---|
| `AI_ENABLED` | `true` | `false` removes Settings → AI and every AI switch and feature, and sends nothing, whatever is configured. |
| `AI_LOG_CONTENT` | `false` | `true` keeps questions, answers and the data sent with them in the usage log, for debugging your own install; Settings → AI shows a warning while it is on. Off, the log has counts, times and outcomes only. |
| `AI_ALLOW_INSECURE_TLS` | `true` | Allows a connection's *Verify TLS certificates* to be switched off (a LAN server with a self-signed certificate). `false` verifies every connection. |
| `GHOSTSCRIPT_BINARY` | `gs` | Ghostscript, for reading scanned PDFs (Phase 26.4, [ai.md](ai.md#reading-receipts-and-documents)): a name looked up on `PATH`, or a full path. `off` turns it off; Imagick is used when loaded. Without either, a scanned PDF asks for a photo instead. The Docker image includes Ghostscript. |

A key typed as `env:NAME` on Settings → AI is read from the variable `NAME`
when it is used, so it can live with the rest of your secrets. A key typed
in full is encrypted with a key derived from `SESSION_SECRET`: without one,
only `env:` keys can be saved, and changing it means entering the keys again.

## Files and backups

| Variable | Default | Meaning |
|---|---|---|
| `UPLOAD_PATH` | `var/uploads` (Docker `/data/uploads`) | Vehicle photos and attachments. Outside `public/`. |
| `MAX_UPLOAD_MB` | `10` | Largest photo, attachment or CSV import. |
| `BACKUP_PATH` | `var/backups` (Docker `/data/backups`) | Safety backups made before a restore, and `bin/backup.php create`. Outside `public/`. |
| `MAX_RESTORE_MB` | `256` | Largest backup the restore form accepts; larger ones restore with `bin/backup.php restore`. |

PHP's `upload_max_filesize` and `post_max_size` must allow the larger of the
two upload limits (the Docker image sets 256M / 260M).

## Logging

| Variable | Default | Meaning |
|---|---|---|
| `LOG_PATH` | `php://stderr` | Log destination (Docker logs by default), or a file such as `var/log/app.log`. |
| `LOG_LEVEL` | `info` (`debug` in development) | PSR-3 level. Failed sign-ins are logged at `notice`. |

## Notifications

Each channel is *configured* once it is set up; each owner then picks which
configured channels to use in **Settings → Reminders**. See
[notification-channels.md](notification-channels.md).

**Email** is not an environment variable: an admin sets the server up in
**Settings → Delivery** (server, port, encryption, username, password, From
address and name, and the default recipient for admins), with *Send test
email*. The password is stored encrypted with a key from `SESSION_SECRET`, or
as `env:NAME` to read it from a variable such as a Docker secret. The
`MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION`,
`MAIL_FROM` and `MAIL_TO` variables were **removed in v3.3.0** and are no
longer read; see [notification-channels.md](notification-channels.md#email).

| Variable | Default | Meaning |
|---|---|---|
| `MAILPIT_PORT` | `8025` | Development only (`docker-compose.dev.yml`): the Mailpit web UI on the host. Unset, `bin/dev-setup.sh` moves to the next free port when 8025 is taken. |
| `NTFY_URL` | *(empty)* | ntfy topic URL, e.g. `https://ntfy.sh/my-garage`: the admins' reminders; each user can set their own topic. |
| `NTFY_TOKEN` | *(empty)* | Access token for a protected topic. |
| `GOTIFY_URL`, `GOTIFY_TOKEN` | *(empty)* | Gotify server URL and application token (the admins'; each user can set their own token on this server). |
| `GOTIFY_PRIORITY` | `5` | 0–10; overdue reminders are sent at 8 or more. |
| `WEBHOOK_URL` | *(empty)* | Receives each notification as a JSON POST. |

## Modules

| Variable | Default | Meaning |
|---|---|---|
| `FEATURES_FUEL` | `true` | Fill-ups and charging. |
| `FEATURES_MAINTENANCE` | `true` | Service history and schedules. |
| `FEATURES_COMPLIANCE` | `true` | Documents (insurance, certificates…). |
| `FEATURES_REMINDERS` | `true` | Reminder list, notifications, calendar feed. |
| `FEATURES_REPORTS` | `true` | Reports and the spend widget. |
| `FEATURES_TYRES` | `true` | Tyres: what is fitted and stored, tyre changes, distance per tyre, tread depth, the wear estimate, Settings → Tyres and tyre reminders. |
| `FEATURES_TRIPS` | `false` | Trips and mileage claims: the Trips tab, saved journeys, mileage rates, the claim report, the business and private split, the *Business mileage* widget and report section, and the trip API. The one module that is off by default ([trips.md](trips.md)). |
| `FEATURES_INCIDENTS` | `true` | Incidents, damage and insurance claims: the Incidents tab, *Part of an incident* on repairs, expenses and tyre changes, the claims history, the sale pack's *Include incidents*, the Reports section, ownership net of payouts, the stalled-claim check, and the incident API and tools ([incidents.md](incidents.md)). |
| `FEATURES_FINANCE` | `true` | Finance and lease agreements: the finance page and agreement pages, the overview card, the dashboard widget, credit charges or lease rentals counted in costs, *Coming up* lines, finance reminders and *Needs attention* items, the API endpoint and the Ask tool ([finance.md](finance.md)). |
| `FEATURES_STATIONS` | `true` | Fuel stations: stations as records linked from fill-ups, the stations list and pages with what you paid, favourites, Settings → Places and straight-line distances, merging and duplicates, the Fuel tab's *By station* card, the station API endpoints and the Ask tool ([stations.md](stations.md)). Part of fuel: off whenever `FEATURES_FUEL` is. Nothing is fetched from outside, unless an admin enables a fuel price provider (see [Fuel prices](#fuel-prices)). |
| `FEATURES_AI_ASK` | `true` | Ask Logbook (Phase 26.2). Does nothing, and is not listed on Settings → Modules, until AI is set up ([ai.md](ai.md)). |
| `FEATURES_AI_ACTIONS` | `true` | Drafting entries from what you say (Phase 26.3). As above. |
| `FEATURES_AI_SCAN` | `true` | Reading receipts and documents (Phase 26.4). As above. |

These are defaults: once an owner saves **Settings → Modules**, that choice
wins. A switched-off module disappears from menus, pages (404), the dashboard,
reports and reminders; its data is kept.

## Update check

| Variable | Default | Meaning |
|---|---|---|
| `UPDATE_CHECK_REPO` | `gwpreston16/Logbook` | The GitHub repository asked for its latest release, as `owner/name`. Forks set their own. Anything else stops the app at start. |
| `UPDATE_CHECK_ALLOWED` | `true` | `false` removes the update check entirely: Settings → Updates, the setup checkbox and the `update_check` job. |
| `LOGBOOK_DOCKER` | unset (`1` in the image) | Set by the Docker image so the update banner gives the Docker upgrade command. Not for setting by hand. |

The check itself is **off** until an admin switches it on in Settings →
Updates (or ticks *Tell me when a new version is out* at first-run setup).
See [Update check](deployment.md#update-check).

## Fuel prices

Listed fuel prices (Phase 30.2, [stations.md](stations.md#fuel-prices)) are
**off** until an admin chooses a provider on Settings → Fuel prices. The
provider, its credentials and the refresh are set there, not here. A
credential can be typed as `env:NAME` to read it from any variable you
choose; one typed in full is encrypted with a key derived from
`SESSION_SECRET`, as AI keys are.

| Variable | Default | Meaning |
|---|---|---|
| `PRICE_HISTORY_DAYS` | `1095` | Days of listed price history kept for stations someone has used or favourited (three years). At least `30`: a smaller value is treated as 30. The hourly `cleanup` job deletes older entries. |

## Demo mode

A public demo that resets itself ([demo-mode.md](demo-mode.md)). Off unless
`DEMO_MODE` is set, and even then it only ever wipes a database that was
seeded as a demo.

| Variable | Default | Meaning |
|---|---|---|
| `DEMO_MODE` | `false` | Seed an **empty** database with the sample data and one account (`demo`, an admin), and run as a demo. On a database that already holds users and was not seeded as a demo, the setting is refused: nothing is deleted, an error is logged at every start and admins see a notice. |
| `DEMO_PASSWORD` | none | The demo owner's password: **required** with `DEMO_MODE`, 8 to 1024 characters, no default. It is shown to every visitor on the sign-in page, so use one you would not use anywhere else. Without it (or too short) demo mode is refused the same way. |
| `DEMO_RESET_HOURS` | `24` | How often the `demo_reset` job puts the sample data back, 1 to 168 (a value outside that is brought inside it). |

## Docker entrypoint only

| Variable | Default | Meaning |
|---|---|---|
| `MIGRATE_ON_START` | `true` | Apply pending migrations when the container starts. |
| `DB_WAIT_TIMEOUT` | `60` | Seconds to wait for the database. |
| `SCHEDULER_ENABLED` | `true` | Run the reminder/notification task inside the container. |
| `SCHEDULER_INTERVAL` | `900` | Seconds between scheduled-task runs. |

## Test suite only

`TEST_DB_DRIVER`, `TEST_DB_HOST`, `TEST_DB_PORT`, `TEST_DB_NAME`,
`TEST_DB_USER`, `TEST_DB_PASSWORD` — same shape as `DB_*`; default SQLite at
`var/testing.sqlite`. PHPUnit never reads `DB_*`.
