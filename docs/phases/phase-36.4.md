# Phase 36.4 — What each channel receives, and quiet hours + v3.3 release

*Choose what reaches you where, and when it may.*

Status: ✅ complete · released as **v3.3.0** with Phases 36.1 to 36.3 (#254) ·
file lives in `docs/phases/`

Phase 36.2 made every notification channel personal (Account →
Notifications); every usable channel receives everything. The owner asked
on 2026-10-07 (#234) for a choice per channel of *what* it receives, and
for quiet hours that **hold** a message until they end.

Read [`CLAUDE.md`](../../CLAUDE.md), [`spec.md`](../../spec.md) §7.11
(recipients, idempotency, the digest, price alerts), §7.30 (jobs),
[Phase 36.2](phase-36.2.md) and [Phase 36.3](phase-36.3.md) first.

**Prerequisites:** [Phase 36.3](phase-36.3.md) built.

---

## Goals

1. **Per-channel categories.** Each channel card (email and every personal
   channel) has a choice of what it receives: *Due*, *Overdue*, *Monthly
   digest*, *Price alerts* (and *Job failures* for admins). Default: all,
   so nothing changes on upgrade.
2. **Quiet hours.** A user sets a start and end time (their time zone); a
   message that would be sent inside them is **held** and sent when they
   end.
3. The test sends regardless of either, and says so.
4. Release **v3.3.0** (Phases 36.1 to 36.4; the owner's decision on
   2026-10-07, #254).

## Not in scope

- Routing by vehicle.
- Changing what a message says, or the reminder statuses.

---

## Spec additions

Written 2026-10-07, after the owner answered #250–#253 and #262–#264:
[`spec.md`](../../spec.md) §7.11 *What each channel receives, and quiet
hours (Phase 36.4)*, §6 NotificationChannel (`categories`) and the
`notifications` preference (`email_categories`, `quiet`), §7.30 *Failure
alerts*, §12 and §13. In short:

- Categories `due`, `overdue`, `digest`, `price_alerts`, `job_failures`
  (admins), per channel, saved with the card; null means all; at least
  one. The server's webhook takes everything.
- A run's reminders are grouped by the categories each channel takes:
  one message per channel, as today; claims and
  `reminder_deliveries.channels` per reminder follow only the channels
  that took it.
- Quiet hours are per user, in their time zone, and may cross midnight.
  Nothing is queued: senders don't claim inside them, and the first run
  after sends what still applies then. Price alerts of one check and held
  job failures are combined per user (one alone unchanged).
- Tests ignore both and say so.
- A run skips a host that failed 3 times without answering (#264), as a
  refusal (shown, never counted). Email is not covered.

---

## Tasks

### 36.4.0 Spec first
- [x] `spec.md` §6, §7.11, §7.30, §12, §13; the answers to the open
      questions below (#250–#253, and Phase 36.3's #262–#264).

### 36.4.1 Audit
- [x] Every sender of notifications and its category: `ReminderNotifier`
      (`reminders`: each reminder `due` or `overdue`, mixed in one
      message; `digest`), `PriceAlertChecker` (`price_alerts`, one
      dispatch per alert), `JobFailureAlerts` (`job_failures`, its marker
      saved before the admins are sent to), the dispatcher's switched-off
      notice (none: always), `SendTestNotificationAction` and the cards'
      *Send test* (none: always).
- [x] Held messages and claims: reminders and the digest hold by not
      claiming / not marking the month; a price alert holds by not being
      claimed (re-checked at the next `fuel_prices` run, every 30 to 120
      minutes); a job failure can't, as its marker is global, so an admin
      in quiet hours gets a held entry (`jobs.held_failures`).
- [x] `OutboundHttp` is a shared readonly service: the per-run breaker
      lives in its own service, armed by the job runner for a run only.

### 36.4.2 Migration, code, docs, translations, tests
- [x] Migration: `notification_channels.categories` (nullable string
      100); rollback drops it. Backups carry it; an older backup restores
      as null (all).
- [x] `NotificationCategory` enum; `NotificationPreferences` gains
      `emailCategories` and `quiet` (every `with*()` carries them);
      `QuietHours` value object (crossing midnight, DST by wall clock).
- [x] Registry: each usable channel with the categories it takes; the
      dispatcher sends a kind only to the channels taking it (tests and
      the notice to all).
- [x] `ReminderNotifier`: groups by `due` / `overdue`, one message per
      group, per-reminder delivery and release; leaves unclaimed what no
      channel takes; quiet hours for reminders and the digest.
- [x] `PriceAlertChecker`: users in quiet hours skipped unclaimed; one
      message per user per check (composer `priceAlerts()`).
- [x] `JobFailureAlerts`: held entries per admin, sent after a later run
      once out of quiet hours, if the streak lasts; several in one
      message.
- [x] Circuit breaker (#264): per run, per host, 3 connection failures,
      `refused` results; never for tests or checks.
- [x] Account → Notifications: *Receives* on each card (email has its
      own Save), *Quiet hours* form; the tests' wording.
- [x] Translations (every locale), `docs/` (notifications guide),
      `CHANGELOG.md` *Unreleased*.
- [x] Tests: categories per kind; mixed groups with one failing; quiet
      hours across midnight and on DST days; held job failures dropped
      when the streak ends; the breaker; the migration both ways.

### Release (with Phases 36.1 to 36.3)
- [x] `CHANGELOG.md` **3.3.0**: the *Unreleased* entries of Phases 36.1 to
      36.3 (already written) plus this phase's, under a dated `## [3.3.0]`
      with an introduction. Draft introduction, from Phase 36.3: "Phases
      36.1–36.4: **your reminders, where you want them**. The email server
      is set up in the app by an admin, and each person chooses their own
      channels (email, ntfy, Gotify, a webhook, Telegram, Discord,
      Pushover, Mattermost and Slack), what each receives and when, sets
      them up themselves and tests each one." Then: "**Read the upgrade
      notes first: email is off after upgrading until an admin sets the
      server up in Settings → Delivery.**", the migrations, and that
      notification secrets are encrypted with `SESSION_SECRET` and are not
      in backups.
- [x] Bump `VERSION` to 3.3.0, rebuild assets, update the README status
      line (with "Coming from 3.2? Email is off after upgrading until an
      admin sets it up in Settings → Delivery") and `ROADMAP.md`; Phases
      36.1 to 36.4 marked complete.
- [ ] The real test through each Phase 36.3 service (its 36.3.5 item), if
      not done before. **The owner's step** (it needs real accounts).
- [ ] Tag `v3.3.0` once merged (the owner's step).

---

## Acceptance criteria

- A user can choose, per channel, which kinds of notification it receives.
- Nothing is sent inside a user's quiet hours; what was held arrives when
  they end, once.
- Upgrading changes nothing until a user chooses.

## Open questions

- **Quiet hours: per user or per channel?** One period for the user
  (simpler), or one per channel (a phone channel quiet at night, email
  not)? *Decided (#250, 2026-10-07):* per user; per channel parked
  (spec §12).
- **Overdue during quiet hours.** Held like everything else, or sent at
  once? *Decided (#251, 2026-10-07):* held like everything else.
- **Held messages that stop applying.** A reminder marked done while its
  message is held: drop it, or send what was due at the time? *Decided
  (#252, 2026-10-07):* dropped; nothing is queued, and the first run after
  sends what still applies then.
- **Several held messages.** Sent as they were, or combined into one?
  *Decided (#253, 2026-10-07):* combined per kind.

Raised by the reviews on 2026-10-07, waiting for the owner (log
#265–#270):

- **Receives chips: 44 px and a check mark?** (#265)
- **Clear a demoted admin's held job failures?** (#266)
- **Breaker over the failed-job alert after a run?** (#267)
- **New categories for channels saved with every box ticked?** (#268)
- **Compare-and-delete the held failures?** (#269, unconfirmed race)
- **Skip the sync in quiet hours?** (#270)

## Reviews

All four review agents on 2026-10-07 (head 6a6c7da), then fixed in
0c58014 and after: the quiet-hours pill shows the saved setting, errors
are announced, one hint under both times, one channel lookup per run, the
station looked up after the quiet check, the spec's skipped wording. Security: no
findings. Performance: two low, both fixed. Bug hunt: one low (wording),
fixed.
- **Release.** Its own v3.4.0, or with the next phase? *Decided
  (#254, 2026-10-07):* with Phases 36.1 to 36.3, as **v3.3.0**; 36.3 no
  longer releases on its own.
