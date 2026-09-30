# Phase 23.2 — Reverse-proxy header sign-in + v2.3 release

*When Authelia or Authentik already guards the door, don't ask twice.*

Status: 📋 planned · releases **v2.3.0** with Phase 23.1 · file lives in
`docs/phases/`

Many self-hosters put every app behind a forward-auth proxy: Authelia with
nginx `auth_request`, Traefik `forwardAuth` or Caddy `forward_auth`, or an
Authentik outpost. The proxy signs the person in and passes their username
in a header such as `Remote-User`. This phase lets Logbook trust that
header, **only** from proxies the admin lists, so a user who has already
signed in at the proxy lands in Logbook signed in.

The danger is well known: if anything other than the proxy can reach the
app, anyone can send the header. The design is built around that. It is off
unless configured, it trusts only listed addresses, it refuses to start
half-configured, and the docs say plainly how to deploy it safely.

Read [`CLAUDE.md`](../../CLAUDE.md), [`spec.md`](../../spec.md) §5, §7.9 and
§9, and Phase 23.1 first.

---

## Goals

1. Header sign-in configured by environment variables, off by default.
2. The header is trusted only when the **connecting address** (not
   `X-Forwarded-For`) is in `AUTH_PROXY_TRUSTED`.
3. Users are found through a `proxy` identity or by username, with optional
   creation and group-based admin, sharing Phase 23.1's rules.
4. The session follows the header: a different user or a missing header
   ends it.
5. Deployment guides for Authelia (nginx, Traefik, Caddy) and Authentik
   (proxy outpost).

## Not in scope

- Header sign-in for the API, calendar feed or `/health`. They keep their
  tokens, and forward-auth usually exempts them.
- Trusting `X-Forwarded-For` or `Forwarded` to work out the client address.
- mTLS or signed headers (for example, validating an Authentik JWT header).
  A possible later hardening.

---

## Spec additions

### §9 Configuration

