<?php

declare(strict_types=1);

namespace Logbook\Service\Notification\Personal;

use Logbook\Service\Notification\DeliveryResult;
use Logbook\Service\Notification\Notification;
use Logbook\Service\Notification\Outbound\OutboundHttp;
use Logbook\Service\Notification\Recipient;
use SensitiveParameter;

/**
 * ntfy (https://ntfy.sh or self-hosted): the user's topic URL, such as
 * https://ntfy.sh/my-garage, and an optional access token. Publishes as
 * JSON to the server root (so titles may contain any character), with the
 * reminders link as the click action; overdue reminders go at high
 * priority.
 */
final readonly class NtfySender implements PersonalSender
{
    public const string KEY = 'ntfy';

    public function __construct(private OutboundHttp $http)
    {
    }

    public function definition(): ChannelDefinition
    {
        return new ChannelDefinition(self::KEY, 'ntfy', 'notifications_active', 'notifications.ntfy.hint', [
            new ChannelField(
                'url',
                'notifications.ntfy.url',
                FieldType::Url,
                required: true,
                hint: 'notifications.ntfy.url_hint',
                placeholder: 'https://ntfy.sh/my-topic',
            ),
            new ChannelField(
                'token',
                'notifications.ntfy.token',
                FieldType::Secret,
                hint: 'notifications.ntfy.token_hint',
                maxLength: 200,
            ),
        ]);
    }

    public function destination(ChannelSettings $settings): ?string
    {
        $server = self::split($settings->value('url'))[0];

        return $server === null ? null : $server . '/';
    }

    public function validate(array $values, #[SensitiveParameter] array $secrets = []): array
    {
        return isset($values['url']) && $values['url'] !== '' && self::split($values['url'])[0] === null
            ? ['url' => 'notifications.ntfy.url_invalid']
            : [];
    }

    public function send(
        Notification $notification,
        Recipient $recipient,
        ChannelSettings $settings,
        bool $restricted,
    ): DeliveryResult {
        [$server, $topic] = self::split($settings->value('url'));
        if ($server === null || $topic === null) {
            return DeliveryResult::failed(self::KEY, 'No ntfy topic URL.');
        }

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
        $token = $settings->secret('token');
        if ($token !== null) {
            $options['auth_bearer'] = $token;
        }

        return $this->http->post(self::KEY, $server . '/', $options, $restricted);
    }

    /**
     * "https://ntfy.example/ntfy/garage" → ["https://ntfy.example/ntfy", "garage"];
     * [null, null] unless it is an http(s) URL ending in a topic name.
     *
     * @return array{0: string|null, 1: string|null}
     */
    public static function split(?string $url): array
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
