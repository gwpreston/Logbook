# Phase 54.10 — Dependabot version updates

*Weekly, grouped dependency-update pull requests for Composer, npm, GitHub
Actions and the Docker and compose images.*

Status: 📋 planned · no release of its own · file lives in `docs/phases/`

This follows [Phase 54.9](phase-54.9.md). It is configuration only: one new
file, `.github/dependabot.yml`, copied in shape from the owner's Renovo repo.

Read [`CLAUDE.md`](../../CLAUDE.md), [`spec.md`](../../spec.md) §13 and
[`open-questions.md`](open-questions.md) first.

**Prerequisites:** [Phase 54.9](phase-54.9.md) merged.

---

## Goals

1. Dependabot opens a pull request once a week per ecosystem: `composer`,
   `npm`, `github-actions`, `docker` (the root `Dockerfile`) and
   `docker-compose` (the compose files).
2. Minor and patch updates of one ecosystem arrive as one grouped pull
   request; a major update arrives on its own.

## Not in scope

- Dependabot *alerts* and *security updates*: these are repository settings
  (Settings → Advanced Security), not files, and the owner turns them on.
- Auto-merge of Dependabot pull requests.
- Any change to application code, migrations or translations.

---

## Decisions (and why)

- **Same shape as Renovo**, so both repos review updates the same way.
- **`docker` points at `/`**, not `/docker/php` as in Renovo: Logbook's
  `Dockerfile` is at the repo root and `docker/php` holds only `logbook.ini`.
- **Weekly and grouped** keeps the review to one sitting a week.

## Tasks

### 54.10.0 Start of phase

- [ ] Read `open-questions.md` and the *Open* and *Scheduled* rows of
      `known-issues.md` (CLAUDE.md §12).

### 54.10.1 The Dependabot file

- [ ] `.github/dependabot.yml` with the five ecosystems above.
- [ ] Spec §13 entry; ROADMAP entry.

### 54.10.2 Finish

- [ ] Merge review; merge.
- [ ] Owner turns on Dependabot alerts and security updates in the
      repository settings.

## Acceptance criteria

- `.github/dependabot.yml` is valid (GitHub's *Insights → Dependency graph →
  Dependabot* page lists the five ecosystems without a parse error).
- No code, migration or translation change; `composer lint` and
  `MarkdownLinksTest` pass.

## Open questions

Found in the 2026-11-04 release sweep (see
[`known-issues.md`](known-issues.md) K182 and K183):

- **#597 (Needs a decision).** How should `symfony/http-client` get the fix
  for the 2026-11-04 advisories (K182), given that 8.0.x has no fixed
  release and 8.1.11 needs PHP 8.4.1 or later while `config.platform.php`
  is 8.4.0? Options: (1) raise the platform pin and move to 8.1.x;
  (2) widen the constraint to `^7.4|^8.0` and lock 7.4.23; (3) stay on
  8.0.x and wait for a backport.
- **#598 (Needs a decision).** How should Dependabot's npm updates to the
  bundled front-end libraries reach `assets/vendor` (K183)? Options:
  (1) Dependabot ignores them; (2) keep the PRs and add a CI check that
  vendored versions match `package-lock.json`; (3) keep the PRs and
  re-vendor by hand on each bump.
