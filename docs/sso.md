# Single sign-on

From 2.3.0 Logbook can sign people in with the identity provider you
already run: **Authelia**, **Authentik** or **Keycloak**, or any other
provider that speaks standard OpenID Connect. The sign-in page gets a
*Sign in with Authentik* button, and the provider's account is linked to a
Logbook user. Single sign-on only changes how someone proves who they are.
What they can see and do is still set in Logbook ([users-and-sharing.md](users-and-sharing.md)).

Passwords stay as the fallback, first-run setup still creates a local
admin, and a command-line link gets the owner in when the provider is down.
`spec.md` §7.9 has the exact rules.

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

## Upgrading and rolling back

Upgrading to 2.3.0 adds the `user_identities` table and lets a user have no
password. Nothing changes until you set `OIDC_ISSUER`. Backups and
`bin/export-user.php` include linked accounts. In another install they work
only with the same provider and issuer.

Rolling the migration back is refused while any user has no password, and
the message names them. Give each a password first (Settings → Account, or
an admin's reset link), then roll back. Rolling back also removes open
break-glass links.

The `docker/sso/` folder has the Docker Compose files used to check
Logbook against each provider ([docker/sso/README.md](../docker/sso/README.md)).
