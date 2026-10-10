<?php

declare(strict_types=1);

namespace Logbook\Service\Notification\Digest;

use DateTimeImmutable;
use Logbook\Support\Money\Money;
use Logbook\Support\Number\Decimal;

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

    /**
     * Without spend or cost per distance (#366): vehicles left with no
     * distance drop out; null when none is left.
     */
    public function withoutAmounts(): ?self
    {
        $lines = [];
        foreach ($this->lines as $line) {
            if ($line->distanceKm !== null) {
                $lines[] = new LastMonthLine($line->vehicle, $line->distanceKm, $line->distanceAverageKm, null);
            }
        }
        if ($lines === []) {
            return null;
        }
        $km = null;
        if (count($lines) > 1) {
            foreach ($lines as $line) {
                $km = Decimal::add($km ?? '0', (string) $line->distanceKm);
            }
        }

        return new self($this->month, $lines, $km, []);
    }

    public function hasFleet(): bool
    {
        return count($this->lines) > 1;
    }
}
