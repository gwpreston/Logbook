<?php

declare(strict_types=1);

namespace Logbook\Domain\FuelPrices;

use DateTimeImmutable;

/**
 * A stored provider station (`provider_stations`, spec.md §6
 * ProviderStation): the feed's copy, re-synced and never backed up.
 */
final readonly class ProviderStation
{
    public function __construct(
        public int $id,
        public string $provider,
        public FeedStation $data,
        public DateTimeImmutable $updatedAt,
        public ?DateTimeImmutable $removedAt = null,
    ) {
    }

    public function ref(): string
    {
        return $this->data->ref;
    }

    public function isRemoved(): bool
    {
        return $this->removedAt !== null;
    }

    /**
     * Shown in rankings, the widget and alerts: neither removed nor
     * temporarily closed (spec.md §7.34 *Closures*).
     */
    public function isOpen(): bool
    {
        return $this->removedAt === null && !$this->data->temporarilyClosed;
    }
}
