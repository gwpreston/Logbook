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
                                                    │                 (NtfySender, GotifySender, WebhookSender, …)
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

       // Checks beyond each field's own (type, length, range); translation keys by field.
       public function validate(array $values): array
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

   Always send through `OutboundHttp::post()` with `$restricted`: it checks
   the policy, pins the address and refuses redirects on every send. A
   `Notification` is already translated and formatted for the recipient:
   `title`, `message` (plain text), `url` (absolute link), `urgent`
   (something is overdue), `items` and `attention`. Report failure with
   `DeliveryResult::failed()`; the error is redacted of the channel's
   secrets before it is stored or shown, exceptions are caught by the
   dispatcher, and one failing channel never stops another.

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
For tests, see `tests/Unit/Service/Notification/Personal/ChannelFormTest.php`
and `tests/Integration/Notification/PersonalChannelsTest.php`.

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
