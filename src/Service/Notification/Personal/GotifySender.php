<?php

declare(strict_types=1);

namespace Logbook\Service\Notification\Personal;

use Logbook\Service\Notification\DeliveryResult;
use Logbook\Service\Notification\Notification;
use Logbook\Service\Notification\Outbound\OutboundHttp;
use Logbook\Service\Notification\Recipient;

/**
 * Gotify (https://gotify.net): POSTs to {server}/message with the user's
 * application token. Priority 0 to 10 (default 5); overdue reminders go at
 * least at 8, which Gotify clients alert on.
 */
final readonly class GotifySender implements PersonalSender
{
    public const string KEY = 'gotify';
    public const int DEFAULT_PRIORITY = 5;
    public const int URGENT_PRIORITY = 8;

    public function __construct(private OutboundHttp $http)
    {
    }

    public function definition(): ChannelDefinition
    {
        return new ChannelDefinition(self::KEY, 'Gotify', 'forum', 'notifications.gotify.hint', [
            new ChannelField(
                'url',
                'notifications.gotify.url',
                FieldType::Url,
                required: true,
                placeholder: 'https://gotify.example.com',
            ),
            new ChannelField(
                'token',
                'notifications.gotify.token',
                FieldType::Secret,
                required: true,
                hint: 'notifications.gotify.token_hint',
                maxLength: 200,
            ),
            new ChannelField(
                'priority',
                'notifications.gotify.priority',
                FieldType::Integer,
                hint: 'notifications.gotify.priority_hint',
                min: 0,
                max: 10,
                default: self::DEFAULT_PRIORITY,
            ),
        ]);
    }

    public function destination(ChannelSettings $settings): ?string
    {
        $url = $settings->value('url');

        return $url === null ? null : rtrim($url, '/') . '/message';
    }

    public function validate(array $values): array
    {
        return [];
    }

    public function send(
        Notification $notification,
        Recipient $recipient,
        ChannelSettings $settings,
        bool $restricted,
    ): DeliveryResult {
        $url = $this->destination($settings);
        $token = $settings->secret('token');
        if ($url === null || $token === null) {
            return DeliveryResult::failed(self::KEY, 'A Gotify server URL and application token are required.');
        }

        $priority = max(0, min(10, $settings->int('priority', self::DEFAULT_PRIORITY)));
        $extras = ['client::display' => ['contentType' => 'text/plain']];
        if ($notification->url !== null) {
            $extras['client::notification'] = ['click' => ['url' => $notification->url]];
        }

        return $this->http->post(self::KEY, $url, [
            'headers' => ['X-Gotify-Key' => $token],
            'json' => [
                'title' => $notification->title,
                'message' => $notification->textWithLink(),
                'priority' => $notification->urgent ? max($priority, self::URGENT_PRIORITY) : $priority,
                'extras' => $extras,
            ],
        ], $restricted);
    }
}
