# Phase 36.3 — Telegram, Discord, Pushover and Mattermost + v3.3 release

*Four more places a reminder can reach you.*

Status: 📋 planned · releases **v3.3.0** with Phases 36.1 and 36.2 · file
lives in `docs/phases/`

Part three of Phase 36. [36.2](phase-36.2.md) made every channel personal
and definition-driven; this phase adds four channels as four definitions
and four senders, and cuts **v3.3.0**.

| Channel | What the user provides | Goes to |
|---|---|---|
| Telegram | bot token, chat ID | `api.telegram.org` |
| Discord | channel webhook URL | `discord.com` |
| Pushover | application token, user key | `api.pushover.net` |
| Mattermost | incoming-webhook URL | the user's own Mattermost server |

Read [`CLAUDE.md`](../../CLAUDE.md), [`spec.md`](../../spec.md) §7.11
(including *Where members' channels may send*), §7.25 (host classing and
secrets), §7.30 (redaction), [Phase 36.1](phase-36.1.md) and
[Phase 36.2](phase-36.2.md) first. **Look up each service's current API
documentation while building** (CLAUDE.md §2: do not guess an API). The
limits and request shapes below were checked against those documents
before this phase was written and must be checked again.

**Prerequisites:** [Phase 36.2](phase-36.2.md) built.

---

## Goals

1. Telegram, Discord, Pushover and Mattermost as personal channels, in the
   same Account → Notifications page, with status and **Send test**.
