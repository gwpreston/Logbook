# Phase 33.1 — Accounts: forgotten password, admin controls, avatars and dev mail

*Get back in without asking anyone, see who's who at a glance, and see
every email the app sends while developing.*

Status: 📋 planned · no release of its own (ships with Phase 33.4 as
**v3.0.0**) · file lives in `docs/phases/`

Phase 19 gave Logbook users, invitations and an admin's one-time reset
link, and decided against self-service reset by email (open question #36:
"No"). The owner has now reversed that (2026-10-04): a user who forgets
their password should be able to reset it themselves from the sign-in
page. This phase adds that, rounds off what an admin can do to an account,
lets everyone upload an avatar, and makes development mail visible through
Mailpit instead of going nowhere.

The pages themselves (sign-in, forgotten password, reset password, the
user management screens) are drawn in [Phase 33.2](phase-33.2.md) to the
prototype in `design-import/`. This phase builds the behaviour with plain,
working templates that 33.2 restyles; it does not wait for the design.

Read [`CLAUDE.md`](../../CLAUDE.md), [`spec.md`](../../spec.md) §6 (User,
Invitation, Session), §7.9 (authentication, users and invitations, SSO,
header sign-in), §7.11 (notifications, email), §7.12 (attachments: upload
validation, serving) and §7.13 (backup) first, and
[`open-questions.md`](open-questions.md) #36.

**Prerequisites:** [Phase 32](phase-32.md) released as v2.16.0.

---

## Goals

1. **Forgotten password:** a *Forgotten your password?* link on sign-in
   that emails a one-time reset link, without revealing which accounts
   exist.
2. **One email address per user**, stored on the user, used for reset
   links and for reminder email (today it lives in the notification
   preferences).
3. **Password hashing** stays on one path: `PasswordHasher`
   (`password_hash()` with Argon2id), with a test that keeps it that way.
4. **Admin controls:** email a reset link, *Sign out everywhere* for a
   user, *Revoke access*, and *Add user* beside *Invite*. Every one of them
   admin-only, pinned by the route inventory.
5. **Avatars:** each user can upload, replace and remove a picture, shown
   wherever a person is shown.
6. **Development mail:** a Mailpit container in the dev stack catches every
   email the app sends (resets, invitations, reminders, digests).
7. **Fresh sample passwords:** `bin/dev-setup.sh --with-sample-data`
   generates new random passwords for the sample users on every run.

## Not in scope

