# Phase 23.1 — Single sign-on with OpenID Connect

*Sign in with the Authelia, Authentik or Keycloak you already run.*

Status: 📋 planned · ships with Phase 23.2 as **v2.3.0** · file lives in
`docs/phases/`

Self-hosters often run an identity provider already. This phase lets
Logbook act as an OpenID Connect client: a *Sign in with Authentik* button,
authorization code flow with PKCE, and the ID token checked properly. The
provider's account is linked to a Logbook user. It builds on Phase 19's
users (admins, invitations, disabling), so SSO only changes how someone
proves who they are, never what they can see.

Local passwords remain as the fallback. First-run setup still creates a
local admin, and a command-line break-glass link means an identity provider
outage can never lock the owner out.

Read [`CLAUDE.md`](../../CLAUDE.md) (§9 Security, §12 open questions) and
[`spec.md`](../../spec.md) §5, §6 User and Session, §7.9 and §9 first.

---

## Goals

1. One OIDC provider configured by environment variables, found through
   discovery (`/.well-known/openid-configuration`).
2. Authorization code flow with PKCE (S256), `state` and `nonce`, and a
   confidential client (client secret).
3. Full ID token validation: signature against the provider's JWKS, `iss`,
   `aud`, `azp`, `exp`, `iat` and `nonce`.
4. **Linking:** explicit by default (a signed-in user links their account),
   optionally by username. Optionally, users are created on first sign-in
   and admin is set from groups.
5. Local sign-in can be switched off for everyone. There is a break-glass
   link from the CLI.
6. Optional sign-out at the provider (RP-initiated logout).

## Not in scope

- More than one provider (see *Open questions*).
- SAML, LDAP and social logins (Google, GitHub) as named features. Any
  provider that speaks standard OIDC works, but only the three are
  documented and tested.
- Back-channel or front-channel logout, and refresh tokens. Logbook keeps
  its own session and never calls the provider after sign-in.
- SSO for the API or the calendar feed. They keep their tokens (Phase
  18.2, §7.6).
- Reverse-proxy header sign-in (Phase 23.2).

---

## Spec additions

### §6 Data model

**UserIdentity** (Phase 23.1): id, user_id (`ON DELETE CASCADE`),
provider (`oidc`; `proxy` from Phase 23.2), issuer (the `iss` URL, up to
255), subject (the `sub`, up to 255), last_login_at (UTC), created_at.
`(provider, issuer, subject)` is unique, so a provider account links to
at most one user. A user may have several identities.

**User:** password_hash becomes nullable. A user created through SSO has
none until they set one, and cannot sign in locally until then.

### §7.9 Authentication: single sign-on

- **Configuration** (§9): `OIDC_ISSUER` (the issuer URL; setting it
  switches SSO on), `OIDC_CLIENT_ID`, `OIDC_CLIENT_SECRET`,
  `OIDC_PROVIDER_NAME` (button text, default "SSO"), `OIDC_SCOPES`
  (default `openid profile email`), `OIDC_USERNAME_CLAIM` (default
  `preferred_username`), `OIDC_GROUPS_CLAIM` (default `groups`),
  `OIDC_LINK` (`explicit` | `username`, default `explicit`),
  `OIDC_AUTO_CREATE` (default `false`), `OIDC_ALLOWED_GROUPS` and
  `OIDC_ADMIN_GROUPS` (comma-separated, optional), `OIDC_LOGOUT` (default
  `false`), `AUTH_LOCAL_LOGIN` (default `true`). The redirect URI to
  register at the provider is `{APP_URL}{APP_BASE_PATH}/auth/oidc/callback`.
  Settings → Users shows it with a copy button, and the admin sees
  whether SSO is configured.
- **Discovery** is fetched from `{OIDC_ISSUER}/.well-known/openid-configuration`
  on first use and cached under `var/cache` for 24 hours. The JWKS is
  cached likewise and re-fetched once when a token's `kid` is unknown.
  The discovered `issuer` must equal `OIDC_ISSUER` exactly, or SSO is
  refused and the reason logged. This is the only outbound request the
  app makes, and only when an admin configures it.
