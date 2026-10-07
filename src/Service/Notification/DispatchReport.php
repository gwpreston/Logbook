<?php

declare(strict_types=1);

namespace Logbook\Service\Notification;

/**
 * The outcome of sending one notification through the owner's channels.
 */
final readonly class DispatchReport
{
    /**
     * @param list<DeliveryResult> $results one per channel tried
     * @param list<list<NotificationCategory>> $carried per result, the categories its message carried (Phase 36.4)
     */
    public function __construct(public array $results, private array $carried = [])
    {
    }

    /**
     * @return list<string> keys of the channels that delivered a message carrying this category
     */
    public function deliveredChannelsFor(NotificationCategory $category): array
    {
        $keys = [];
        foreach ($this->results as $i => $result) {
            if ($result->delivered && in_array($category, $this->carried[$i] ?? [], true)) {
                $keys[] = $result->channel;
            }
        }

        return $keys;
    }

    /**
     * @return list<string> keys of the channels that delivered
     */
    public function deliveredChannels(): array
    {
        return array_values(array_map(
            static fn (DeliveryResult $r): string => $r->channel,
            array_filter($this->results, static fn (DeliveryResult $r): bool => $r->delivered),
        ));
    }

    /**
     * @return list<DeliveryResult>
     */
    public function failures(): array
    {
        return array_values(array_filter($this->results, static fn (DeliveryResult $r): bool => !$r->delivered));
    }

    public function anyDelivered(): bool
    {
        return $this->deliveredChannels() !== [];
    }

    /**
     * No channel was even tried: none is both enabled and configured.
     */
    public function hadNoChannels(): bool
    {
        return $this->results === [];
    }
}
