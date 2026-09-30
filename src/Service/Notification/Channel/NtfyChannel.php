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
 * ntfy (https://ntfy.sh or self-hosted). NTFY_URL is the topic URL, e.g.
 * https://ntfy.sh/my-garage; NTFY_TOKEN an optional access token.
 *
 * Publishes as JSON to the server root (so titles may contain any
 * character), with the reminders link as the click action; overdue
 * reminders go at high priority.
 */
final readonly class NtfyChannel implements NotificationChannel
{
    private ?string $server;
    private ?string $topic;
    private ?string $token;

    public function __construct(Env $env, private HttpClientInterface $http)
    {
        [$this->server, $this->topic] = self::split($env->nullableString('NTFY_URL'));
        $this->token = $env->nullableString('NTFY_TOKEN');
    }

    public function key(): string
    {
        return 'ntfy';
    }

    public function label(): string
    {
        return 'ntfy';
    }

    public function isConfigured(): bool
    {
        return $this->server !== null && $this->topic !== null;
    }

    public function reaches(Recipient $recipient): bool
    {
        return $this->target($recipient) !== null;
    }

    public function send(Notification $notification, Recipient $recipient): DeliveryResult
    {
        $target = $this->target($recipient);
        if ($target === null) {
            return DeliveryResult::failed($this->key(), 'No ntfy topic (a personal one, or NTFY_URL for admins).');
        }
        [$server, $topic, $token] = $target;

        $payload = [
            'topic' => $topic,
            'title' => $notification->title,
            'message' => $notification->message,
            'priority' => $notification->urgent ? 4 : 3,
            'tags' => [$notification->urgent ? 'warning' : 'car'],
        ];
        if ($notification->url !== null) {
            $payload['click'] = $notification->url;
        }

        $options = ['json' => $payload];
        if ($token !== null) {
            $options['auth_bearer'] = $token;
        }

        return HttpDelivery::post($this->http, $this->key(), $server . '/', $options);
    }

    /**
     * Where this person's messages go: their own topic URL, else the
     * instance's for an admin (with NTFY_TOKEN, which belongs to it).
     *
     * @return array{string, string, string|null}|null server, topic, token
     */
    private function target(Recipient $recipient): ?array
    {
        [$server, $topic] = self::split($recipient->ntfyUrl);
        if ($server !== null && $topic !== null) {
            // The instance token only goes to the instance's own server.
            return [$server, $topic, $server === $this->server ? $this->token : null];
        }
        if ($recipient->isAdmin && $this->server !== null && $this->topic !== null) {
            return [$this->server, $this->topic, $this->token];
        }

        return null;
    }

    /** Whether a personal topic URL is one this channel can use (Settings checks it). */
    public static function isTopicUrl(string $url): bool
    {
        return self::split($url)[0] !== null;
    }

    /**
     * "https://ntfy.example/ntfy/garage" → ["https://ntfy.example/ntfy", "garage"];
     * [null, null] unless it is an http(s) URL ending in a topic name.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private static function split(?string $url): array
    {
        $trimmed = rtrim($url ?? '', '/');
        $slash = strrpos($trimmed, '/');
        if ($slash === false) {
            return [null, null];
        }

        $server = substr($trimmed, 0, $slash);
        $topic = substr($trimmed, $slash + 1);
        $valid = in_array(parse_url($server, PHP_URL_SCHEME), ['http', 'https'], true)
            && is_string(parse_url($server, PHP_URL_HOST))
            && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $topic) === 1;

        return $valid ? [$server, $topic] : [null, null];
    }
}