- Verifying email addresses (a confirmation round trip). Recorded as an
  open question below; linking SSO accounts by email (#51) stays parked.
- Two-factor authentication, passkeys, password strength meters.
- Public sign-up. Only admins create accounts.
- Resetting a password for an account with no password when local sign-in
  is off (`AUTH_LOCAL_LOGIN=false`): there is nothing to reset.
- Gravatar or any other remote avatar source (data stays local).

---

## Spec additions

### §6 User (changed)

> - `email` (nullable, up to 254 characters, stored trimmed and
>   lower-cased, indexed, **not** unique: a household may share an
>   address). The user's own address, for reset links and reminder email.
> - `avatar_path` (nullable): the stored file's path relative to
>   `UPLOAD_PATH/avatars`, and `avatar_updated_at` (nullable, UTC), used
>   to bust caches.
>
> Migration: each user's notification-preferences `email` moves to
> `users.email` and is removed from the preferences; rollback moves it
> back. Applies and rolls back on every engine.

### §6 Invitation (changed)

> Kind `reset` gains a second origin. `created_by` = the user themselves
> marks a **self-service** reset (60 minutes); an admin's stays 7 days.
> Creating either revokes the user's other open reset links.

### §7.9 Authentication and sessions (changed)

Replaces "There is no self-service reset by email" with:

> **Forgotten password** (Phase 33.1, decided 2026-10-04, superseding
> #36)
>
> - Shown only when email is configured (`MAIL_HOST`) and local sign-in is
>   on. Otherwise the link is absent and the routes answer 404.
> - `GET /forgot-password` asks for **username or email address**.
>   `POST` always answers with the same page and wording, whatever was
>   typed: "If that matches an account with an email address, we've sent
>   it a link. It works for 60 minutes." No account, a disabled account,
>   an account with no address, an SSO-only account (no password): same
>   answer, nothing sent.
> - Matching: a username matches one user; an email address matches every
>   active user with that address and a password, each getting their own
>   email (one link each, naming the username).
> - **Equal timing:** the answer must not be measurably faster when
>   nothing is sent. The mail is handed to the mailer after the response
>   is flushed (`fastcgi_finish_request()` where available); otherwise the
>   request is padded to a fixed floor (1.5 s). Tested with a fake clock
>   and a fake mailer, not wall time.
> - **Throttle:** at most 5 requests per client address per 15 minutes
>   and 3 emails per account per hour; over either, the same answer and
>   nothing sent. Logged at notice level with the address, never the
>   typed text.
> - **The email** is in the user's language: who asked (the address the
>   request came from), the link
>   (`{APP_URL}{APP_BASE_PATH}/reset/{token}`), that it expires in 60
>   minutes, and "If this wasn't you, ignore this email. Your password
>   hasn't changed." Plain text and HTML, no remote images.
> - **Using the link:** the existing reset page (new password and
>   confirmation). Opening it (GET) uses nothing up, so a mail scanner's
>   preview cannot spend it; the POST does. Success sets the password
>   through `PasswordHasher`, deletes all the user's sessions, revokes
>   their other reset links, signs them in (session regenerated, CSRF
>   rotated) and sends a short "Your Logbook password was changed" email.
>   API keys are untouched (they are not passwords).
> - A used, expired, revoked or unknown link answers 404 as today.
>
> **Email address**
>
> - Settings → Account → *Profile* has **Email**. Changing it needs the
>   current password (when the user has one), and a notice goes to the
>   **old** address. Settings → Reminders shows the same address and links
>   to it; it no longer has its own field. `MAIL_TO` remains the fallback
>   for **reminders** to admins without an address, never for reset
>   links.
>
> **Admin controls** on Settings → Users (`ManageUsers`, admins only;
> each a plain POST with CSRF, refused on the last active admin where it
> would lock everyone out, as today):
>
> - *Send reset email*: creates the 7-day reset link and emails it to the
>   user's address, in their language. Without an address, or without
>   email configured, the link is shown once as today. The link is never
>   both emailed and shown.
> - *Sign out everywhere*: deletes every session of that user (any device,
>   any method). It does not disable them; a header-based session will
>   sign straight back in, which the confirmation says. An admin may do
>   this to themselves (their current session included).
> - *Revoke access*: the existing *Disable* under the label the prototype
>   uses (see open questions), plus revoking the user's open links and
>   API keys in the same transaction.
> - *Add user*: username, display name, email, *Admin*. Creates the
>   account now, with no password, and emails a 7-day set-password link
>   (kind `reset`). Needs email configured; otherwise the form says to use
>   *Invite* instead. Unlike an invitation the account exists at once, so
>   vehicles can be shared or transferred to it before first sign-in.
> - Members never see these controls, and every route under
>   `/settings/users` asks `ManageUsers`. Signing out another user is
>   admin-only; a member signs out only themselves.
>
> **Avatars**
>
> - Settings → Account → *Profile*: upload (JPEG, PNG or WebP, up to
>   5 MB, by type sniffing, not extension), replace, remove. Drag and drop
>   as every file input (Phase 21.1).
> - Processed with GD as vehicle photos are: turned upright from EXIF,
>   then **re-encoded** to a 256 × 256 centre-cropped WebP (JPEG where
>   GD lacks WebP), which drops all metadata. The original is not kept.
> - Stored under `UPLOAD_PATH/avatars/`, never in the web root. Served by
>   `GET /users/{id}/avatar?v={avatar_updated_at}` to signed-in users only
>   (any signed-in user may see any avatar: names are already visible to
>   them), with `Cache-Control: private, max-age=31536000, immutable` and
>   `X-Content-Type-Options: nosniff`.
> - Without one: initials on a colour taken from the user id, as today's
>   placeholder. Shown in the sidebar footer, Settings → Users, sharing
>   lists, "added by" on entries and the Ask conversation.
> - Included in backup and restore, in `bin/export-user.php`, and deleted
>   with the user.

### §9 Configuration (changed)

> - `PASSWORD_RESET_ENABLED` (default `true`): set `false` to hide the
>   forgotten-password link even with email configured.
> - Development only (`docker-compose.dev.yml`): `MAILPIT_PORT` (default
>   `8025`), the Mailpit web UI on the host.

### §10 Deployment — development stack (changed)

> `docker-compose.dev.yml` runs **Mailpit** (`axllent/mailpit`, pinned
> tag, multi-arch) as `mailpit`, and the app's dev environment points at
> it: `MAIL_HOST=mailpit`, `MAIL_PORT=1025`, `MAIL_ENCRYPTION=none`,
> `MAIL_FROM=logbook@localhost`. Its UI is on `http://localhost:8025`.
> Mailpit is never in `docker-compose.yml` or `docker-compose.mysql.yml`.
>
> `bin/dev-setup.sh --with-sample-data` generates a new random password
> for each sample user on **every** run (20 characters from an
> unambiguous alphabet, from `/dev/urandom`): on a fresh database the
> seeder creates the users with them; on one that already has the sample
> users, it sets the new passwords on `demo` and `partner` only (and their
> other sessions end, as any password change does). The passwords are
> printed in the summary and written to `var/dev-credentials` (mode 600,
> git-ignored), which `--status` prints. The seeder refuses to run with
> `APP_ENV=production`, as today.

---

## Tasks

### 33.1.0 Spec and open questions first
- [ ] `spec.md` §6, §7.9, §7.11, §9, §10 and §13 as above.
- [ ] `open-questions.md`: #36 → *Decided* (2026-10-04): self-service reset
      by email, Phase 33.1. Add this phase's open questions.
- [ ] `ROADMAP.md` rows and sections for 33.1–33.4.

### 33.1.1 Password hashing check
- [ ] Confirm every password is hashed through `PasswordHasher`
      (`password_hash()`, `PASSWORD_ARGON2ID`) and verified with
      `password_verify()`, with re-hash on sign-in when needed.
- [ ] `DemoDataSeeder` uses `PasswordHasher` instead of calling
      `password_hash()` itself, so the sample users get the app's options.
- [ ] Architecture test: no `password_hash(`, `crypt(`, `md5(` or `sha1(`
      on a password anywhere in `src/`, `db/` or `bin/` outside
      `PasswordHasher`.

### 33.1.2 Email address on the user
- [ ] Migration: `users.email`, index; move each user's preference email
      across and back on rollback. SQLite, MySQL, MariaDB, PostgreSQL.
- [ ] `NotificationPreferences` reads the address from the user; Settings
      → Reminders shows it with a link to Account.
- [ ] Profile field with current-password check and notice to the old
      address. Proxy and OIDC auto-created users get their address here
      instead of in the preferences.

### 33.1.3 Forgotten password
- [ ] `ForgotPasswordAction` (GET/POST), `PasswordResetService`
      (matching, throttle, link, email), reusing `InvitationRepository`
      and the keyed token hash.
- [ ] Throttle generalised from `FailedKeyThrottle` (per address and per
      account).
- [ ] Mail after the response is flushed, or the timing floor.
- [ ] Reset POST: password set, sessions and other links revoked, sign-in,
      "password changed" email.
- [ ] Sign-in page link, shown under the rules above.
- [ ] Email templates (text and HTML) in en and de.

### 33.1.4 Admin controls
- [ ] *Send reset email*, *Sign out everywhere*, *Revoke access*, *Add
      user* on Settings → Users, with confirmation pages for the last
      three.
- [ ] Self-service resets shown in the open links list as "Requested by
      them", revocable.
- [ ] Route inventory: every `/settings/users*` route classified
      `ManageUsers`; a member gets 403 on each.

### 33.1.5 Avatars
- [ ] Migration: `avatar_path`, `avatar_updated_at`.
- [ ] `AvatarService` (validate, orient, crop, re-encode, store, delete)
      and `AvatarAction` (serve).
- [ ] `ui.avatar(user, size)` macro; used in the sidebar footer, users
      list, sharing lists, "added by", Ask.
- [ ] Backup, restore, export and user deletion include avatars.

### 33.1.6 Development stack
- [ ] `docker-compose.dev.yml`: `mailpit` service (pinned tag, healthcheck)
      and the app's `MAIL_*` pointing at it.
- [ ] `bin/dev-setup.sh`: start Mailpit with the stack; print its URL in
      the summary and `--status`; generate the sample passwords and pass
      them to the seeder (`DEMO_PASSWORD`, `PARTNER_PASSWORD`); write
      `var/dev-credentials`; update `--help`.
- [ ] `DemoDataSeeder`: read the passwords from the environment (generate
      and print them when run directly); when the sample users exist,
      set the new passwords instead of skipping silently. Give `demo` and
      `partner` email addresses (`demo@example.test`,
      `partner@example.test`) so resets can be tried in Mailpit.
- [ ] README, `docs/configuration.md`, `docs/deployment.md`,
      `docs/users-and-sharing.md`: forgotten password, admin controls,
      avatars, Mailpit, and that sample passwords are no longer fixed.

### 33.1.7 Tests
- [ ] **Enumeration:** identical response body, status and headers for an
      unknown username, unknown address, disabled user, SSO-only user and
      a real one; the fake mailer received exactly one message, for the
      real one only.
- [ ] **Shared address:** two users with one address get one email each.
- [ ] **Throttle:** the sixth request from an address and the fourth email
      for an account in an hour send nothing and look the same.
- [ ] **Link:** GET doesn't spend it; POST does; expired at 60 minutes;
      a newer link revokes the older; sessions of the user all deleted;
      the user is signed in; API keys still work.
- [ ] **Hidden** without `MAIL_HOST`, with `AUTH_LOCAL_LOGIN=false` and with
      `PASSWORD_RESET_ENABLED=false` (link absent, routes 404).
- [ ] **Admin controls:** each one admin-only; last-admin guards; *Sign out
      everywhere* removes every session; *Revoke access* blocks sign-in
      and API keys at once; *Add user* emails a set-password link and the
      account can be shared to before first sign-in.
- [ ] **Email change** needs the current password and notifies the old
      address.
- [ ] **Avatars:** rejects a PHP file named `.jpg`, an SVG, a 6 MB file, a
      decompression bomb (pixel limit); output has no EXIF; served only to
      signed-in users; removed with the user; survives backup and restore.
- [ ] **Hashing** architecture test.
- [ ] **Migration** round trip on every engine, email moved both ways.
- [ ] `bin/dev-setup.sh`: a shell test (or the smoke test) that two runs
      with `--with-sample-data` print different passwords and that both
      sign in.

---

## Acceptance criteria

1. On the sign-in page, *Forgotten your password?* → type `demo` → an
   email appears in Mailpit at `http://localhost:8025` → its link sets a
   new password and signs in; the old password no longer works.
2. Typing a username that doesn't exist looks exactly the same and sends
   nothing.
3. An admin can email a reset link, sign a user out everywhere, revoke
   their access and add a user; a member sees none of it and gets 403 on
   the routes.
4. A user uploads a photo as their avatar; it appears in the sidebar and
   on Settings → Users, cropped square and without metadata.
5. Two runs of `./bin/dev-setup.sh --with-sample-data` print two different
   pairs of passwords, and the latest pair works.
6. Definition of done (CLAUDE.md §11) holds.

## Open questions

- **Verify email addresses?** A confirmation link when an address is set
  would stop a typo sending reset links to a stranger, and would let
  `OIDC_LINK=email` (#51) be reconsidered. Drafted: not verified, but a
  change needs the current password and notifies the old address.
- **"Revoke the user":** the brief asks for it beside the reset email. The
  draft maps it to the existing *Disable* plus revoking links and API
  keys. Does the prototype mean that, or deleting the account, or only
  ending their sessions (*Sign out everywhere*)?
- **Self-service reset lifetime:** 60 minutes drafted (an admin's link
  stays 7 days).
- **Reset for SSO-only users** with local sign-in on: allow it to set a
  first password, or keep "nothing sent" as drafted?
- **Avatar visibility:** any signed-in user drafted. Restrict to users who
  share a vehicle with them?
