# Phase 33.1 — Accounts: forgotten password, admin controls, avatars and dev mail

*Get back in without asking anyone, see who's who at a glance, and see
every email the app sends while developing.*

Status: ✅ complete · no release of its own (ships with Phase 33.4 as
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
2. **One confirmed email address per user**, stored on the user, confirmed
   by a link, used for reset links, sign-in by email and reminder email
   (today it lives in the notification preferences).
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

- Linking SSO accounts by email (#51) stays parked, although addresses
  are now confirmed (#157).
- Two-factor authentication, passkeys, password strength meters.
- Public sign-up. Only admins create accounts.
- Resetting a password for an account with no password when local sign-in
  is off (`AUTH_LOCAL_LOGIN=false`): there is nothing to reset.
- Gravatar or any other remote avatar source (data stays local).

---

## Spec additions

Written into [`spec.md`](../../spec.md) on 2026-10-05, with this phase's
open questions decided (below): §6 User (`email`, `email_pending`,
`avatar_path`, `avatar_updated_at`), §6 Invitation (self-service `reset`,
kind `email`), §6 Session, §7.9 *Email addresses*, *Sign-in by username
or email*, *Forgotten password*, *Admin controls* and *Avatars*, §7.9
OIDC *Finding the user* (the `email` claim), §7.11 *Channels per user*,
§9 `PASSWORD_RESET_ENABLED` and `MAIL_TO`, §10 *Development stack* and
§13. The spec is the current text; the draft that stood here is
superseded by it.

---

## Tasks

### 33.1.0 Spec and open questions first
- [x] `spec.md` §6, §7.9, §7.11, §9, §10 and §13 as above.
- [x] `open-questions.md`: #36 → *Scheduled* (2026-10-04): self-service
      reset by email, Phase 33.1 (and the note in Phase 19). This phase's
      open questions added and decided (#157–#165).
- [x] `ROADMAP.md` rows and sections for 33.1–33.4.

### 33.1.1 Password hashing check
- [x] Confirm every password is hashed through `PasswordHasher`
      (`password_hash()`, `PASSWORD_ARGON2ID`) and verified with
      `password_verify()`, with re-hash on sign-in when needed.
- [x] `DemoDataSeeder` uses `PasswordHasher` instead of calling
      `password_hash()` itself, so the sample users get the app's options.
- [x] Architecture test: no `password_hash(`, `crypt(`, `md5(` or `sha1(`
      on a password anywhere in `src/`, `db/` or `bin/` outside
      `PasswordHasher`.

### 33.1.2 Email address on the user
- [x] Migration: `users.email`, `users.email_pending`, index;
      `invitations.email`; move each user's preference email across
      (confirmed) and back on rollback. SQLite, MySQL, MariaDB, PostgreSQL.
- [x] `NotificationPreferences` reads the address from the user; Settings
      → Reminders shows it with a link to Account.
- [x] Profile field with current-password check, pending address,
      confirmation link (kind `email`, 24 hours, GET shows, POST
      confirms), *Send the link again*, *Cancel*, and notices to the old
      address.
- [x] Proxy and OIDC auto-created users get their address on the user:
      proxy confirmed, OIDC confirmed only with `email_verified`, else
      pending.
- [x] Sign-in by username or confirmed email (#162).

### 33.1.3 Forgotten password
- [x] `ForgotPasswordAction` (GET/POST), `PasswordResets`
      (matching, throttle, link, email), reusing `InvitationRepository`
      and the keyed token hash.
- [x] Throttle generalised from `FailedKeyThrottle` (per address and per
      account).
- [x] Mail after the response is flushed, or the timing floor.
- [x] Reset POST: password set, sessions and other links revoked, sign-in,
      "password changed" email.
- [x] Sign-in page link, shown under the rules above.
- [x] Email templates (text and HTML) in en and de.

### 33.1.4 Admin controls
- [x] *Send reset email*, *Sign out everywhere*, *Revoke access* /
      *Restore access* (the renamed *Disable* / *Enable*, #158), *Add
      user* on Settings → Users, with confirmation pages for *Sign out
      everywhere*, *Revoke access* and *Add user*. Using *Add user*'s link
      confirms the address.
- [x] Self-service resets shown in the open links list as "Requested by
      them", revocable.
- [x] Route inventory: every `/settings/users*` route classified
      `ManageUsers`; a member gets 403 on each.

### 33.1.5 Avatars
- [x] Migration: `avatar_path`, `avatar_updated_at`.
- [x] `AvatarService` (validate, orient, crop, re-encode, store, delete)
      and `AvatarAction` (serve).
- [x] `ui.avatar(user, size)` macro; used in the sidebar footer, users
      list, sharing lists, "added by", Ask.
- [x] Backup, restore, export and user deletion include avatars.

### 33.1.6 Development stack
- [x] `docker-compose.dev.yml`: `mailpit` service (pinned tag, healthcheck)
      and the app's `MAIL_*` pointing at it.
- [x] `bin/dev-setup.sh`: start Mailpit with the stack; print its URL in
      the summary and `--status`; generate the sample passwords and pass
      them to the seeder (`DEMO_PASSWORD`, `PARTNER_PASSWORD`); write
      `var/dev-credentials`; update `--help`.
- [x] `DemoDataSeeder`: read the passwords from the environment (generate
      and print them when run directly); when the sample users exist,
      set the new passwords instead of skipping silently. Give `demo` and
      `partner` email addresses (`demo@example.test`,
      `partner@example.test`) so resets can be tried in Mailpit.
- [x] README, `docs/configuration.md`, `docs/deployment.md`,
      `docs/users-and-sharing.md`: forgotten password, admin controls,
      avatars, Mailpit, and that sample passwords are no longer fixed.

### 33.1.7 Tests
- [x] **Enumeration:** identical response body, status and headers for an
      unknown username, unknown address, disabled user, SSO-only user and
      a real one; the fake mailer received exactly one message, for the
      real one only.
- [x] **Shared address:** two users with one address get one email each.
- [x] **Throttle:** the sixth request from an address and the fourth email
      for an account in an hour send nothing and look the same.
- [x] **Link:** GET doesn't spend it; POST does; expired at 60 minutes;
      a newer link revokes the older; sessions of the user all deleted;
      the user is signed in; API keys still work.
- [x] **Hidden** without `MAIL_HOST`, with `AUTH_LOCAL_LOGIN=false` and with
      `PASSWORD_RESET_ENABLED=false` (link absent, routes 404).
- [x] **Admin controls:** each one admin-only; last-admin guards; *Sign out
      everywhere* removes every session; *Revoke access* blocks sign-in
      and API keys at once (as *Disable* does); *Add user* emails a set-password link and the
      account can be shared to before first sign-in.
- [x] **Email change** needs the current password, notifies the old
      address, stays pending (unused for resets, sign-in and reminders)
      until the link's POST; the GET spends nothing; an expired or
      replaced link answers 404.
- [x] **Sign-in by email:** a confirmed address of one user signs in; a
      shared or pending address is refused like a wrong password; a
      username containing `@` wins over an address.
- [x] **Avatars:** rejects a PHP file named `.jpg`, an SVG, a 6 MB file, a
      decompression bomb (pixel limit); output has no EXIF; served only to
      signed-in users; removed with the user; survives backup and restore.
- [x] **Hashing** architecture test.
- [x] **Migration** round trip on every engine, email moved both ways.
- [x] `bin/dev-setup.sh`: a shell test (or the smoke test) that two runs
      with `--with-sample-data` print different passwords and that both
      sign in.

---

## Changed while building it

spec.md §6, §7.9, §7.11, §9 and §10 are the current text.

- **No sign-in throttle existed to count against.** Sign-in has only the
  failed sign-in log (for fail2ban); a refused email sign-in is logged the
  same way. The spec said otherwise and was corrected.
- **Reset links stay `/invite/{token}`**, as every reset link was; no
  `/reset/` route.
- **A new `RateLimiter`** (a sliding window per bucket and key, files in
  `var/cache/rate-limit`) rather than reshaping `FailedKeyThrottle`, whose
  block-after-N semantics the API still wants.
- **The timing floor is the main path, not a fallback.** The Docker image
  runs Apache with mod_php, where `fastcgi_finish_request()` doesn't exist.
  Every *Forgotten password* answer is padded to 1.5 s, and its emails are
  queued in `AfterResponse`, which the front controller runs after the
  response is emitted (closing the connection first under PHP-FPM).
- **The answer echoes what was typed**, in a hidden field, so *Send it
  again* re-posts it; the enumeration test compares answers with that value
  taken out.
- **Avatars use the photo pixel limit** (50 megapixels, `ImageCleaner`)
  rather than a second 40 MP limit, and `avatar_path` is relative to
  `UPLOAD_PATH` (under `avatars/`), like every stored file, so backups,
  restores and `bin/export-user.php` carry it with no special case.
- **The avatar route is `/users/{member}/avatar`**: Slim puts route
  arguments into request attributes, so user routes use `{member}`
  (Phase 19).
- **Email links are left out of the open links list** on Settings → Users:
  they are the user's own, and *Cancel* on Settings → Account revokes them.
- **A reset link records the address it was emailed to**
  (`invitations.email`), so using *Add user*'s link confirms exactly that
  pending address and no other.
- **The email field's password is `email_password`**, so the Account page
  doesn't have two inputs with one id.
- **`phpunit.xml.dist` pins `MAIL_HOST` empty**: the dev container now
  points at Mailpit, and tests choose email themselves.
- **The dev-setup round trip is `bin/tools/dev-sample-passwords-test.sh`**,
  run against the Docker stack (in an isolated compose project for this
  phase: `COMPOSE_PROJECT_NAME=logbook-check APP_PORT=8091
  MAILPIT_PORT=8026`). It checks two runs give two passwords, the latest
  signs in and the earlier doesn't, `var/dev-credentials` is mode 600, and a
  reset email for `demo` reaches Mailpit.

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

All decided by the owner on 2026-10-05, before the phase was built
([`open-questions.md`](open-questions.md) #157–#165).

- **Verify email addresses?** *Decided 2026-10-05 (#157):* yes. A new or
  changed address waits as pending until its 24-hour link is used, and is
  used for nothing until then. #51 stays parked.
- **"Revoke the user":** *Decided 2026-10-05 (#158):* the prototype has no
  such control. *Revoke access* / *Restore access* is the existing
  *Disable* / *Enable* renamed: sessions and API keys are already refused
  while disabled, and work again on *Restore access*.
- **Self-service reset lifetime:** *Decided 2026-10-05 (#159):* 60 minutes;
  an admin's link stays 7 days.
- **Reset for SSO-only users** with local sign-in on: *Decided 2026-10-05
  (#160):* nothing sent, as drafted.
- **Avatar visibility:** *Decided 2026-10-05 (#161):* any signed-in user.
- **What a user signs in with** (found while starting: the prototype signs
  in by email, the draft by username with shared addresses): *Decided
  2026-10-05 (#162):* either. A username first; otherwise a confirmed
  address held by exactly one active user with a password.
- **Are the addresses already in the notification preferences confirmed?**
  (found while starting): *Decided 2026-10-05 (#163):* yes, on upgrade.
- **What a pending address is used for** (found while starting):
  *Decided 2026-10-05 (#164):* nothing; the old confirmed address (or
  none) stays in use.
- **Addresses from outside the profile form** (found while starting):
  *Decided 2026-10-05 (#165):* confirmed without a link for *Add user*
  once its set-password link is used, OIDC with `email_verified`, the
  proxy's header or claim, and the sample users.