> - `AUTH_PROXY_HEADER`: the username header (for example `Remote-User`,
>   `X-authentik-username`). Empty (the default) means header sign-in is
>   off.
> - `AUTH_PROXY_TRUSTED`: comma-separated IP addresses and CIDR ranges of
>   the proxy (for example `172.18.0.0/16`, `10.0.0.5`). **Required** when
>   the header is set. With the header set and this empty, the app refuses
>   to boot and names both variables, rather than trusting everyone.
> - `AUTH_PROXY_NAME_HEADER`, `AUTH_PROXY_EMAIL_HEADER` (optional; used only
>   when creating a user), `AUTH_PROXY_GROUPS_HEADER` (optional,
>   comma-separated values).
> - `AUTH_PROXY_LINK` (`identity` | `username`, default `username`). With a
>   proxy, the username is the proxy's decision; `identity` requires an
>   explicit link first, as OIDC's `explicit` does.
> - `AUTH_PROXY_AUTO_CREATE` (default `false`), `AUTH_PROXY_ALLOWED_GROUPS`,
>   `AUTH_PROXY_ADMIN_GROUPS` (as Phase 23.1's).
> - `AUTH_PROXY_LOGOUT_URL` (optional): where *Sign out* sends the browser,
>   for example Authelia's logout page.

### §7.9 Authentication: header sign-in

> - **Where it runs:** a middleware in the page (session) route group,
>   before the auth guard. It never runs for the API, calendar feed,
>   `/health` or assets.
> - **Trust check:** the connecting address (`REMOTE_ADDR`, as PHP sees
>   it) must be in `AUTH_PROXY_TRUSTED`. From any other address the header
>   is **ignored**, and the request goes through normal sign-in. A warning is
>   logged at most once per address per hour: "Header Remote-User from
>   203.0.113.9 ignored: not a trusted proxy". Behind Docker, the proxy's
>   container network is the trusted range.
> - **Resolving the user:** the header value is trimmed and lower-cased.
>   Then:
>   1. a `proxy` identity (Phase 23.1's table: provider `proxy`, issuer =
>      the header name, subject = the value) → that user;
>   2. else, with `AUTH_PROXY_LINK=username`, the user with that username is
>      linked (an identity row is written);
>   3. else, with `AUTH_PROXY_AUTO_CREATE=true`, a new member (display name
>      and email from their headers when present), sent to the welcome form;
>   4. else the "not linked" page (Phase 23.1's wording).
>   Allowed and admin groups apply as in Phase 23.1, read from
>   `AUTH_PROXY_GROUPS_HEADER`. A disabled user is refused.
> - **Session follows the header:**
>   - no Logbook session, or one for another user → sign in as the header's
>     user (session regenerated, CSRF rotated), marked as header-based;
>   - a header-based session and the header now missing or different → the
>     session ends, and then the new user (if any) is signed in;
>   - a session from a password or OIDC sign-in is kept while no header
>     arrives, so mixed access (LAN direct, internet through the proxy)
>     still works; a header for another user replaces it.
> - **Sign-in page:** with header sign-in on and the request from a trusted
>   proxy without the header, the page says "Your sign-in proxy didn't send
>   a user. Check its configuration", besides the usual methods.
> - **Sign-out:** ends the session. For header-based sessions it then goes
>   to `AUTH_PROXY_LOGOUT_URL` when set. Without one, the next request
>   would sign straight back in, so the page explains that sign-out happens
>   at the proxy.
> - **Settings → Users:** shows *Proxy* as a sign-in method; an admin can
>   remove the identity.

---

## Decisions (and why)

- **Connecting address only.** `X-Forwarded-For` is the header an attacker
  would forge next. The proxy that adds the username header is the one
  connecting, so its address is the trustworthy fact.
- **Refuse to boot when half-configured.** A header without a trusted list
  would let anyone sign in as anyone. A loud failure at start-up is the
  only safe outcome.
- **Username linking by default here,** unlike OIDC. With forward auth the
  proxy decides who someone is and passes nothing else stable. An
  `identity` mode remains for admins who want explicit links.
- **Password sessions survive when no header arrives.** People reach
  self-hosted apps both directly on the LAN and through the proxy. Only a
  header for someone else overrides.

---

## Tasks

### Spec and docs
- [ ] §7.9 and §9 in `spec.md`; the Phase 23.2 line in §13.
- [ ] `docs/sso.md` gains *Header sign-in*: a warning box (the app must be
      unreachable except through the proxy, and the proxy must overwrite
      the header on every request), then worked configs for Authelia with
      nginx `auth_request`, Traefik `forwardAuth` and Caddy `forward_auth`,
      and an Authentik proxy outpost, each exempting `/api/`, `/calendar/`
      and `/health` from forward auth.
- [ ] `docker/nginx` example updated; `.env.example` and
      `docs/configuration.md` gain every new variable.

### Code
- [ ] `Support\Net\IpRange` (IPv4 and IPv6 CIDR matching, parsed at boot).
- [ ] Boot-time configuration check (header set without a trusted list →
      refuse to start with a clear message, in the web entry point and the
      CLI).
- [ ] `Middleware\ProxyAuthMiddleware` and `Service\Auth\ProxySignIn`
      (resolution, groups, session following). Reuse Phase 23.1's identity
      repository, JIT welcome form and group sync.
- [ ] Sign-out redirect; sign-in page notice; Settings → Users method.
- [ ] Translations (en, de).

### Tests
- [ ] **Trust:** a header from a trusted IPv4 address, an IPv6 address and
      a CIDR range signs in; from an untrusted address it is ignored and
      logged once per hour; `X-Forwarded-For` naming a trusted address from
      an untrusted peer is ignored.
- [ ] Boot refuses a header without a trusted list.
- [ ] **Resolution:** identity, username linking, `identity` mode refusing
      unlinked users, JIT, allowed groups, admin sync and the last-admin
      guard, a disabled user refused.
- [ ] **Session following:** another user's header replaces the session; a
      missing header ends a header-based session; a password session
      survives requests without a header; fixation (the id changes on
      every switch).
- [ ] Never active on the API, calendar feed, `/health` or assets.
- [ ] Sign-out with and without `AUTH_PROXY_LOGOUT_URL`.
- [ ] Works under `APP_BASE_PATH`.
- [ ] Integration suite green on every engine.
- [ ] **Smoke test:** `bin/smoke-test.sh` gains a header-auth run behind the
      nginx example, with the header sent by nginx only.

### Release (with Phase 23.1)
- [ ] `CHANGELOG.md` **2.3.0**: OIDC sign-in and header sign-in. Upgrade
      notes: one migration (identities, nullable passwords); everything is
      off until configured; the security notes for header sign-in.
- [ ] Bump `VERSION`, rebuild assets, update the README (status, the
      documentation table gains `docs/sso.md`).

---

## Acceptance criteria

1. Behind Authelia with forward auth, a user signed in at Authelia opens
   Logbook already signed in as their own user.
2. The same header sent from anywhere except a trusted proxy signs no one
   in.
3. The app will not start with a header configured and no trusted proxies.
4. Switching user at the proxy switches the Logbook session, and signing out
   at the proxy signs out of Logbook on the next request.
5. Definition of done (CLAUDE.md §11) holds.

## Open questions

- **Authentik's signed JWT header:** worth validating instead of trusting a
  plain username header, when the outpost sends one? It removes the
  trusted-network requirement for Authentik users, at the cost of a second
  code path.
- **Default `AUTH_PROXY_LINK`:** `username` (drafted) or `identity`?
