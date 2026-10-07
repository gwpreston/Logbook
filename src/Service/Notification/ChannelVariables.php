<?php

declare(strict_types=1);

namespace Logbook\Service\Notification;

use Logbook\Service\Notification\Channel\WebhookChannel;
use Logbook\Support\Config\Env;

/**
 * The channel variables of before Phase 36.2, for Settings → Delivery's
 * notices (spec.md §7.11 *The server's variables*): the five imported once
 * and no longer read, and the deprecated `WEBHOOK_URL`.
 */
final readonly class ChannelVariables
{
    public const array REMOVED = ['NTFY_URL', 'NTFY_TOKEN', 'GOTIFY_URL', 'GOTIFY_TOKEN', 'GOTIFY_PRIORITY'];

    public function __construct(private Env $env)
    {
    }

    /**
     * @return list<string> the removed variables that are still set
     */
    public function removedButSet(): array
    {
        return array_values(array_filter(self::REMOVED, fn (string $name): bool => $this->env->string($name) !== ''));
    }

    public function webhookSet(): bool
    {
        return $this->env->string(WebhookChannel::VARIABLE) !== '';
    }
}
