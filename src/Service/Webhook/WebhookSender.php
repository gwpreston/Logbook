<?php

declare(strict_types=1);

namespace Logbook\Service\Webhook;

use DateTimeImmutable;
use Logbook\Domain\User\User;
use Logbook\Domain\Webhook\Webhook;
use Logbook\Kernel;
use Logbook\Service\Notification\DeliveryResult;
use Logbook\Service\Notification\Outbound\OutboundHttp;

/**
 * One signed POST to a webhook (spec.md §7.20 *Webhooks*). Where it may go
 * is §7.11's policy, exactly as a personal channel's: checked and pinned on
 * every send, no redirects, an admin's own unrestricted (OutboundHttp).
 *
 * `X-Logbook-Signature: t=<unix time>,v1=<hex HMAC-SHA256 of "t.body">`
 * with the webhook's secret; `docs/api.md` shows how to check it.
 */
final readonly class WebhookSender
{
    public const string CHANNEL = 'entry-webhook';
    public const string SIGNATURE = 'X-Logbook-Signature';

    public function __construct(
        private OutboundHttp $http,
        private WebhookSecrets $secrets,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function send(Webhook $webhook, User $owner, array $payload, DateTimeImmutable $now): DeliveryResult
    {
        $secret = $this->secrets->open($webhook);
        if ($secret === null) {
            return DeliveryResult::refused(self::CHANNEL, 'The webhook has no signing secret; make a new one.');
        }
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $headers = [
            'Content-Type' => 'application/json',
            'User-Agent' => 'Logbook/' . Kernel::version(),
            'X-Logbook-Event' => is_string($payload['event'] ?? null) ? $payload['event'] : '',
            'X-Logbook-Delivery' => is_string($payload['id'] ?? null) ? $payload['id'] : '',
            self::SIGNATURE => self::signature($secret, $body, $now->getTimestamp()),
        ];

        $result = $this->http->post(self::CHANNEL, $webhook->url, ['headers' => $headers, 'body' => $body], !$owner->isAdmin);
        if ($result->delivered || $result->error === null || preg_match('/^HTTP \d{3}/', $result->error) === 1) {
            return $result;
        }

        // A transport error's text can quote the URL, which may carry a token: reduce it to its kind.
        return $result->refused
            ? $result
            : DeliveryResult::failed(self::CHANNEL, OutboundHttp::connectionError($result->error));
    }

    public static function signature(string $secret, string $body, int $time): string
    {
        return sprintf('t=%d,v1=%s', $time, hash_hmac('sha256', $time . '.' . $body, $secret));
    }
}
