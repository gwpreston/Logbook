# Checking single sign-on against a real provider

The PHPUnit suite tests single sign-on against an in-process provider
(`tests/Support/FakeIdentityProvider.php`), so it needs no network. This
folder is for checking by hand that Logbook works with the real products
([docs/sso.md](../../docs/sso.md)).

Run Logbook on the host, bare PHP, so the browser and PHP both reach the
provider at `localhost`:

```sh
export APP_URL=http://localhost:8090 DB_DRIVER=sqlite DB_NAME=var/sso-check.sqlite
export OIDC_ISSUER=http://localhost:8180/realms/logbook OIDC_CLIENT_ID=logbook \
       OIDC_CLIENT_SECRET=logbook-test-secret OIDC_PROVIDER_NAME=Keycloak OIDC_LOGOUT=true
vendor/bin/phinx migrate -e development
composer start          # http://localhost:8090
```

Then set up the first account, link it in Settings → Account → *Single
sign-on*, sign out, and sign in with the button.

## Keycloak

```sh
docker compose -f docker/sso/keycloak/compose.yml up -d
```

Development mode, in memory. It imports the realm `logbook`
(`keycloak/realm-logbook.json`) with a confidential client `logbook`
(secret `logbook-test-secret`, PKCE S256, a `groups` mapper in the ID
token), the redirect and post-logout URIs for `http://localhost:8090` (and
`/logbook` under a base path), and two users: `pat` / `pat-password` (groups
`logbook`, `logbook-admins`) and `sam` / `sam-password` (group `logbook`).
Admin console: http://localhost:8180, `admin` / `admin`.

Checked with Keycloak 26.4 for Phase 23.1 (RS256 tokens): link, sign in
(back to the page asked for) with `OIDC_ALLOWED_GROUPS=logbook` and
`OIDC_ADMIN_GROUPS=logbook-admins` set, an unlinked account refused, and
sign-out at Keycloak returning to sign-in.

## Authentik and Authelia

Start them from their own quick-start Compose files, which change between
releases:

- Authentik: https://docs.goauthentik.io/install-config/install/docker-compose
- Authelia: https://www.authelia.com/integration/deployment/docker/

Then create the client as [docs/sso.md](../../docs/sso.md#authentik) and
[docs/sso.md](../../docs/sso.md#authelia) describe. Authelia wants its own
address on https, so put it behind a TLS proxy (or use its `local`
certificates) and set `OIDC_ISSUER` to that address.
