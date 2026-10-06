# Phase 35.1 — Demo mode

*A public demo that resets itself and cannot hurt anyone, including you.*

Status: 🚧 in progress · no release of its own (ships with Phase 35.2 as
**v3.2.0**) · file lives in `docs/phases/`

Logbook has sample data (`DemoDataSeeder`, loaded by
`bin/dev-setup.sh --with-sample-data`), but the seeder refuses to run when
`APP_ENV=production`, nothing resets it, and an instance left open to the
public would let a stranger change the owner's password, send mail, point a
webhook at your network or restore a backup over everything. Tracktor has a
`TRACKTOR_DEMO_MODE` switch that seeds on start, with a second switch that
overwrites existing data. This phase adds a demo mode that seeds, resets on
a schedule and locks down what a stranger must not do, and that is built
so that setting the switch on a real instance deletes nothing.

Read [`CLAUDE.md`](../../CLAUDE.md), [`spec.md`](../../spec.md) §5
(jobs), §7.9 (authentication, setup), §7.11 (notifications), §7.13 (backup
and restore), §9 (configuration), §10 (deployment) and
[Phase 18.1](phase-18.1.md) (the route inventory),
[Phase 28.1](phase-28.1.md) (jobs) and [Phase 33.1](phase-33.1.md) (sample
passwords) first.

**Prerequisites:** Phase 34.3 (its file is not yet written) released as v3.1.0.

---

## Goals

1. `DEMO_MODE=true` seeds an **empty** database with the sample data, in a
   production environment, and marks the instance as a demo.
2. A `demo_reset` job, and `bin/demo-reset.php`, put the sample data back on
   a schedule (every 24 hours by default).
3. A **guard**: only an instance that was seeded as a demo can ever be
   wiped. A real instance with the switch set is left alone and says so.
4. A **locked-down visitor experience**: actions a stranger must not take
   are refused with a friendly page, pinned by the route inventory.
5. The **sign-in page** shows the demo's credentials, and every page shows
   a banner with when it resets.

## Not in scope

