# Phase 36.1 — Email server settings (admin)

*The server's email, set up in the app by the person who runs it.*

Status: 🚧 in progress · no release of its own (ships with Phase 36.3 as
**v3.3.0**) · file lives in `docs/phases/`

Phase 36 is **Notifications**, built last, after Phases 33 to 35 are
released. It has three parts:

- **36.1 (this file):** the email server (SMTP) moves from environment
  variables into Settings. Admin only, for the whole application.
- **[36.2](phase-36.2.md):** every user has their own notification
  channels, in Settings → Account → Notifications. The channels that
  today live in environment variables move there.
- **[36.3](phase-36.3.md):** Telegram, Discord, Pushover and Mattermost.

Today the SMTP server is configured only with `MAIL_HOST`, `MAIL_PORT`,
`MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION`, `MAIL_FROM` and
`MAIL_TO` (§9). Changing it means editing the server's environment and
restarting. Phase 18.1 created `InstanceAbility::ManageNotifications` and
noted that it "guards nothing yet": this phase gives it its first page.

Read [`CLAUDE.md`](../../CLAUDE.md) (§9, §10), [`spec.md`](../../spec.md)
§7.9 (forgotten password, invitations), §7.11 (notifications), §7.25 (AI
connections: how secrets are stored and shown), §7.30 (jobs and log
redaction), §9, [Phase 18.1](phase-18.1.md), [Phase 19](phase-19.md) and
[Phase 33.1](phase-33.1.md) (the user's email address, forgotten
password, development mail) first.

**Prerequisites:** [Phase 35.2](phase-35.2.md) released as v3.2.0, and
[Phase 33.2](phase-33.2.md) built (Settings has its final layout).

---

## Goals

1. **Settings → Delivery** (`/settings/delivery`, admins,
   `InstanceAbility::ManageNotifications`) with an **Email server** card:
   host, port, encryption, username, password, From address and name, and
   **Send test email**.
