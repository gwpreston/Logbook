<?php

declare(strict_types=1);

namespace Logbook\Service\Station;

use Logbook\Domain\Fuel\FuelEntry;

/**
 * One fill-up at a station as a user may see it (spec.md §7.33): its
 * vehicle's currency, and whether its amounts are theirs to see (spec.md
 * §7.21: ViewCosts, or their own entry).
 */
final readonly class StationVisit
{
    public function __construct(
        public FuelEntry $entry,
        public string $currency,
        public bool $amountVisible,
    ) {
    }

    public function stationId(): int
    {
        return $this->entry->data->stationId ?? 0;
    }
}