- A separate sandbox per visitor. One shared demo account, reset together.
- Hosting a demo for the project, analytics or tracking of any kind.
- A *Reset now* button for visitors (it would be a way to disrupt others).
- A second demo account (#217).
- Anything that overwrites data from an environment variable alone (see
  *Decisions*).

---

## Spec additions

### §9 Configuration (changed)

> - `DEMO_MODE` (default `false`). See §7.36.
> - `DEMO_PASSWORD` (no default; required when `DEMO_MODE` is true; 8 to
>   1024 characters). The demo owner's password. It is shown to visitors on
>   the sign-in page by design.
> - `DEMO_RESET_HOURS` (default `24`; 1 to 168). How often the demo is put
>   back.

### §7.36 Demo mode (new)

> **The guard.** A demo is recognised by a marker, the global setting
> `demo.instance` (JSON: `created_at`, `last_reset_at`, `seeded_by`), which
> only the demo seeding path writes. It is **not in backups or exports**,
> so restoring a backup can neither create nor remove it.
>
> | State at start | What happens |
> |---|---|
> | `DEMO_MODE` off, no marker | A normal instance. |
> | `DEMO_MODE` off, marker present | Demo features are inert: no reset, no banner, no restrictions. Data is untouched. Turning `DEMO_MODE` back on resumes the demo. |
> | `DEMO_MODE` on, database empty (no users) | Seed the sample data, write the marker, create the demo owner. `/setup` answers 404. |
> | `DEMO_MODE` on, marker present | The demo is active. |
> | `DEMO_MODE` on, users exist, **no marker** | **Demo mode is refused.** The app runs as a normal instance. A line at error level is logged at every start, and every admin sees a banner: "DEMO_MODE is set, but this database holds real data. Demo mode is off and nothing was changed. Remove the setting." Nothing is deleted. |
> | `DEMO_MODE` on, `DEMO_PASSWORD` missing or too short | Demo mode is refused the same way, with that reason. |
>
> The only code path that deletes data is the reset, and it runs only with
> the marker present *and* `DEMO_MODE` on.
>
> **The demo owner** is `demo`, an **admin** (so the visitor sees the
> admin screens), with the sample data's six vehicles, UK units and GBP,
> and `DEMO_PASSWORD` as its password. It is created by the seeder; there
> is no other account.
>
> **Sample data and dates.** The seeder takes "today" as a parameter and
> places every seeded date relative to it, so *Last 12 months*, *Coming
> up*, reminders, the calendar and the economy checks always have
> something to show, however long the demo has been running. (Today the
> seeder's dates may be fixed; see task 35.1.2.)
>
> **Reset** (`Service\Demo\DemoResetter`):
> 1. Take the job's lock. Refuse unless the guard allows it.
> 2. In **one transaction**: clear every data table the way a restore
>    clears them (so it works on every engine), keeping an explicit
>    keep-list (migration history, the marker); run the seeder with today's
>    date; update `last_reset_at`. A failure rolls back and leaves the old
>    data.
> 3. After the commit, delete the uploaded files and avatars.
> 4. Every session ends. A visitor is signed out and sees "The demo was
>    reset. Sign in again."
>
> A test lists every table in the schema and fails when one is neither
> cleared nor on the keep-list, so a table added later cannot silently
> survive a reset.
>
> **The job** `demo_reset` (§7.30): interval `DEMO_RESET_HOURS`, listed
> only while the demo is active. It is **excluded from the page-visit
> trigger**, so a visitor's request never waits for a reset: it runs from
> cron, the Docker scheduler or the external URL. `php bin/demo-reset.php
> [--yes]` runs the same service by hand and refuses without the marker.
>
> **What a visitor cannot do** (answered with a friendly *Not available in
> the demo* page, status 403, never a bare error; the link to it is hidden
> from navigation):
>
> - users, invitations, sign-in providers, header sign-in, API keys and
>   MCP, AI connections and every AI feature, fuel-price providers other
>   than the built-in *Sample prices (demo)* one, backup, restore,
>   export-everything, import from another app, running or editing jobs,
>   the update check, and `/setup`;
> - changing the password, the email address or the avatar, and linking
>   single sign-on;
> - sending anything **out**: reminder and digest notifications, test
>   notifications, email and every channel are switched off (the reminder
>   job records "demo: not sent"), and the app makes no outbound request
>   except to the sample provider's own generator;
> - creating a calendar feed.
>
> Everything else works, so a visitor can add fill-ups, tyres, documents,
> reminders and expenses (without files), rearrange the dashboard and switch modules.
>
> **Uploads are blocked** (decided #214, replacing the draft's 2 MB cap):
> file fields are not offered and a request carrying a file is refused with
> the *Not available in the demo* page. Records with an optional file work
> without one.
>
> **Banner** on every signed-in page: "This is a demo. It resets {in 3
> hours | at 02:00} and nothing here is private." The time is in the
> viewer's time zone.
>
> **Sign-in page**: "Try it: username `demo`, password `{DEMO_PASSWORD}`",
> as text (and a *Fill in* button when JavaScript is on).
>
> **Robots:** every response carries `X-Robots-Tag: noindex, nofollow`.
>
> **For later phases:** anything that sends data out or accepts a secret
> from a visitor asks `DemoMode::blocks(DemoRestriction)` first. Phase 36
> (notifications) must do so.

### §7.30 Jobs (changed)

> `demo_reset` is added to the job table: interval `DEMO_RESET_HOURS`,
> active only in demo mode, never run from a page visit.

### §8 Route inventory (changed)

> Every route declares `demo: allowed` or `demo: blocked`. The inventory
> test fails for a route with neither, so a route added later must choose.

---

## Decisions (and why)

- **No switch that overwrites from the environment.** Tracktor's
  `FORCE_DATA_SEED` can overwrite existing data on startup, so one wrong
  variable on a real server loses everything. Logbook only ever resets an
  instance that was *seeded as a demo*, and `bin/demo-reset.php` asks for
  the same proof.
- **No default demo password.** A well-known default would be one mistyped
  variable away from an open door. Demo mode refuses to start without one.
- **The marker is not backed up.** Otherwise restoring a demo backup onto
  a real instance, or the reverse, could make a real instance resettable.
- **Reset by clearing and reseeding, in one transaction.** A failure leaves
  the previous data, and clearing reuses the restore's routine so it works
  on all four engines.
- **Block, don't hide.** A route answers 403 with an explanation as well as
  disappearing from the menu, so a bookmarked link tells the truth.

---

## Tasks

### 35.1.0 Spec first
- [x] `spec.md` §7.36, §7.30, §8 route inventory, §9; §13 entry;
      `ROADMAP.md` row and section; `CLAUDE.md` §10 is unchanged (the
      variables are documented in `.env.example` as usual).

### 35.1.1 Audit
- [ ] How does `DemoDataSeeder` date its rows (fixed dates, or relative to
      the run)? Record under *Audit*. If fixed, list what has to move.
- [ ] What does the restore routine clear and in what order? Can it be
      called inside a transaction on every engine? Record it.
- [ ] List every route and classify it `allowed` or `blocked` (the
      inventory test enforces this later).

### 35.1.2 Code
- [ ] `Service\Demo\DemoMode` (`isActive()`, `blocks(DemoRestriction)`,
      `state()` returning the table above) and the startup check that logs
      and raises the admin notice.
- [ ] `DemoDataSeeder` takes a clock: every seeded date is relative to it.
      `bin/dev-setup.sh --with-sample-data` still works and still prints
      fresh random passwords (Phase 33.1); only `DEMO_MODE` uses
      `DEMO_PASSWORD`.
- [ ] Seed-on-empty in the Docker entrypoint and the web start path, after
      migrations, writing the marker last.
- [ ] `DemoResetter`, the `demo_reset` job, `bin/demo-reset.php`.
- [ ] Middleware: refuse blocked routes with the *Not available in the
      demo* page; `X-Robots-Tag`; the banner; the sign-in text; uploads
      blocked (no file fields, a file in a request refused); `/setup` 404.
- [ ] Short-circuit outbound sending (notifications, mail, calendar feed
      creation, update check, AI, API) behind `DemoMode::blocks`.

### 35.1.3 Docs and configuration
- [ ] `.env.example` and `docs/configuration.md`: the three variables.
- [ ] `docs/demo-mode.md`: how to run a public demo (compose example,
      `DEMO_MODE`, `DEMO_PASSWORD`, reset interval, what visitors can and
      cannot do, the guard table, how to stop being a demo: remove the
      variable, or empty the database and start again). README docs table.
- [ ] `docs/deployment.md`: a short *Running a public demo* section linking
      to it.

### 35.1.4 Translations
- [ ] Every catalogue in `translations/`: the banner, the blocked page, the
      sign-in text, the admin refusal notices.

### 35.1.5 Tests
- [ ] Unit: the guard table, every row (empty/marker/no marker/flag off,
      missing and short password).
- [ ] Integration: starting with `DEMO_MODE` on and a database that has
      users and no marker deletes **nothing**, shows the admin notice and
      logs the error. The same start on an empty database seeds and marks.
- [ ] Integration: a reset replaces the data, deletes uploaded files and
      avatars, ends sessions, keeps the marker and updates `last_reset_at`;
      a failure part-way (a seeder that throws) leaves the old data intact.
- [ ] Integration: the schema test (every table cleared or on the
      keep-list).
- [ ] Integration: dates. Seeding at two different "today" values gives
      the same shapes relative to each (last 12 months populated, a due and
      an overdue reminder, economy checks present).
- [ ] Integration: every blocked route answers 403 with the friendly page
      and is absent from navigation; allowed routes work; a fake HTTP
      client and a fake mailer record **zero** outbound calls through a
      full reminder run, a test notification and an update check.
- [ ] Integration: banner, sign-in text, `X-Robots-Tag`, uploads refused,
      `DEMO_PASSWORD` never logged or shown in a job's output (it contains
      "PASSWORD", so the redaction rule covers it; prove it).
- [ ] Integration: the marker is absent from a backup and an export; the
      route inventory fails for a route with no `demo:` declaration.
- [ ] Integration: `demo_reset` is not triggered by a page visit; the CLI
      refuses without the marker.
- [ ] Every test on SQLite, PostgreSQL, MySQL and MariaDB.

### 35.1.6 Checks
- [ ] A smoke test: start the production image with `DEMO_MODE=true`, sign
      in as `demo`, reset by CLI, sign in again.
- [ ] `design-reviewer` agent on the banner, the sign-in page and the
      blocked page at 375, 768 and 1280 px, light and dark.

### Release
- [ ] Ships with Phase 35.2 as **v3.2.0**.

---

## Acceptance criteria

- A fresh container with `DEMO_MODE=true` and `DEMO_PASSWORD` set comes up
  with the sample data and the demo owner, in `APP_ENV=production`.
- After the interval it is back to the sample data, with dates relative to
  that day.
- The same variable on an instance with real data changes nothing and
  tells the admin why.
- A visitor cannot change credentials, reach administration, send anything
  out, or leave anything that survives a reset.
- Search engines are told not to index it.

## Audit

*(Filled in by 35.1.1: how the seeder dates its rows, what restore clears,
and the route classification.)*

## Open questions

All decided by the owner on 2026-10-06, before the phase was built
([`open-questions.md`](open-questions.md) #212–#217). #245 and #246,
left open by Phase 34.3, were decided the same day: keep as they are.

- **Admin or member?** *Decided 2026-10-06 (#212):* admin, so visitors see
  Modules and the other admin screens.
- **Interval.** *Decided 2026-10-06 (#213):* 24 hours by default
  (`DEMO_RESET_HOURS`).
- **Uploads.** *Decided 2026-10-06 (#214):* **blocked altogether**, not
  capped at 2 MB as drafted. File fields are not offered and a request
  with a file is refused; records with an optional file work without one.
- **Credentials on the sign-in page.** *Decided 2026-10-06 (#215):* shown,
  with a *Fill in* button.
- **Shifting the seeded history.** *Decided 2026-10-06 (#216):* every date
  relative to the run.
- **Second demo user.** *Decided 2026-10-06 (#217):* one account.
