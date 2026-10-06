<?php

declare(strict_types=1);

namespace Logbook\Service\Notification;

use InvalidArgumentException;
use Logbook\Service\Demo\DemoMode;
use Logbook\Service\Demo\DemoRestriction;

/**
 * Every notification channel the app knows, as registered in the
 * `notification.channels` DI list (config/dependencies.php). Knows nothing
 * about concrete channel types.
 */
final readonly class ChannelRegistry
{
    /** @var array<string, NotificationChannel> */
    private array $channels;

    /**
     * @param iterable<NotificationChannel> $channels
     */
    public function __construct(iterable $channels, private ?DemoMode $demo = null)
    {
        $byKey = [];
        foreach ($channels as $channel) {
            $key = $channel->key();
            if (preg_match('/^[a-z0-9][a-z0-9-]{0,31}$/', $key) !== 1) {
                throw new InvalidArgumentException(sprintf('Notification channel key "%s" is invalid.', $key));
            }
            if (isset($byKey[$key])) {
                throw new InvalidArgumentException(sprintf('Two notification channels use the key "%s".', $key));
            }
            $byKey[$key] = $channel;
        }
        $this->channels = $byKey;
    }

    /**
     * @return list<NotificationChannel> in registration order
     */
    public function all(): array
    {
        return array_values($this->channels);
    }

    public function get(string $key): ?NotificationChannel
    {
        return $this->channels[$key] ?? null;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->channels);
    }

    /**
     * @return list<string> keys of the channels this server has configured
     */
    public function configuredKeys(): array
    {
        return array_values(array_map(
            static fn (NotificationChannel $c): string => $c->key(),
            array_filter($this->channels, static fn (NotificationChannel $c): bool => $c->isConfigured()),
        ));
    }

    /**
     * The channels to use for a person: enabled by them AND able to reach
     * them (configured here, with their own details or an admin's defaults).
     *
     * @return list<NotificationChannel>
     */
    public function active(NotificationPreferences $preferences, Recipient $recipient): array
    {
        // Nothing is sent from a demo (spec.md §7.36): no channel reaches anyone.
        if ($this->demo?->blocks(DemoRestriction::Outbound) === true) {
            return [];
        }

        return array_values(array_filter(
            $this->channels,
            static fn (NotificationChannel $c): bool => $preferences->isEnabled($c->key()) && $c->reaches($recipient),
        ));
    }
}