- **Sign-in page:** with SSO configured, a *Sign in with {name}* button
  above the password form. With `AUTH_LOCAL_LOGIN=false` the password
  form is gone, and the page shows only the button.
- **Flow:** `GET /auth/oidc/start?return=…` creates a pre-sign-in session
  holding `state`, `nonce`, the PKCE verifier and the checked `return`
  (local paths only, as §7.9's sign-in redirect). It then redirects to the
  authorization endpoint (`response_type=code`, S256 challenge).
  `GET /auth/oidc/callback` checks `state` (single use, 10 minutes),
  exchanges the code at the token endpoint with the client secret and
  verifier, and validates the ID token:
  - the signature uses a key from the JWKS, with algorithms RS256, PS256,
    ES256 or EdDSA only (`none` and HS* are refused);
  - `iss` equals the issuer; `aud` contains the client id; `azp` equals it
    when present; `exp` is in the future and `iat` not in the future, with
    60 seconds of leeway; `nonce` matches.
  Any failure shows "Sign-in with {name} didn't work. Try again, or sign
  in with your password" (the password option only when local sign-in is
  on), and the specific reason is logged, never shown.
- **Finding the user:**
  1. An identity with this issuer and `sub` → that user.
  2. Else, with `OIDC_LINK=username`: a user whose username equals the
     username claim (lower-cased) and who has **no** OIDC identity yet is
     linked. Only use this with a provider whose usernames only admins can
     set, as the docs say.
  3. Else, with `OIDC_AUTO_CREATE=true`: a new member is created (username
     from the claim, sanitised, suffixed if taken; display name from
     `name`; locale from `locale` when supported, else `APP_LOCALE`). They
     land on a short welcome form (time zone, unit preset, currency), as
     invitations do.
  4. Else: "Your {name} account isn't linked to Logbook. Ask an admin to
     invite you, then link it from Settings → Account."
- **Groups:** with `OIDC_ALLOWED_GROUPS`, a user outside them is refused
  (message as 4). With `OIDC_ADMIN_GROUPS`, `is_admin` is set from them at
  every SSO sign-in, both ways, except that the last admin is never
  demoted (logged). Without these variables, groups are ignored and admin
  stays as set in the app.
- **After sign-in:** exactly as a password sign-in. The session is
  regenerated, CSRF rotated, it returns to `return`, and a disabled user
  is refused. The session remembers that it came from SSO, and keeps the
  ID token only for logout when `OIDC_LOGOUT` is on.
- **Linking** (Settings → Account → *Single sign-on*): *Link {name}
  account* runs the flow for the signed-in user and stores the identity.
  It is refused if that identity belongs to someone else. *Unlink* is
  refused while it is the user's only way in (no password and local
  sign-in on, or local sign-in off).
- **Passwords for SSO users:** *Set a password* appears when local sign-in
  is on. Changing it signs out other sessions, as today.
- **Sign-out:** local sign-out as today. With `OIDC_LOGOUT=true` and an
  `end_session_endpoint`, the browser then goes there with `id_token_hint`
  and `post_logout_redirect_uri` = the sign-in page.
- **Break-glass:** `php bin/auth.php login-link <username>` prints a
  one-time sign-in link (10 minutes, keyed hash stored, as invitations
  are). It works even with `AUTH_LOCAL_LOGIN=false` and SSO down. Its use
  is logged.
- **Setup** (first run) is unchanged: it always creates a local admin with
  a password, whatever the SSO settings.
- **Admin view:** Settings → Users shows each user's sign-in methods
  (*Password*, *{name}*), and an admin can remove an identity.

---

## Decisions (and why)

- **Explicit linking by default.** Matching on username or email lets
  anyone who can create an account at the provider take over a Logbook user
  of the same name. Linking from a signed-in session proves both sides.
- **Groups sync admin only when asked.** Without the variables, the app's
  own user management keeps working exactly as in Phase 19.
- **The ID token is validated in full.** Discovery and the token endpoint
  run over TLS, but the ID token is the proof. Every check is listed and
  tested.
- **Local sign-in stays on by default, and there is always a break-glass
  link.** An identity provider is one more thing that can be down, and a
  vehicle logbook must never be unreachable because of it.
