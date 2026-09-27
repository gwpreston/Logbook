# Notification channels

Reminders always appear in the app. When they come due (and again if they
become overdue) the scheduled task also sends them through the owner's
**notification channels** (spec.md §7.11). Logbook ships four:

| Key | Channel | Configured by |
|---|---|---|
| `email` | Email over SMTP (symfony/mailer) | `MAIL_HOST` (+ `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION`, `MAIL_FROM`, `MAIL_TO`) |
| `ntfy` | [ntfy](https://ntfy.sh) push | `NTFY_URL` (topic URL), `NTFY_TOKEN` (optional) |
| `gotify` | [Gotify](https://gotify.net) push | `GOTIFY_URL`, `GOTIFY_TOKEN` (+ `GOTIFY_PRIORITY`) |
| `webhook` | JSON `POST` to any URL | `WEBHOOK_URL` |

A channel is **configured** when its environment variables are set, and
**enabled** per owner in Settings → Reminders (until the owner chooses, every
configured channel is on). Only channels that are both are used. Settings →
Reminders → *Send a test* checks the ones in use; failures are logged at
`warning` level.

## How it fits together

```
bin/run-scheduled-tasks.php ─► ScheduledTasks ─► ReminderNotifier
                                                    │  sync, claim, compose
                                                    ▼
                                         NotificationDispatcher
                                                    │  registry->active(preferences)
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

       public function send(Notification $notification, Recipient $recipient): DeliveryResult
       {
           return HttpDelivery::post($this->http, $this->key(), 'https://api.telegram.org/bot' . $this->token . '/sendMessage', [
               'json' => ['chat_id' => $this->chatId, 'text' => $notification->title . "\n\n" . $notification->textWithLink()],
           ]);
       }
   }
   ```

   `send()` gets a `Notification` that is already translated and formatted for
   the owner: `title`, `message` (plain text), `url` (absolute link to the
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

That's all: it appears in Settings → Reminders, can be enabled per owner, is
tested by *Send a test*, and receives reminders and digests. For tests, see
`tests/Support/FakeChannel.php` and `tests/Unit/Service/Notification/NotificationDispatcherTest.php`;
the shipped channels are exercised with recorded transports in
`tests/Integration/Reminder/NotificationDeliveryTest.php`.

## What is sent, and when

- Each reminder is sent **once when it becomes due** (enters its lead time)
  and **once more if it becomes overdue**. Everything newly due for an owner in
  one run goes out as one notification.
- Before sending, each reminder is *claimed* (a conditional update of
  `reminders.notified_status`), so overlapping or repeated runs never send it
  twice. If no channel delivers, the claim is released and the next run
  retries; channels that did deliver are recorded in
  `reminders.channels_notified` and are not repeated.
- With no channel enabled and configured, nothing is claimed: whatever is
  still due is sent once a channel is set up.
- The optional monthly digest goes out on the first run of each month (the
  owner's time zone).
