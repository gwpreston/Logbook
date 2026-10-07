# Phase 36.2 — Personal notification channels

*Everyone chooses where their own reminders go, and sets it up themselves.*

Status: 🚧 in progress · no release of its own (ships with Phase 36.3 as
**v3.3.0**) · file lives in `docs/phases/`

Part two of Phase 36 ([36.1](phase-36.1.md) moved the email server into
Settings). Since Phase 19 each user has their own email address and may
give a personal ntfy topic and Gotify token, but the *servers* behind those
(`NTFY_URL`, `NTFY_TOKEN`, `GOTIFY_URL`, `GOTIFY_TOKEN`, `WEBHOOK_URL`) are
environment variables with no page, only admins receive through the
instance's, and the webhook is one endpoint for everybody. This phase makes
every channel personal and puts them in the user's own **Account**:

- a **Notifications** page under Settings → Account, with one card per
  channel;
- **Email, ntfy, Gotify and Webhook** as personal channels (Telegram,
  Discord, Pushover and Mattermost follow in [36.3](phase-36.3.md), on the
  same machinery);
- a **status and a test** on each channel;
- a **policy** on where members' channels may send, because a member can now
  enter an address the server will call;
- the page checked against how the **design prototype** has *Settings →
  Reminder delivery*.

Read [`CLAUDE.md`](../../CLAUDE.md), [`spec.md`](../../spec.md) §7.11
(notifications: recipients, channels per user, idempotency, digest), §7.25
(AI connections: host classing, secrets), §8 (Settings layout), §7.30
(redaction), [Phase 19](phase-19.md), [Phase 33.1](phase-33.1.md) (the
user's email address), [Phase 33.2](phase-33.2.md) (Settings layout and its
*Prototype notes*) and [Phase 36.1](phase-36.1.md) first.

**Prerequisites:** [Phase 36.1](phase-36.1.md) built.

---

## Goals

1. **Settings → Account → Notifications:** a card per channel, generated
   from a definition, with enable, save, **Send test** and remove, and the
   last result.
2. **Every channel is personal.** Email (the server's SMTP, the user's own
   address), ntfy, Gotify and Webhook each belong to one user.
3. **Existing setups carry over:** personal ntfy topics and Gotify tokens
   move into the new place, and the server's ntfy and Gotify variables are
   imported once into admins' own channels, then no longer read;
   `WEBHOOK_URL` stays as the server's webhook, deprecated.
4. **Failing channels switch themselves off** after 5 failures in a row,
   and the user is told.
5. **A destination policy** for members' channels, set by an admin on
   Settings → Delivery.
6. **Secrets handled as in 36.1**, with one difference: members cannot use
   `env:` references.
7. **The prototype's *Reminder delivery* design** audited and followed
   where it shows things the app has.

## Not in scope

