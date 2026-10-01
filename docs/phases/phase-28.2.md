# Phase 28.2 — Update check and dashboard banner + v2.11 release

*Know when a new Logbook is out, without anything updating itself.*

Status: 📋 planned · releases **v2.11.0** with Phase 28.1 · file lives in
`docs/phases/`

A daily job asks GitHub for the latest Logbook release and compares it
with the installed version. When a newer one exists, admins see a banner on
the dashboard with a link to the release notes and to the upgrade guide.
It never downloads or installs anything.

It is the first request Logbook makes to a third party without being
configured to, so it is **off until an admin switches it on**, and the
setting says exactly what is sent.

Read [`CLAUDE.md`](../../CLAUDE.md), [`spec.md`](../../spec.md) §9 and §10,
and Phase 28.1 (jobs and admin notices) first.

---

## Goals

1. An `update_check` job (daily, plus *Check now*) calling GitHub's latest
   release endpoint for the configured repository.
2. Settings → System → *Updates*: *Check for updates* (off by default),
   *Show update banner* (on), the last result, and *Check now*.
3. An admin notice on the dashboard when a newer version is out, which can
   be dismissed per version.
4. Upgrade guidance that fits how Logbook is installed (Docker or bare
   PHP).

## Not in scope

- Downloading, verifying or applying updates.
- Pre-release or beta channels (see *Open questions*).
- Showing release notes inside Logbook.
- Telling members (non-admins) about updates.

---

## Spec additions

### §7.31 Updates (new)

