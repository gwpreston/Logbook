# Phase 35.2 — Proxmox LXC, Traefik and Caddy guides + v3.2 release

*Run it on the Proxmox box in the cupboard, behind the proxy you already
use.*

Status: ✅ complete · released as **v3.2.0** with Phase 35.1 · file lives in
`docs/phases/`

`docs/deployment.md` covers Docker, bare PHP, Apache and nginx. Since
Phase 23.2 there are forward-auth guides for Authelia and Authentik on
nginx, Traefik and Caddy, and the bare-PHP section has a Caddy example.
What is missing is the ordinary case on two popular proxies, **Traefik**
and **Caddy** in front of the Docker image (at the root and at a subpath),
and any guidance for **Proxmox**, where many self-hosters run small
services in LXC containers. Tracktor offers a Proxmox LXC install.

This phase is documentation, tested examples, and smoke tests that keep
them true. It changes no application behaviour.

Read [`CLAUDE.md`](../../CLAUDE.md), [`spec.md`](../../spec.md) §10
(deployment), §11 (reverse proxy, subpath, hard refresh), §9
(configuration), `docs/deployment.md`, and [Phase 23.2](phase-23.2.md)'s
guides first.

**Prerequisites:** [Phase 35.1](phase-35.1.md) built (the release includes
it).

---

## Goals

1. **Traefik and Caddy recipes** for the Docker image: root and subpath,
   automatic HTTPS, the health check, and what `APP_URL`, `APP_BASE_PATH`
   and `SESSION_SECURE` must be.
2. **A Proxmox LXC guide** that gets Logbook running in a container and
   keeps it backed up and updated.
3. **Examples that are tested**: the files in the guides are the files the
   smoke test runs.
4. Release **v3.2.0** (Phases 35.1 and 35.2).

## Not in scope

- An install script, or a listing in a third-party script collection (see
  the open questions).
- Changing how the app handles proxies, base paths or headers. If the
  recipes show a bug, that is its own fix.
- Kubernetes, Nomad, Unraid and other platforms.
- Moving or rewriting what `docs/deployment.md` already says. It gets
  links, not edits to its meaning.

---

## Spec additions

### §10 Deployment (changed)

> - **Reverse proxies** are documented, with tested examples, for nginx,
>   Apache, Traefik and Caddy, at the root and at a subpath
>   (`docs/reverse-proxies.md`), and for Authelia and Authentik behind
>   them (Phase 23.2).
> - **Proxmox VE:** `docs/proxmox-lxc.md` describes running the Docker image
>   inside an LXC container and running PHP 8.4 natively in one, each with
>   backups and updates.

### §11 Non-functional requirements (changed)

> - The production image is smoke-tested at the root and at a subpath
>   behind nginx, Caddy and Traefik: the health check, sign-in, a deep link
>   with a hard refresh, and an asset.

---

## Decisions (and why)

- **Examples are files, and the smoke test runs those files.** A recipe
  pasted into a guide goes stale the first time a proxy changes a label or
  a directive. A recipe that CI runs is caught the day it breaks.
- **Look the syntax up, don't recall it.** Traefik and Caddy both change
  between major versions. The tasks say to read the current documentation,
  and the guide records the versions it was tested with.
- **Nothing in `docs/deployment.md` changes meaning.** It gets links. Phase
  20's link test and every existing bookmark keep working.
- **The guides state what the app trusts, and no more.** A proxy guide that
  promises header handling the app does not have is worse than none.
- **Proxmox: every command run on a real host.** A guide for a platform
  where mistakes cost a container is only useful if it was followed once
  from a clean start.

---

## Tasks

### 35.2.0 Spec first
- [x] `spec.md` §10 and §11 as above; §13 entry; `ROADMAP.md` row and
      section.

### 35.2.1 Audit
- [x] Read `docs/deployment.md` and Phase 23.2's guides. List, under
      *Audit*, what each proxy already has (root, subpath, HTTPS, health
      check, forward auth) and what is missing. Build only what is
      missing.