- Telegram, Discord, Pushover and Mattermost ([36.3](phase-36.3.md)).
- More than one channel of the same kind per user (parked).
- Per-channel choice of *what* to receive and quiet hours
  ([36.4](phase-36.4.md), #234); routing by vehicle.
- A REST API for channels. They hold secrets.
- Changing when notifications are sent, what they say, who the recipients
  are, or idempotency (§7.11).

---

## Spec additions

Rewritten on 2026-10-07 with the owner's decisions (#227–#235, #247–#249);
`spec.md` carries the same text.

### §6 Data model (changed)

> **NotificationChannel** (new), `notification_channels`: id, user_id
> (`ON DELETE CASCADE`), kind (`ntfy` | `gotify` | `personal-webhook`, and
> from 36.3 `telegram` | `discord` | `pushover` | `mattermost`; email is
> not a row, and `webhook` stays the key of the server's webhook),
> enabled (boolean), settings (JSON: the kind's non-secret fields),
> last_status (`ok` | `failed`, null before the first send),
> last_attempt_at (UTC), last_error (up to 255 characters, redacted),
> failures (consecutive failed sends, default 0), switched_off_at (UTC,
> null unless switched off after failures), created_at, updated_at.
> Unique `(user_id, kind)`. Secrets are NotificationSecrets (§6, 36.1)
> with `owner_user_id` set, named `{kind}.{field}`. **`env:` references
> are never allowed for a user's secrets**, neither saved nor typed into
> *Send test*: a reference to the server's environment would let a member
> have the server send a variable's value to an address they chose.
>
> Email's *enabled* flag stays in the user's notification preferences;
> its last result is the user setting `notifications.email_result`. Rows
> are in backups and exports **without secrets**; a restored channel with
> a secret field shows *Needs setup* until it is entered again.

### §7.11 Notifications (changed)

> - **A channel is usable for a user** when it is enabled, configured
>   (its required fields saved, its secrets readable) and allowed (below).
>   Email is usable when the server's email is configured (36.1), the user
>   has an address (Phase 33.1, or the admins' default recipient for an
>   admin) and has it enabled.
> - **Definitions.** Each personal kind is a `ChannelDefinition` (key,
>   label, icon, fields with their type, whether each is a secret, limits
>   and hint) plus a sender that sends one notification with one user's
>   settings. The page, the validation and the dispatcher read only the
>   definition, so a new kind is a definition and a sender
>   (`docs/notification-channels.md`).
> - **Delivery.** For each recipient the dispatcher sends the same content
>   through email (if usable), the server's webhook (if set, below) and
>   each usable personal channel. One channel failing never stops another,
>   and one recipient failing never affects another. After each send to a
>   personal channel or email, its last status, time and error are
>   written. `reminder_deliveries.channels` lists the keys that succeeded.
>   Claiming, retrying and not repeating a partial success are unchanged.
> - **Switched off after failures** (#233). A personal channel that fails
>   **5 sends in a row** is switched off: `enabled` false,
>   `switched_off_at` set, the card says "Switched off after 5 failed
>   sends. Check the settings, then switch it on again." The user is told
>   once, through their other usable channels (nothing is sent if there
>   are none; the card still says it). That notice never counts towards a
>   channel's failures. A success resets the count; switching the channel
>   on again (or saving it) resets the count and clears
>   `switched_off_at`. Tests never count. **Email is never switched off**
>   (its failures are usually the server's).
> - **Personal channels:**
>   - **Email:** the user's address (Phase 33.1) through the server's
>     SMTP. *Not available on this server* until an admin sets it up.
>   - **ntfy:** *Topic URL* (`https://ntfy.sh/my-topic`; visible) and an
>     optional *Access token* (secret).
>   - **Gotify:** *Server URL*, *Application token* (secret) and
>     *Priority* (0 to 10, default 5; overdue reminders go at least at 8).
>   - **Webhook** (`personal-webhook`): *URL*; the JSON payload is the
>     server webhook's, including `user`.
>   A saved secret goes only to the host it was saved for: changing a
>   URL's host requires the secret to be typed again (as 36.1's test
>   does for the SMTP password).
> - **The environment variables** (#227, #247). `NTFY_URL`, `NTFY_TOKEN`,
>   `GOTIFY_URL`, `GOTIFY_TOKEN` and `GOTIFY_PRIORITY` are **imported once
>   by the migration and then removed**: never read again. The import
>   keeps everything that worked: every admin without their own ntfy
>   topic gets the server's topic and token as their ntfy channel; every
>   admin without their own Gotify token gets the server's Gotify
>   (URL, token, priority); a user whose own ntfy topic is on `NTFY_URL`'s
>   server gets `NTFY_TOKEN` as their access token, as they used it. While
>   any of the five is still set, Settings → Delivery says "`NTFY_URL` is
>   set in the environment but is no longer read. It was imported into
>   admins' own channels; remove it." **`WEBHOOK_URL` stays** (#228): the
>   server's webhook, key `webhook`, receives every recipient's
>   notifications while it is set, as before (a user who had switched it
>   off keeps it off). It is **deprecated**; Settings → Delivery says so
>   and names it. It is the admin's own setting, so the policy below does
>   not apply to it.
> - **Send test.** Each card has **Send test**, which sends "Test from
>   Logbook" through that channel only, with the typed values *unsaved*
>   (an empty secret field: the saved secret, if the host is the same),
>   and shows the result on the page. At most 5 tests per user in 10
>   minutes. Tests do not change the card's last result. The *Send test
>   notification* on Settings → Reminders sends through every usable
>   channel, as before.
> - **Status words** on a card: *On*, *Off*, *Needs setup*, *Blocked by
>   your administrator's setting*, *Switched off after failures*, *Not
>   available on this server* (email, until the server is set up). Text
>   and icon, never colour alone. Under it the last result: "Last sent
>   {time}" or "Last attempt failed: {error}".
> - **Privacy** (#232). A user's channels, values and last errors are
>   visible to that user only. Admins see nothing of members' channels,
>   not even the kinds. Channel routes take the user from the session and
>   the kind from the path, never an id, so another user's channel cannot
>   be addressed.
> - **Demo mode** (Phase 35.1) blocks saving, testing and sending.

### §7.11 Where members' channels may send (new)

> Admins choose on **Settings → Delivery → Where members can send**
> (global setting `notifications.member_destinations`):
>
> | Setting | Members' channels may send to |
> |---|---|
> | *The internet only* (`internet`) | public addresses |
> | *The internet and your network* (`network`, default, #229) | public addresses and *Your network* |
> | *The internet, your network and this server* (`server`) | all three classes |
>
> The classes are §7.25's (*This server*, *Your network*, *Internet*),
> from the same `ConnectionLocator` rules, including the admin's list of
> *This server's addresses*. **Every** address a name resolves to must be
> allowed (not only the widest). In addition, **link-local addresses
> (169.254.0.0/16, fe80::/10) are always refused for members**: they are
> where cloud metadata services and router interfaces live. So are
> unspecified, multicast, reserved and broadcast addresses and IPv6 forms
> carrying an IPv4 address (added by the build review, 2026-10-07; spec
> §7.11 lists them). IPv4-mapped
> IPv6 addresses are classed as their IPv4 form.
>
> - The host is resolved when a channel is saved, when it is tested and
>   **on every send**, and the request connects to an address that was
>   classed (pinned), so a name cannot change between the check and the
>   call. Redirects are never followed for any channel
>   (`max_redirects: 0`). A name that does not resolve is refused for a
>   member.
> - An **admin's own** channels are not restricted.
> - The class shows as a badge (icon and words) on a channel card.
> - A member's saved address that the policy now refuses is **kept** and
>   shown as *Blocked by your administrator's setting*; it is not used and
>   not deleted.

### §8 Settings layout (changed)

> - **Account** gains a second link row, **Notifications**
>   (`/settings/notifications`, #231): *In-app* (always on), Email, then
>   a card per personal channel. The *Reminders and notifications* page
>   no longer holds channels. It keeps lead times, the digest switch, the
>   calendar feed, the *Needs attention* thresholds and *Send test
>   notification*, and shows one line, "Sent to: Email, ntfy" (or
>   "Nowhere yet"), linking to Account → Notifications.
> - **Administration → Delivery** gains the card *Where members can send*
>   and the notices about the channel variables.
>
> The prototype's *Reminder delivery* rows (40 px icon tile, title, hint,
> status; fields and *Send test* under the row) are followed. Where it
> puts channel setup in Settings rather than in Account, the owner's
> decision (Account → Notifications) wins (see *Prototype notes*).

---

## Decisions (and why)

Decided by the owner on 2026-10-07, before any code.

- **Channels under Account, not Reminders** (#231). They are personal
  data about where *you* are reached. Reminders keeps what is about
  reminders.
- **Import the channel variables once, then stop reading them** (#227,
  #247). Every admin and every same-server topic keeps working, and there
  is one place to look afterwards. `WEBHOOK_URL` is the exception (#228):
  it serves integrations that want everyone's notifications, which a
  personal webhook can't, so it stays, deprecated.
- **A policy, because a member can now enter an address** (#229). The
  default allows a household's own network, where self-hosted ntfy and
  Gotify live, so nothing breaks on upgrade.
- **No key at migration** (#230): a token is created as *Needs setup*
  and the old value is left where it was (it was already stored there in
  plain text, so nothing new is exposed), until the user enters it again.
- **No `env:` for members.** It is safe for an admin (they own the
  environment); it is a way to read it for anyone else.
- **Admins cannot see members' channels** (#232). Support does not need
  the values, and a household still has privacy within it.
- **Switch off after 5 failures in a row** (#233, #248), and tell the user
  through their other channels. Email never switches off (#249).
- **Per-channel choices and quiet hours** (#234) are wanted, as their own
  phase: [36.4](phase-36.4.md). Quiet hours hold messages until they end.
- **A definition per kind.** It keeps the page, the validation and the
  tests the same for all eight channels and makes adding a ninth small.
- **Distinct keys for the two webhooks.** The server's stays `webhook`
  (so existing preferences and delivery records mean what they did); the
  personal one is `personal-webhook`. One key for both would let a success
  on one hide a failure on the other.

---

## Tasks

### 36.2.0 Spec first
- [x] `spec.md` §6, §7.11 (three parts), §8, §9, §13; open questions
      #227–#235 decided and #247–#249 added (2026-10-07);
      [Phase 36.4](phase-36.4.md) written for #234; `ROADMAP.md` rows and
      section; 36.3's release notes brought in line.

### 36.2.1 Audits
- [x] **How personal channels are stored today** (Phase 19). Recorded
      under *Audit*.
- [x] **Prototype: *Settings → Reminder delivery*.** Recorded under
      *Prototype notes*, extending Phase 33.2's.

### 36.2.2 Migration (every engine, reversible)
- [x] `notification_channels` table as above.
- [x] Move each user's personal ntfy topic and Gotify token into rows.
      Gotify's row takes `GOTIFY_URL` as its *Server URL* (the server the
      token belongs to) and `GOTIFY_PRIORITY`. The *enabled* flag follows
      the user's saved choice, or *on* where they never saved one (Phase
      19's "every configured channel"). Tokens are sealed exactly as
      `SecretBox` seals (`logbook-notify`; the code is repeated in the
      migration and a test pins the two together); **a token is never
      copied in the clear** into the new tables. Without a key the channel
      is created as *Needs setup* and the old value is left where it was
      until the user enters it again. Moved values leave the preferences.
- [x] Import once (#247): admins without their own ntfy get `NTFY_URL` and
      `NTFY_TOKEN`; admins without their own Gotify token get `GOTIFY_URL`,
      `GOTIFY_TOKEN` and `GOTIFY_PRIORITY`; a personal topic on
      `NTFY_URL`'s server gets `NTFY_TOKEN`. The migration reads the same
      environment as the app (`phinx.php` passes the few variables it
      needs, including `SESSION_SECRET` / `SESSION_SECRET_FILE`). Logs
      counts only, never values.
- [x] The preferences' `channels` list keeps `email` and `webhook`.
- [x] Rolling back puts personal topics and tokens back into the
      preferences (opening sealed tokens where it can; imported channels
      are dropped, since the variables were their source) and drops the
      table and the users' channel secrets.

### 36.2.3 Code
- [x] `ChannelDefinition`s and senders for ntfy, Gotify and the personal
      webhook; email and the server webhook stay `NotificationChannel`s;
      the registry gives the dispatcher every usable channel for a
      recipient; the dispatcher writes the last result and counts
      failures; switching off after 5 and the notice.
- [x] `OutboundDestination`: the policy (every resolved address, link-local
      refused for members), resolve and pin, `max_redirects: 0`. Used by
      every personal sender (and by 36.3's).
- [x] Account → Notifications page and actions (save, switch on and off,
      remove, test with the throttle); the secret field pattern from 36.1;
      no `env:` for user secrets (save and test); a saved secret only to
      its host; routes take the user from the session and the kind from the
      path; route inventory.
- [x] Settings → Delivery: *Where members can send*, the removed-variables
      notice and the `WEBHOOK_URL` notice. Settings → Reminders: channels
      removed, the *Sent to* line added. Settings → Account: the
      *Notifications* row.
- [x] `NtfyChannel` and `GotifyChannel` (environment-based) removed;
      `WebhookChannel` keeps `WEBHOOK_URL`, with `max_redirects: 0`.
- [x] Users' notification secrets in the redaction; channel rows in
      backups (without secrets) and the demo reset.

### 36.2.4 Docs
- [x] `docs/notification-channels.md`: rewritten around personal channels,
      the policy, switching off, the server webhook and *adding a channel*
      (a definition and a sender). `.env.example`, `docs/configuration.md`
      and the compose files: the five variables removed (imported once),
      `WEBHOOK_URL` deprecated.
- [x] `docs/deployment.md`, README: where notifications are configured.
- [x] `CHANGELOG.md` (unreleased 3.3.0).

### 36.2.5 Translations
- [x] English and German strings for the page, statuses, hints, errors,
      the switched-off notice and the policy card.

### 36.2.6 Tests
- [x] Unit: each definition's validation (ntfy URL and topic, Gotify URL,
      token and priority range, webhook URL; `http` and `https` only, no
      credentials, no fragment).
- [x] Unit: the policy matrix. Each setting against loopback, `localhost`,
      `host.docker.internal`, RFC 1918, ULA, `100.64.0.0/10`, `.lan` names,
      link-local, a public address, a name that resolves to both, an
      IPv4-mapped IPv6 address; link-local always refused for a member and
      allowed for an admin.
- [x] Unit: the request is pinned to the classed address (a resolver that
      changes its answer between calls is not followed); a redirect is
      reported and not followed.
- [x] Unit: an `env:` reference is refused for a member's secret (save and
      test) and accepted for an admin's SMTP password.
- [x] Integration: the dispatcher sends through every usable channel for
      each recipient; a failing channel does not stop the others; a
      recipient with none gets nothing; the last result is written;
      `reminder_deliveries` and idempotency behave as before.
- [x] Integration: 5 failures in a row switch a channel off and tell the
      user once through their other channels; email never switches off; a
      success resets the count; switching on again clears it.
- [x] Integration: **user A cannot read, edit, switch, test or remove user
      B's channel** (an IDOR test per route); a member cannot reach
      Delivery; the route inventory classifies every new route.
- [x] Integration: a secret never appears in any response, including after
      a validation error, nor in a job's output, the log or an error; the
      test uses unsaved typed values; a saved secret is not sent to a
      changed host; the throttle blocks the sixth test.
- [x] Integration: `NTFY_*` and `GOTIFY_*` are no longer read; the server
      webhook still receives every recipient's notifications with the
      payload unchanged; the Delivery notices name the variables set.
- [x] Integration: a member's saved address that the policy refuses shows
      *Blocked*, is not used and is not deleted; relaxing the policy brings
      it back.
- [x] Integration: the migration moves personal values, imports the
      variables, keeps *enabled*, handles no key (nothing copied in the
      clear), and rolls back; the app opens what it sealed.
- [x] Integration: backups contain channel rows without secrets; a restore
      shows *Needs setup*.
- [x] Integration: demo mode blocks saving, testing and sending.
- [x] Without JavaScript every action works (each is a form).

### 36.2.7 Checks
- [ ] `design-reviewer` agent on Notifications, Delivery and Reminders at
      375, 768 and 1280 px, light and dark, all four accents, keyboard only,
      and the secret fields with a screen reader.
- [ ] `bug-hunter`, `security-scanner` and `performance-auditor` on the
      branch.

### Sample data
- [x] `DemoDataSeeder`: the demo owner has Email on and nothing else.
      Demo mode sends nothing; nothing else is seeded. (Already so: the
      seeder stores no notification preferences, which means email on, and
      no channel rows.)

### Release
- [ ] Ships with Phase 36.3 as **v3.3.0**.

---

## Acceptance criteria

- A user can set up, test, switch off and remove their own ntfy, Gotify,
  webhook and email delivery, and nobody else can see or change it.
- A household that configured notifications by environment variable still
  receives them after upgrading, and Settings → Delivery says which
  variables can now be removed.
- A channel that keeps failing switches itself off and says so.
- A member cannot make the server call an address the admin has not
  allowed, and cannot use `env:` to read a variable.
- Settings → Reminders no longer holds channels and says where they went.
- The prototype's *Reminder delivery* design is reflected where the app has
  the data, and everything it shows that the app lacks is decided.

## Audit

Done on 2026-10-07, before any code.

**Storage today (Phase 19).** One user-scoped row of the `settings` table,
name `notifications`, JSON `{channels, digest, ntfy_url, gotify_token}`
(`Service\Reminder\ReminderSettingsStore`, `NotificationPreferences`).
`channels` is the list of enabled channel keys (`email`, `ntfy`,
`gotify`, `webhook`), or null until the user saves Settings → Reminders,
meaning every configured channel. `ntfy_url` is a topic URL and
**`gotify_token` is stored in plain text**; neither is in
`notification_secrets`. Both are edited on Settings → Reminders
(`ReminderSettingsForm`) and copied onto `Recipient`.

**Channels.** `NtfyChannel`, `GotifyChannel` and `WebhookChannel` read
their variables in their constructors. ntfy: the personal topic, else
`NTFY_URL` for admins; `NTFY_TOKEN` goes with any topic on `NTFY_URL`'s
server, **members' included**. Gotify: the server is always `GOTIFY_URL`;
the token is the personal one, else `GOTIFY_TOKEN` for admins. Webhook:
`WEBHOOK_URL` for every recipient. All use the shared HTTP client
(`max_redirects: 3`, 15 s), wrapped by the demo guard; nothing classes or
pins the destination.

**Senders.** `NotificationDispatcher` is called by `ReminderNotifier`
(reminders, digest), `PriceAlertChecker`, `JobFailureAlerts` and
`SendTestNotificationAction`, each with `Recipient::of()` and the user's
preferences; `ChannelRegistry::active()` picks the channels. Keys reach
`reminder_deliveries.channels` and `reminders.channels_notified` (history
only; nothing reads them to decide anything).

**Helpers.** `ConnectionLocator` classes by the widest of the resolved
addresses (fine for a badge, not for a policy, so the policy classes each
address). `IpRange` reads IPv4-mapped IPv6 as IPv4. `RateLimiter` gives a
sliding window per key (the test throttle). Removed variables are shown on
Settings → Delivery (36.1's `OLD_VARIABLES`); there is no start-up notice
mechanism, so the drafted "each start logs one notice" is dropped in
favour of the Delivery page.

## Prototype notes

Extends [Phase 33.2](phase-33.2.md)'s note on *Reminder delivery* (item 5).
The prototype's card (`design-import/Logbook.dc.html`) shows, in rows
divided by hairlines, each with a 40 px icon tile, a bold title, a muted
hint and a switch or badge:

1. **In-app** (`notifications`): "Badge count and the Reminders list",
   badge *Always on*.
2. **Email** (`mail`): "A digest sent to your inbox", a switch; when on,
   the address in an input and *Send test* beside it.
3. **Push via webhook** (`webhook`): "ntfy, Gotify, Home Assistant or any
   URL that accepts a POST", a switch; when on, format chips *ntfy* /
   *JSON webhook*, the URL with *Send test* beside it, a hint per format,
   and a status line with an `info` icon ("Delivered at 08:14", "Server
   replied 404", "Couldn't reach that URL").
4. *Send at* time chips and *Frequency* chips (*Daily digest* / *Once per
   item*).
5. **Calendar feed (iCal)**: a switch, the URL, *Copy*, *Download .ics*,
   *Reset link*.

Sorted:

- **Layout and style** (built): rows with a 40 px icon tile, title, hint
  and the status on the right; the fields and *Send test* indented under
  the row; the last result as a status line with an icon; hairlines
  between rows; one card per channel on the page. From the app's tokens
  and macros.
- **Behaviour the app has** (built from it): *In-app, always on* (an info
  row at the top of Notifications); email on or off and its test; ntfy
  and webhook URLs and a test; the last result. The calendar feed and the
  digest stay on Settings → Reminders (they are about reminders, #231).
- **The app's own way, decided:** the email address is the account's,
  confirmed on Profile (Phase 33.1), so the row shows it with a link
  instead of an input; ntfy, Gotify and the webhook are separate cards
  (one definition each) rather than one URL with format chips (#231).
- **Behaviour the app does not have:** webhook format chips, *Send at* and
  *Frequency* are already parked (#168, spec §12). *Download .ics*: the
  feed's `https` link already downloads the file, so nothing is added.
  Nothing new to decide (#235).

## Open questions

All decided on 2026-10-07, before any code (log #227–#235, #247–#249).

- **The environment variables.** *Decided (#227, #247):* imported once by
  the migration into every admin without their own ntfy or Gotify, plus
  `NTFY_TOKEN` onto same-server personal topics; then never read.
- **The instance webhook.** *Decided (#228):* `WEBHOOK_URL` still
  receives every recipient's notifications while set; deprecated.
- **Policy default.** *Decided (#229):* *The internet and your network*.
- **No key at migration.** *Decided (#230):* *Needs setup*, old value left
  in place until re-entered.
- **Where in Settings.** *Decided (#231):* Account → Notifications.
- **Admins' view.** *Decided (#232):* nothing.
- **Failing channels.** *Decided (#233, #248, #249):* switched off after 5
  failed sends in a row, the user told once through their other channels;
  email never switched off.
- **What to receive.** *Scheduled (#234):* per-channel choices and quiet
  hours (held until they end) in [Phase 36.4](phase-36.4.md).
- **Anything the prototype shows.** *Decided (#235):* nothing new; see
  *Prototype notes*.
