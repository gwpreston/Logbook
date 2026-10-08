# Running a public demo

Demo mode (Phase 35.1) turns an **empty** Logbook installation into a demo
that anyone can try: it starts with a sample garage, puts itself back to that
sample every day, and keeps a stranger away from everything that could hurt
the server or its owner. It is built so that setting the switch on a real
instance **deletes nothing**.

- [Quick start](#quick-start)
- [What happens at start](#what-happens-at-start)
- [The reset](#the-reset)
- [What visitors see and can do](#what-visitors-see-and-can-do)
- [Running it safely](#running-it-safely)
- [Stopping being a demo](#stopping-being-a-demo)
- [Variables](#variables)

## Quick start

On a server of its own, with its own empty volume, add three lines to the
`.env` next to `docker-compose.yml` (the compose files pass them on):

```bash
APP_URL=https://demo.example.com
DEMO_MODE=true
DEMO_PASSWORD=try-the-garage-2026   # shown to every visitor
# DEMO_RESET_HOURS=24
```

```bash
docker compose up -d
```

The first start migrates the database, finds it empty, seeds the
sample garage and creates one account, **`demo`**, an admin, whose password is
`DEMO_PASSWORD`. There is no first-run setup page: `/setup` answers 404.

Bare PHP works too: set the variables, run `php bin/demo-seed.php` after the
migrations (or just let the first request do it) and keep the scheduler
running ([Background jobs](deployment.md#background-jobs)): the reset runs from
it.

`DEMO_PASSWORD` is **required** (8 to 1024 characters) and has no default,
because a well-known default would be one mistyped variable away from an open
door.

## What happens at start

Logbook decides what `DEMO_MODE` means from what it finds in the database.
The proof that a database is a demo is a marker that only the demo's own
seeding writes. It is **not in backups or exports**, so restoring a backup can
neither create nor remove it.

| State at start | What happens |
|---|---|
| `DEMO_MODE` off, no marker | A normal instance. |
| `DEMO_MODE` off, marker present | Demo features are inert: no reset, no banner, no restrictions. The data is untouched. Turning `DEMO_MODE` back on resumes the demo. |
| `DEMO_MODE` on, the database is empty (no users) | The sample data is seeded, the marker written (last), the `demo` account created. |
| `DEMO_MODE` on, marker present | The demo is running. |
| `DEMO_MODE` on, users exist, **no marker** | **Refused.** Logbook runs as a normal instance. An error line is logged at every start ("DEMO_MODE is set, but this database holds real data…"), and every admin sees a notice on the dashboard: *Demo mode is off and nothing was changed. Remove the setting.* Nothing is deleted. |
| `DEMO_MODE` on, `DEMO_PASSWORD` missing or too short | Refused the same way, with that reason. |

The only code that deletes data is the reset, and it runs only with the marker
present *and* `DEMO_MODE` on. There is deliberately no switch that overwrites
existing data from an environment variable.

## The reset

Every `DEMO_RESET_HOURS` (24 by default) the `demo_reset` job puts the demo
back:

1. It takes the job's lock, and refuses unless the guard allows it.
2. In **one transaction** it empties every table except the migration history
   and the job runs, seeds the sample data again, and moves the marker's reset
   time. If anything fails, the transaction rolls back and the old data stays.
3. After the commit it deletes the uploaded files and avatars.
4. Every session ends. A visitor is signed out and sees *The demo was reset.
   Sign in again.*

The job is listed on Settings → Jobs only while the demo is running (and that
page is not offered to visitors). It is **never run from a page visit**, so a
visitor's request never waits for a reset: it runs from cron, the Docker
scheduler or the external URL. By hand:

```bash
php bin/demo-reset.php          # asks first
php bin/demo-reset.php --yes
```

The command refuses unless the database carries the marker and `DEMO_MODE` is
on. In Docker run it as the web server user:
`docker compose exec -u www-data logbook php bin/demo-reset.php --yes`.

**Dates.** Every date in the sample is placed relative to the day it is
seeded, so *Last 12 months*, *Coming up*, the reminders, the calendar and the
economy checks always have something to show, however long the demo has been
running. The history moves by whole weeks, so weekday patterns (commutes) keep
their shape and the newest entry is always a few days old.

## What visitors see and can do

Everyone signs in as `demo`. The sign-in page says so: *Try it: username
`demo`, password …* as text, with a *Fill in* button when JavaScript is on. A
banner on every signed-in page says when the demo resets, in the viewer's
time zone, and that nothing there is private. Every response carries
`X-Robots-Tag: noindex, nofollow`.

Everything about logging and looking works: fill-ups, tyres, expenses,
reminders, the dashboard and its widgets, reports, the calendar, switching
modules in Settings → Modules, and the sample *Cheapest near me* prices.

What does not, and answers a friendly *Not available in the demo* page (403)
with its link left out of the navigation:

- **Users and sign-in:** users, invitations, single sign-on and header sign-in,
  forgotten-password and email links, `/setup`.
- **Credentials:** changing the password, the email address or the picture.
- **Install-wide tools:** backup, restore, export everything, importing from
  another app or a CSV file, jobs, the update check, fuel price providers
  (the sample prices need none).
- **API keys, the REST API, MCP and entry webhooks** (nothing is queued or sent), and **every AI feature**.
- **Sending anything out:** reminder and digest notifications, test
  notifications, email and every channel are switched off (the reminder job
  records *demo: not sent*), calendar feeds cannot be made, and the app makes
  no outbound request at all: the mail transport and the HTTP client refuse.
- **Uploading a file:** the file fields are not offered and a request that
  carries a file is refused. Documents, receipts and vehicle photos can still
  be saved without one, so nothing a visitor adds can be seen by the next
  visitor except text, and nothing but text survives a reset.

## Running it safely

- Give the demo **its own server or container and volume**, not one shared
  with anything you keep. The reset deletes everything in the database it was
  seeded into.
- The `demo` account is an admin so visitors can see Modules, but every
  install-wide page is blocked for it. It is still an account on the app, and
  anyone can sign in: put the usual rate limiting in front of the reverse
  proxy, and do not reuse its password.
- `DEMO_PASSWORD` is shown to visitors on the sign-in page by design, and the
  job output masks it like any variable with *PASSWORD* in its name.
- Leave password sign-in on (`AUTH_LOCAL_LOGIN`, the default): the demo has
  no other way in.
- Run the scheduler. Without it the reset never happens. Docker's entrypoint
  runs one by default (`SCHEDULER_ENABLED`).

## Stopping being a demo

- **Switch it off, keep the data:** remove `DEMO_MODE`. Demo features go
  inert: no reset, no banner, no restrictions. You then have an instance with
  an account whose password is public, so change it or start again.
- **Start a real instance:** empty the database (or use a new volume) and
  start without `DEMO_MODE`. The first-run setup page asks for your account.
- **Never** copy a demo's database over a real one: a backup does not carry
  the marker, so restoring it onto a real instance cannot make that instance
  resettable, and restoring a real instance's backup onto a demo cannot stop
  it being one.

## Variables

| Variable | Default | Meaning |
|---|---|---|
| `DEMO_MODE` | `false` | Run as a demo (see [What happens at start](#what-happens-at-start)). |
| `DEMO_PASSWORD` | none | The demo owner's password, 8 to 1024 characters, shown to visitors. |
| `DEMO_RESET_HOURS` | `24` | Hours between resets, 1 to 168. |

For developers: anything that sends data out, accepts a file or accepts a
secret from a visitor asks `DemoMode::blocks(DemoRestriction)` first, and every
route declares whether the demo allows it (`DemoRoutes::BLOCKED`, and the
route inventory test makes a new route choose). `spec.md` §7.36 is the
specification.
