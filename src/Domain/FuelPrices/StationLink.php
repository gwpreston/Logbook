<?php

declare(strict_types=1);

namespace Logbook\Domain\FuelPrices;

/**
 * A Logbook station's link to a provider station, by the provider's code
 * and the feed's own id, never a row id (spec.md §6 Station, decided
 * 2026-10-03, #143), and whether its details follow the feed.
 */
final readonly class StationLink
{
    public function __construct(
        public string $provider,
        public string $ref,
        public bool $keepMyDetails = false,
    ) {
    }
}
