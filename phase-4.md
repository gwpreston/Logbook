# Phase 4 — Reminders + Notifications

**Goal:** turn schedule next-due dates and compliance expiries into reminders
that reach the user in-app **and** through at least one outbound channel, driven
by a scheduled task; plus an optional calendar feed.

Read `spec.md` (§6, §7.6, §7.11, §10) and `CLAUDE.md` (§8) before starting.

**Prerequisites:** Phase 3 complete and green.

---

## Scope

**In:** Reminder domain; generation from maintenance schedules, compliance
expiries, and manual reminders; configurable lead times; statuses; in-app list;
a pluggable notification channel interface with email (SMTP), ntfy, and Gotify
shipped, and adding further channels made cheap; scheduled task; optional monthly
digest; optional iCal/webcal feed.

**Out:** the full dashboard (Phase 5 — this phase only produces the reminders
widget's data), reports.

---

## Tasks

### 4.1 Reminder domain + migration
- [x] `Reminder` (source `schedule|compliance|manual`, title, due_date,
      lead_time_days, status `upcoming|due|overdue|dismissed|done`,
      channels_notified, last_notified_at).
- [x] Applies + rolls back on both DBs.

### 4.2 Reminder generation service
- [x] Generate from `MaintenanceSchedule.next_due`,
      `ComplianceDocument.expiry_date`, and manual reminders.
- [x] Derive status (upcoming / due / overdue) against lead time, in the user's
      timezone.

### 4.3 Notification channels (pluggable)
- [x] `NotificationChannel` interface (e.g. `send(Reminder|Digest): Result`,
      `isConfigured(): bool`, `key(): string`).
- [x] Ship three implementations: `EmailChannel` (SMTP via symfony/mailer),
      `NtfyChannel`, and `GotifyChannel` (POST to a Gotify server's
      `/message` with an app token; configurable priority/title/message).
- [x] Channel **registry**: channels are discovered from DI (tagged services)
      and enabled per config; the dispatcher iterates enabled+configured channels
      without knowing their concrete types.
- [x] Per-user (or global) selection of which channels are active, stored in
      settings.
- [x] **Adding a new channel must require only:** implement the interface,
      register/tag it in DI, and add its config keys — no change to the reminder
      engine or dispatcher. Document this in a short "adding a channel" note.

### 4.4 Scheduled task
- [x] Console command that evaluates due reminders and dispatches them.
- [x] Cron entry in the bare install; entrypoint-scheduled in Docker.
- [x] **Idempotent:** use `last_notified_at` + status so re-runs don't re-send.

### 4.5 Digest (optional)
- [x] "What's due this month" summary notification.

### 4.6 In-app
- [x] Reminders list; dismiss / mark done; lead-time configuration in settings.

### 4.7 iCal / webcal feed (optional)
- [x] Authenticated feed URL exposing reminders as VEVENTs.

### 4.8 i18n
- [x] All new strings translatable, including notification templates.

### 4.9 Tests
- [x] Unit: generation logic, status transitions, idempotency guard; the
      dispatcher iterates only enabled + configured channels.
- [x] Integration: dispatch through each shipped channel (email, ntfy, Gotify)
      with a mocked transport; iCal output validity; dismiss / mark-done.
- [x] Pass on **both** MySQL and Postgres.

---

## Deliverables
A reminder engine that surfaces everything upcoming/due/overdue in-app and
delivers via at least one outbound channel on a schedule, with an optional
calendar feed.

## Acceptance criteria
- [x] Compliance expiries and schedule due-dates generate reminders with correct
      status relative to lead time.
- [x] Each shipped outbound channel (email, ntfy, Gotify) delivers when
      configured; only enabled+configured channels are used; no duplicate sends
      across repeated task runs.
- [x] A new channel can be added by implementing the interface + registering +
      config alone, with no change to the reminder engine or dispatcher.
- [x] The scheduled task runs in both the Docker and cron paths.
- [x] Dismiss / mark-done work; the iCal feed validates and is auth-protected.
- [x] Suite green on both DBs; translatable; works behind a subpath.

## Gotchas
- Idempotency is the trap: guard with `last_notified_at` + status so a re-run
  doesn't spam the user.
- "Due today" is timezone-relative — compute against the user's tz, store UTC.
- Don't hard-code channels; the dispatcher works off the registry, so email,
  ntfy, and Gotify — and later ones like Telegram or Discord — are all just
  implementations of the same interface, added without touching the engine.
