# Single sign-on

From 2.3.0 Logbook can sign people in with the identity provider you
already run: **Authelia**, **Authentik** or **Keycloak**, or any other
provider that speaks standard OpenID Connect. The sign-in page gets a
*Sign in with Authentik* button, and the provider's account is linked to a
Logbook user. Single sign-on only changes how someone proves who they are.
What they can see and do is still set in Logbook ([users-and-sharing.md](users-and-sharing.md)).

Passwords stay as the fallback, first-run setup still creates a local
admin, and a command-line link gets the owner in when the provider is down.
Already behind a forward-auth proxy? [Header sign-in](#header-sign-in)
trusts the user it passes on instead. `spec.md` §7.9 has the exact rules.

- [How it works](#how-it-works)
- [Setting it up](#setting-it-up)
  - [Authentik](#authentik)
  - [Keycloak](#keycloak)
  - [Authelia](#authelia)
  - [Other providers](#other-providers)
- [Linking accounts to users](#linking-accounts-to-users)
- [Groups and admins](#groups-and-admins)
- [Switching password sign-in off](#switching-password-sign-in-off)
- [Break-glass: getting in when the provider is down](#break-glass-getting-in-when-the-provider-is-down)
- [Signing out](#signing-out)
- [When it doesn't work](#when-it-doesnt-work)
- [Header sign-in](#header-sign-in) (behind Authelia or an Authentik outpost)
  - [Authelia with nginx](#authelia-with-nginx)
  - [Authelia with Traefik](#authelia-with-traefik)
  - [Authelia with Caddy](#authelia-with-caddy)
  - [Authentik proxy outpost](#authentik-proxy-outpost)
- [Upgrading and rolling back](#upgrading-and-rolling-back)

---

## How it works

Logbook is a *confidential client* using the authorization code flow with
PKCE (S256), `state` and `nonce`:

1. *Sign in with …* goes to the provider's sign-in page.
2. The provider sends the browser back to
   `{APP_URL}{APP_BASE_PATH}/auth/oidc/callback` with a one-time code.
3. Logbook exchanges the code for an ID token, using the client secret,
   and checks the token in full: its signature against the provider's
   published keys (RS256, PS256, ES256 or EdDSA; never `none` or HS256),
   the issuer, the audience, `azp`, the expiry and issue time (60 seconds
   of clock leeway) and the nonce.
4. The account is matched to a Logbook user (see
   [Linking](#linking-accounts-to-users)), and from then on it is an
   ordinary Logbook session.

Logbook finds the provider through
`{OIDC_ISSUER}/.well-known/openid-configuration` and caches that and the
provider's keys under `var/cache/oidc` for a day. These, the code exchange
and (for some providers) one userinfo request are the only connections
Logbook makes to the provider, and only when you configure it. It never
calls the provider after sign-in. The API and the calendar feed keep their
own tokens.

## Setting it up

You need three things from the provider: the **issuer** URL, a **client
id** and a **client secret**. Logbook needs these variables (all of them are
in [configuration.md](configuration.md#single-sign-on)):

```env
OIDC_ISSUER=https://auth.example.com/application/o/logbook/
OIDC_CLIENT_ID=logbook
OIDC_CLIENT_SECRET=the-secret-from-the-provider
OIDC_PROVIDER_NAME=Authentik
```

At the provider, register this **redirect URI** exactly, with the scheme
and any base path:

```
{APP_URL}{APP_BASE_PATH}/auth/oidc/callback
e.g. https://cars.example.com/auth/oidc/callback
     https://home.example.com/logbook/auth/oidc/callback
```

Settings → Users shows the exact URI with a copy button, and whether
single sign-on is on. `APP_URL` must be the address people open Logbook at:
the redirect URI is built from it.

After a restart the sign-in page has the button. Sign in with your password
as usual, then go to **Settings → Account → Single sign-on** and choose
**Link Authentik account**. From then on the button signs you in.

> **The issuer must match exactly.** Logbook compares `OIDC_ISSUER` with the
> `issuer` in the provider's discovery document character for character,
> including any trailing slash. Open
> `{issuer}/.well-known/openid-configuration` in a browser and copy the
> `issuer` value from there.

Keycloak has been checked end to end against a real install
([docker/sso](../docker/sso/README.md)). The Authentik and Authelia
steps follow their current documentation and have not been run against a
real install yet.

### Authentik

1. **Applications → Providers → Create → OAuth2/OpenID Provider.**
   - *Client type*: **Confidential**.
   - *Redirect URIs*: `strict`, the callback URI above.
   - *Signing Key*: choose a certificate (the built-in *authentik
     Self-signed Certificate* will do). Without one Authentik signs ID
     tokens with HS256, which Logbook refuses.
   - *Scopes*: keep `openid`, `email` and `profile`. Authentik's `profile`
     scope includes the `groups` claim.
2. **Applications → Applications → Create**, with slug `logbook` and the
   provider above.
3. Copy the client id and secret from the provider. The issuer is
   `https://authentik.example.com/application/o/logbook/`, **with** the
   trailing slash.

For sign-out at Authentik too (`OIDC_LOGOUT=true`), nothing else is needed:
Authentik publishes an `end_session_endpoint`.

### Keycloak

1. In your realm, **Clients → Create client**: type *OpenID Connect*,
   client id `logbook`.
2. *Capability config*: **Client authentication: On** (confidential),
   **Standard flow: On**. Everything else can stay off.
3. *Login settings*: *Valid redirect URIs* = the callback URI. For
   `OIDC_LOGOUT`, set *Valid post logout redirect URIs* to
   `{APP_URL}{APP_BASE_PATH}/login`.
4. *Advanced → Proof Key for Code Exchange Code Challenge Method*: `S256`
   (optional: Logbook always sends it, and this makes Keycloak insist on it).
5. **Credentials** tab: copy the client secret.
6. Groups (only if you use `OIDC_ALLOWED_GROUPS` or `OIDC_ADMIN_GROUPS`):
   **Client scopes → logbook-dedicated → Add mapper → By configuration →
   Group Membership**, token claim name `groups`, *Full group path* **off**,
   *Add to ID token* **on**.

The issuer is `https://keycloak.example.com/realms/<realm>` (no trailing
slash). Keycloak puts `preferred_username` in the ID token by default.

### Authelia

Add a client to `identity_providers.oidc.clients` in Authelia's
configuration (4.38 or later):

```yaml
identity_providers:
  oidc:
    clients:
      - client_id: logbook
        client_name: Logbook
        # The hashed secret: authelia crypto hash generate pbkdf2 --password '…'
        client_secret: '$pbkdf2-sha512$310000$…'
        public: false
        authorization_policy: two_factor
        require_pkce: true
        pkce_challenge_method: S256
        redirect_uris:
          - https://cars.example.com/auth/oidc/callback
        scopes: [openid, profile, email, groups]
        response_types: [code]
        grant_types: [authorization_code]
        token_endpoint_auth_method: client_secret_basic
        id_token_signed_response_alg: RS256
```

Your `jwks` must contain an RS256 (or ES256) key. The issuer is Authelia's
own URL, e.g. `https://auth.example.com`, without a trailing slash. Put
the plain secret, not the hash, in `OIDC_CLIENT_SECRET`.

Recent Authelia versions leave `preferred_username` and `groups` out of the
ID token and serve them from the userinfo endpoint. Logbook notices the
missing claims and asks userinfo once, so this works without a
`claims_policy`. Add `groups` to the client's scopes if you use the group
variables.

### Other providers

Any provider with OpenID Connect discovery, the code flow with a client
secret, and ID tokens signed with RS256, PS256, ES256 or EdDSA should work.
Set `OIDC_USERNAME_CLAIM` and `OIDC_GROUPS_CLAIM` if it names those claims
differently. Only Authelia, Authentik and Keycloak are documented and
tested. Logbook supports one provider at a time.

## Linking accounts to users

A provider account reaches at most one Logbook user. How an account that
isn't linked yet finds its user is set by `OIDC_LINK` and
`OIDC_AUTO_CREATE`:

| Setting | An account that isn't linked yet | Risk |
|---|---|---|
| `OIDC_LINK=explicit` (default) | reaches nobody until a signed-in user links it in **Settings → Account** | none: linking proves both sides |
| `OIDC_LINK=username` | is linked to the user with the same username (`preferred_username`, lower-cased), if that user has no linked account yet | anyone who can choose their username at the provider can take over the Logbook user of that name. Use it **only** if usernames at the provider are set by admins alone. |
| `OIDC_AUTO_CREATE=true` | becomes a new member, with no password, the username from the claim (made valid, with `-2` added if it is taken) and the name and language from the provider. They see a short welcome form (units, currency, time zone). | anyone allowed to sign in at the provider gets an account. Combine it with `OIDC_ALLOWED_GROUPS`. |

Linking by email is deliberately not offered: Logbook doesn't verify
email addresses.

Someone whose account isn't linked sees "Your Authentik account isn't
linked to Logbook. Ask an admin to invite you, then link it from Settings →
Account." The usual way in for a new person is an invitation
([users-and-sharing.md](users-and-sharing.md#inviting-someone)): they sign
up with a password, then link their account.

**Unlinking.** Settings → Account → *Unlink* removes the link. It is refused
while it is your only way in: if you have no password, or password sign-in
is off. A user created by single sign-on can **Set a password** in
Settings → Account (no current password asked, as there is none) while
password sign-in is on.

**Admins** see each user's sign-in methods on Settings → Users (*Password*,
*Authentik*), and can remove a user's linked account there, with the same
rule.

## Groups and admins

These are off unless you set them, and then admin stays as set in
Logbook:

- `OIDC_ALLOWED_GROUPS=logbook,family`: only members of one of these
  groups may sign in with single sign-on, or link an account. Everyone else
  gets the "isn't linked" message. Password sign-in isn't affected.
- `OIDC_ADMIN_GROUPS=logbook-admins`: at every single sign-on, the user
  becomes an admin if they are in one of these groups and a member if not.
  The last active admin is never demoted (it is logged instead), so a
  mistake at the provider can't leave Logbook without an admin.

Group names are compared exactly. The groups come from the claim named by
`OIDC_GROUPS_CLAIM` (default `groups`), which may be a list or one string.

## Switching password sign-in off

With `AUTH_LOCAL_LOGIN=false` the sign-in page shows only the *Sign in
with …* button. A password sent anyway is refused, and Settings → Account
hides the password card. First-run setup still creates a local admin with
a password: the password just isn't accepted at sign-in.

Before you switch it off, link your own account and check the button
works. If something goes wrong, the break-glass link below always gets you
in.

## Break-glass: getting in when the provider is down

On the server:

```sh
php bin/auth.php login-link pat
# Docker:
docker compose exec -u www-data app php bin/auth.php login-link pat
```

It prints a one-time link (`{APP_URL}{APP_BASE_PATH}/login/link/…`).
Opening it shows a *Sign in as …* button, and pressing it signs that user
in, once, within 10 minutes. It works with password sign-in off and the
provider unreachable, never for a disabled user, and a new link replaces
the user's earlier one. Making and using it are logged at notice level.
Settings → Users lists an open link, and an admin can revoke it there.

Only someone with a shell on the server can make one: it is the same trust
as `bin/backup.php` and `bin/api-key.php`.

## Signing out

*Sign out* always ends the Logbook session. With `OIDC_LOGOUT=true` the
browser then goes on to the provider's sign-out page
(`end_session_endpoint`, with `id_token_hint`), which sends it back to
Logbook's sign-in page. Register `{APP_URL}{APP_BASE_PATH}/login` as the
post-logout redirect URI. If the provider publishes no
`end_session_endpoint`, or the session came from a password, sign-out stays
local.

Logbook doesn't take part in back-channel or front-channel logout: signing
out at the provider doesn't end a Logbook session that is already open.
Sessions still expire after 30 days without activity, and disabling a user
in Settings → Users ends theirs at once.

## When it doesn't work

People see a deliberately vague "Sign-in with Authentik didn't work". The
reason is in Logbook's log (`LOG_PATH`, default stderr: `docker compose
logs app`), at notice or warning level, prefixed "Single sign-on". Common
ones:

| The log says | Fix |
|---|---|
| The provider says its issuer is "…", but OIDC_ISSUER is "…" | Copy `issuer` from the discovery document into `OIDC_ISSUER` exactly (the trailing slash matters). |
| The ID token uses the algorithm "HS256" | Authentik: choose a *Signing Key* on the provider. Elsewhere: sign ID tokens with RS256 or ES256. |
| The token endpoint answered HTTP 401 (invalid_client) | The client secret is wrong, or the client isn't confidential. |
| The token endpoint answered HTTP 400 (invalid_grant) | The redirect URI registered differs from the one Settings → Users shows (scheme, host, base path), or the code was used already. |
| The callback's state is unknown or already used | The sign-in was started in another browser, the session cookie was lost (check `SESSION_SECURE` behind a TLS proxy), or the back button replayed it. Start again. |
| The sign-in took longer than 10 minutes | Start again. |
| … is not linked to any user | Link the account in Settings → Account, or see [Linking](#linking-accounts-to-users). |
| … not in OIDC_ALLOWED_GROUPS | The user isn't in the group, or the groups claim isn't sent (Keycloak: the Group Membership mapper; Authelia: the `groups` scope). |

A provider that can't be reached at all shows "Authentik can't be reached
just now" on the sign-in page. The password form still works if it is on,
and the break-glass link always works.

## Header sign-in

Many self-hosters already put every app behind a forward-auth proxy:
Authelia with nginx, Traefik or Caddy, or an Authentik outpost. The proxy
signs people in and passes the username on in a header such as
`Remote-User`. With header sign-in, someone who has signed in at the proxy
opens Logbook already signed in as their own user, without a second
sign-in or an OIDC client to set up.

> **Read this before switching it on.** Header sign-in trusts a header that
> anyone can type. It is only safe when **both** of these hold:
>
> 1. **The app is reachable only through the proxy.** No published port on
>    the app container, no LAN address that skips the proxy, and nothing
>    else on the proxy's network that could connect to it. Logbook checks
>    the *connecting address* against `AUTH_PROXY_TRUSTED` and ignores the
>    header from anywhere else. That check is only as good as your network.
> 2. **The proxy sets the header on every request it passes on**, replacing
>    whatever the client sent. The examples below do this. Watch out for a
>    "bypass" or public rule at the proxy that forwards a request without
>    authenticating it, because the client's own header then travels
>    through unchanged.
>
> **Underscores.** A client can send `Remote_User`, which many tools treat
> as `Remote-User`. Logbook reads the proxy's headers from the variables
> the web server hands PHP (`HTTP_REMOTE_USER`), and Apache 2.4 (the Docker
> image) never puts a header with an underscore there. On a bare-PHP
> install with nginx and php-fpm, keep nginx's default
> `underscores_in_headers off`, which drops them. PHP's built-in
> development server (`composer start`) doesn't drop them, so never put it
> behind a real proxy.

It is off unless `AUTH_PROXY_HEADER` (or `AUTH_PROXY_JWT_HEADER`) is set.
The app refuses to start with a header and no `AUTH_PROXY_TRUSTED`, and
names both variables, rather than trusting everyone. Every variable is in
[configuration.md](configuration.md#header-sign-in).

### How it works

- **Only the page routes.** The API, the calendar feed, `/health` and the
  app's static files never look at the header. Exempt them from forward
  auth at the proxy, because scripts and calendar apps can't sign in there.
  The examples do this.
- **Trust:** the header counts only when the connecting address
  (`REMOTE_ADDR`) is in `AUTH_PROXY_TRUSTED`, a comma-separated list of IP
  addresses and CIDR ranges (IPv4 and IPv6). `X-Forwarded-For` and
  `Forwarded` are never used for this, since a client can write them. From
  any other address the header is ignored and logged, at most once per
  address per hour: `Header Remote-User from 203.0.113.9 ignored: not a
  trusted proxy`. With Docker, the trusted address is the proxy container's
  address on the network it shares with the app. Give that network a fixed
  subnet so the address stays the same (the example below does).
- **Finding the user:** the header's value, trimmed and lower-cased, is the
  proxy account. A proxy account already linked to a user signs in as that
  user. With `AUTH_PROXY_LINK=username` (the default), a user with the
  same username and no proxy account yet is linked the first time. With
  `AUTH_PROXY_AUTO_CREATE=true`, anyone else becomes a new member (display
  name and email from `AUTH_PROXY_NAME_HEADER` and
  `AUTH_PROXY_EMAIL_HEADER`), who sees the welcome form once.
  `AUTH_PROXY_ALLOWED_GROUPS` and `AUTH_PROXY_ADMIN_GROUPS` work like the
  OIDC ones ([Groups and admins](#groups-and-admins)), read from
  `AUTH_PROXY_GROUPS_HEADER` (comma- or `|`-separated). Nothing happens
  while no user exists: run first-run setup on the app directly, or let the
  proxy through once with the header absent.
- **The session follows the header.** A header for someone else switches
  the session, under a new session id. A header that goes missing (signed
  out at the proxy, or the request didn't come through it) ends a session
  that the header started. A session from a password or OIDC sign-in is
  kept when no header arrives, so you can still reach Logbook directly on
  the LAN with your password. Only a header naming another *linked* user
  replaces it. Each switch answers with a redirect to the same page, so a
  form posted at that moment is never applied.
- **Linking a different name.** When your Logbook username differs from
  your proxy username, or with `AUTH_PROXY_LINK=identity`, sign in with
  your password and open Logbook through the proxy. A banner offers *Link
  your proxy account*. After that the proxy alone signs you in. Settings →
  Account lists the linked proxy account, and Settings → Users shows
  *Proxy* among each user's sign-in methods, where an admin can remove it.

### Signing out with header sign-in

*Sign out* ends the Logbook session, but the proxy would sign you straight
back in on the next request. Set `AUTH_PROXY_LOGOUT_URL` to the proxy's
sign-out page and *Sign out* goes on there (Authelia:
`https://auth.example.com/logout`; Authentik:
`https://logbook.example.com/outpost.goauthentik.io/sign_out`). Without
it, Logbook shows a page saying to sign out at the proxy. Signing out at
the proxy ends the Logbook session on the next request.

### Authelia with nginx

[`docker/nginx/forward-auth-example.conf`](../docker/nginx/forward-auth-example.conf)
is a complete server block for Logbook at `/logbook/`. It uses
`auth_request` against Authelia's `/api/authz/auth-request`, sets the four
`Remote-*` headers from Authelia's answer on every request, and exempts
the API, the calendar feed and `/health`. The smoke test
(`bin/smoke-test.sh header`) runs it unchanged. On the app:

```dotenv
APP_BASE_PATH=/logbook
AUTH_PROXY_HEADER=Remote-User
AUTH_PROXY_NAME_HEADER=Remote-Name
AUTH_PROXY_EMAIL_HEADER=Remote-Email
AUTH_PROXY_GROUPS_HEADER=Remote-Groups
AUTH_PROXY_TRUSTED=172.29.71.10
AUTH_PROXY_LOGOUT_URL=https://auth.example.com/logout
```

and in Docker Compose, a fixed address for nginx, with no `ports:` on the
app:

```yaml
services:
  proxy:
    networks:
      default:
        ipv4_address: 172.29.71.10
networks:
  default:
    ipam:
      config:
        - subnet: 172.29.71.0/24
```

### Authelia with Traefik

Blank the headers first, then `forwardAuth`, so a request Authelia lets
through without a user never carries the client's own:

```yaml
# Dynamic configuration (file provider)
http:
  middlewares:
    strip-remote:
      headers:
        customRequestHeaders:
          Remote-User: ""
          Remote-Groups: ""
          Remote-Name: ""
          Remote-Email: ""
    authelia:
      forwardAuth:
        address: http://authelia:9091/api/authz/forward-auth
        trustForwardHeader: true
        authResponseHeaders: [Remote-User, Remote-Groups, Remote-Name, Remote-Email]
  routers:
    logbook:
      rule: Host(`logbook.example.com`)
      middlewares: [strip-remote, authelia]
      service: logbook
    logbook-exempt:
      rule: Host(`logbook.example.com`) && (PathPrefix(`/api/`) || PathPrefix(`/calendar/`) || Path(`/health`))
      priority: 100
      middlewares: [strip-remote]
      service: logbook
  services:
    logbook:
      loadBalancer:
        servers:
          - url: http://app:80
```

`AUTH_PROXY_TRUSTED` is Traefik's address on the app's network.

### Authelia with Caddy

```caddyfile
logbook.example.com {
	# Never pass on what the client sent.
	request_header -Remote-User
	request_header -Remote-Groups
	request_header -Remote-Name
	request_header -Remote-Email

	@exempt path /api/* /calendar/* /health
	handle @exempt {
		reverse_proxy app:80
	}
	handle {
		forward_auth authelia:9091 {
			uri /api/authz/forward-auth
			copy_headers Remote-User Remote-Groups Remote-Name Remote-Email
		}
		reverse_proxy app:80
	}
}
```

`AUTH_PROXY_TRUSTED` is Caddy's address on the app's network.

### Authentik proxy outpost

Create a **Proxy Provider** for Logbook, in *Proxy* mode (the outpost
forwards to Logbook itself) or *Forward auth (single application)* with
nginx, Traefik or Caddy in front, and an application for it. Add
`^/(api|calendar)/.*` and `^/health$` to the provider's *Unauthenticated
Paths*, so the API, the calendar feed and the health check get through
without signing in. (Prefix them with your base path, for example
`^/logbook/(api|calendar)/.*`.) The outpost sets its `X-authentik-*`
headers on every request it forwards and drops headers with underscores.

There are two ways to use it.

**The plain header,** from the outpost's address only:

```dotenv
AUTH_PROXY_HEADER=X-authentik-username
AUTH_PROXY_NAME_HEADER=X-authentik-name
AUTH_PROXY_EMAIL_HEADER=X-authentik-email
AUTH_PROXY_GROUPS_HEADER=X-authentik-groups
AUTH_PROXY_TRUSTED=172.29.71.20
AUTH_PROXY_LOGOUT_URL=https://logbook.example.com/outpost.goauthentik.io/sign_out
```

**The signed JWT,** `X-authentik-jwt`. The outpost also passes on the ID
token its provider issued. An Authentik proxy provider can't have a signing
key, so Authentik signs that token with **HS256 and the provider's client
secret**. Logbook checks it with the same secret, so the header can't be
forged without the secret, and `AUTH_PROXY_TRUSTED` becomes optional:

```dotenv
AUTH_PROXY_JWT_HEADER=X-authentik-jwt
AUTH_PROXY_JWT_ISSUER=https://authentik.example.com/application/o/logbook/
AUTH_PROXY_JWT_AUDIENCE=<the provider's Client ID>
AUTH_PROXY_JWT_SECRET=<the provider's client secret>
# Optional, and still enforced when set:
AUTH_PROXY_TRUSTED=172.29.71.20
```

- The issuer is the application's, with the trailing slash. The client ID
  is on the provider's page. The client secret isn't shown there; an admin
  can read it from the API as `client_secret` in
  `GET /api/v3/outposts/proxy/` (with an API token for an admin).
- Only HS256 is accepted. Logbook checks the signature, the issuer, the
  audience (`aud` must contain the client ID), the expiry and the issue
  time, with 60 seconds of leeway. The username is `preferred_username`;
  `name`, `email` and `groups` also come from the token, never from the
  plain headers.
- **The secret can mint tokens.** Anyone who has it can sign in as anyone.
  Keep it out of logs and backups of `.env`, as you would a password.
- **A captured token works until it expires** (the provider's *Token
  validity*), from anywhere if `AUTH_PROXY_TRUSTED` is empty. When it
  expires, the Logbook session ends with it. The outpost's own session
  lasts the same validity (plus a second), so it signs in again at
  Authentik and passes on a fresh token.
  Keep the validity short, and set `AUTH_PROXY_TRUSTED` too when you can.
- With forward auth, also pass `X-authentik-jwt` on: add it to Traefik's
  `authResponseHeaders` or Caddy's `copy_headers`, or to nginx's
  `auth_request_set` and `proxy_set_header` lines.

### When header sign-in doesn't work

| The log says | Fix |
|---|---|
| Header Remote-User from 172.x.y.z ignored: not a trusted proxy | That is the proxy's address as Logbook sees it: put it (or its subnet) in `AUTH_PROXY_TRUSTED`. If it is your browser's address instead, the request bypassed the proxy. |
| Header … refused: the value is not one username | A comma or control characters in the value. Either the wrong header is configured, or it arrived twice (joined with a comma) because the proxy appended its header to the client's instead of replacing it. Use `proxy_set_header` (nginx) or the examples above. |
| Header X-authentik-jwt … refused: the JWT's issuer "…" is not AUTH_PROXY_JWT_ISSUER | Copy the issuer from the token (or the application's OpenID configuration) exactly, trailing slash included. |
| … the JWT was refused: Signature verification failed | `AUTH_PROXY_JWT_SECRET` isn't the provider's client secret. |
| … the JWT has expired | The outpost passed on an old token. Check the server clocks, and the provider's token validity. |
| Header sign-in: "…" is not linked to any user, or not in AUTH_PROXY_ALLOWED_GROUPS | Link it while signed in (the banner), set `AUTH_PROXY_LINK=username` or `AUTH_PROXY_AUTO_CREATE=true`, or check the groups header. |

"Your sign-in proxy didn't send a user" on the sign-in page means a
trusted proxy sent the request without the header. The proxy isn't
authenticating that path, or it is passing the header under another name.

"Too many redirects" through the proxy means the browser isn't keeping
Logbook's session cookie, so every page signs in again and redirects to
itself. Usually `SESSION_SECURE=true` (or an `https` `APP_URL`) while the
browser reaches Logbook over plain `http`.

## Upgrading and rolling back

Upgrading to 2.3.0 adds the `user_identities` table and lets a user have no
password. Nothing changes until you set `OIDC_ISSUER` or an
`AUTH_PROXY_*` header. Header sign-in needs no migration of its own: it
stores its linked accounts in the same table. Backups and
`bin/export-user.php` include linked accounts. In another install they work
only with the same provider and issuer.

Rolling the migration back is refused while any user has no password, and
the message names them. Give each a password first (Settings → Account, or
an admin's reset link), then roll back. Rolling back also removes open
break-glass links.

The `docker/sso/` folder has the Docker Compose files used to check
Logbook against each provider ([docker/sso/README.md](../docker/sso/README.md)).
