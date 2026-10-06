# Phase 36.2 — Personal notification channels

*Everyone chooses where their own reminders go, and sets it up themselves.*

Status: 📋 planned · no release of its own (ships with Phase 36.3 as
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
   move into the new place; the environment variables keep working as
   admin-only fallbacks, marked deprecated.
4. **A destination policy** for members' channels, set by an admin on
   Settings → Delivery.
5. **Secrets handled as in 36.1**, with one difference: members cannot use
   `env:` references.
6. **The prototype's *Reminder delivery* design** audited and followed
   where it shows things the app has.

## Not in scope

- Telegram, Discord, Pushover and Mattermost ([36.3](phase-36.3.md)).
- More than one channel of the same kind per user (parked).
- Per-channel choice of *what* to receive (due, overdue, digest, price
  alerts), quiet hours, or routing by vehicle. They would be new settings;
  see the open questions.
- A REST API for channels. They hold secrets.
- Changing when notifications are sent, what they say, who the recipients
  are, or idempotency (§7.11).

---

## Spec additions

### §6 Data model (changed)

> **NotificationChannel** (new): id, user_id (`ON DELETE CASCADE`), kind
> (`ntfy` | `gotify` | `webhook`, and from 36.3 `telegram` | `discord` |
> `pushover` | `mattermost`; email is not a row), enabled (boolean),
> settings (JSON: the kind's non-secret fields), last_status (`ok` |
> `failed`, null before the first send), last_attempt_at (UTC),
> last_error (up to 255 characters, redacted), created_at, updated_at.
> Unique `(user_id, kind)`. Secrets are NotificationSecrets (§6, 36.1) with
> `owner_user_id` set. **`env:` references are not allowed for a user's
> secrets.** A reference to the server's environment, saved by a member,
> would let them have the server send a variable's value to an address they
> chose.
>
> Email's *enabled* flag and last result stay with the user's notification
> preferences. Rows are in backups and exports **without secrets**; a
> restored channel shows *Needs setup* until its secret is entered again.

### §7.11 Notifications (changed)

> - **A channel is usable for a user** when it is enabled, configured (its
>   required fields saved, its secrets readable) and allowed (below). Email
>   is usable when the server's email is configured (36.1), the user has an
>   address (Phase 33.1) and has it enabled.
> - **Definitions.** Each kind is a `ChannelDefinition` (label, icon, fields
>   with their type, whether each is a secret, validation and hint, and
>   the message limits) plus a sender that implements `NotificationChannel`
>   for one recipient's settings. The page, the validation and the
>   dispatcher read only the definition, so a new kind is a definition and a
>   sender (`docs/notification-channels.md`).
> - **Delivery.** For each recipient, the dispatcher sends the same content
>   through each usable channel. One channel failing never stops another,
>   and one recipient failing never affects another (as today). After each
>   send the channel's `last_status`, `last_attempt_at` and `last_error` are
>   written. `reminder_deliveries.channels` lists the kinds that succeeded,
>   with the same keys as today. The rules for claiming, retrying and not
>   repeating a partial success are unchanged.
> - **Personal channels:**
>   - **Email:** the user's address (Phase 33.1) through the server's SMTP.
>     Not available until an admin sets the server up.
>   - **ntfy:** *Topic URL* (as today, stored visible) and an optional
>     *Access token* (secret).
>   - **Gotify:** *Server URL* and *Application token* (secret), and a
>     *Priority* (0 to 10, default 5; overdue reminders go at least at 8, as
>     today).
>   - **Webhook:** *URL*; the JSON payload is unchanged, including the
>     `user` object, so one endpoint can serve several people.
> - **The environment variables** `NTFY_URL`, `NTFY_TOKEN`, `GOTIFY_URL`,
>   `GOTIFY_TOKEN`, `GOTIFY_PRIORITY` and `WEBHOOK_URL` keep working as
>   **admin-only fallbacks**, exactly as Phase 19 describes: an admin with no
>   channel of that kind of their own receives through the server's, and the
>   instance webhook still receives every recipient's notifications. They are
>   **deprecated**: the admin's card says "Using the server's ntfy topic
>   (set in the environment). Save your own to replace it.", Settings →
>   Delivery lists the variables that are set, and each start logs one
>   notice naming them. A member never uses them.
> - **Send test.** Each card has **Send test**, which sends "Test from
>   Logbook" through that channel only, with the typed values *unsaved*
>   (as 36.1's test does), and shows the result on the page. At most 5 tests
>   per user in 10 minutes. The existing *Send test notification* on
>   Settings → Reminders sends through every usable channel.
> - **Status words** on a card: *On*, *Off*, *Needs setup*, *Blocked by your
>   administrator's setting*, *Not available on this server* (email, until
>   the server is set up). Text and icon, never colour alone. Under it the
>   last result: "Last sent {time}" or "Last attempt failed: {error}".
> - **Privacy.** A user's channels, values and last errors are visible to
>   that user only. Admins can read neither the values nor the kinds
>   configured. Channel routes take the user from the session, never from
>   the request, so another user's channel cannot be addressed by id.
> - **Demo mode** (Phase 35.1) blocks saving, testing and sending.

### §7.11 Where members' channels may send (new)

> Admins choose on **Settings → Delivery → Where members can send**:
>
> | Setting | Members' channels may send to |
> |---|---|
> | *The internet only* | public addresses |
> | *The internet and your network* (default) | public addresses and *Your network* |
> | *The internet, your network and this server* | all three classes |
>
> The classes are §7.25's (*This server*, *Your network*, *Internet*), from
> the same `ConnectionLocator`, including the admin's list of *This
> server's addresses*. In addition, **link-local addresses
> (169.254.0.0/16, fe80::/10) are always refused for members**: they are
> where cloud metadata services and router interfaces live.
>
> - The host is resolved when a channel is saved, when it is tested and
>   **on every send**, and the request connects to the address that was
>   classed (pinned), so a name cannot change between the check and the
>   call. Redirects are never followed (`max_redirects: 0`).
> - An **admin's own** channels are not restricted.
> - The class shows as a badge (icon and words) on a channel card.
> - A member's saved address that the policy now refuses is **kept** and
>   shown as *Blocked by your administrator's setting*; it is not used and
>   not deleted.

### §8 Settings layout (changed)

> - **Account** gains **Notifications** (its own page): Email, then the
>   channel cards. The *Reminders and notifications* card no longer holds
>   channels. It keeps lead times, the digest switch, the calendar feed, the
>   *Needs attention* thresholds and the *Send test notification* button,
>   and shows one line, "Sent to: Email, ntfy" (or "Nowhere yet"), linking
>   to Account → Notifications.
> - **Administration → Delivery** gains the card *Where members can send*
>   and a list of any deprecated environment variables in use.
>
> The prototype's *Reminder delivery* layout is followed where it differs
> only in layout and style (see *Prototype notes*). Where it puts channel
> setup in Settings rather than in Account, the owner's decision (Account →
> Notifications) wins and the difference is recorded.

