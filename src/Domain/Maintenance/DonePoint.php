<?php

declare(strict_types=1);

namespace Logbook\Domain\Maintenance;

use DateTimeImmutable;

/**
 * When (calendar date) and at what odometer (km) a job was done. Either part
 * may be unknown.
 */
final readonly class DonePoint
{
    public function __construct(
        public ?DateTimeImmutable $on = null,
        public ?string $km = null,
    ) {
    }

    public function isKnown(): bool
    {
        return $this->on !== null || $this->km !== null;
    }
}
