# Notification channels

Reminders always appear in the app. When they come due (and again if they
become overdue) the scheduled task also sends them through each recipient's
**notification channels** (spec.md §7.11). A vehicle's recipients are its
owner and anyone it is shared with who ticked *Send me its reminders*
([users-and-sharing.md](users-and-sharing.md)). Logbook ships four:

| Key | Channel | Configured by |
|---|---|---|
| `email` | Email over SMTP (symfony/mailer) | `MAIL_HOST` (+ `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION`, `MAIL_FROM`, `MAIL_TO`) |
| `ntfy` | [ntfy](https://ntfy.sh) push | `NTFY_URL` (topic URL), `NTFY_TOKEN` (optional) |
| `gotify` | [Gotify](https://gotify.net) push | `GOTIFY_URL`, `GOTIFY_TOKEN` (+ `GOTIFY_PRIORITY`) |
| `webhook` | JSON `POST` to any URL | `WEBHOOK_URL` |

A channel is **configured** when its environment variables are set, and
**enabled** per user in Settings → Reminders (until they choose, every
configured channel is on). It is used for someone only when it is enabled
and it **reaches** them:

| Channel | Reaches an admin | Reaches a member |
|---|---|---|
| `email` | their own address, else `MAIL_TO` | their own address only |
| `ntfy` | their own topic URL, else `NTFY_URL` | their own topic URL only |
| `gotify` | their own application token, else `GOTIFY_TOKEN` (on `GOTIFY_URL`) | their own token on `GOTIFY_URL` only |
| `webhook` | always, when `WEBHOOK_URL` is set | always; the payload names the `user` |

So a household ntfy topic or a shared inbox gets the admins' reminders only,
never everyone's cars. Each person sets their own address, topic and token in
Settings → Reminders. *Send a test* checks the channels that reach you;
failures are logged at `warning` level.

## How it fits together

```
bin/run-scheduled-tasks.php ─► ScheduledTasks ─► ReminderNotifier
                                                    │  sync, claim, compose
                                                    ▼
                                         NotificationDispatcher
                                                    │  registry->active(preferences, recipient)
                                                    ▼
                           ChannelRegistry ◄── 'notification.channels' (DI list)
                                                    │
                            EmailChannel · NtfyChannel · GotifyChannel · WebhookChannel · …
```

The reminder engine and the dispatcher only ever see the
`Logbook\Service\Notification\NotificationChannel` interface. Nothing outside a
channel class knows it exists, apart from its line in the DI list.

## Adding a channel

Say, Telegram. Three steps, and nothing in the reminder engine, the
dispatcher or the settings page changes:

1. **Implement the interface** in `src/Service/Notification/Channel/`:

   ```php
   final readonly class TelegramChannel implements NotificationChannel
   {
       private ?string $token;
       private ?string $chatId;

       public function __construct(Env $env, private HttpClientInterface $http)
       {
           // 3. Its own configuration, read from its own variables.
           $this->token = $env->nullableString('TELEGRAM_BOT_TOKEN');
           $this->chatId = $env->nullableString('TELEGRAM_CHAT_ID');
       }

       public function key(): string { return 'telegram'; }        // stored in settings; never change it
       public function label(): string { return 'Telegram'; }      // a product name, or a translation key
       public function isConfigured(): bool { return $this->token !== null && $this->chatId !== null; }
       // Whether it can deliver to this person: here, one chat for everyone,
       // so an instance-wide chat is an admin's (see the table above).
       public function reaches(Recipient $recipient): bool { return $this->isConfigured() && $recipient->isAdmin; }

       public function send(Notification $notification, Recipient $recipient): DeliveryResult
       {
           return HttpDelivery::post($this->http, $this->key(), 'https://api.telegram.org/bot' . $this->token . '/sendMessage', [
               'json' => ['chat_id' => $this->chatId, 'text' => $notification->title . "\n\n" . $notification->textWithLink()],
           ]);
       }
   }
   ```

   `send()` gets a `Notification` that is already translated and formatted for
   the recipient (a `Recipient`: their id, name, username, whether they are an
   admin, and their own email, ntfy topic and Gotify token), and a
   `Notification`: `title`, `message` (plain text), `url` (absolute link to the
   reminders), `urgent` (something is overdue) and structured `items`. Report
   failure with `DeliveryResult::failed()`; exceptions are caught and logged by
   the dispatcher too, and one failing channel never stops the others.

2. **Register it** by adding it to the list in `config/dependencies.php`:

   ```php
   'notification.channels' => [
       get(EmailChannel::class),
       // …
       get(TelegramChannel::class),
   ],
   ```

   (A separate definitions file can append with `DI\add([get(TelegramChannel::class)])`
   instead of editing the list.) PHP-DI autowires the constructor: `Env`,
   `HttpClientInterface`, `LoggerInterface` and the other app services are
   available.

3. **Document its variables** in `.env.example` and spec.md §9 (and pass them
   through in the compose files if Docker users should set them in `.env`).

That's all: it appears in Settings → Reminders, can be enabled per user, is
tested by *Send a test*, and receives reminders and digests. For tests, see
`tests/Support/FakeChannel.php` and `tests/Unit/Service/Notification/NotificationDispatcherTest.php`;
the shipped channels are exercised with recorded transports in
`tests/Integration/Reminder/NotificationDeliveryTest.php`.

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