---

## Decisions (and why)

- **Channels under Account, not Reminders.** They are personal data about
  where *you* are reached. Reminders keeps what is about reminders.
- **The environment variables stay, as deprecated fallbacks.** Nothing an
  admin relies on stops on upgrade, and there is no migration that copies a
  server secret into the database. Removing them is a later, announced
  change (spec §12).
- **A policy, because a member can now enter an address.** Today a member
  can already give a personal ntfy topic URL; this phase puts a definite
  rule and an admin switch on that, instead of leaving it open. The default
  allows a household's own network, which is where self-hosted ntfy and
  Gotify live.
- **No `env:` for members.** It is safe for an admin (they own the
  environment); it is a way to read it for anyone else.
- **Admins cannot see members' channels.** Support does not need the
  values, and a household still has privacy within it.
- **A definition per kind.** It keeps the page, the validation and the
  tests the same for all eight channels and makes adding a ninth small.

---

## Tasks

### 36.2.0 Spec first
- [ ] `spec.md` §6, §7.11 (two parts), §8; `docs/phases/open-questions.md`
      gets this phase's questions; §13 entry; `ROADMAP.md` row and section.

### 36.2.1 Audits
- [ ] **How personal channels are stored today** (Phase 19): where the
      personal ntfy topic and Gotify token live, in what form (plain or
      encrypted), and the *enabled channels* preference. Record under
      *Audit*.
