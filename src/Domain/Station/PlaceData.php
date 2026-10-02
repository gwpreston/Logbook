<?php

declare(strict_types=1);

namespace Logbook\Domain\Station;

/**
 * A saved place as entered (spec.md §6 Place): a name and a position,
 * canonical decimal strings with 6 places.
 */
final readonly class PlaceData
{
    public function __construct(
        public string $name,
        public string $latitude,
        public string $longitude,
    ) {
    }
}