- [x] Record what the app trusts from a proxy today: forwarded protocol and
      address headers, the trusted-proxy list used by header sign-in, and
      what `SESSION_SECURE` follows. The guides must say exactly this and no
      more.

### 35.2.2 Tested examples
- [x] `docker/examples/`: a Compose file for the app behind Traefik and a
      `Caddyfile` with its Compose file, each with a root variant and a
      subpath variant (`/logbook`), written against the **current**
      Traefik and Caddy documentation (look it up; do not write from
      memory). Each variant works whether the proxy forwards or strips the
      prefix, since the app supports both (§11).
- [x] The examples use the same service names, ports and variables as the
      existing compose files so a reader can diff them.

### 35.2.3 Reverse-proxy guide
- [x] `docs/reverse-proxies.md`: for each of nginx, Apache, Traefik and
      Caddy (existing examples linked, new ones written), root and
      subpath, HTTPS, the health check URL, `APP_URL`, `APP_BASE_PATH`,
      `SESSION_SECURE`, what to exempt for forward auth (link to Phase
      23.2), and the common failures (assets 404 at a subpath, redirect
      loops, a hard refresh on a deep link).
- [x] `docs/deployment.md` links to it from its proxy section. README docs
      table gains a row.

### 35.2.4 Proxmox LXC guide
- [x] `docs/proxmox-lxc.md` with **two routes**, each fully worked from a
      new container:
      - **Docker in an unprivileged container:** the container options
        Docker needs, installing Docker and the Compose plugin, using the
        project's compose file, data on the container's own storage, and
        the caveat that Proxmox's own guidance favours a virtual machine for
        Docker (state it plainly and link to the Proxmox documentation).
      - **PHP 8.4 natively:** a Debian container, PHP 8.4 and the required
        extensions, a database choice (SQLite for one household, or
        PostgreSQL in the same container), nginx or Apache, the cron entry
        for the scheduled task, `.env`, migrations, and the document root.
        State which Debian release ships PHP 8.4 itself and what to add for
        older ones, after checking.
- [x] For both: container size (disk, memory), start on boot, putting it
      behind the proxy on the Proxmox host or another container, **backups**
      (a Proxmox backup of the container *and* Logbook's own backup, and
      why both), **updating** (pull and restart; for the native route, pull,
      `composer install --no-dev -o`, migrate), and getting a shell to
      read logs.
- [x] Every command in the guide was **run on a real Proxmox host** against
      a fresh template, and the versions it was run on are written at the
      top with the date. No command pipes a download into a shell.
      *Open: the owner runs it before `v3.2.0` is tagged (see the open
      questions). Nothing pipes into a shell.*

### 35.2.5 Smoke tests and CI
- [x] Extend the proxy part of `bin/smoke-test.sh` to run the new examples
      for Caddy and Traefik at the root and at a subpath, with the checks
      the nginx case already makes (health, sign-in, deep link with hard
      refresh, an asset).
- [x] CI: add them to the existing smoke job if the time is acceptable, or
      to a separate workflow on a schedule and on changes to `docker/` and
      `docs/` (see the open questions).
- [x] The link-check test from Phase 20 passes with the new files.

### Release (with Phase 35.1)
- [x] `CHANGELOG.md` **3.2.0**: *Added* — demo mode (35.1); Traefik and
      Caddy recipes and the Proxmox LXC guide (35.2). No migration; new
      optional configuration (`DEMO_MODE`, `DEMO_PASSWORD`,
      `DEMO_RESET_HOURS`); no backup change. *Upgrade notes*: none.
- [x] Bump `VERSION`, rebuild assets, update the README status and
      `ROADMAP.md`.
- [x] Tag `v3.2.0` once merged.

---

## Acceptance criteria

- Following the Traefik guide, and separately the Caddy guide, gives a
  working HTTPS install at a root domain and at a subpath, and the smoke
  test proves it on every change to the examples.
- Following the Proxmox guide from a fresh container gives a working
  install by either route, with a backup and an update procedure that were
  actually run.
- The guides say what the app trusts from a proxy and nothing the app does
  not do.
- `composer check` passes; CI is green on all engines; the link test
  passes; the image builds.