- [ ] **Prototype: *Settings → Reminder delivery*.** Read Phase 33.2's
      *Prototype notes* for the reminders card first and extend them rather
      than redoing them. Open the prototype's file in `design-import/`, list
      what it shows (sections, cards, controls, states, wording) and what the
      app shows today, and record the differences under *Prototype notes*.
      Sort each difference as Phase 33.2 does: *layout and style* (build it,
      from the app's tokens and macros), *behaviour the app already has*
      (build it from existing services), or *behaviour or data the app does
      not have* (do **not** build it; add it to *Open questions*).

### 36.2.2 Migration (every engine, reversible)
- [ ] `notification_channels` table as above.
- [ ] Move each user's personal ntfy topic and Gotify token into rows.
      Gotify's row takes the `GOTIFY_URL` in force as its *Server URL*, since
      that is the server the token belongs to. The *enabled* flag follows the
      user's saved choice, or *on* where they never saved one (Phase 19's
      "every configured channel"). Tokens are encrypted if a key exists;
      **a token is never copied in the clear** into the new table. Without a
      key that channel is created as *Needs setup* and the old value is left
      where it was, untouched, until the user enters it again. Log each
      outcome (counts only, no values).
- [ ] Rolling back restores the old preferences (decrypting where it can)
      and drops the table.

### 36.2.3 Code
- [ ] `ChannelDefinition`s and senders for email, ntfy, Gotify and webhook;
      the `NotificationChannel` interface takes the recipient's settings;
      the dispatcher uses usable channels per recipient and writes the last
      result.
- [ ] `OutboundDestination` policy (using `ConnectionLocator`), resolve and
      pin the address, never follow redirects. Used by ntfy, Gotify and
      webhook senders (and by 36.3's).
- [ ] Account → Notifications page and actions (save, enable, remove, test,
      with the throttle); the secret field pattern from 36.1; routes take
      the user from the session and declare their ability so the route
      inventory sees them.
- [ ] Settings → Delivery: *Where members can send* and the deprecated
      variables list. Settings → Reminders: remove channels, add the *Sent
      to* line.
- [ ] Fallback behaviour for admins and the start-up notice.
- [ ] Add user secrets to the redaction processor; exclude them from backups
      and exports; include rows without secrets.

### 36.2.4 Docs
- [ ] `docs/notification-channels.md`: rewritten around personal channels,
      the policy, the fallbacks and *adding a channel* (a definition and a
      sender). `.env.example` and `docs/configuration.md`: the five channel
      variables marked deprecated, with where to set them instead.
- [ ] `docs/deployment.md`, README: where notifications are configured.

### 36.2.5 Translations
- [ ] English and German strings for the page, statuses, hints, errors and
      the policy card.

### 36.2.6 Tests
- [ ] Unit: each definition's validation (ntfy URL and topic, Gotify URL,
      token and priority range, webhook URL; `http` and `https` only, no
      credentials, no fragment).
- [ ] Unit: the policy matrix. Each setting against loopback, `localhost`,
      `host.docker.internal`, RFC 1918, ULA, `100.64.0.0/10`, `.lan` names,
      link-local, a public address, a name that resolves to both, an
      IPv4-mapped IPv6 address; link-local always refused for a member and
      allowed for an admin.
- [ ] Unit: the request is pinned to the classed address (a resolver that
      changes its answer between calls is not followed); a redirect is
      reported and not followed.
- [ ] Unit: an `env:` reference is refused for a member's secret and
      accepted for an admin's SMTP password.
