<?php

declare(strict_types=1);

namespace Logbook\Service\Incident;

use DateTimeImmutable;
use Logbook\Domain\Incident\Fault;

/**
 * The claims history's filters (spec.md §7.29 *Claims history*): the last
 * 3, 5 (default) or 10 years, or a date range; a vehicle; a driver; claims
 * only or every incident; a fault.
 */
final readonly class ClaimsFilter
{
    public const array YEARS = [3, 5, 10];
    public const int DEFAULT_YEARS = 5;

    public function __construct(
        /** One of YEARS; ignored when a range is given. */
        public int $years = self::DEFAULT_YEARS,
        /** Calendar dates, both inclusive. */
        public ?DateTimeImmutable $from = null,
        public ?DateTimeImmutable $until = null,
        public ?int $vehicleId = null,
        /** A user's id, or a typed driver's name ("name:Sam"). */
        public ?string $driver = null,
        public bool $claimsOnly = false,
        public ?Fault $fault = null,
    ) {
    }

    /**
     * The first day counted: the range's start, else the same day $years
     * before today, by calendar date.
     */
    public function start(DateTimeImmutable $today): ?DateTimeImmutable
    {
        if ($this->from !== null || $this->until !== null) {
            return $this->from;
        }

        return $today->modify(sprintf('-%d years', $this->years));
    }

    public function end(DateTimeImmutable $today): DateTimeImmutable
    {
        return $this->from !== null || $this->until !== null ? ($this->until ?? $today) : $today;
    }

    public function isRange(): bool
    {
        return $this->from !== null || $this->until !== null;
    }
}
