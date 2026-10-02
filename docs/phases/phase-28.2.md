# Phase 28.2 — Update check and dashboard banner + v2.11 release

*Know when a new Logbook is out, without anything updating itself.*

Status: ✅ complete · releases **v2.11.0** with Phase 28.1 · file lives in
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
2. Settings → *Installation* → *Updates*: *Check for updates* (off by default),
   *Show update banner* (on), the last result, and *Check now*.
3. An admin notice on the dashboard when a newer version is out, which can
   be dismissed per version.
4. Upgrade guidance that fits how Logbook is installed (Docker or bare
   PHP).

## Not in scope

- Downloading, verifying or applying updates.
- Pre-release or beta channels (#111, spec §12).
- Showing release notes inside Logbook.
- Telling members (non-admins) about updates.

---

## Spec additions

What was added to `spec.md` is §7.31, with the decisions below; the
draft here is kept as it was planned.

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
- [x] §7.31 and §9 in `spec.md`; the Phase 28.2 line in §13.
- [x] `docs/deployment.md`: an *Upgrading* anchor (exists; check the link
      target) and a short *Update check* section; `.env.example` and
      `docs/configuration.md` gain the two variables.
- [x] Docker image sets `LOGBOOK_DOCKER=1`.

### Code
- [x] `Service\Updates\ReleaseChecker` (request, ETag, rate limits,
      validation, semver comparison) as the `update_check` job, which
      names its own due time (`TimedJob`: the install's daily minute and a
      rate limit's wait) and is never `failed` for GitHub's errors (#114).
- [x] `Support\Version\SemVer` (parse, compare, pre-release suffix).
- [x] Settings → Updates (under *Installation*); the setup checkbox; the
      banner in the admin notice area, with the release's own upgrade
      guide (#116); per-admin dismissal by version, kept for good.
- [x] Translations (en, de).

### Tests
- [x] **Recorded responses** with Symfony's `MockHttpClient` (CI needs no
      network):
      newer, same, older; `304` with ETag; `404`; `403` and `429` with
      `Retry-After` and `X-RateLimit-Reset`; a timeout; an oversized body; a
      redirect to `api.github.com` (followed) and to another host (refused);
      a `tag_name` that isn't a version; an `html_url` for another
      repository (the "moved" error, #115); *Check now* during a rate
      limit's wait sends nothing (#117); GitHub's errors are `ok` runs
      that never raise a failure alert (#114).
- [x] SemVer: `v` prefix, patch and minor ordering, `-dev` older than its
      release.
- [x] Off by default: no request is ever made until switched on, by a
      pass, *Run now*, `bin/run-job.php` or the URL trigger; with
      `UPDATE_CHECK_ALLOWED=false` the setting is gone and the job isn't
      registered.
- [x] Banner: shown only to admins, only when newer, only with the banner
      on; dismissed per version and per admin; back for the next version.
- [x] Install type: the Docker line with `LOGBOOK_DOCKER=1`, the bare line
      without.
- [x] Release name with HTML is escaped.
- [x] The random daily minute stays the same across runs for one install.
- [x] Integration suite green on every engine.

### Release (with Phase 28.1)
- [x] `CHANGELOG.md` **2.11.0**: background jobs in Settings (run now,
      output, health warning, page-visit and URL triggers, scheduled
      backups) and the update check. Upgrade notes: one migration; the
      update check is off until switched on; bare installs without cron can
      now use the page-visit or URL trigger.
- [x] Bump `VERSION`, rebuild assets, update the README status.

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

All decided on 2026-10-02, before the phase started:

- **Pre-releases** (#111). *Decided 2026-10-02:* stable releases only, as
  drafted (`releases/latest`); an *Include pre-releases* option is in
  spec §12.
- **Setup default** (#112). *Decided 2026-10-02:* unticked, as drafted
  (spec §7.31).
- **Security releases** (#113). *Decided 2026-10-02:* no marking; every
  release is treated alike and the banner setting always applies. Marking
  is in spec §12.

Found while starting it:

- **Failure alerts for a failed check** (#114). Phase 28.1 alerts admins
  when a job fails twice in a row, which a GitHub outage, a rate limit or
  a repository with no releases would do every day. *Decided 2026-10-02:*
  GitHub's errors are recorded as an `ok` run with the error as its
  summary and on the Updates page; only an error in Logbook itself fails
  the run (spec §7.31).
- **A renamed repository** (#115). GitHub redirects to
  `api.github.com/repositories/{id}/…`, and the release's `html_url` then
  names the new repository, so it fails the link check. *Decided
  2026-10-02:* follow the redirect (api.github.com only, at most two),
  then record "The repository has moved to {owner/name}; set
  `UPDATE_CHECK_REPO`" and show no banner; the name is compared ignoring
  case (spec §7.31).
- **Where *How to upgrade* points** (#116). The app doesn't serve
  `docs/`. *Decided 2026-10-02:* GitHub, at the release's tag:
  `https://github.com/{repo}/blob/v{latest}/docs/deployment.md#upgrading`
  (spec §7.31).
- ***Check now* during a rate limit** (#117). *Decided 2026-10-02:* it
  waits like the daily run: no request, and "Rate limited by GitHub until
  {time}" (spec §7.31).

Settled while building (spec §7.31): *Check now* shows only while
checking is on; a rate limit's wait is kept between a minute and a day;
`If-None-Match` is sent only while a release is stored; a release name is
shown in the banner only when it says more than the version; the banner's
dismissal is its own user setting (`updates.dismissed`), so it never
expires like the other notices'; `UPDATE_CHECK_REPO` that isn't
`owner/name` stops the app at start; and a build without a version number
(`dev`) never shows the banner.

Settled while starting, without changing behaviour: there is no *System*
section in Settings, so *Updates* sits under *Installation* beside *Jobs*
(as Phase 28.1's Jobs page did); the HTTP tests use Symfony's
`MockHttpClient`, which the other outbound clients already use, rather
than a PSR-18 mock; *Upgrading* already exists in `docs/deployment.md`
(`#upgrading`); and `gwpreston16/Logbook` has published releases, so
`releases/latest` answers for the default repository.
