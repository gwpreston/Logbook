# Reverse proxies

How to put Logbook behind **nginx**, **Apache**, **Caddy** or **Traefik**,
at the root of its own domain (`https://logbook.example.com/`) or at a
subpath of another one (`https://example.com/logbook/`), with HTTPS.

The Caddy and Traefik examples are files in
[`docker/examples/`](../docker/examples/), and CI runs those files unchanged
on every change ([How the examples are tested](#how-the-examples-are-tested)).
They were written against the current documentation of each proxy and tested
with **Caddy 2.11**, **Traefik 3.7** and **nginx 1.29** on 2026-10-06.

- [What to set on Logbook](#what-to-set-on-logbook)
- [What Logbook trusts from a proxy](#what-logbook-trusts-from-a-proxy)
- [Root or subpath, forwarded or stripped](#root-or-subpath-forwarded-or-stripped)
- [nginx](#nginx)
- [Apache](#apache)
- [Caddy](#caddy)
- [Traefik](#traefik)
- [The health check](#the-health-check)
- [Forward auth (Authelia, Authentik)](#forward-auth-authelia-authentik)
- [Large uploads and slow answers](#large-uploads-and-slow-answers)
- [Common failures](#common-failures)
- [How the examples are tested](#how-the-examples-are-tested)

---

## What to set on Logbook

Three variables, the same for every proxy ([configuration.md](configuration.md)):

| Variable | At the root | At a subpath |
|---|---|---|
| `APP_URL` | `https://logbook.example.com` | `https://example.com/logbook` (or without `/logbook`: both work) |
| `APP_BASE_PATH` | empty | `/logbook` |
| `SESSION_SECURE` | leave empty | leave empty |

- **`APP_URL`** is the public address. Logbook uses it for links that leave
  the browser: emails, notifications, calendar feeds, the API's paging
  links, the OpenID Connect redirect. It doesn't matter whether it repeats
  the base path.
- **`APP_BASE_PATH`** is the prefix every page, link and asset carries.
  Leave it empty at the root. Don't pick a prefix equal to one of the app's
  own top-level routes (such as `/health`).
- **`SESSION_SECURE`** marks the session cookie `Secure`. Its default
  follows `APP_URL`: on when it starts with `https://`. So with the right
  `APP_URL` you don't set it. It isn't worked out from the request (see
  below), so an `http://` `APP_URL` behind an HTTPS proxy gives cookies
  without `Secure`.

## What Logbook trusts from a proxy

Less than many apps do. This is what the code does today:

- **No forwarded header is read.** Not `X-Forwarded-For`, `-Proto`,
  `-Host`, `-Prefix` or `Forwarded`. The proxies below send them by
  habit; Logbook ignores them. There is no trusted-proxy setting for them,
  because nothing would use it.
- **The client address is the connecting one** (PHP's `REMOTE_ADDR`). Behind
  a proxy that is the proxy's address, for everyone. The Docker image has no
  `mod_remoteip`. So:
  - the failed sign-in log line (`Failed sign-in for "…" from …`) names the
    proxy, and a fail2ban rule should watch the **proxy's** access log for the
    client instead;
  - failed API keys are counted per address, so behind a proxy one client
    guessing keys also delays everyone's good keys for 10 minutes
    ([deployment.md](deployment.md#the-rest-api-behind-a-proxy)).
- **Header sign-in** ([sso.md](sso.md#header-sign-in)) is the one place an
  address is trusted: a plain user header is read only from a connection
  whose `REMOTE_ADDR` is in `AUTH_PROXY_TRUSTED` (Authentik's signed JWT
  mode checks the signature instead), which should be the proxy's
  address on the app's network. Forwarding headers are never consulted for
  it. **The examples here are plain proxies**: they pass on whatever
  headers the client sends, `Remote-User` included. Never set
  `AUTH_PROXY_TRUSTED` to their address as they are; header sign-in needs a
  proxy that removes the client's own header and sets it after forward auth,
  as in [sso.md's examples](sso.md#header-sign-in).
- **HTTPS** is known only from `APP_URL` (above). Redirects Logbook sends
  are relative (`Location: /logbook/garage`), so they keep the scheme and
  host the browser used.
- **The prefix** comes from `APP_BASE_PATH`, never from a header. A request
  arriving with or without the prefix is routed the same way.

## Root or subpath, forwarded or stripped

At a subpath, a proxy can either **forward the prefix** (`/logbook/garage`
reaches Logbook as it is) or **strip it** (`/garage` reaches Logbook).
Logbook accepts both, and every page, link and asset still carries the
prefix. Forwarding is recommended: there is nothing to get wrong. Each
subpath example shows both styles: the forwarding one at `/logbook/` and the
stripping one at `/stripped/`, so the smoke test can check both. Keep one.

Visiting `/logbook` without the slash should redirect to `/logbook/`; each
example does that.

## nginx

- In front of the Docker image, at a subpath, forwarding or stripping:
  [`docker/nginx/subpath-example.conf`](../docker/nginx/subpath-example.conf),
  run by the `pgsql` smoke test.
- Logbook on nginx itself (php-fpm), at the root or at a subpath:
  [deployment.md → nginx + php-fpm](deployment.md#nginx--php-fpm).
- With Authelia's forward auth:
  [`docker/nginx/forward-auth-example.conf`](../docker/nginx/forward-auth-example.conf)
  and [sso.md](sso.md#authelia-with-nginx).

At the root, the example's `location /logbook/ { … }` becomes
`location / { proxy_pass http://app:80; … }` with `APP_BASE_PATH` empty. nginx
doesn't do HTTPS on its own: add your certificate with `listen 443 ssl;`,
`ssl_certificate` and `ssl_certificate_key`, or use Certbot's nginx plugin.
Keep `client_max_body_size 260m;` (see
[Large uploads](#large-uploads-and-slow-answers)).

## Apache

- In front of the Docker image (`mod_proxy_http`):
  [deployment.md → Subpath and reverse proxies](deployment.md#subpath-and-reverse-proxies).
- Logbook on Apache itself, at the root or with `Alias` at a subpath:
  [deployment.md → Apache](deployment.md#apache).

HTTPS on Apache is `mod_ssl` with your certificate, or Certbot's Apache
plugin. The Apache examples aren't run by the smoke test; the image itself
serves Logbook with Apache in every smoke test.

## Caddy

Caddy gets and renews the certificate on its own. Files:

| | Compose file | Caddyfile |
|---|---|---|
| Root | [`docker/examples/caddy/compose.yml`](../docker/examples/caddy/compose.yml) | [`Caddyfile`](../docker/examples/caddy/Caddyfile) |
| Subpath | [`docker/examples/caddy/compose.subpath.yml`](../docker/examples/caddy/compose.subpath.yml) | [`Caddyfile.subpath`](../docker/examples/caddy/Caddyfile.subpath) |

Each compose file is layered on top of the project's `docker-compose.yml`, so
the app, its database and its variables are the ones you already know. It
adds a `proxy` service, sets `APP_URL` (and `APP_BASE_PATH`), and stops
publishing the app's own port 8080, so everything goes through Caddy.

1. Point your domain's DNS at the host, and let ports 80 and 443 reach it
   (Let's Encrypt checks port 80 or 443).
2. In `.env` next to `docker-compose.yml`, beside your other settings:

   ```dotenv
   LOGBOOK_DOMAIN=logbook.example.com
   ```
3. Start it:

   ```bash
   docker compose -f docker-compose.yml -f docker/examples/caddy/compose.yml up -d
   ```

   (or `compose.subpath.yml` for `https://logbook.example.com/logbook/`).
4. Open `https://logbook.example.com/` and create the first account.

The root Caddyfile is a site block with one line in it:

```caddyfile
{$LOGBOOK_DOMAIN} {
	reverse_proxy app:80 {
		health_uri /health
	}
}
```

At a subpath, `handle /logbook/*` forwards the prefix and `handle_path`
strips it; `redir /logbook /logbook/ 308` adds the slash.

Already running Caddy for other sites? Add the site block to your own
Caddyfile instead. Caddy must be able to reach the app: on the same Docker
network, `app:80`; with Caddy on the host itself, keep `docker-compose.yml`
alone, change its `ports:` line to `"127.0.0.1:8080:80"` so only the host
can reach the app, and use `reverse_proxy 127.0.0.1:8080`.

The `caddy_data` volume holds the certificates. Keep it: a new one asks Let's
Encrypt again, which has rate limits.

## Traefik

Traefik reads its routes from labels on the app container and gets the
certificate from Let's Encrypt. Files:

| | Compose file |
|---|---|
| Root | [`docker/examples/traefik/compose.yml`](../docker/examples/traefik/compose.yml) |
| Subpath | [`docker/examples/traefik/compose.subpath.yml`](../docker/examples/traefik/compose.subpath.yml) |

Each is layered on top of `docker-compose.yml`, like Caddy's.

1. Point the domain at the host, with ports 80 and 443 open (the example uses
   the HTTP challenge on port 80).
2. In `.env`:

   ```dotenv
   LOGBOOK_DOMAIN=logbook.example.com
   ACME_EMAIL=you@example.com
   ```
3. Start it:

   ```bash
   docker compose -f docker-compose.yml -f docker/examples/traefik/compose.yml up -d
   ```

   (or `compose.subpath.yml` for the subpath).

What the example sets up:

- two entry points: `web` (port 80) sends everything to `websecure` (443);
- a `letsencrypt` certificate resolver, kept in the `traefik_letsencrypt`
  volume (keep it);
- on the app, a router for `Host(`logbook.example.com`)` and a service on
  port 80 with a health check on `/health`;
- at a subpath, the rule
  `Host(…) && (Path(`/logbook`) || PathPrefix(`/logbook/`))`, so
  `/logbookx` isn't caught, and a `redirectRegex` middleware that adds the
  slash to `/logbook`. The stripping style is a second router with a
  `stripPrefix` middleware;
- a longer read timeout on `websecure` (600 s, Traefik's default is 60 s):
  see [Large uploads](#large-uploads-and-slow-answers).

**The Docker socket.** Traefik reads labels through
`/var/run/docker.sock`. The example mounts it read-only, but anything with
access to the socket can control Docker, which means root on the host. If
that's too much, put a Docker socket proxy in between, or use Traefik's
file provider instead of labels: [sso.md](sso.md#authelia-with-traefik) has
a router written that way.

Already running Traefik? Copy the `labels:` of the `app` service into your
own override, change `websecure` and `letsencrypt` to the names of your entry
point and resolver, and put the app on Traefik's network.

## The health check

`<your URL>/health` (at a subpath, `/logbook/health`; Logbook also answers
`/health` without the prefix) returns `200` and
`{"status":"ok","database":"ok",…}` while the app and its database work, and
`503` when they don't ([deployment.md](deployment.md#health-check)). It needs
no sign-in. The Docker image checks it itself; the Caddy and Traefik examples
also use it to take an unhealthy app out of rotation.

## Forward auth (Authelia, Authentik)

Putting a sign-in portal in front of Logbook, and letting it sign people in
by header, is in [sso.md](sso.md#header-sign-in), with examples for nginx,
Traefik and Caddy. Exempt these paths from forward auth, or the things that
use them break: `<base>/api/`, `<base>/calendar/`, `<base>/health` and
`<base>/mcp`. They check their own keys and tokens.

## Large uploads and slow answers

- **Restoring a backup** in the browser sends the whole file in one request,
  up to `MAX_RESTORE_MB` (256 MB by default). nginx refuses bodies over 1 MB
  unless told (`client_max_body_size 260m;`). Caddy and Traefik have no
  size limit by default. Traefik stops reading a request after 60 seconds by
  default, which a large file on a slow link can exceed, so the example
  raises it.
- **Ask Logbook** can take a few minutes with a slow local model:
  [deployment.md → Ask Logbook behind a proxy](deployment.md#ask-logbook-behind-a-proxy).
  Caddy and Traefik have no response timeout by default.

## Common failures

| What you see | Why, and what to do |
|---|---|
| Pages load at a subpath but have no styles; assets 404 | `APP_BASE_PATH` isn't set, or doesn't match the proxy's prefix. Set it to `/logbook` exactly (a leading slash, no trailing one). |
| `/logbook/garage` works, but a hard refresh (F5) on a deep link gives the proxy's 404 | The proxy only matches `/logbook` or `/logbook/` exactly. Match the prefix and everything under it (`location /logbook/`, `handle /logbook/*`, `PathPrefix(`/logbook/`)`). Check with `<your URL>/diagnostics/deep/link` and F5. |
| A redirect loop at the subpath | Usually the prefix is added twice: the proxy rewrites `/logbook/x` to `/logbook/logbook/x`, or an `APP_URL` was used as the proxy target. Forward the path as it is, or strip it, and nothing else. |
| First-run setup or signing in answers *400* with "expired", or returns to the sign-in page | The session cookie was dropped. `APP_URL` says `https://` but the site is reached over plain HTTP (the browser won't send a `Secure` cookie back), or the proxy changes the path the cookie belongs to. Use HTTPS, or until the proxy is in front, an `http://` `APP_URL`. `SESSION_SECURE=false` also works, only for a trial on a private network. |
| Every request goes to the wrong site, or Traefik answers 404 | `LOGBOOK_DOMAIN` doesn't match the name in the browser: the routers match on the host name. |
| The browser warns about the certificate | The certificate couldn't be issued: DNS doesn't point here yet, or port 80/443 isn't reachable. Caddy and Traefik log why (`docker compose logs proxy`). Traefik serves its own default certificate until it has one. |
| Restoring a backup fails with `413` | The proxy's body size limit (nginx's `client_max_body_size`). |
| Every API call answers `401 missing_key` although a key is sent | The `Authorization` header doesn't reach PHP: [deployment.md](deployment.md#the-rest-api-behind-a-proxy). |

## How the examples are tested

`bin/smoke-test.sh caddy`, `caddy-subpath`, `traefik` and `traefik-subpath`
start the production image with the example's own compose file and
configuration, with `LOGBOOK_DOMAIN=localhost`, and check over HTTPS:

- `/health` through the proxy;
- the redirect from HTTP to HTTPS;
- a deep link loaded cold (a hard refresh), an asset, and a page that
  doesn't exist;
- at a subpath, the `/logbook` redirect and the stripping style;
- first-run setup and sign-in, and that the session cookie is `Secure`;
- a signed-in deep link and the web app manifest.

Caddy issues its own certificate for `localhost`. For Traefik, a small
overlay in `docker/smoke/` removes the certificate resolver, so CI never asks
Let's Encrypt for a name it can't have, and Traefik serves its default
certificate. Nothing else is changed. CI runs all four on every change, in
the same job as the nginx tests.
