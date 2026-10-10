<?php

declare(strict_types=1);

namespace Logbook\Service\Notification\Digest;

use DateTimeImmutable;
use Logbook\Support\Money\Money;

/**
 * The digest's *Last month* section (spec.md §7.11 *The monthly
 * briefing*): a line per vehicle and, with two or more, the fleet's
 * distance and its spend per currency (never converted).
 */
final readonly class LastMonth
{
    /**
     * @param non-empty-list<LastMonthLine> $lines
     * @param array<string, Money> $fleetSpend currency → the month's spend, with two or more lines
     */
    public function __construct(
        /** The month's first day (a calendar date). */
        public DateTimeImmutable $month,
        public array $lines,
        /** Kilometres summed, or null with fewer than two lines or no distance. */
        public ?string $fleetDistanceKm,
        public array $fleetSpend,
    ) {
    }

    public function hasFleet(): bool
    {
        return count($this->lines) > 1;
    }
}