2. Each message fits its service's limits and cannot **ping people it should
   not** (a vehicle's name or a note is text another user typed).
3. Tokens that appear in a URL never reach a page, a log or an error.
4. Release **v3.3.0** (Phases 36.1 to 36.3).

## Not in scope

- A shared bot, application token or workspace provided by the admin
  (see the open questions).
- Slack, Matrix, Signal, Apprise and other services. The definition-driven
  design makes each a small addition later.
- Rich formatting beyond what each service needs: no Discord embeds, no
  Telegram keyboards, no Pushover attachments.
- Receiving anything back (commands to a bot, button presses).

---

## Spec additions

### §7.11 Notifications: message and limits (changed)

> - **One message, formatted per service.** The dispatcher builds a
>   `NotificationMessage` (kind: `reminder` | `digest` | `price_alert` |
>   `test`; a title; items; a link; urgency: `low` | `normal` | `high`),
>   and each sender turns it into its service's request. Existing channels
>   keep their formats.
> - **Limits are counted in Unicode code points**, which is how all four
>   services count. A message is **cut at an item boundary** with a final
>   line "…and N more" and the link, never mid-item and never over the
>   limit. A single item longer than the limit is cut with an ellipsis.
> - **Urgency:** *overdue* is `high`, *due* is `normal`, the monthly digest
>   is `low`, a price alert is `normal`.
> - **Third-party notice.** On the Telegram, Discord and Pushover cards:
>   "This sends your reminders through {service}'s servers." Mattermost is
>   the user's own server and has none.
> - **Redaction.** A token or webhook secret that appears in a request URL
>   or a response is removed from every error before it reaches a page, a
>   job's output or the log.
> - **HTTP.** Senders use the one outbound HTTP client of 36.2: 10-second
>   timeout, no redirects, address pinned, the destination policy applied.
>   Telegram, Discord and Pushover have fixed hosts, so their requests go
>   only to those hosts (the policy check still runs).

### §7.11 Channels (changed): the four new kinds

> **Telegram** (`telegram`)
> - Fields: *Bot token* (secret), *Chat ID*.
> - Token: digits, a colon, then at least 30 letters, digits, `_` or `-`.
>   Chat ID: a whole number (negative for a group) or `@channelusername`.
> - Request: `POST https://api.telegram.org/bot{token}/sendMessage` with
>   `chat_id` and `text` (1 to **4096** characters). **Plain text**: no
>   `parse_mode`, so a vehicle name cannot inject formatting. Link previews
>   are switched off. The monthly digest is sent without a sound
>   (`disable_notification`).
> - **Saving** checks the token with `getMe` ("Bot @name") and shows the
>   bot's name.
> - **Find my chat** (a button on the card): a person must start a
>   conversation with their bot first (Telegram does not let a bot message
>   someone who has not). The button asks `getUpdates` for the latest
>   messages and lists the **private** chats found, each with its ID and
>   first name, for the user to pick. Only the picked ID is stored; nothing
>   else from the response is kept. If the bot has a webhook set, Telegram
>   refuses `getUpdates`; the card says so in words.
> - Errors in words: *Telegram rejected the bot token*; *The bot can't
>   reach that chat. Open it in Telegram and press Start*; *Chat not found*;
>   *Telegram asked us to wait {n} seconds*.
>
> **Discord** (`discord`)
> - Field: *Webhook URL* (secret: the token is in it).
> - The URL must be `https://` with the host exactly `discord.com`,
>   `discordapp.com`, `ptb.discord.com` or `canary.discord.com`, the path
>   `/api/webhooks/{numeric id}/{token}`, and no query. A host such as
>   `discord.com.example.org` is refused.
> - Request: `POST` the webhook URL with JSON `content` (up to **2000**
>   characters) and `username` "Logbook", and `allowed_mentions` of
>   `{"parse": []}` so `@everyone`, `@here` and role or user mentions in the
>   text ping nobody.
> - Errors in words: *That webhook no longer exists* (404); *Discord asked
>   us to wait {n} seconds* (429).
>
> **Pushover** (`pushover`)
> - Fields: *Application token* (secret), *User key* (secret), *Device*
>   (optional).
> - Request: `POST https://api.pushover.net/1/messages.json` with `token`,
>   `user`, `message` (up to **1024** characters), `title` (up to **250**),
>   and `url` and `url_title` for the link (their limits taken from the
>   current documentation). `priority`: `-1` for the digest, `0` normally,
>   `1` for an overdue reminder. **Emergency priority (2) is never used**:
>   it needs a retry and expiry and repeats until acknowledged.
> - **Saving** validates the pair with `/1/users/validate.json`.
> - Errors in words: *Pushover rejected the token or the user key*; *This
>   application has used its monthly messages*.
>
> **Mattermost** (`mattermost`)
> - Fields: *Webhook URL* (secret), *Channel* (optional: `town-square`, or
>   `@name` for a direct message, as the webhook allows).
> - The URL is `http` or `https`, with a path that ends in `/hooks/{id}`
>   (so a Mattermost served under a subpath works); any host, classed and
>   subject to the destination policy as a member's own server will usually
>   be on their network.
> - Request: `POST` JSON `text` (Markdown, up to **16383** characters) and
>   `channel` when set. The user's strings (vehicle names, titles, notes)
>   are escaped for Markdown, and mentions are neutralised: `@name`,
>   `@channel`, `@here`, `@all` and the `<!channel>` form are written so
>   they do not trigger one (a zero-width space after the `@` or `<!`). The
>   title is bold and the items a list.
> - Errors in words: *Mattermost refused the message. Check that incoming
>   webhooks are enabled and that this webhook isn't locked to another
>   channel* (400 and 403); *That webhook was not found* (404).

### §7.11 Send test (changed)

> The test message for every channel is "Test from Logbook" with the link,
> through the same sender and the same limits as a real one. For Telegram
> it also confirms the bot's name; for Pushover, the key pair.

---

## Decisions (and why)

- **Each person brings their own bot, application or webhook.** It matches
  36.2 (every channel personal), needs nothing from the admin and keeps
  each person's credentials out of everyone else's reach. A shared bot or
  Pushover application would be easier for members and is a decision for the
  owner (open question).
- **Plain text for Telegram, escaped Markdown for Mattermost, no mentions
  anywhere.** Other users' text goes into these messages. Formatting and
  pings are not theirs to trigger on someone else's account.
- **Discord's host list is exact.** The field is a webhook URL a member
  types and the server will call; "looks like Discord" is not enough.
- **Never emergency priority on Pushover.** A reminder that keeps ringing
  until acknowledged is not a reminder, it is an alarm.
- **Truncate at items, not characters.** A digest cut mid-line is unreadable;
  "and 4 more" with a link is not.
- **The third-party notice.** Logbook's promise is that data stays local;
  these three channels deliberately send some of it elsewhere, and the card
  says so where the choice is made.

---

## Tasks

### 36.3.0 Spec first
- [ ] `spec.md` §7.11 as above; §13 entry; `ROADMAP.md` row and section.
- [ ] **Check each service's current documentation** and correct this phase
      and the spec where it differs: Telegram `sendMessage`, `getMe`,
      `getUpdates`; Discord *Execute Webhook*; Pushover *Messages* and
      *User validation*; Mattermost *Incoming webhooks*. Record the date and
      the pages used under *Audit*.

### 36.3.1 Code
- [ ] `NotificationMessage` and the fitting helper (code-point counting,
      item boundary, "…and N more"), with the urgency mapping.
- [ ] Four `ChannelDefinition`s and four senders implementing
      `NotificationChannel`. No new dependency: the existing HTTP client.
- [ ] The Telegram *Find my chat* action (a form post; a result page lists
      the chats to pick from).
- [ ] The Mattermost escaping and mention neutralisation; the Discord
      `allowed_mentions`; the Pushover priority mapping.
- [ ] Redaction of tokens in URLs and responses for all four.
- [ ] The cards appear on Account → Notifications from their definitions
      (36.2); the third-party notice on three of them.

### 36.3.2 Docs
- [ ] `docs/notification-channels.md`: a section per service: how to get
      the token or URL (check the current steps), what the fields mean, the
      limits, what Logbook sends, who the service sees it, and
      troubleshooting from the error words above. Update *Adding a channel*.
- [ ] README feature paragraph and docs table.

### 36.3.3 Translations
- [ ] English and German for hints, notices, statuses and the error words.
      Service names are not translated.

### 36.3.4 Tests
- [ ] Unit, per service with a fake HTTP client: the exact request (URL,
      method, headers, body) for a reminder, a digest, a price alert and a
      test; validation of good and bad tokens, IDs and URLs (including
      `https://discord.com.example.org/api/webhooks/1/x`, a Discord URL with a
      query, a Mattermost URL not ending in `/hooks/{id}`).
- [ ] Unit: fitting. A digest of 200 items fits each limit exactly or under;
      multi-byte text and emoji are counted in code points, not bytes; a
      single oversized item is cut; "and N more" is right.
- [ ] Unit: mentions. `@everyone`, `@here`, `@channel`, `@user`, `<!channel>`
      and Markdown characters in a vehicle name, a title and a note are
      neutralised for Mattermost; Discord sends `allowed_mentions` with an
      empty `parse`; Telegram sends no `parse_mode`.
- [ ] Unit: Pushover priority is `-1`, `0` or `1` and never `2`.
- [ ] Unit: the error words for each status a service can return (401, 403,
      404, 400, 409, 429, a timeout, a redirect), and that none contains a
      token.
- [ ] Integration: the destination policy applies to Mattermost (a LAN host
      allowed under the default and refused under *The internet only*) and
      is still enforced for the fixed-host services.
- [ ] Integration: *Find my chat* parses a `getUpdates` response, lists only
      private chats, stores only the chosen ID, and handles the webhook-set
      refusal.
- [ ] Integration: all eight kinds usable for one recipient in one run send
      through all eight; one failing leaves the other seven and the
      recipient's other channels untouched; idempotency and
      `reminder_deliveries` are as before.
- [ ] Integration: secrets (including tokens inside URLs) never appear in a
      response, a job's output, the log or an error; the cards show *Saved /
      Replace / Remove*.
- [ ] Integration: user A cannot touch user B's new channels; demo mode
      blocks them; the route inventory classifies the new routes.
- [ ] The English and German catalogues stay in step (existing key tests).
- [ ] On every engine; the suite passes on SQLite, PostgreSQL, MySQL and
      MariaDB.

### 36.3.5 Checks
- [ ] Send a real test through each service once, from a throwaway
      account, and record that it was done and when under *Audit*. (Not in
      CI: it needs credentials.)
- [ ] `design-reviewer` agent on the page with all eight cards at 375, 768
      and 1280 px, light and dark, all four accents.

### Sample data
- [ ] None. Demo mode sends nothing.

### Release (with Phases 36.1 and 36.2)
- [ ] `CHANGELOG.md` **3.3.0**:
      - *Added* — Settings → Delivery with the email server (36.1);
        Account → Notifications with personal Email, ntfy, Gotify and
        Webhook channels, a status and a test on each, and an admin setting
        for where members' channels may send (36.2); Telegram, Discord,
        Pushover and Mattermost (36.3).
      - *Changed* — channels moved from Settings → Reminders to Account →
        Notifications. The webhook payload is unchanged.
      - *Deprecated* — `NTFY_URL`, `NTFY_TOKEN`, `GOTIFY_URL`,
        `GOTIFY_TOKEN`, `GOTIFY_PRIORITY`, `WEBHOOK_URL` and the `MAIL_*`
        variables still work as fallbacks and defaults.
      - *Upgrade notes* — two migrations (`notification_secrets`,
        `notification_channels`); personal ntfy and Gotify settings move
        automatically; **notification secrets are encrypted with
        `SESSION_SECRET` and are not in backups, so a restored install asks
        for them again**; members' channels that point somewhere the new
        setting refuses are kept and shown as blocked.
- [ ] Bump `VERSION`, rebuild assets, update the README status and
      `ROADMAP.md`.
- [ ] Tag `v3.3.0` once merged.

---

## Acceptance criteria

- A user can receive their reminders on Telegram, Discord, Pushover or
  Mattermost, set up and tested from their own Account page, with no
  administrator involved.
- No message exceeds its service's limit, and none can ping anyone.
- No token appears anywhere it should not, including inside a URL in an
  error.
- Eight channels can be usable for one person at once and one failing does
  not affect the others.
- `composer check` passes on every engine; the image builds; a subpath
  install works.

## Audit

*(Filled in by 36.3.0 and 36.3.5: the documentation pages checked and when,
corrections made, and the date of the real test through each service.)*

## Open questions

- **A shared bot or application.** Each user brings their own (drafted), or
  may the admin provide a Telegram bot or Pushover application token once,
  leaving members to enter only a chat ID or user key? Recommendation: keep
  it personal now; a shared option can be added later without changing
  what is stored.
- **Find my chat.** Build it (drafted: it removes the hardest step) or
  document how to find a chat ID by hand?
- **Quiet digests.** Telegram without a sound and Pushover at low priority
  for the monthly digest (drafted), or the same as a reminder?
- **Mattermost's channel override.** Offer the optional *Channel* field
  (drafted), or use the channel the webhook was made for?
- **Wording of the third-party notice** on Telegram, Discord and Pushover.
- **More services.** Slack, Matrix, Signal, or a bridge such as Apprise:
  not now (drafted), or note one in spec §12?