> - **Settings → System → Updates** (admins):
>   - *Check for updates*: **off by default**, with the explanation "Once a
>     day, Logbook asks api.github.com for the latest release of
>     {repo}. Nothing about your data is sent; GitHub sees your server's
>     address and the app's version."
>   - *Show update banner*: on by default. With it off, the result still
>     shows on this page.
>   - The installed version (from `VERSION`), and the result: latest
>     version, published date, release link, last checked, or the last
>     error. *Check now* runs the job (Phase 28.1's *Run now*).
>   - First-run setup offers *Tell me when a new version is out* as an
>     unticked checkbox.
> - **The job** `update_check`, daily, at a random minute chosen once per
>   install (so installs don't all call at midnight), only when the setting
>   is on:
>   - `GET https://api.github.com/repos/{UPDATE_CHECK_REPO}/releases/latest`
>     with `Accept: application/vnd.github+json`,
>     `X-GitHub-Api-Version: 2022-11-28`, `User-Agent: Logbook/{version}
>     (+https://github.com/{repo})`, and `If-None-Match` with the last ETag.
>   - A timeout of 10 seconds and a response cap of 1 MB. Redirects are
>     followed only to `api.github.com` (a renamed repository), at most two.
>   - `304`: unchanged, and `last_checked_at` is updated. `404`: "No releases
>     published yet". `403` or `429`: rate limited, and the next try waits
>     for `Retry-After` or `X-RateLimit-Reset`. Other errors are recorded
>     with their status. Errors never show a banner.
>   - From the response only `tag_name`, `html_url`, `published_at` and
>     `name` are read. `tag_name` must match `^v?\d+\.\d+\.\d+$`; `html_url`
>     must start with `https://github.com/{repo}/releases/`. Anything else
>     is recorded as an error, not shown. `releases/latest` already leaves
>     out drafts and pre-releases.
>   - Versions are compared as semantic versions (`v2.12.0` > `2.11.3`). A
>     development build (`2.11.0-dev`) counts as older than `2.11.0`.
>   - The result is stored in instance settings: latest version, release
>     URL, release name, published at, ETag, last checked at, last error.
>   - The job's summary: "2.12.0 available (installed 2.11.0)", "Up to date
>     (2.11.0)", "Newer than the latest release (2.12.0-dev)", or the error.
> - **Banner** (Phase 28.1's admin notice area, dashboard, admins only),
>   when checking is on, the banner is on, and the latest is newer than
>   installed: "Logbook {latest} is available (you have {installed}).
>   Release notes · How to upgrade". *Release notes* opens the `html_url`.
>   *How to upgrade* goes to `docs/deployment.md#upgrading`, plus the line
>   for this install: Docker (`LOGBOOK_DOCKER=1`, set by the image) gives
>   "`docker compose pull && docker compose up -d`"; bare PHP gives "Back
>   up, then follow the upgrade steps". Release names are shown as escaped
>   text. **Dismiss** hides it for that version, per admin, and the next
>   newer release shows it again.
> - **Never automatic:** no file is downloaded, and nothing runs that came
>   from GitHub.

### §9 Configuration

> - `UPDATE_CHECK_REPO` (default `gwpreston16/Logbook`; forks set their own).
> - `UPDATE_CHECK_ALLOWED` (default `true`; `false` removes the option
>   entirely, for installs that must never call out).

---

## Decisions (and why)

- **Off by default.** Logbook's promise is that it makes no third-party
  requests unless the owner sets them up. An update check is a reasonable
  exception only when the owner chooses it.
- **Read four fields and validate them.** The response is from the
  internet. Only what the banner needs is read, the link is checked to be
  the repository's own release page, and the release body is never
  rendered.
- **Upgrade advice by install type.** "A new version is out" is only useful
  with the next step, and the step differs between Docker and bare PHP.
- **Dismiss per version.** Hiding the banner for 2.12.0 must not hide
  2.13.0.

---

## Tasks

### Spec and docs
- [ ] §7.31 and §9 in `spec.md`; the Phase 28.2 line in §13.
- [ ] `docs/deployment.md`: an *Upgrading* anchor (exists; check the link
      target) and a short *Update check* section; `.env.example` and
      `docs/configuration.md` gain the two variables.
- [ ] Docker image sets `LOGBOOK_DOCKER=1`.

### Code
- [ ] `Service\Updates\ReleaseChecker` (request, ETag, rate limits,
      validation, semver comparison) as the `update_check` job.
- [ ] `Support\Version\SemVer` (parse, compare, pre-release suffix).
- [ ] Settings → System → Updates; the setup checkbox; the banner in the
      admin notice area; per-admin dismissal by version.
- [ ] Translations (en, de).

### Tests
- [ ] **Recorded responses** with a PSR-18 mock (CI needs no network):
      newer, same, older; `304` with ETag; `404`; `403` and `429` with
      `Retry-After` and `X-RateLimit-Reset`; a timeout; an oversized body; a
      redirect to `api.github.com` (followed) and to another host (refused);
      a `tag_name` that isn't a version; an `html_url` for another
      repository.
- [ ] SemVer: `v` prefix, patch and minor ordering, `-dev` older than its
      release.
- [ ] Off by default: no request is ever made until switched on; with
      `UPDATE_CHECK_ALLOWED=false` the setting is gone and the job never
      calls out.
- [ ] Banner: shown only to admins, only when newer, only with the banner
      on; dismissed per version and per admin; back for the next version.
- [ ] Install type: the Docker line with `LOGBOOK_DOCKER=1`, the bare line
      without.
- [ ] Release name with HTML is escaped.
- [ ] The random daily minute stays the same across runs for one install.
- [ ] Integration suite green on every engine.

### Release (with Phase 28.1)
- [ ] `CHANGELOG.md` **2.11.0**: background jobs in Settings (run now,
      output, health warning, page-visit and URL triggers, scheduled
      backups) and the update check. Upgrade notes: one migration; the
      update check is off until switched on; bare installs without cron can
      now use the page-visit or URL trigger.
- [ ] Bump `VERSION`, rebuild assets, update the README status.

---

## Acceptance criteria

1. With *Check for updates* on and a newer release on GitHub, an admin sees
   the banner with the right versions, the release link and the upgrade
   step for their install.
2. Dismissing it hides it until the next newer release.
3. With the setting off, or `UPDATE_CHECK_ALLOWED=false`, Logbook never
   contacts GitHub.
4. A failed or rate-limited check never shows a banner, and its reason is
   on the Updates page.
5. Definition of done (CLAUDE.md §11) holds.

## Open questions

- **Pre-releases:** offer a *Include pre-releases* option, which would use
  the releases list instead of `latest`, or keep to stable releases only
  (drafted)?
- **Setup default:** keep the setup checkbox unticked (drafted), or ticked
  with the explanation beside it?
- **Security releases:** mark a release as a security fix (for example a
  `[security]` tag in its name) and show the banner even when the banner
  setting is off?
