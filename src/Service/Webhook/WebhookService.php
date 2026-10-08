<?php

declare(strict_types=1);

namespace Logbook\Service\Webhook;

use Logbook\Domain\User\User;
use Logbook\Domain\Webhook\Webhook;
use Logbook\Domain\Webhook\WebhookEvent;
use Logbook\Domain\Webhook\WebhookPause;
use Logbook\Repository\WebhookDeliveryRepository;
use Logbook\Repository\WebhookRepository;
use Logbook\Service\Notification\DeliveryResult;
use Logbook\Service\Notification\Outbound\OutboundDestination;
use Logbook\Service\Notification\Outbound\OutboundHttp;
use Logbook\Support\Validation\ValidationErrors;
use Psr\Clock\ClockInterface;

/**
 * Settings → API keys → Webhooks (spec.md §7.20 *Webhooks*, Phase 39.3):
 * one's own webhooks, by user and id, so nobody reaches another's. A new
 * webhook, and *New secret*, return the secret to show once; it is stored
 * sealed. The address is checked against §7.11's policy on saving, as a
 * personal channel's is.
 */
final readonly class WebhookService
{
    public const int NAME_MAX = 100;
    public const int URL_MAX = 500;

    public function __construct(
        private WebhookRepository $webhooks,
        private WebhookDeliveryRepository $deliveries,
        private WebhookSecrets $secrets,
        private WebhookSender $sender,
        private OutboundDestination $destinations,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return list<Webhook>
     */
    public function webhooksOf(User $user): array
    {
        return $this->webhooks->listForUser($user->id);
    }

    public function webhookOf(User $user, int $id): ?Webhook
    {
        return $this->webhooks->find($user->id, $id);
    }

    /**
     * Deliveries still to go, per webhook.
     *
     * @param list<Webhook> $webhooks
     * @return array<int, int>
     */
    public function waiting(array $webhooks): array
    {
        return $this->deliveries->waiting(array_map(static fn (Webhook $w): int => $w->id, $webhooks));
    }

    public function canStoreSecrets(): bool
    {
        return $this->secrets->canStore();
    }

    /**
     * The add form: a name, an address the user may send to, and at least
     * one event.
     *
     * @param array<array-key, mixed> $input
     * @return array{name: string, url: string, events: list<WebhookEvent>}|ValidationErrors
     */
    public function parse(User $user, array $input): array|ValidationErrors
    {
        $errors = new ValidationErrors();
        $name = trim(is_string($input['name'] ?? null) ? $input['name'] : '');
        $url = trim(is_string($input['url'] ?? null) ? $input['url'] : '');
        $chosen = is_array($input['events'] ?? null) ? $input['events'] : [];
        $events = array_values(array_filter(array_map(
            static fn (mixed $value): ?WebhookEvent => is_string($value) ? WebhookEvent::tryFrom($value) : null,
            $chosen,
        )));

        if ($name === '') {
            $errors->add('name', 'validation.required');
        } elseif (mb_strlen($name) > self::NAME_MAX) {
            $errors->add('name', 'validation.too_long', ['max' => self::NAME_MAX]);
        }
        if ($url === '') {
            $errors->add('url', 'validation.required');
        } elseif (mb_strlen($url) > self::URL_MAX) {
            $errors->add('url', 'validation.too_long', ['max' => self::URL_MAX]);
        } else {
            $destination = $this->destinations->check($url, !$user->isAdmin, classify: !$user->isAdmin);
            if (!$destination->isAllowed()) {
                $errors->add('url', 'webhooks.error.destination', ['reason' => OutboundHttp::refusal($destination)]);
            }
        }
        if ($events === []) {
            $errors->add('events', 'webhooks.error.events');
        }

        return $errors->isEmpty() ? ['name' => $name, 'url' => $url, 'events' => $events] : $errors;
    }

    /**
     * @param list<WebhookEvent> $events
     * @return array{webhook: Webhook, secret: string}
     */
    public function create(User $user, string $name, string $url, array $events): array
    {
        $secret = $this->secrets->make();
        $id = $this->webhooks->insert($user->id, $name, $url, $events, $secret['sealed'], $this->clock->now());
        $webhook = $this->webhooks->find($user->id, $id) ?? throw new \LogicException('Webhook not saved.');

        return ['webhook' => $webhook, 'secret' => $secret['plain']];
    }

    /**
     * A new signing secret, shown once (#292); the old one stops working.
     */
    public function newSecret(User $user, Webhook $webhook): string
    {
        $secret = $this->secrets->make();
        $this->webhooks->setSecret($user->id, $webhook->id, $secret['sealed'], $this->clock->now());

        return $secret['plain'];
    }

    public function pause(User $user, Webhook $webhook): void
    {
        $this->webhooks->pause($user->id, $webhook->id, WebhookPause::User, $this->clock->now());
    }

    /**
     * Running again with its failures at 0 (#292); its waiting deliveries
     * go with the next pass. Refused while it has no secret.
     */
    public function resume(User $user, Webhook $webhook): bool
    {
        if ($webhook->needsSecret()) {
            return false;
        }
        $this->webhooks->resume($user->id, $webhook->id, $this->clock->now());

        return true;
    }

    public function delete(User $user, Webhook $webhook): void
    {
        $this->webhooks->delete($user->id, $webhook->id);
    }

    /**
     * *Send test*: one signed `webhook.test` now, in the request. A
     * success resets the failures; a failure is shown, never counted.
     */
    public function test(User $user, Webhook $webhook): DeliveryResult
    {
        $now = $this->clock->now();
        $result = $this->sender->send($webhook, $user, [
            'event' => 'webhook.test',
            'id' => bin2hex(random_bytes(16)),
            'occurred_at' => $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'webhook_id' => $webhook->id,
        ], $now);
        if ($result->delivered) {
            $this->webhooks->recordSuccess($webhook->id, $now);
        } else {
            $this->webhooks->recordFailure($webhook->id, (string) $result->error, false, $now);
        }

        return $result;
    }
}
