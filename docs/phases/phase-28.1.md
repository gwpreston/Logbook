# Phase 28.1 — Scheduled jobs in Settings

*See that the background jobs ran, run one now, and keep reminders going
even without cron.*

Status: 🚧 in progress · ships with Phase 28.2 as **v2.11.0** · file lives in
`docs/phases/`

Logbook's background work runs from `bin/run-scheduled-tasks.php`. In
Docker the entrypoint runs it every `SCHEDULER_INTERVAL` seconds; on a bare
install the owner must add a cron entry. Backups can run from cron with
`bin/backup.php create`. Later phases added clean-up work to the same task.

If cron is never set up, nothing tells the owner. Reminders and the digest
silently never go out. This phase:

- turns each piece of background work into a named **job**;
- records every run with its output;
- adds an admin page to see them and **run one now**;
- warns when the scheduler hasn't run;
- adds two fallbacks for hosts without cron: running on page visits, and a
  secret URL an external pinger can call;
- offers optional scheduled backups.

Read [`CLAUDE.md`](../../CLAUDE.md), [`spec.md`](../../spec.md) §5, §7.6,
§7.11, §7.13, §9 and §10, and Phase 18.1's instance abilities first.

---

## Goals

1. A **job registry**: each job has a name, description, interval, lock
   and a service that returns a result and log lines. The CLI and the web
   run the same code.
2. **Job runs** recorded with trigger, times, status, a summary and the
   output (secrets redacted).
3. **Settings → Jobs** (admins): each job's last run, next
   expected run, *Run now*, and the run history with full output.
4. **Scheduler health:** a warning on the page and in an admin notice area
   when nothing has run for too long.
5. **Fallback triggers:** *On page visits* and *External URL*, each
   optional.
6. **Scheduled backups** (off by default) with retention.

## Not in scope

- Running arbitrary commands, or jobs defined by users.
- Restore as a job. It stays on its own confirmation page (§7.13).
- A queue or worker process. Jobs stay short and run in the process that
  triggers them.
- Changing how any job works inside (reminder rules, digest timing, backup
  format).

---

## Spec additions

### §5 Architecture: jobs

> - `Service\Jobs\Job` interface: `name()`, `interval()` (seconds, or null
>   for manual only), `run(JobContext $ctx): JobResult`. `JobContext` holds
>   a PSR-3 logger that collects lines and a cancellation check. `JobResult`
>   holds a status (`ok` | `partial` | `failed`), a one-line summary and
>   counts.
> - `Service\Jobs\JobRegistry` lists jobs. `Service\Jobs\JobRunner` runs one
>   job: takes its lock, writes a `job_runs` row, runs it, stores the
>   output, releases the lock. It is used by every trigger.
> - **Jobs** (each existing piece of work, split out of
>   `run-scheduled-tasks.php`):
>
>   | Job | Interval | Does |
>   |---|---|---|
>   | `reminders` | every run | syncs reminders and sends notifications (§7.6, §7.11) |
>   | `digest` | every run (it acts only on the first run of the month) | the monthly digest |
>   | `cleanup` | daily | retention: AI usage log, expired drafts, pending uploads, ask threads, invitations, job runs |
>   | `backup` | per *Scheduled backups* (off by default) | `bin/backup.php create` into `BACKUP_PATH` |
>   | `update_check` | daily (Phase 28.2) | checks for a new release |
>
> - **Locks:** one lock file per job (`var/locks/job-<name>.lock`, `flock`).
>   A run that finds its job locked is recorded as `skipped_locked`, naming
>   the run holding it. A `running` row older than an hour, with no lock
>   held, is marked `interrupted`. The existing all-task lock becomes the
>   runner's lock for a scheduler pass.
> - **Redaction:** a log processor replaces the values of every environment
>   variable whose name contains `PASSWORD`, `SECRET`, `TOKEN` or `KEY`, every
>   stored AI connection secret (Phase 26.1), and every API key prefix match
>   (`lbk_…`) with `••••` before a line is stored or printed.

### §6 Data model

> **JobRun** (Phase 28.1): id, job, trigger (`cron` | `docker` | `page_visit`
> | `url` | `manual`), user_id (for manual runs, `ON DELETE SET NULL`),
> started_at, finished_at (UTC), status (`running` | `ok` | `partial` |
> `failed` | `skipped_locked` | `interrupted`), summary (up to 255), output
> (text, at most 64 KB; longer output keeps the first and last 32 KB with
> "… N lines left out …"). Index `(job, started_at)`. The `cleanup` job
> keeps the last 50 runs per job and nothing older than 90 days. Not in
> backups.

### §7.30 Jobs and the scheduler (new)

