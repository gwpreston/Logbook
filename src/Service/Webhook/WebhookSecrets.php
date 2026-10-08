<?php

declare(strict_types=1);

namespace Logbook\Service\Webhook;

use Logbook\Domain\Webhook\Webhook;
use Logbook\Service\Ai\SecretBox;
use Logbook\Service\Ai\SecretUnreadable;

/**
 * A webhook's signing secret (spec.md §6 Webhook): made here, shown once,
 * stored sealed with its own key (HKDF info `logbook-webhook`, §7.25) and
 * opened only to sign a delivery.
 */
final readonly class WebhookSecrets
{
    private const string PREFIX = 'whsec_';

    private SecretBox $box;

    public function __construct(SecretBox $box)
    {
        $this->box = $box->withInfo(SecretBox::WEBHOOK);
    }

    /**
     * Whether a secret can be stored (a `SESSION_SECRET` to seal it with).
     */
    public function canStore(): bool
    {
        return $this->box->canStore(self::PREFIX);
    }

    /**
     * A new secret: the value to show once, and what to store.
     *
     * @return array{plain: string, sealed: string}
     */
    public function make(): array
    {
        $plain = self::PREFIX . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        return ['plain' => $plain, 'sealed' => $this->box->store($plain)];
    }

    /**
     * The secret to sign with, or null when there is none or it can't be
     * opened (a changed `SESSION_SECRET`).
     */
    public function open(Webhook $webhook): ?string
    {
        if ($webhook->secret === null) {
            return null;
        }
        try {
            return $this->box->open('webhook', $webhook->secret);
        } catch (SecretUnreadable) {
            return null;
        }
    }
}
