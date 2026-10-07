<?php

declare(strict_types=1);

namespace Logbook\Service\Notification\Channel;

use Logbook\Service\Notification\DeliveryResult;
use Logbook\Service\Notification\Notification;
use Logbook\Service\Notification\NotificationChannel;
use Logbook\Service\Notification\Recipient;
use Logbook\Service\Notification\WebhookPayload;
use Logbook\Support\Config\Env;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The server's webhook (`WEBHOOK_URL`, key `webhook`; spec.md §7.11,
 * deprecated from Phase 36.2, #228): it receives every recipient's
 * notifications as JSON (WebhookPayload), naming them. Set by the admin
 * in the environment, so the members' destination policy does not apply;
 * redirects are not followed. Personal webhooks are `personal-webhook`.
 */
final readonly class WebhookChannel implements NotificationChannel
{
    public const string VARIABLE = 'WEBHOOK_URL';

    private ?string $url;

    public function __construct(Env $env, private HttpClientInterface $http)
    {
        $url = $env->nullableString(self::VARIABLE);
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

    /** Server-wide: it receives every recipient's notifications, naming them. */
    public function reaches(Recipient $recipient): bool
    {
        return $this->isConfigured();
    }

    public function send(Notification $notification, Recipient $recipient): DeliveryResult
    {
        if ($this->url === null) {
            return DeliveryResult::failed($this->key(), 'WEBHOOK_URL is not set.');
        }

        return HttpDelivery::post($this->http, $this->key(), $this->url, [
            'json' => WebhookPayload::of($notification, $recipient),
            'max_redirects' => 0,
        ]);
    }
}