> - **Settings → Jobs** (`/settings/jobs`, `InstanceAbility::RunJobs`,
>   admins). A table of jobs: name and description, schedule ("Every 15
>   minutes", "Daily", "Off"), last run (time, trigger, status badge as text,
>   summary), the next run expected, and **Run now**. Under it, **Recent
>   runs** across all jobs, newest first, each opening its run page.
> - **Run page** (`/settings/jobs/runs/{id}`): job, trigger, who ran it,
>   start, finish, duration, status, summary, and the output in a monospace
>   block with *Copy*.
> - **Run now** (POST, CSRF): runs the job in the request. The session lock
>   is released first (`session_write_close`), so other pages stay usable;
>   `ignore_user_abort(true)` keeps the job going if the browser goes away;
>   and the time limit is 300 seconds.
>   - Without JS: the POST runs the job and then redirects to its run page.
>   - With JS: the button shows *Running…* and the run page's output updates
>     every 2 seconds (the runner writes output to the row as it goes, at
>     most once a second) until the run finishes.
>   - If a reverse proxy times the request out first, the job still finishes
>     and its run page shows the result.
>   - A locked job answers "Already running (started 14:02 by cron)" with a
>     link to that run.
> - **Scheduler health:** the page shows the last scheduler pass (any
>   trigger but `manual`) and its trigger. When none has finished within
>   **2 × `SCHEDULER_INTERVAL`** (default 30 minutes), it shows "Reminders
>   aren't being sent automatically: the scheduler last ran {time}." (or
>   "…has never run"), with the three ways to fix it: cron (the exact line
>   for this install's path), *On page visits*, or *External URL*.
> - **Admin notices:** a notice area at the top of the dashboard, admins
>   only, holding the scheduler warning (this phase) and the update banner
>   (Phase 28.2). Each notice has a link to its settings page and *Dismiss*
>   for 24 hours, per admin. The scheduler warning comes back while the
>   problem lasts.
> - **Triggers** (Settings → Jobs → *How jobs run*):
>   - **Cron** and **Docker** as today. The entrypoint sets
>     `LOGBOOK_SCHEDULER_TRIGGER=docker` so its runs are labelled.
>   - **On page visits** (off by default): every signed-in page load sends a
>     beacon (`navigator.sendBeacon` to `POST /_scheduler/tick`, with the
>     CSRF token) when the page's footer data says the last pass is older
>     than the interval. The server re-checks under the runner's lock and,
>     if due, runs a scheduler pass in that beacon request. The visitor
>     never waits for it. It needs someone to visit, and the page says so:
>     "Jobs run when anyone uses Logbook. Reminders may be late on quiet
>     days."
>   - **External URL** (off by default): `GET|POST {APP_URL}{APP_BASE_PATH}/cron/{token}`
>     runs a scheduler pass (only due jobs; never a chosen job). It answers
>     `200` with a plain-text summary, or `429` within 60 seconds of the
>     last accepted call. The token (32 random bytes) is shown once, stored
>     as an HMAC-SHA256 keyed with `SESSION_SECRET` (as calendar tokens are),
>     and can be regenerated. The route sits outside the session and CSRF
>     groups. The page suggests free services that call a URL on a schedule
>     (cron-job.org, Uptime Kuma, a router's scheduler).
>   Any number of triggers can be on together; the locks keep runs from
>   overlapping, and each job's interval decides whether a pass runs it.
> - **Scheduled backups** (on the `backup` job's row): *Off* (default),
>   *Daily* or *Weekly*, and *Keep the last N* (default 7, 1–60). Files are
>   named `logbook-scheduled-YYYYMMDD-HHMMSS.zip`. Retention deletes only
>   files with that prefix, never the owner's own or pre-restore backups.
>   The summary gives the file name and size; the files are listed on the
>   backup page with *Download*.
> - **CLI** (unchanged entry points): `php bin/run-scheduled-tasks.php`
>   runs a scheduler pass (trigger `cron`, or `docker` from the
>   entrypoint). New: `php bin/run-job.php <job>` (trigger `manual`, no user)
>   and `php bin/run-job.php --list`. Both print the same lines the web page
>   stores and exit non-zero on `failed`.
> - **`/health`** gains `"scheduler": {"last_pass": "…", "stale": false}`
>   for monitoring. It never changes the health status code, so Docker's
>   health check is unaffected.

### §9 Configuration

> `SCHEDULER_INTERVAL` now also applies to bare installs (the expected cron
> frequency for the health warning, and the page-visit and URL triggers'
> spacing). `JOB_TIME_LIMIT` (seconds for *Run now*; default 300).

---

## Decisions (and why)

- **One runner for every trigger.** Cron, Docker, a page visit, a URL and a
  button all go through `JobRunner`, so the output on the page is exactly
  what cron would have done.
- **Locks per job.** A manual reminder run while cron is mid-pass must
  never double-send. The reminder claims (§7.11) already prevent that, and
  the locks make it visible rather than silent.
- **A beacon, not "run after the response".** Finishing a response early
  depends on PHP-FPM or LiteSpeed. A beacon is a separate request, works on
  every server, and never slows the visitor's page.
- **The URL trigger runs only due jobs.** A leaked URL can at worst make
  the scheduler do what it would have done anyway, at most once a minute.
- **Restore is never a job.** It replaces everything and has its own checks
  and confirmation. A button in a list is the wrong place for it.

---

## Tasks

### Spec and docs
- [ ] §5, §6, §7.30, §9 and §10 in `spec.md`; the Phase 28.1 line in §13.
- [ ] `docs/deployment.md`: *Background jobs*: cron, Docker, page visits,
      external URL, how to check they run, scheduled backups.

### Migration
- [ ] `job_runs`; instance settings for the triggers, URL token hash and
      backup schedule. Reversible on every engine.

### Code
- [ ] `Service\Jobs\*` (interface, registry, runner, context, result, locks,
      redaction processor).
- [ ] Split `run-scheduled-tasks.php`'s work into the `reminders`, `digest`
      and `cleanup` jobs with no change in behaviour; add the `backup` job.
- [ ] `bin/run-job.php`; the entrypoint's trigger label.
- [ ] Settings → Jobs, run page with polling, *How jobs run*,
      scheduled backups; the admin notice area, the scheduler warning
      and failure alerts (notice and notification, #107).
- [ ] `cleanup` deletes closed invitations after 90 days (#109).
- [ ] `POST /_scheduler/tick` and its footer beacon; `/cron/{token}`.
- [ ] `/health` field.
- [ ] Translations (en, de).

### Tests
- [ ] **Same behaviour:** the existing reminder, notification and digest
      tests pass through the new jobs unchanged.
- [ ] Runs recorded for each trigger, with the right labels and user.
- [ ] Locks: a manual run during a cron pass → `skipped_locked` naming the
      other run; no double notification; an interrupted run marked after an
      hour.
- [ ] Redaction: an SMTP password, an AI key and an `lbk_` key in a log line
      are stored and printed as `••••`.
- [ ] Output cap: 64 KB with the gap note.
- [ ] *Run now* without JS redirects to the run page; with JS the run page
      polls; a disconnect mid-run still finishes the run.
- [ ] Health warning at 2 × interval; never-run wording; the cron line uses
      this install's path; the notice dismisses for 24 hours and returns.
- [ ] Page visits: a beacon runs a due pass once under concurrency (two
      beacons, one pass) and does nothing when not due or when off.
- [ ] URL: a valid token runs a pass; a bad token → 404; 429 within 60
      seconds; regenerating invalidates the old token; runs only due jobs.
- [ ] Scheduled backups: daily and weekly timing; retention deletes only
      `logbook-scheduled-` files.
- [ ] Access: members get 404 for every jobs route.
- [ ] Failure alerts: two failures in a row → notice and one
      notification per admin; a third failure sends nothing more; an
      `ok` run ends the streak.
- [ ] Cleanup: invitations closed over 90 days ago are deleted, open
      and recent ones kept; it runs hourly.
- [ ] `/health` keeps its status code when the scheduler is stale.
- [ ] Integration suite green on every engine.

---

## Acceptance criteria

1. On a bare install without cron, Settings → Jobs says reminders
   aren't being sent and offers three fixes. Turning on *On page visits*
   makes the warning go once a pass has run.
2. *Run now* on `reminders` shows its output ("Synced 14 reminders; sent 2
   by email") and records the run.
3. A job never runs twice at once, whatever triggers it.
4. No secret appears in any stored or printed output.
5. Definition of done (CLAUDE.md §11) holds.

## Open questions

All decided on 2026-10-02, before the phase started:

- **Run now for members** (#106). *Decided 2026-10-02:* admins only, as
  drafted; members get 404 on every jobs route (spec §7.30 *Access*).
- **Failure notifications** (#107). *Decided 2026-10-02:* yes. When a
  job's last two finished runs failed, admins get a dashboard notice for
  as long as the streak lasts, and once per streak a `job_failed`
  notification through their own channels (spec §7.30 *Failure alerts*).

Found while starting it:

- **How often `cleanup` runs** (#108). The drafted "daily" would have
  slowed clean-ups that ran every pass (unclaimed scans are promised gone
  after 24 hours). *Decided 2026-10-02:* hourly (spec §5 *Jobs*).
- **Invitation retention** (#109). Nothing deleted invitations, and no
  retention was set. *Decided 2026-10-02:* closed links (used, revoked
  or expired) are deleted 90 days after they closed; open ones never
  (spec §7.30 *Jobs and their summaries*).

Settled while starting, without changing behaviour: the locks live in
the cache directory (`var/` may not be writable in the Docker image),
the Jobs page sits under Settings → *Installation* (there is no *System*
section), and `bin/run-scheduled-tasks.php` stays quiet unless given
`-v` with its exit codes unchanged, because cron mails any output.
