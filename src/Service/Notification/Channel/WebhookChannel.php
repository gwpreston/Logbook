<?php

declare(strict_types=1);

namespace Logbook\Service\Notification\Channel;

use Logbook\Service\Notification\DeliveryResult;
use Logbook\Service\Notification\Notification;
use Logbook\Service\Notification\NotificationChannel;
use Logbook\Service\Notification\NotificationItem;
use Logbook\Service\Notification\Recipient;
use Logbook\Support\Config\Env;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * A generic webhook (WEBHOOK_URL) receiving the notification as JSON, for
 * Home Assistant, n8n, Node-RED, a chat bridge…:
 *
 *   {"event": "reminders"|"digest"|"test", "title": …, "message": …,
 *    "url": …, "urgent": bool, "items": [{"reminder_id", "title",
 *    "detail", "status", "due_on"}]}
 */
final readonly class WebhookChannel implements NotificationChannel
{
    private ?string $url;

    public function __construct(Env $env, private HttpClientInterface $http)
    {
        $url = $env->nullableString('WEBHOOK_URL');
        $this->url = $url !== null && in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true) ? $url : null;
    }

    public function key(): string
    {
        return 'webhook';
    }

    public function label(): string
    {
        return 'notifications.channel.webhook';
    }

    public function isConfigured(): bool
    {
        return $this->url !== null;
    }

    public function send(Notification $notification, Recipient $recipient): DeliveryResult
    {
        if ($this->url === null) {
            return DeliveryResult::failed($this->key(), 'WEBHOOK_URL is not set.');
        }

        return HttpDelivery::post($this->http, $this->key(), $this->url, [
            'json' => [
                'event' => $notification->kind->value,
                'title' => $notification->title,
                'message' => $notification->message,
                'url' => $notification->url,
                'urgent' => $notification->urgent,
                'items' => array_map(static fn (NotificationItem $i): array => $i->toArray(), $notification->items),
            ],
        ]);
    }
}