- **One provider.** The three named products each act as a single identity
  provider, and several providers add linking questions with little gain
  for a household.

---

## Tasks

### Spec and docs
- [ ] §6, §7.9 and §9 in `spec.md`; the Phase 23.1 line in §13; remove the
      OIDC line from §12.
- [ ] `docs/sso.md`: client setup in Authelia, Authentik and Keycloak (the
      client type, redirect URI and scopes; the groups claim and how each
      emits it), linking modes and their risk, and break-glass.
- [ ] `.env.example` and `docs/configuration.md`: every new variable.

### Dependencies
- [ ] Choose an OIDC or JWT library (see *Open questions*). Requirements:
      pure PHP, maintained, PHP 8.4 and 8.5, PSR-18 or its own HTTP client
      without extensions beyond `openssl` and `sodium` (EdDSA), JWKS
      support, and no framework coupling. Pin it and record the choice in
      `spec.md` §4.

### Migration
- [ ] `user_identities`; `users.password_hash` nullable. Every engine,
      reversible. Rollback is refused while any user has no password, with
      a message naming them. Moves the schema version; backups include the
      table.

### Code
- [ ] `Service\Auth\Oidc\Discovery` (cache, issuer check),
      `Service\Auth\Oidc\TokenValidator`, `Service\Auth\Oidc\OidcSignIn`
      (flow state, user resolution, groups, JIT).
- [ ] `Action\Auth\OidcStart`, `OidcCallback`, `OidcLink`, `OidcUnlink`;
      welcome form for JIT users.
- [ ] Sign-in page changes; Settings → Account *Single sign-on* card;
      Settings → Users sign-in methods and the redirect URI.
- [ ] `bin/auth.php login-link`.
- [ ] Translations (en, de).

### Tests
- [ ] **A test identity provider** in PHPUnit: an in-process fake issuing
      discovery, JWKS and tokens signed with test keys (RS256, ES256,
      EdDSA), so the suite needs no network.
- [ ] **Token validation:** a good token passes. Each of these fails: wrong
      signature, `alg: none`, HS256, unknown `kid` (refetch then fail),
      wrong `iss`, wrong `aud`, wrong `azp`, expired, `iat` in the future
      beyond leeway, nonce mismatch, replayed `state`, `state` older than
      10 minutes.
- [ ] **Resolution:** existing identity; username linking (and not when the
      user already has an identity); JIT on and off; allowed groups; admin
      sync both ways and the last-admin guard; a disabled user refused.
- [ ] **Linking:** link, refused when taken, unlink refused when it is the
      only way in.
- [ ] `AUTH_LOCAL_LOGIN=false`: the form is gone and a password POST is
      refused; break-glass works once and expires.
- [ ] `return` open-redirect attempts ignored; works under
      `APP_BASE_PATH`; session fixation (the id changes at sign-in).
- [ ] RP logout URL built correctly; without an `end_session_endpoint`,
      local sign-out only.
- [ ] Integration suite green on every engine.
- [ ] **Manual interop check** (in the PR): against real Authelia,
      Authentik and Keycloak containers, with the `docker-compose` examples
      kept in `docker/sso/`.

---

## Acceptance criteria

1. With four variables set, the sign-in page offers *Sign in with
   Authentik*, and a linked user signs in through it.
2. A token failing any validation rule never signs anyone in.
3. An unlinked provider account cannot reach any Logbook user unless the
   admin chose username linking or automatic creation.
4. With local sign-in off and the provider down, the owner still gets in
   with a break-glass link from the CLI.
5. Definition of done (CLAUDE.md §11) holds.

## Open questions

- **Library:** use a dedicated OIDC client library, or `league/oauth2-client`
  plus a JWT and JWKS library with the validation written here? The first
  is less code; the second keeps every check visible and tested.
- **More than one provider:** needed (for example, Authentik for the family
  and Keycloak at work), or is one enough?
- **Email linking:** should `OIDC_LINK=email` exist, matching a verified
  (`email_verified: true`) email to the user's reminder email address? It
  is off in this draft because Logbook doesn't verify its own emails.
