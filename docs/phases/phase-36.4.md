# Phase 36.4 — What each channel receives, and quiet hours + v3.3 release

*Choose what reaches you where, and when it may.*

Status: 📋 planned · releases **v3.3.0** with Phases 36.1 to 36.3 (#254) ·
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

*(Drafted when the phase starts, after the open questions are answered.)*

---

## Tasks

### 36.4.0 Spec first
- [ ] `spec.md` §6, §7.11, §8; the answers to the open questions below.

### 36.4.1 Audit
- [ ] Every sender of notifications (reminders, digest, price alerts, job
      failures, the switched-off notice, tests) and how each would be
      classed into a category.
- [ ] How a held message interacts with claiming (`reminder_deliveries`),
      the digest month and price-alert claims.

### 36.4.2 Migration, code, docs, translations, tests
- [ ] To be listed once the spec additions are written.

### Release (with Phases 36.1 to 36.3)
- [ ] `CHANGELOG.md` **3.3.0**: the *Unreleased* entries of Phases 36.1 to
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
- [ ] Bump `VERSION` to 3.3.0, rebuild assets, update the README status
      line (with "Coming from 3.2? Email is off after upgrading until an
      admin sets it up in Settings → Delivery") and `ROADMAP.md`; Phases
      36.1 to 36.4 marked complete.
- [ ] The real test through each Phase 36.3 service (its 36.3.5 item), if
      not done before.
- [ ] Tag `v3.3.0` once merged.

---

## Acceptance criteria

- A user can choose, per channel, which kinds of notification it receives.
- Nothing is sent inside a user's quiet hours; what was held arrives when
  they end, once.
- Upgrading changes nothing until a user chooses.

## Open questions

- **Quiet hours: per user or per channel?** One period for the user
  (simpler), or one per channel (a phone channel quiet at night, email
  not)?
- **Overdue during quiet hours.** Held like everything else, or sent at
  once?
- **Held messages that stop applying.** A reminder marked done while its
  message is held: drop it, or send what was due at the time?
- **Several held messages.** Sent as they were, or combined into one?
- **Release.** Its own v3.4.0, or with the next phase? *Decided
  (#254, 2026-10-07):* with Phases 36.1 to 36.3, as **v3.3.0**; 36.3 no
  longer releases on its own.
