# Phase 36.3 — Telegram, Discord, Pushover, Mattermost and Slack + v3.3 release

*Five more places a reminder can reach you.*

Status: 🚧 in progress · releases **v3.3.0** with Phases 36.1 and 36.2 · file
lives in `docs/phases/`

Part three of Phase 36. [36.2](phase-36.2.md) made every channel personal
and definition-driven; this phase adds five channels as five definitions
and five senders, and cuts **v3.3.0**. Slack was added by the owner on
2026-10-07 (#241).

| Channel | What the user provides | Goes to |
|---|---|---|
| Telegram | bot token, chat ID | `api.telegram.org` |
| Discord | channel webhook URL | `discord.com` |
| Pushover | application token, user key | `api.pushover.net` |
| Mattermost | incoming-webhook URL | the user's own Mattermost server |
| Slack | bot token, channel | `slack.com` |

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

1. Telegram, Discord, Pushover, Mattermost and Slack as personal channels, in the
   same Account → Notifications page, with status and **Send test**.
2. Each message fits its service's limits and cannot **ping people it should
   not** (a vehicle's name or a note is text another user typed).
3. Tokens that appear in a URL never reach a page, a log or an error.
4. Release **v3.3.0** (Phases 36.1 to 36.3).
5. Saved settings are re-checked against their kind's rules on every send
   (#258, for every personal kind).

## Not in scope

- A shared bot, application token or workspace provided by the admin
  (#236, spec §12).
- Matrix, Signal, Apprise and other services (#241, spec §12). The definition-driven
  design makes each a small addition later.
- Rich formatting beyond what each service needs: no Discord embeds, no
  Telegram keyboards, no Slack blocks, no Pushover attachments.
- Receiving anything back (commands to a bot, button presses).

---

## Spec additions

Written into [`spec.md`](../../spec.md) §7.11 *Telegram, Discord,
Pushover, Mattermost and Slack* on 2026-10-07 (with the §6 kinds, §12 and
§13), after the owner's answers and the documentation check below. The
draft this phase first carried differs from it in these ways:

- **Slack** added (#241): bot token and channel, `chat.postMessage`,
  checked with `auth.test`, plain text with `&`, `<`, `>` escaped, fitted
  to 4000 characters.
- **Urgency** for the two kinds the draft missed (#260): a failed job is
  `high`, *your channel switched off* `normal`.
- **Saving when the check can't be made** (#261): saved, with a notice.
- **Re-checked on every send** (#258): a row that fails its kind's rules
  is refused before any request and never counts.
- **No new `NotificationMessage` class.** The dispatcher's existing
  `Notification` already carries the kind, title, lines and link; the
  urgency and the fitting are helpers beside it, so the existing channels
  stay byte-for-byte the same.
- Cut at a **line** boundary (each reminder and each check is one line).
- Corrections from the documentation (see *Audit*): Telegram's
  `link_preview_options` (not the deprecated `disable_web_page_preview`);
  Discord's `flags: 4` to suppress the link preview; Pushover's `url`
  512 and `url_title` 100, its 30-character token and key, its device
  format and the 429 for the monthly limit.

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
- **Slack through a bot token** (#241), not an incoming webhook: the
  owner's choice. It can post to any channel the app is invited to, and
  `auth.test` checks it on saving.
- **Checked on saving, saved anyway when unreachable** (#261). A rejected
  token is a typo to fix now; an outage should not stop someone setting
  up.
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
- [x] `spec.md` §7.11, §6 kinds, §12, §13; `ROADMAP.md` row and section.
- [x] **Check each service's current documentation** and correct this
      phase and the spec where it differs: Telegram `sendMessage`, `getMe`,
      `getUpdates`; Discord *Execute Webhook*; Pushover *Messages* and
      *User validation*; Mattermost *Incoming webhooks*; Slack
      `chat.postMessage`, `auth.test` and *Formatting text*. Recorded
      under *Audit*.

### 36.3.1 Code
- [ ] The urgency mapping and the fitting helper (code-point counting,
      line boundary, "…and N more").
- [ ] A request on the outbound client that returns the status and the
      decoded body (for `getMe`, `getUpdates`, `users/validate`,
      `auth.test` and Slack's `ok: false`), with the same policy check,
      pinning and no redirects.
- [ ] Five `PersonalSender`s with their definitions. No new dependency.
- [ ] The check on saving (Telegram, Pushover, Slack): refused when the
      service rejects the token, saved with a notice when it can't be
      reached.
- [ ] The Telegram *Find my chat* action (a form post; a result page lists
      the chats to pick from; picking saves the chat ID).
- [ ] The Mattermost escaping and mention neutralisation; Slack's escaping;
      the Discord `allowed_mentions`; the Pushover priority mapping.
- [ ] Redaction of tokens in URLs and responses for all five; the badge
      shows the host only.
- [ ] Saved settings re-checked against their kind's rules before every
      send, for every personal kind (#258).
- [ ] The cards appear on Account → Notifications from their definitions
      (36.2); the third-party notice on four of them.

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
      query, a Mattermost URL not ending in `/hooks/{id}`, a Slack token
      that isn't `xoxb-`).
- [ ] Unit: fitting. A digest of 200 items fits each limit exactly or under;
      multi-byte text and emoji are counted in code points, not bytes; a
      single oversized line is cut; "and N more" is right.
- [ ] Unit: mentions. `@everyone`, `@here`, `@channel`, `@user`, `<!channel>`
      and Markdown characters in a vehicle name, a title and a note are
      neutralised for Mattermost; `<!channel>` and `<@U1>` can't be formed
      for Slack; Discord sends `allowed_mentions` with an empty `parse`;
      Telegram sends no `parse_mode`.
- [ ] Unit: Pushover priority is `-1`, `0` or `1` and never `2`; the urgency
      of every kind.
- [ ] Unit: the error words for each status a service can return (401, 403,
      404, 400, 409, 429, a timeout, a redirect, Slack's codes), and that
      none contains a token.
- [ ] Unit: the check on saving: rejected → not saved; unreachable → saved
      with the notice.
- [ ] Integration: the destination policy applies to Mattermost (a LAN host
      allowed under the default and refused under *The internet only*) and
      is still enforced for the fixed-host services.
- [ ] Integration: *Find my chat* parses a `getUpdates` response, lists only
      private chats, stores only the chosen ID, and handles the webhook-set
      refusal.
- [ ] Integration: all nine kinds usable for one recipient in one run send
      through all nine; one failing leaves the others and the recipient's
      other channels untouched; idempotency and `reminder_deliveries` are
      as before.
- [ ] Integration: a restored row that breaks its kind's rules is refused
      before any request and doesn't count (#258).
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
      CI: it needs credentials; the owner's step.)
- [ ] `design-reviewer` agent on the page with all cards at 375, 768
      and 1280 px, light and dark, all four accents.

### Sample data
- [ ] None. Demo mode sends nothing.

### Release (with Phases 36.1 and 36.2)
- [ ] `CHANGELOG.md` **3.3.0**:
      - *Added* — Settings → Delivery with the email server (36.1);
        Account → Notifications with personal Email, ntfy, Gotify and
        Webhook channels, a status and a test on each, and an admin setting
        for where members' channels may send (36.2); Telegram, Discord,
        Pushover, Mattermost and Slack (36.3).
      - *Changed* — channels moved from Settings → Reminders to Account →
        Notifications. A channel that fails 5 times in a row switches
        off (email never does). Saved channel settings are re-checked on
        every send. The webhook payload is unchanged.
      - *Deprecated* — `WEBHOOK_URL` (the server's webhook) still
        receives every recipient's notifications.
      - *Removed* — the `MAIL_*` variables (36.1, #223): **email is off
        after upgrading until an admin sets the server up in Settings →
        Delivery**; nothing is imported. `NTFY_URL`, `NTFY_TOKEN`,
        `GOTIFY_URL`, `GOTIFY_TOKEN` and `GOTIFY_PRIORITY` (36.2, #247):
        **imported once** into admins' own channels by the upgrade, then
        no longer read.
      - *Upgrade notes* — two migrations (`notification_secrets`,
        `notification_channels`); personal ntfy and Gotify settings and the
        server's ntfy and Gotify variables move automatically (keep
        `SESSION_SECRET` set while upgrading, or tokens wait to be
        re-entered); **notification secrets are encrypted with
        `SESSION_SECRET` and are not in backups, so a restored install asks
        for them again**; members' channels that point somewhere the new
        setting refuses are kept and shown as blocked.
- [ ] Bump `VERSION`, rebuild assets, update the README status and
      `ROADMAP.md`; Phases 36.1 and 36.2 marked complete.
- [ ] Tag `v3.3.0` once merged.

---

## Acceptance criteria

- A user can receive their reminders on Telegram, Discord, Pushover,
  Mattermost or Slack, set up and tested from their own Account page, with no
  administrator involved.
- No message exceeds its service's limit, and none can ping anyone.
- No token appears anywhere it should not, including inside a URL in an
  error.
- Nine channels can be usable for one person at once and one failing does
  not affect the others.
- `composer check` passes on every engine; the image builds; a subpath
  install works.

## Audit

**Documentation checked on 2026-10-07**, before any code:

- Telegram Bot API (`core.telegram.org/bots/api`): `sendMessage` text
  1–4096 characters after entity parsing; `link_preview_options` replaces
  `disable_web_page_preview`; `disable_notification`; JSON bodies
  accepted; errors `{ok: false, error_code, description, parameters:
  {retry_after}}`; `getUpdates` doesn't work while a webhook is set (409);
  `chat.type` `private` with `first_name`; tokens `123456:ABC-…`.
- Discord (`docs.discord.com/developers/resources/webhook`, moved from
  `discord.com/developers/docs`): `POST /webhooks/{id}/{token}`, `content`
  up to 2000, `username` may not contain "discord" or "clyde",
  `allowed_mentions`, `flags` limited to `SUPPRESS_EMBEDS` (4),
  `SUPPRESS_NOTIFICATIONS` and components; 204 without `wait`; 429 with
  `retry_after`.
- Pushover (`pushover.net/api`): message 1024, title 250, `url` 512,
  `url_title` 100; token and user key 30 `[A-Za-z0-9]`; device up to 25
  `[A-Za-z0-9_-]`, comma-separated for several; priorities −2 to 2, 2
  needs `retry` and `expire`; errors 4xx `{status: 0, errors: […]}`; 429
  over the monthly quota; `/1/users/validate.json` with `token`, `user`,
  `device`.
- Mattermost (`docs.mattermost.com/developers/integrate/webhooks/incoming`,
  moved from `developers.mattermost.com`): `/hooks/{id}`, JSON `text`
  (Markdown) and `channel` (a name or `@user`); posts up to 16383
  characters (longer is split into several posts); success 200 `ok`; the
  error statuses are not documented, so the words are kept as drafted.
- Slack (`docs.slack.dev`): `chat.postMessage` with a Bearer token,
  `channel` and `text`, 4000 characters recommended (40000 truncated),
  200 with `ok: false` and a code on error, 429 with `Retry-After`;
  `auth.test` needs no scope and returns `team`; *Formatting text*: escape
  `&`, `<`, `>`; `@channel` doesn't ping without `link_names`.

**Real test through each service:** *(36.3.5, the owner's step.)*

## Open questions

All decided on 2026-10-07, before any code (log #236–#241, #260–#261);
the 36.2 reviews' #255–#258 the same day.

- **A shared bot or application.** *Decided (#236):* personal only; a
  shared option is parked in spec §12.
- **Find my chat.** *Decided (#237):* built.
- **Quiet digests.** *Decided (#238):* yes: Telegram without a sound,
  Pushover at `-1`.
- **Mattermost's channel override.** *Decided (#239):* offered.
- **Wording of the third-party notice.** *Decided (#240):* "This sends
  your reminders through {service}'s servers."
- **More services.** *Decided (#241):* Slack is added to this phase, with
  a bot token and a channel; Matrix, Signal and Apprise are parked in
  spec §12.
- **Urgency of a failed job and a switched-off notice** (found while
  starting). *Decided (#260):* `high` and `normal`.
- **Saving when the check can't be made** (found while starting).
  *Decided (#261):* saved, with "couldn't be reached to check it".
