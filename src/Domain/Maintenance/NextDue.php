<?php

declare(strict_types=1);

namespace Logbook\Domain\Maintenance;

use DateTimeImmutable;

/**
 * Where a recurring job next falls due: a calendar date (from the months
 * interval), an odometer reading in km (from the distance interval), or both
 * — in which case whichever is reached first applies (see
 * Service\Maintenance\DueState).
 */
final readonly class NextDue
{
    public function __construct(
        /** Calendar date. */
        public ?DateTimeImmutable $on = null,
        /** Kilometres, canonical decimal. */
        public ?string $km = null,
    ) {
    }

    public function isKnown(): bool
    {
        return $this->on !== null || $this->km !== null;
    }
}
