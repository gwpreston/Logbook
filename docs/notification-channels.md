# Notification channels

Reminders always appear in the app. When they come due (and again if they
become overdue) the scheduled task also sends them through each recipient's
**notification channels** (spec.md §7.11). A vehicle's recipients are its
owner and anyone it is shared with who ticked *Send me its reminders*
([users-and-sharing.md](users-and-sharing.md)).

From v3.3.0 every channel but one is **personal**: each person sets up
their own in **Settings → Account → Notifications**, and nobody else, not
even an admin, can see or change them.

| Key | Channel | Set up by |
|---|---|---|
| `email` | Email to your confirmed address | the server's email in **Settings → Delivery** (admins; see [Email](#email)); each person switches it on or off |
| `ntfy` | [ntfy](https://ntfy.sh) push | each person: *Topic URL* and an optional *Access token* |
| `gotify` | [Gotify](https://gotify.net) push | each person: *Server URL*, *Application token*, *Priority* (0–10, default 5) |
| `personal-webhook` | JSON `POST` to your own URL | each person: *URL* |
| `telegram` | [Telegram](#telegram) from your own bot | each person: *Bot token*, *Chat ID* |
| `discord` | [Discord](#discord) channel webhook | each person: *Webhook URL* |
| `pushover` | [Pushover](#pushover) push | each person: *Application token*, *User key*, optional *Device* |
| `mattermost` | [Mattermost](#mattermost) incoming webhook | each person: *Webhook URL*, optional *Channel* |
| `slack` | [Slack](#slack) from your own Slack app | each person: *Bot token*, *Channel* |
| `webhook` | JSON `POST` to the server's URL | **deprecated**: `WEBHOOK_URL`; receives everyone's notifications |

## Account → Notifications

The page has a row per channel, as the design's *Reminder delivery* card
does: an icon, the name, a hint, and the status in words with an icon.

- **Status:** *On*, *Off*, *Not set up*, *Needs setup* (a required field or
  token is missing, for instance after a restore), *Blocked by your
  administrator's setting* (see [below](#where-members-can-send)),
  *Switched off after failures*, *Not available on this server* (email,
  until an admin sets it up). Under it: "Last sent {time}" or "Last attempt
  failed {time}: {error}".
- **Save** stores the fields; **Send test** sends "Logbook test
  notification" through that channel only, with what is typed, **without
  saving it**, and shows the result. At most 5 tests per person in 10
  minutes. Tests don't change the last result.
- **Tokens** are never shown again, not even masked: *Saved*, with a field
  to replace it (empty keeps it) and *Remove the saved token*. They are
  encrypted with a key from `SESSION_SECRET`; without one they can't be
  saved. A saved token is only ever sent to the host it was saved for:
  change the URL's host and it must be typed again. `env:NAME` is **not**
  accepted here (it would let a member read the server's environment).
- **Checked on saving** (Telegram, Pushover, Slack, from v3.3.0): saving asks
  the service whether the token works. A token it **rejects** is not saved
  and the field says why; if the service **can't be reached**, the card is
  saved and says "Saved, but {service} couldn't be reached to check it."
- **Re-checked on every send** (from v3.3.0): saved settings must still pass
  the card's own rules (a restore can bring back old rows). One that
  doesn't is not sent, says "The saved settings are no longer valid. Open
  the card and save them again.", and never counts towards switching off.
- **Receives** (from v3.3.0): each card, email's included, has a box per
  kind of message: *Due*, *Overdue*, *Monthly digest*, *Price alerts* and,
  for admins, *Job failures*. All are ticked until you change them, so
  nothing changes on upgrade. At least one must stay ticked; to stop a
  channel altogether, switch it off. The server's webhook (`WEBHOOK_URL`)
  has no card and receives everything.
- **Quiet hours** (from v3.3.0): one period for all your channels, in your
  time zone, off by default. It can run past midnight (22:00 to 07:00).
  Nothing is sent inside it; nothing is queued either. The first scheduled
  run after it ends sends what **still applies then**: reminders still due
  or overdue (one done meanwhile isn't sent; one that went overdue
  meanwhile is sent once, as overdue), the month's digest, price alerts
  whose price is still below, and failed jobs still failing. Each kind goes
  as one message.
- **Tests** (*Send test* on a card, and Settings → Reminders' test) ignore
  both: they are sent whatever the channel receives, even in quiet hours,
  and say so.
- **Switch on / off** and **Remove** (with a confirmation).
- **Switched off after failures.** A personal channel that fails **5 sends
  in a row** switches itself off; you are told once through your other
  channels, and the card says so until you switch it on again (or save it).
  A success resets the count; tests don't count. Email is never switched
  off: its failures are usually the server's.
- *Settings → Reminders* keeps lead times, the digest, the calendar feed
  and *Send test notification* (through every channel that is on), and
  says where reminders go: "Sent to: Email, ntfy."

## Where members can send

A member's ntfy, Gotify or webhook address is one the **server** calls, so
an admin chooses in **Settings → Delivery → Where members can send**:

| Setting | Members' channels may send to |
|---|---|
| *The internet only* | public addresses |
| *The internet and your network* (default) | also your home or office network (RFC 1918, IPv6 ULA, `100.64.0.0/10`, `.lan` and similar names) |
| *The internet, your network and this server* | also this server (loopback, `localhost`, Docker's host, and the addresses listed under *This server's addresses* in AI connections) |

- **Every** address a name resolves to must be allowed, and the request
  connects to an address that was checked (so a name can't change in
  between). It is checked when a channel is saved, tested and on every send.
- Always refused for members, whatever the setting: link-local
  (`169.254.0.0/16`, `fe80::/10`, where cloud metadata services live),
  unspecified (`0.0.0.0/8`, `::`), multicast, reserved and broadcast
  addresses, and IPv6 forms carrying an IPv4 address (NAT64, 6to4).
- Redirects are never followed, for any channel.
- An admin's own channels are not restricted.
- **Docker:** the bridge gateway (`172.17.0.1`), the app's own container
  address and other containers on the compose network are private
  addresses, so they count as *Your network*, which the default allows.
  To keep members off them, list the bridge subnet (e.g. `172.16.0.0/12`)
  and the container's address under *This server's addresses* (Settings →
  AI connections), or choose *The internet only*.
- A saved address the setting now refuses is **kept**, shown as *Blocked*,
  and not used; relaxing the setting brings it back.

## Upgrading to v3.3.0

- Each person's ntfy topic and Gotify token (from Settings → Reminders)
  move to their Notifications page, switched on or off as they were.
- **`NTFY_URL`, `NTFY_TOKEN`, `GOTIFY_URL`, `GOTIFY_TOKEN` and
  `GOTIFY_PRIORITY` are imported once, then no longer read.** Every admin
  without their own ntfy topic gets the server's (with its token); every
  admin without their own Gotify token gets the server's Gotify; a topic on
  `NTFY_URL`'s server gets `NTFY_TOKEN`, as it was sent with it. Keep them
  set, and `SESSION_SECRET` unchanged, until the new version has started
  once; then remove them. Settings → Delivery says while any is still set.
- Without a `SESSION_SECRET` the tokens can't be encrypted: those channels
  are created as *Needs setup* and nothing new is copied in the clear; a
  personal Gotify token stays where it already was (in plain text, as
  before) until its owner enters it again on the Notifications page, which
  removes it. Rolling the upgrade back also puts tokens back there in plain
  text, as the older version kept them.
- **`WEBHOOK_URL` keeps working** as the server's webhook (key `webhook`):
  it receives every recipient's notifications, naming them, as before. It
  is deprecated, and no longer follows redirects (it used to follow up to
  three).
- Backups hold the channels but never their tokens: after a restore, each
  person enters theirs again.

## Email

The email server is set up in the app, by an admin, in **Settings →
Delivery → Email server**, and nowhere else (spec.md §7.11). Every email
Logbook sends uses it: reminders, the monthly digest, invitations, password
resets and address confirmations. A change applies at once, without a
restart.

- **Fields:** *Server* (a host name or IP address, no `smtp://`), *Port*
  (empty: 587 with STARTTLS, 465 with TLS, 25 without), *Encryption*
  (STARTTLS required, TLS from the start, or none), *Username*, *Password*,
  *From address*, *From name* (default "Logbook") and *Default recipient
  for admins* (where reminders go for an admin with no confirmed address;
  never used for reset links). With no encryption and a username, the page
  warns that the password would be sent unencrypted.
- **The password** is never shown again, not even masked: the page says
  *A password is saved*, a new one replaces it, an empty field keeps it,
  and *Remove the saved password* removes it. It is encrypted with a key
  from `SESSION_SECRET`. To keep it outside the app (a Docker secret, say),
  type `env:NAME` instead and Logbook reads that variable when sending.
  Without a `SESSION_SECRET` only `env:NAME` can be saved. If the key
  changes, or a backup is restored (backups never hold it), the page says
  *Re-enter the password* and nothing is sent until you do.
- **Send test email** sends one message to your own confirmed address (or
  one you type) with the values in the form, **without saving them**, so a
  typo never replaces a working setup. A failure names the stage
  (connection, encryption, sign-in or send) with the server's reply, with
  any password taken out. It waits at most 10 seconds to connect and for
  each reply, and never retries.
- **Remove email server** turns email off.

**Upgrading from 3.2 or earlier:** the `MAIL_HOST`, `MAIL_PORT`,
`MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION`, `MAIL_FROM` and
`MAIL_TO` variables are no longer read, and nothing is copied from them.
Email is off until an admin fills in Settings → Delivery; while any of them
is still set, the page says so. Then remove them from your `.env` or compose
file.

**Development:** `docker-compose.dev.yml` runs Mailpit, and
`bin/dev-setup.sh` points Settings → Delivery at it (server `mailpit`, port
`1025`, encryption *None*, From `logbook@localhost`) when no email server
is saved; its inbox is at `http://localhost:8025`.

## The services

Five services added in v3.3.0 (Phase 36.3). Each person brings their own
bot, application or webhook; the admin provides nothing. Every request
goes through the same outbound client as ntfy and Gotify: a 10-second
timeout, no redirects, the address checked and pinned, and the
[destination policy](#where-members-can-send) applied. Telegram, Discord,
Pushover and Slack only ever go to their own hosts.

What all five have in common:

- **Limits are counted in characters (Unicode code points),** as the
  services count them. A message that's too long is cut **between two
  reminders**, with a last line "…and 4 more" and the link, never in the
  middle of one.
- **Nothing can ping anyone or inject formatting.** Vehicle names, titles
  and notes are text other people may have typed: Telegram and Slack get
  plain text, Discord is told to allow no mentions, and Mattermost's
  Markdown and mentions are escaped.
- **Tokens never appear in an error.** Several of these services put the
  token in the URL; errors are reduced to words ("Telegram rejected the bot
  token", "The service did not answer in time"), shown in your language.
- **How loudly.** Overdue reminders and failed jobs are *high*, due
  reminders, price alerts and tests *normal*, the monthly digest *low*:
  Telegram sends the digest without a sound, Pushover uses priority −1 /
  0 / 1 (never the emergency priority 2, which repeats until acknowledged).
- **A third-party notice** on the Telegram, Discord, Pushover and Slack
  cards: "This sends your reminders through {service}'s servers." Logbook
  keeps your data on your server; these four deliberately send some of it
  (titles, vehicle names, due dates and the link) to someone else's.
  Mattermost is your own server.

### Telegram

1. In Telegram, talk to **@BotFather**, send `/newbot`, choose a name and a
   username, and copy the **token** it gives you
   (`123456789:AAH…`).
2. Paste it as *Bot token* and **Save**. Logbook checks it with Telegram
   and says "Telegram is saved: @your_bot".
3. Open your new bot in Telegram and press **Start** (a bot can't message
   anyone who hasn't).
4. Back on the card, press **Find my chat**. It lists the private chats
   that have written to your bot; choose yours. Only its ID is stored.
   For a group, add the bot to the group and type the group's ID (it starts
   with a minus) in *Chat ID*.

Limit 4096 characters, plain text, no link previews. If *Find my chat*
says the bot has a webhook set, the bot is used by something else that
receives its messages: remove that webhook or type the chat ID yourself.

| Error | What to do |
|---|---|
| Telegram rejected the bot token | Copy the token again from @BotFather (`/token`). |
| The bot can't reach that chat | Open the chat with your bot and press Start (or unblock it). |
| Chat not found | Check the chat ID; for a group, add the bot to it first. |
| Telegram asked us to wait *n* seconds | Too many messages at once; it sends again on the next run. |

### Discord

1. In Discord, open the channel's **Edit channel → Integrations →
   Webhooks → New webhook**, and **Copy webhook URL**.
2. Paste it as *Webhook URL* and **Save**.

Only `https://discord.com/api/webhooks/{id}/{token}` (or `discordapp.com`,
`ptb.discord.com`, `canary.discord.com`) is accepted, with no query. The
whole URL is a secret: its last part is the token. Messages come from
"Logbook", up to 2000 characters, with no link preview, and
`@everyone`, `@here`, roles and users in the text ping nobody.

| Error | What to do |
|---|---|
| That webhook no longer exists | It was deleted in Discord: make a new one and paste its URL. |
| Discord refused the message | Rare; send a test, and check the channel still exists. |
| Discord asked us to wait *n* seconds | Rate-limited; it sends again on the next run. |

### Pushover

1. On [pushover.net](https://pushover.net), your **User key** is on the
   dashboard.
2. **Create an Application/API token**, name it "Logbook", and copy its
   **API token**.
3. Paste both and **Save**: Logbook checks the pair with Pushover. To send
   to one device only, type its name in *Device* (several, separated by
   commas).

Message up to 1024 characters, title up to 250; the link is sent as the
notification's URL, titled "Open Logbook". A free application can send
10,000 messages a month.

| Error | What to do |
|---|---|
| Pushover rejected the token or the user key | Copy both again; check the device name if you set one. |
| This application has used its monthly messages | Wait for the 1st of the month, or create another application. |

### Mattermost

1. In Mattermost, **Integrations → Incoming webhooks → Add incoming
   webhook**, choose a channel, and copy the URL (it ends in `/hooks/` and
   a long ID). An admin may need to enable incoming webhooks first.
2. Paste it as *Webhook URL* and **Save**. To send somewhere other than the
   webhook's channel, give a *Channel* (`town-square`, or `@name` for a
   direct message); the webhook must not be locked to its channel.

The URL may be on your network: it is checked against
[where members can send](#where-members-can-send) like any member's
address. Messages are Markdown (the title bold, reminders as a list), up to
16383 characters, with every user's text escaped and `@name`, `@channel`,
`@here` and `@all` made inert.

| Error | What to do |
|---|---|
| Mattermost refused the message | Check incoming webhooks are enabled and the webhook isn't locked to another channel. |
| That webhook was not found | It was deleted: make a new one. |

### Slack

1. At [api.slack.com/apps](https://api.slack.com/apps), **Create New App →
   From scratch**, name it "Logbook" and choose your workspace.
2. Under **OAuth & Permissions → Bot Token Scopes**, add `chat:write`, then
   **Install to Workspace** and copy the **Bot User OAuth Token**
   (`xoxb-…`).
3. In Slack, invite the app to the channel: `/invite @Logbook`.
4. Paste the token, and the channel's ID (in the channel's details, such
   as `C0123456789`) or `#name`, and **Save**. Logbook checks the token and
   says "Slack is saved: {workspace}".

Plain text up to 4000 characters, no link previews; `<!channel>`,
`<!here>` and `<@…>` can't be formed.

| Error | What to do |
|---|---|
| Slack rejected the bot token | Reinstall the app and copy the new token. |
| Invite the app to the channel first | `/invite @Logbook` in the channel. |
| Channel not found | Use the channel's ID from its details. |
| That channel is archived | Unarchive it, or choose another. |

## How it fits together

```
bin/run-scheduled-tasks.php ─► ScheduledTasks ─► ReminderNotifier
                                                    │  sync, claim, compose
                                                    ▼
                                         NotificationDispatcher ─► ChannelResults (last result, failures)
                                                    │  registry->active(preferences, recipient)
                                                    ▼
          ChannelRegistry ◄── 'notification.channels' (EmailChannel, WebhookChannel: the server's)
                          ◄── UserChannels::usable(recipient) ◄── 'notification.personal'
                                                    │                 (NtfySender, … TelegramSender, SlackSender)
                                                    ▼
                         BoundChannel(sender, the user's settings) ─► OutboundHttp (check, pin, POST)
```

The dispatcher only ever sees the
`Logbook\Service\Notification\NotificationChannel` interface. A personal
kind is a `ChannelDefinition` (its fields) and a `PersonalSender`; the
Notifications page, the form's validation and the registry read only the
definition.

## Adding a channel

Say, Matrix. Two steps, and nothing in the reminder engine, the dispatcher,
the page or the migrations changes:

1. **Write a sender** in `src/Service/Notification/Personal/`:

   ```php
   final readonly class MatrixSender implements PersonalSender
   {
       public const string KEY = 'matrix';

       public function __construct(private OutboundHttp $http)
       {
       }

       public function definition(): ChannelDefinition
       {
           // The key is stored with each user's channel: never change it.
           return new ChannelDefinition(self::KEY, 'Matrix', 'forum', 'notifications.matrix.hint', [
               new ChannelField('url', 'notifications.matrix.url', FieldType::Url, required: true),
               new ChannelField('room', 'notifications.matrix.room', FieldType::Text, required: true, maxLength: 255),
               new ChannelField('token', 'notifications.matrix.token', FieldType::Secret, required: true, maxLength: 500),
           ]);
       }

       // Where it sends: checked against the policy and shown as the card's badge.
       public function destination(ChannelSettings $settings): ?string
       {
           return $settings->value('url');
       }

       // Checks beyond each field's own (type, length, range), on the typed values and any
       // secrets typed; also run on every send against the saved ones. Translation keys by field.
       public function validate(array $values, #[SensitiveParameter] array $secrets = []): array
       {
           return str_starts_with($values['room'] ?? '!', '!') ? [] : ['room' => 'notifications.matrix.room_invalid'];
       }

       public function send(Notification $notification, Recipient $recipient, ChannelSettings $settings, bool $restricted): DeliveryResult
       {
           $url = rtrim((string) $settings->value('url'), '/') . '/_matrix/client/v3/rooms/'
               . rawurlencode((string) $settings->value('room')) . '/send/m.room.message/' . bin2hex(random_bytes(8));

           return $this->http->post(self::KEY, $url, [
               'auth_bearer' => $settings->secret('token'),
               'json' => ['msgtype' => 'm.text', 'body' => $notification->title . "\n\n" . $notification->textWithLink()],
           ], $restricted);
       }
   }
   ```

   Always send through `OutboundHttp` with `$restricted`: `post()` when only
   success matters, `send()` when the answer's status and JSON body do (it
   never quotes the URL in an error). Both check the policy, pin the
   address and refuse redirects on every send. A
   `Notification` is already translated and formatted for the recipient:
   `title`, `message` (plain text), `url` (absolute link), `urgent`
   (something is overdue), `items` and `attention`. Report failure with
   `DeliveryResult::failed()`; the error is redacted of the channel's
   secrets before it is stored or shown, exceptions are caught by the
   dispatcher, and one failing channel never stops another.

   Helpers the Phase 36.3 senders use: `MessageText::fit()` (a service's
   limit, cut between lines with "…and N more"), `ServiceText` (those
   words in the recipient's language, `plainLines()`), `ReplyWords` (a
   service's errors as translation keys, shown by the `channel_error`
   filter), `Notification::urgency()`, `thirdParty: true` on the
   definition for the notice, `neededToSend: true` on a field that may be
   filled after saving, and `VerifiesSettings` to check a token on saving.

2. **Register it** in `config/dependencies.php`, and add its strings (EN
   and DE) to `translations/`:

   ```php
   'notification.personal' => [
       get(NtfySender::class),
       // …
       get(MatrixSender::class),
   ],
   ```

That's all: it gets a card on everyone's Notifications page, with saving,
tokens, *Send test*, the status, the policy and switching off after
failures. Its rows live in `notification_channels` and its secrets in
`notification_secrets` (named `matrix.token`); neither needs a migration.
For tests, see `tests/Unit/Service/Notification/Personal/ChannelFormTest.php`,
`ServiceRulesTest.php` and `MessageTextTest.php` beside it, and
`tests/Integration/Notification/PersonalChannelsTest.php` and
`ServiceChannelsTest.php`.

## What is sent, and when

- Each reminder is sent to each recipient **once when it becomes due**
  (enters its lead time, the vehicle owner's) and **once more if it becomes
  overdue**. Everything newly due for one person in a run goes out as one
  notification, in their language, units and time zone.
- Before sending, each reminder is *claimed* for that person and status (a
  row in `reminder_deliveries`, unique per reminder, user and status), so
  overlapping or repeated runs never send it to them twice. If no channel
  delivers, their claims are released and the next run retries; channels
  that did deliver are recorded and are not repeated. One person's failure
  never resends to another.
- With no channel reaching someone, nothing is claimed for them: whatever is
  still due is sent once they set a channel up.
- The optional monthly digest goes out on the first run of each month (each
  user's time zone), covering the vehicles they receive reminders for. It is
  on for users created from 2.1.0 on (setup and invitations); users from
  before keep whatever they had, which is off unless they turned it on.
  Everyone can change it under Settings → Reminders.
- From 2.4.0 the digest also lists the person's *Needs attention* checks
  (readings or fill-ups that look wrong, mileage or a valuation gone
  stale) on the same vehicles, as they would see them: none for a View
  share, and never one they have hidden. A month with checks and nothing
  due still sends a digest. The webhook's JSON carries them as an
  `attention` list (`vehicle_id`, `vehicle`, `kind`, `title`) beside
  `items`, which keeps its shape; for other events the list is empty.
- From 2.5.0 those checks include economy drift (`drift_liquid`,
  `drift_electric`), fuel price outliers (`fuel_price`) and maintenance
  cost outliers (`maintenance_cost`), each as one line with its title.
- From 2.11.0 admins are also told when a background job fails twice in a
  row (Settings → Jobs), once until it works again, through their own
  channels. The webhook's `event` is `job_failed`, with empty `items` and
  `attention`.
- From 3.3.0 a channel that switched itself off is announced once through
  the person's other channels; the webhook's `event` is `channel_off`.
- From 3.3.0 each channel gets only what it receives (*Receives* above).
  A run's reminders still go as one message per channel: a channel taking
  due and overdue gets them all, one taking only overdue gets the overdue
  ones. A reminder counts as sent once a channel taking it delivered it,
  and `reminder_deliveries.channels` lists only those channels. One that
  no channel takes is left for later, as with no channel at all.
- From 3.3.0 every price alert of one person's that fires in one check
  goes as one message ("2 price alerts", a line each); a single alert is
  unchanged. The webhook's `items` stays empty for price alerts.
- From 3.3.0, within one job run, a service that fails 3 times without
  answering (a timeout, a connection or TLS failure; not an HTTP error) is
  skipped for the rest of the run: its card says "The service didn't
  answer earlier in this run, so it was skipped.", which never counts
  towards switching it off. What wasn't delivered anywhere is retried on
  the next run. Tests and checks on saving are never skipped; email isn't
  covered.
