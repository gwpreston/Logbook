<?php

declare(strict_types=1);

namespace Logbook\Service\Notification\Personal;

use Logbook\Service\Notification\DeliveryResult;
use Logbook\Service\Notification\Notification;
use Logbook\Service\Notification\Outbound\OutboundHttp;
use Logbook\Service\Notification\Recipient;
use Logbook\Service\Notification\WebhookPayload;

/**
 * A personal webhook (`personal-webhook`, spec.md §7.11): the user's URL
 * receives the same JSON as the server's webhook (WebhookPayload),
 * including `user`, so one endpoint can serve several people.
 */
final readonly class WebhookSender implements PersonalSender
{
    public const string KEY = 'personal-webhook';

    public function __construct(private OutboundHttp $http)
    {
    }

    public function definition(): ChannelDefinition
    {
        return new ChannelDefinition(self::KEY, 'notifications.webhook.label', 'webhook', 'notifications.webhook.hint', [
            new ChannelField(
                'url',
                'notifications.webhook.url',
                FieldType::Url,
                required: true,
                hint: 'notifications.webhook.url_hint',
                placeholder: 'https://example.com/hook',
            ),
        ]);
    }

    public function destination(ChannelSettings $settings): ?string
    {
        return $settings->value('url');
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
        $url = $settings->value('url');
        if ($url === null) {
            return DeliveryResult::failed(self::KEY, 'No webhook URL.');
        }

        return $this->http->post(self::KEY, $url, ['json' => WebhookPayload::of($notification, $recipient)], $restricted);
    }
}
