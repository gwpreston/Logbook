# Phase 36.1 — Email server settings (admin)

*The server's email, set up in the app by the person who runs it.*

Status: 📋 planned · no release of its own (ships with Phase 36.3 as
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
2. **One effective configuration:** what is saved in Settings, else the
   `MAIL_*` variables exactly as today. Every email the app sends (reminder
   email, the digest, invitations, resets, tests) is built from it, in one
   place.
3. **The password is a secret**, stored the way AI secrets are: encrypted,
   never shown again, redacted from errors and logs, left out of backups.
4. **Nothing breaks on upgrade.** An install configured by environment
   keeps working until an admin saves settings.
5. "Is email set up?" (the forgotten-password link, invitations by email,
   the Email channel) reads the effective configuration.

## Not in scope

- Personal channels and the other providers ([36.2](phase-36.2.md),
  [36.3](phase-36.3.md)). The *Delivery* page gets its second card in 36.2.
- More than one SMTP account, or per-user SMTP.
- OAuth 2 sign-in to an SMTP provider (Microsoft 365 and Gmail increasingly
  require it for new apps). Parked in spec §12; an app password or an SMTP
  relay works today.
- DKIM signing, bounce handling, editing email templates, a `sendmail` or
  PHP `mail()` transport.
- Removing the `MAIL_*` variables. They stay as defaults.

---

## Spec additions

### §6 Data model (changed)

> **NotificationSecret** (new): id, owner_user_id (nullable, `ON DELETE
> CASCADE`; null = the installation), name (up to 64: `smtp_password`, and
> from 36.2 `ntfy_token`, `gotify_token`, and so on), value (`v1:` +
> base64 of the `secretbox` nonce and ciphertext, as AI secrets; for
> installation rows only, a reference `env:NAME`), created_at, updated_at.
> Unique `(owner_user_id, name)`. **Never** in backups, exports, the API or
> any page.
>
> **Setting** `email.smtp` (scope global): `host`, `port`, `encryption`
> (`tls` | `ssl` | `none`), `username`, `from_address`, `from_name`,
> `updated_at`, `updated_by`. The password is a NotificationSecret
> (`smtp_password`).

### §7.11 Notifications (changed): the email server

> - **Effective email configuration.** If an admin has saved settings
>   (`email.smtp` exists), they are used **entirely**; the `MAIL_*`
>   variables are then ignored. Otherwise the variables are used as
>   before. `MailConfig::effective()` returns one or the other and says
>   which (`settings` | `environment` | `none`). Email is **configured**
>   when the effective configuration has a host.
> - **One transport.** `MailerFactory` is the only place a mail transport
>   is built, from `MailConfig::effective()`. Reminder email, the digest,
>   invitations, password resets (Phase 33.1), the test email and
>   anything later use it. An architecture test fails if anything else
>   builds one.
> - **Settings → Delivery → Email server.** Fields: *Server* (host),
>   *Port* (default by encryption: 587 for `tls`, 465 for `ssl`, 25 for
>   `none`), *Encryption* (`tls` = STARTTLS required, `ssl` = implicit TLS,
>   `none`), *Username*, *Password*, *From address*, *From name* (default
>   "Logbook"). Validation: a host (no scheme, no path), a port 1 to 65535,
>   a valid From address, a name up to 100 characters; CR and LF are
>   rejected in every field (header injection). With `none` and a username,
>   a warning: "Your password would be sent unencrypted." The page also
>   says which source is in use: "These settings come from the
>   environment (`MAIL_HOST`). Saving here replaces them." and offers
>   **Use the environment instead** (deletes the saved settings and the
>   saved password).
> - **The password** is stored as a NotificationSecret:
>   - an `env:NAME` reference (a valid variable name) stores only the
>     reference and reads the variable when sending, for those who keep it
>     in a Docker secret. Only admins can save this page, so the reference
>     is safe here (it is **not** allowed for members' secrets, §36.2);
>   - anything else is encrypted with libsodium `secretbox`, key from
>     `SESSION_SECRET` by HKDF-SHA256, info `logbook-notify`;
>   - without a usable key only `env:` references can be saved, and the
>     form says so (see the open question on `SESSION_SECRET`);
>   - it is never shown again, not even masked: *Saved* with *Replace* and
>     *Remove*; an empty field keeps it; a form re-shown after an error
>     never puts it back;
>   - if the key has changed it says *Re-enter the password*, and nothing
>     is sent with it; an `env:` variable that is unset says *Set {NAME}*.
> - **Send test email** sends one message to the **admin's own address**
>   (`users.email`, Phase 33.1; without one the button asks for it), using
>   the **saved or typed** values without saving them, so a typo is found
>   before it replaces a working setup. It reports success, or the stage
>   that failed (connection, encryption, sign-in, send) with the server's
>   reply, redacted. Timeouts: 10 seconds to connect, 30 in all; no retry.
> - **Redaction.** Every `notification_secrets` value, and the value of an
>   `env:` reference, is added to the log redaction (§7.30), as AI secrets
>   are. Error text from the transport is redacted before it reaches a
>   page, a job's output or the log.
> - **Backups.** `email.smtp` is in backups; `notification_secrets` is not.
>   A restored install says *Re-enter the password* on the Delivery page,
>   and the restore page says so.
> - **Changes are logged** at notice level with the admin's id and the
>   fields changed, never the values.
> - **Demo mode** (Phase 35.1) blocks this page and every send.

### §7.9 Authentication (changed)

> "Shown only when email is configured (`MAIL_HOST`)" becomes "shown only
> when the server's email is configured (§7.11)", for the forgotten-password
> link and routes, and for invitations and resets sent by email.

### §9 Configuration (changed)

> `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`,
> `MAIL_ENCRYPTION`, `MAIL_FROM` are **defaults**: used while nothing is
> saved in Settings → Delivery. `MAIL_TO` stays an admin's default
> recipient (Phase 19). The development stack's Mailpit (Phase 33.1)
> keeps working through them.

### §8 Settings layout (changed)

> **Administration** gains **Delivery** (admins). The installation-wide
> places notifications leave the server from are set there. Personal
> channels are in Account (36.2).

---

## Decisions (and why)

- **Saved settings replace the environment, whole.** Merging field by field
  makes it impossible to tell where a value came from. One source is in
  use, and the page names it.
- **The environment stays as the default.** Nothing breaks on upgrade, the
  development stack keeps its Mailpit variables, and anyone who prefers
  configuration in files keeps it. `CLAUDE.md` §10 says everything a
  self-hoster needs is an environment variable; this phase makes SMTP
  settable in either place, and §10 gets one sentence saying so.
- **The AI secret rules, reused.** Encrypted at rest, shown once, redacted,
  never backed up. People already meet them on *Settings → AI*, and the
  code and the tests exist.
- **Test with typed values, unsaved.** A wrong password should not replace
  a working one.
- **One mail transport.** Several places building their own is how one of
  them ends up ignoring the settings.

---

## Tasks

### 36.1.0 Spec first
- [ ] `spec.md` §6, §7.9, §7.11, §8, §9; `CLAUDE.md` §10 sentence; §13
      entry; `ROADMAP.md` row and section.

### 36.1.1 Audit
- [ ] Find every place that reads `MAIL_*` or builds a transport or sends
      mail (reminders, digest, invitations, resets, tests, the dev stack).
      Record under *Audit*.
- [ ] **Does the Docker entrypoint generate and keep a `SESSION_SECRET`
      when none is set?** If not, a default install has no key, and neither
      this password nor members' tokens (36.2) can be saved from the app.
      Record the answer and take the decision to the owner (see the open
      questions) before building the form.
- [ ] How does AI-secret storage (`AiSecret`) work in code: can its
      encryption and redaction be reused as they are, or should they be
      generalised into one `SecretBox` both use?

### 36.1.2 Migration (every engine, reversible)
- [ ] `notification_secrets` table as above. Rolling back drops it (the SMTP
      password is lost; the settings row is kept).

### 36.1.3 Code
- [ ] `MailConfig` (`effective()`, `source()`), `MailerFactory`, and the
      secret storage (reuse or generalise, per the audit).
- [ ] Settings → Delivery action, template, validation, test action, *Use
      the environment instead*; routes declare
      `InstanceAbility::ManageNotifications`.
- [ ] Replace every place that read `MAIL_*` or built a transport. Gate the
      forgotten-password link and the Email channel on
      `MailConfig::effective()`.
- [ ] Add the secrets to the log redaction processor.
- [ ] Exclude `notification_secrets` from backups and exports; the restore
      page notes it.

### 36.1.4 Docs and configuration
- [ ] `.env.example` and `docs/configuration.md`: the `MAIL_*` variables are
      described as defaults, with a pointer to Settings → Delivery.
- [ ] `docs/notification-channels.md`: an *Email* section: where it is
      configured, which source wins, the test button.
- [ ] README *Configuration* paragraph; `docs/deployment.md` mention.

### 36.1.5 Translations
- [ ] English and German strings for the page, hints, warnings and errors.

### 36.1.6 Tests
- [ ] Unit: `MailConfig` (nothing saved uses the environment; saved settings
      use settings entirely, even for fields the environment has; *Use the
      environment instead* returns to it; nothing anywhere gives `none`).
- [ ] Unit: validation (host with scheme or path, ports, CR/LF in each
      field, From address, encryption values, `none` with a username warns).
- [ ] Unit: the secret rules, shared with AI: round trip, a changed key says
      *Re-enter*, an unset `env:` variable says *Set {NAME}*, no key means
      only `env:` can be saved.
- [ ] Integration: the page is admin only (a member, a disabled user and a
      signed-out visitor are refused; the route inventory classifies it);
      the saved password never appears in any response, including after a
      validation error; secrets are redacted from a failing test's message,
      a job's output and the log.
- [ ] Integration: **Send test email** uses typed values without saving
      them, goes to the admin's address, and reports each failing stage
      (a fake transport for each).
- [ ] Integration: the forgotten-password link and invitations appear
      exactly when the effective configuration has a host, and follow a
      change at once.
- [ ] Architecture: nothing but `MailerFactory` builds a transport.
- [ ] Integration: `notification_secrets` is absent from a backup and an
      export; restoring leaves *Re-enter the password*; the restore page
      says so.
- [ ] Integration: demo mode refuses the page and sends nothing.
- [ ] Migration applies and rolls back on every engine; the suite passes on
      SQLite, PostgreSQL, MySQL and MariaDB. The dev stack still delivers
      to Mailpit through `MAIL_*`.

### 36.1.7 Checks
- [ ] `design-reviewer` agent on the page at 375, 768 and 1280 px, light and
      dark; keyboard only; the *Saved / Replace / Remove* secret field with
      a screen reader.

### Sample data
- [ ] None. Demo mode blocks the page.

### Release
- [ ] Ships with Phase 36.3 as **v3.3.0**.

---

## Acceptance criteria

- An admin can set up, test and change the email server in Settings without
  touching the server's environment, and a member cannot see the page.
- An install configured only by `MAIL_*` sends exactly as before.
- The password cannot be read back from any page, backup, log or error.
- Forgotten password, invitations, reminders and the digest all use the
  effective configuration.

## Audit

*(Filled in by 36.1.1: every mail path, the `SESSION_SECRET` answer, and the
secret-storage reuse decision.)*

## Open questions

- **A default install's `SESSION_SECRET`.** If the Docker entrypoint does
  not make one, should it generate a random secret on first start and keep
  it in `/data` (readable only by the app), so the encrypted password and
  members' tokens work out of the box? Recommendation: yes. Without it,
  Settings → Delivery can only save an `env:` reference on a default
  install. (Changing a secret later still invalidates what it encrypted, as
  today.)
- **Environment after saving.** Ignored entirely (drafted), or merged field
  by field?
- **Secret storage.** A new `notification_secrets` table (drafted), a
  generalised table shared with AI secrets, or encrypted columns?
- **`MAIL_TO`.** Stays an admin's default recipient (drafted), or retired
  now that each user's address is on their account (Phase 33.1)?
- **OAuth 2 for SMTP.** Parked (drafted), or wanted for Microsoft 365 and
  Gmail?