2. **Settings are the only source** (decided 2026-10-06, #223): the
   `MAIL_*` variables are removed and never read. Every email the app
   sends (reminder email, the digest, invitations, resets, address
   confirmations, tests) is built from the saved settings, in one place.
3. **The password is a secret**, stored the way AI secrets are: encrypted,
   never shown again, redacted from errors and job output, left out of
   backups.
4. **Upgrading is explained, not automatic.** Nothing is imported from
   `MAIL_*` (#223): an install that used them has email off until an
   admin fills in the page, and the release notes, the upgrade notes and
   the page itself (while a `MAIL_*` variable is still set) say so.
5. "Is email set up?" (the forgotten-password link, invitations by email,
   address confirmations, the Email channel) reads the saved settings at
   once, with no restart.
6. **A fresh Docker volume gets a `SESSION_SECRET`** (#222), so the
   password can be sealed out of the box.

---

## Not in scope

- Personal channels and the other providers ([36.2](phase-36.2.md),
  [36.3](phase-36.3.md)). The *Delivery* page gets its second card in 36.2.
- More than one SMTP account, or per-user SMTP.
- OAuth 2 sign-in to an SMTP provider (Microsoft 365 and Gmail increasingly
  require it for new apps). Parked in spec §12; an app password or an SMTP
  relay works today.
- DKIM signing, bounce handling, editing email templates, a `sendmail` or
  PHP `mail()` transport.
- Importing the `MAIL_*` values into Settings (#223: no import).

---

## Spec additions

Written into [`spec.md`](../../spec.md) on 2026-10-06, after the open
questions were decided: §6 *NotificationSecret* and the `email.smtp`
setting; §7.9 (*Forgotten password* and the default recipient for
admins); §7.11 *The email server*; §8 *Settings layout* (Administration →
Delivery); §9 (`SESSION_SECRET_FILE`, the `MAIL_*` variables removed,
the development Mailpit); §12 (OAuth 2 for SMTP); §13. `CLAUDE.md` §10
gains a sentence on the exception.

---

## Decisions (and why)

- **Settings are the only source; the variables go** (owner, 2026-10-06,
  #223). One place to edit, so there is never a question of which value is
  in use. No import: the owner accepted that an environment-configured
  install has email off until it is set up again, and the release says so.
- **The default recipient for admins moves into Settings** (#225): kept
  as the fallback for admins' reminders, now a field on the card instead
  of `MAIL_TO`.
- **A secret only on a fresh volume** (#222). An empty `SESSION_SECRET`
  still keys session, API-key, feed and invitation hashes today, so
  generating one for an existing install would sign everyone out and
  break those links. The app reads it from `SESSION_SECRET_FILE`, so
  `docker exec … php bin/…` sees the same key as Apache.
- **A new `notification_secrets` table, the AI sealing code** (#224). The
  same shape as `fuel_price_secrets`. `SecretBox` takes its HKDF info
  string, so these are sealed with `logbook-notify` and nothing that
  exists is re-encrypted.
- **Test with typed values, unsaved.** A wrong password should not replace
  a working one.
- **One mail transport, built per send.** Several places building their
  own is how one of them ends up ignoring the settings; building it once
  per process is how a long scheduler run misses a change.
- **OAuth 2 for SMTP is parked** (#226, spec §12).

---

## Tasks

### 36.1.0 Spec first
- [x] `spec.md` §6, §7.9, §7.11, §8, §9, §12, §13; `CLAUDE.md` §10
      sentence; `ROADMAP.md` row and section.

### 36.1.1 Audit
- [x] Every place that reads `MAIL_*`, builds a transport or sends mail.
      Recorded under *Audit*.
- [x] Does the Docker entrypoint generate a `SESSION_SECRET`? No (see
      *Audit*); decided #222.
- [x] `SecretBox` reuse: reused, with its HKDF info as a parameter.

### 36.1.2 Migration (every engine, reversible)
- [x] `notification_secrets` table. Rolling back drops it (the SMTP
      password is lost; the settings row is kept).

### 36.1.3 Code
- [x] `SecretBox` takes its HKDF info (`logbook-ai` stays the default);
      `SecretUnreadable` names the secret without saying "AI".
- [x] `NotificationSecretRepository`, `NotificationSecrets` (store, open,
      state: saved / re-enter / set {NAME}).
- [x] `MailConfig` (`effective()`, `source()`, built from the setting and
      the secret on every call), `MailerFactory` (the only transport
      builder; demo guard around it; 10 s connect timeout, 30 s overall),
      replacing `EmailConfig::fromEnv()` and the DI singleton transport.
- [x] `EmailChannel`, `InvitationMailer`, `AccountMailer`, `UsersPage`,
      `PasswordResets` and `EmailAddresses` read `MailConfig` per call;
      the admins' default recipient comes from the setting.
- [x] Settings → Delivery: action, template, validation, *Send test
      email*, *Remove email server*, the `MAIL_*`-still-set notice, a
      Settings card under Administration; routes declare
      `InstanceAbility::ManageNotifications` (hidden: 404), demo
      `blocked`.
- [x] `notification_secrets` in `OutputRedactor`; transport error text
      redacted (`Redactor`) before a page, job output or the log.
- [x] Exclude `notification_secrets` from backups and demo resets; the
      restore page notes it.
- [x] `SESSION_SECRET_FILE` in `AppSettings`; `bin/session-secret.php`
      (fresh database only) run by the entrypoint after migrating;
      `ENV SESSION_SECRET_FILE=/data/session-secret` in the image.
- [x] Remove `MAIL_*` from compose files, the dev compose (Mailpit stays),
      `.env.example`.

### 36.1.4 Docs and configuration
- [x] `.env.example` and `docs/configuration.md`: `MAIL_*` gone, a pointer
      to Settings → Delivery; `SESSION_SECRET_FILE`.
- [x] `docs/notification-channels.md`: an *Email* section: where it is
      configured, the password rules, the test button.
- [x] README *Configuration* paragraph and the dev Mailpit steps;
      `docs/deployment.md`; `CHANGELOG.md` *Unreleased* with the upgrade
      warning.

### 36.1.5 Translations
- [x] English and German strings for the page, hints, warnings and errors.

### 36.1.6 Tests
- [x] Unit: `MailConfig` (nothing saved is `none` whatever `MAIL_*` holds;
      saved settings are used; removing returns to `none`).
- [x] Unit: validation (host with scheme, path or port, ports, CR/LF in
      each field, From address, recipient, encryption values, `none` with
      a username warns).
- [x] Unit: the secret rules: round trip, sealed with `logbook-notify` (an
      AI-info box can't open it), a changed key says *Re-enter*, an unset
      `env:` variable says *Set {NAME}*, no key means only `env:`.
- [x] Unit: `bin/session-secret.php`'s service: writes only on an empty
      database, never overwrites, never with `SESSION_SECRET` set;
      `AppSettings` reads the file.
- [x] Integration: the page is admin only (a member, a disabled user and a
      signed-out visitor are refused; the route inventory classifies it);
      the saved password never appears in any response, including after a
      validation error; secrets are redacted from a failing test's message
      and a job's output.
- [x] Integration: **Send test email** uses typed values without saving
      them, goes to the admin's address, and reports each failing stage.
- [x] Integration: the forgotten-password link and invitations appear
      exactly when a server is saved, and follow a change at once.
- [x] Architecture: nothing in `src/` but `MailerFactory` builds a
      transport.
- [x] Integration: `notification_secrets` is absent from a backup;
      restoring leaves *Re-enter the password*; the restore page says so.
- [x] Integration: demo mode refuses the page and sends nothing.
- [ ] Migration applies and rolls back on every engine; the suite passes on
      SQLite, PostgreSQL, MySQL and MariaDB. *(SQLite done locally; the
      other engines run in CI.)*

### 36.1.7 Checks
- [x] `design-reviewer` agent on the page at 375, 768 and 1280 px, light and
      dark; keyboard only; the *Saved / Replace / Remove* secret field with
      a screen reader.

### Sample data
- [x] None. Demo mode blocks the page.

### Release
- [ ] Ships with Phase 36.3 as **v3.3.0**, with the upgrade warning.

---

## Acceptance criteria

- An admin can set up, test and change the email server in Settings without
  touching the server's environment, and a member cannot see the page.
- The `MAIL_*` variables are not read anywhere, and an upgraded install
  that used them is told what to do.
- The password cannot be read back from any page, backup, job output or
  error.
- Forgotten password, invitations, reminders and the digest all use the
  saved settings, and follow a change without a restart.
- A fresh Docker volume can seal the password without the admin setting
  `SESSION_SECRET`.

## Audit

Done on 2026-10-06, before any code.

**Mail paths.** `Service\Notification\Channel\EmailConfig::fromEnv()` is
the only reader of `MAIL_*`, called from five places, each in a
constructor: `EmailChannel` (reminders, the digest, price alerts, the
reminder test), `User\InvitationMailer` (invitations), `User\AccountMailer`
(password resets and address confirmations, Phase 33.1, used by
`Auth\PasswordResets` and `User\EmailAddresses`), `Action\Settings\UsersPage`
(*mail configured* for Users and Add user) and `config/dependencies.php`,
which builds **one** transport per process (`EsmtpTransport`, wrapped by
`Demo\DemoGuardedTransport`). Gates on "configured":
`PasswordResets::available()`, `EmailAddresses`, `UsersPage` /
`AddUserAction`, `ReminderSettingsPage`, `EmailChannel::isConfigured()`,
`InvitationMailer`. The compose files pass every `MAIL_*`;
`docker-compose.dev.yml` sets Mailpit's. Tests inject
`tests/Support/RecordingMailTransport`.

**`SESSION_SECRET`.** The entrypoint never generates one, and
`docker-compose.yml` / `docker-compose.mysql.yml` default it to empty. An
empty secret still keys the HMACs of session ids, API keys, calendar-feed
tokens and invitation links, so a default install works, but cannot seal
a secret (only `env:` references). Decided #222: a fresh volume only.

**Secret storage.** `Service\Ai\SecretBox` (seal / open / `env:`) is used
as it is by AI connections (`ai_secrets`) and fuel prices
(`fuel_price_secrets`, Phase 30.2), both with the HKDF info `logbook-ai`.
Reused, with the info as a parameter. Redaction: `Jobs\OutputRedactor`
masks job output (environment variables named like secrets, AI and fuel
price secrets); there is no log-wide processor, so error text is redacted
where it is produced (`Ai\Redactor`), as AI errors are. Backups exclude
`ai_secrets` and `fuel_price_secrets` (`BackupRepository`), and demo
resets clear them (`DemoResetter`): `notification_secrets` joins both.

## Open questions

All decided on 2026-10-06, before any code (log #222–#226).

- **A default install's `SESSION_SECRET`.** *Decided (#222):* generated
  only on a fresh volume (no users yet), kept in `/data/session-secret`
  and read through `SESSION_SECRET_FILE`; existing installs are told to
  set one.
- **Environment after saving.** *Decided (#223):* Settings are the only
  source; the `MAIL_*` variables are removed, nothing is imported, and the
  development Mailpit is set up by hand.
- **Secret storage.** *Decided (#224):* a new `notification_secrets`
  table.
- **`MAIL_TO`.** *Decided (#225):* kept as the admins' default recipient,
  moved into Settings → Delivery as a field.
- **OAuth 2 for SMTP.** *Decided (#226):* parked (spec §12).
