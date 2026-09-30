<?php

declare(strict_types=1);

namespace Logbook\Service\Notification\Channel;

use Logbook\Service\Notification\DeliveryResult;
use Logbook\Service\Notification\Notification;
use Logbook\Service\Notification\NotificationChannel;
use Logbook\Service\Notification\Recipient;
use Logbook\Support\Config\Env;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Gotify (https://gotify.net). POSTs to GOTIFY_URL/message with the
 * application token GOTIFY_TOKEN. Priority GOTIFY_PRIORITY (0–10, default
 * 5); overdue reminders go at least at 8, which Gotify clients alert on.
 */
final readonly class GotifyChannel implements NotificationChannel
{
    public const int DEFAULT_PRIORITY = 5;
    public const int URGENT_PRIORITY = 8;

    private ?string $url;
    private ?string $token;
    private int $priority;

    public function __construct(Env $env, private HttpClientInterface $http)
    {
        $url = $env->nullableString('GOTIFY_URL');
        $this->url = $url !== null && in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)
            ? rtrim($url, '/')
            : null;
        $this->token = $env->nullableString('GOTIFY_TOKEN');

        $priority = filter_var($env->string('GOTIFY_PRIORITY'), FILTER_VALIDATE_INT);
        $this->priority = is_int($priority) ? max(0, min(10, $priority)) : self::DEFAULT_PRIORITY;
    }

    public function key(): string
    {
        return 'gotify';
    }

    public function label(): string
    {
        return 'Gotify';
    }

    public function isConfigured(): bool
    {
        return $this->url !== null && $this->token !== null;
    }

    /**
     * The server is the instance's (GOTIFY_URL); the token is theirs, or
     * GOTIFY_TOKEN for an admin.
     */
    public function reaches(Recipient $recipient): bool
    {
        return $this->url !== null && $this->tokenFor($recipient) !== null;
    }

    public function send(Notification $notification, Recipient $recipient): DeliveryResult
    {
        $token = $this->tokenFor($recipient);
        if ($this->url === null || $token === null) {
            return DeliveryResult::failed(
                $this->key(),
                'GOTIFY_URL and a token (their own, or GOTIFY_TOKEN for admins) are required.',
            );
        }

        $extras = ['client::display' => ['contentType' => 'text/plain']];
        if ($notification->url !== null) {
            $extras['client::notification'] = ['click' => ['url' => $notification->url]];
        }

        return HttpDelivery::post($this->http, $this->key(), $this->url . '/message', [
            'headers' => ['X-Gotify-Key' => $token],
            'json' => [
                'title' => $notification->title,
                'message' => $notification->textWithLink(),
                'priority' => $notification->urgent ? max($this->priority, self::URGENT_PRIORITY) : $this->priority,
                'extras' => $extras,
            ],
        ]);
    }

    private function tokenFor(Recipient $recipient): ?string
    {
        return $recipient->gotifyToken ?? ($recipient->isAdmin ? $this->token : null);
    }
}