- [ ] Integration: the dispatcher sends through every usable channel for
      each recipient; a failing channel does not stop the others; a
      recipient with none gets nothing; `last_status` and `last_error` are
      written; `reminder_deliveries` and idempotency behave as before.
- [ ] Integration: **user A cannot read, edit, enable, test or remove user
      B's channel** by id or otherwise (an IDOR test per route); a member
      cannot reach Delivery; the route inventory classifies every new route.
- [ ] Integration: a secret never appears in any response, including after a
      validation error, nor in a job's output, the log or an error; the test
      button uses unsaved typed values; the throttle blocks the sixth test.
- [ ] Integration: an admin falls back to the environment's ntfy, Gotify and
      webhook until they save their own; a member never does; the webhook
      payload is unchanged; the start-up notice and the Delivery list name
      the variables in use.
- [ ] Integration: a member's saved address that the policy refuses shows
      *Blocked*, is not used and is not deleted; relaxing the policy brings
      it back.
- [ ] Integration: the migration moves personal values, keeps *enabled*,
      handles no key (nothing copied in the clear), and rolls back; on every
      engine.
- [ ] Integration: backups and exports contain channel rows without secrets;
      a restore shows *Needs setup*.
- [ ] Integration: demo mode blocks saving, testing and sending.
- [ ] Without JavaScript every action works (each is a form).

### 36.2.7 Checks
- [ ] `design-reviewer` agent on Notifications, Delivery and Reminders at
      375, 768 and 1280 px, light and dark, all four accents, keyboard only,
      and the secret fields with a screen reader.

### Sample data
- [ ] `DemoDataSeeder`: the demo owner has Email on and the others off. Demo
      mode sends nothing; nothing else is seeded.

### Release
- [ ] Ships with Phase 36.3 as **v3.3.0**.

---

## Acceptance criteria

- A user can set up, test, switch off and remove their own ntfy, Gotify,
  webhook and email delivery, and nobody else can see or change it.
- A household that configured notifications by environment variable still
  receives them after upgrading, and sees which variables are deprecated.
- A member cannot make the server call an address the admin has not
  allowed, and cannot use `env:` to read a variable.
- Settings → Reminders no longer holds channels and says where they went.
- The prototype's *Reminder delivery* design is reflected where the app has
  the data, and everything it shows that the app lacks is in *Open
  questions*.

## Audit

*(Filled in by 36.2.1: how personal channels are stored today.)*

## Prototype notes

*(Filled in by 36.2.1: the prototype's *Settings → Reminder delivery*,
sorted into layout and style, behaviour the app has, and behaviour it does
not.)*

## Open questions

- **The environment variables.** Keep as admin-only deprecated fallbacks
  (drafted), import them into the admin's own channels once on upgrade, or
  remove them now? Recommendation: fallbacks now, removal announced for a
  later major release.
- **The instance webhook.** `WEBHOOK_URL` still receives every recipient's
  notifications while it is set (drafted), or retire it now that webhooks
  are personal? The REST API (Phase 18.2) already serves integrations that
  want everything.
- **Policy default.** *The internet and your network* (drafted), or
  *The internet only*? The first keeps a household's LAN ntfy and Gotify
  working after upgrade; the second is stricter.
- **No key at migration.** The drafted behaviour (Gotify created as *Needs
  setup*, old value left in place) assumes a default install may have no
  `SESSION_SECRET`. If 36.1's decision makes the entrypoint generate one,
  this case disappears.
- **Where in Settings.** Account → Notifications (the owner's request,
  drafted). This replaces Phase 33.2's draft grouping, which put channels
  on the Reminders card; confirm.
- **Admins' view.** Admins see nothing about members' channels (drafted),
  or the kinds configured per user for support?
- **Failing channels.** Show the last error only (drafted), or switch a
  channel off after repeated failures?
- **What to receive.** Tracktor lets each provider subscribe to reminders,
  alerts and information separately. Not asked for here: leave out
  (drafted), or add per-channel choices (due, overdue, digest, price
  alerts) and quiet hours as new settings?
- **Anything the prototype shows** that the app has no data for: added by
  36.2.1.