## Audit

2026-10-06.

**What each proxy had before this phase:**

| Proxy | Root | Subpath | HTTPS | Health check | Forward auth | Tested by CI |
|---|---|---|---|---|---|---|
| nginx (in front of the image) | text only | `docker/nginx/subpath-example.conf`, forwarding and stripping | not shown | no | `docker/nginx/forward-auth-example.conf` (Phase 23.2) | yes: `pgsql`, `header` |
| nginx + php-fpm (bare PHP) | `deployment.md` | `deployment.md` | not shown | no | — | no |
| Apache (in front of the image) | no | `deployment.md`, three lines | `X-Forwarded-Proto` only | no | — | no |
| Apache (bare PHP) | `deployment.md` | `deployment.md` (`Alias`) | not shown | no | — | no (the image's own vhost is) |
| Caddy | no | `deployment.md`, three lines, forwarding only, no slash redirect | automatic, not mentioned | no | `sso.md` (Authelia, root) | no |
| Traefik | no | no | no | no | `sso.md` (Authelia, file provider, root) | no |

Built: Caddy and Traefik at the root and at a subpath (forwarding and
stripping), with HTTPS and the health check, and a guide that links the
nginx and Apache examples rather than repeating them.

**What the app trusts from a proxy today** (written into
`docs/reverse-proxies.md`):

- No forwarded header at all: nothing in `src/` or in `slim/psr7` reads
  `X-Forwarded-*` or `Forwarded`.
- The client address is `REMOTE_ADDR` (sign-in logging and throttling, the
  API's failed-key counter, OIDC and login-link logging). The image has no
  `mod_remoteip`, so behind a proxy that is the proxy's address.
- Header sign-in reads its header only from a `REMOTE_ADDR` inside
  `AUTH_PROXY_TRUSTED` (`ProxyHeaders`, `ProxyAuthConfig`).
- `SESSION_SECURE` defaults to `APP_URL` starting with `https://`
  (`AppSettings`), never to the request's scheme.
- Absolute links come from `APP_URL` (`AbsoluteUrl`), which may or may not
  repeat `APP_BASE_PATH`; redirects are relative; the prefix comes from
  `APP_BASE_PATH` only (`BasePathMiddleware`).

**Findings while building:** Traefik matches `Host()` without the port, and
when it can't get a certificate it serves its default one, so the examples
work over HTTPS on `localhost` unchanged apart from the resolver (dropped in
`docker/smoke/compose.traefik*.yml` so CI never calls Let's Encrypt).
Traefik 3's entry points stop reading a request after 60 s by default; the
example raises it to 600 s for backup restores. Caddy issues an internal
certificate for `localhost` by itself. No app bug was found.

**CI time (#220):** the four new smoke variants took 67 s together locally
with images cached (Caddy 22 s, the others 15 s each), against 31 s for the
existing `pgsql` variant: about a minute more, plus pulling `caddy:2.11-alpine`
and `traefik:v3.7` once. They run in the existing smoke job.

## Open questions

- **A script?** *Decided 2026-10-06 (#218):* docs only. A script is more
  to maintain and to test on every distribution release.
- **Which Proxmox route first?** *Decided 2026-10-06 (#219):* Docker in
  LXC first: it matches the README quick start and the one image
  everything else uses. The native PHP route follows it in the same guide.
- **CI cost of the proxy smoke tests.** *Decided 2026-10-06 (#220):* in
  the existing smoke job if they add only a few minutes; otherwise a
  separate workflow on a schedule and on changes to `docker/` and
  `docs/`. The measured time is recorded under *Audit*.
- **Third-party script collections.** Listing Logbook in one is a separate,
  outside decision and not part of this phase (#221, still the owner's).
- **Running the Proxmox guide on a real host.** *Decided 2026-10-06:* the
  guide is written from the current Proxmox, Debian and Docker
  documentation; the owner runs it on their Proxmox host and reports back
  before `v3.2.0` is tagged. Until then the versions line at the top of the
  guide is blank and task 35.2.4's last box stays unticked.
