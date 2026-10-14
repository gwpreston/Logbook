<?php

declare(strict_types=1);

namespace Logbook\Support;

use DateTimeImmutable;
use Logbook\Service\Vehicle\VehicleAge;
use Logbook\Support\Date\LocalTime;

/**
 * The date of the input a figure rests on, and what it was projected from
 * (spec.md §8 *Show the working*). One value for every surface: pages,
 * print, CSV, the API and Ask all read the same date from it.
 *
 * Dates are calendar dates in the owner's time zone (midnight UTC, like a
 * valuation's). The date is only shown when it isn't today (see current()).
 */
final readonly class AsOf
{
    private function __construct(
        public AsOfKind $kind,
        /** Calendar date of the input (midnight UTC). */
        public DateTimeImmutable $date,
        /** What an estimate is projected from, e.g. "last 12 months"; null for a plain input. */
        public ?string $basis,
        /** The input is from today (in the owner's time zone), so nothing is shown. */
        public bool $isToday,
        /** Whole days from the date to today; 0 for a date in the future. */
        public int $ageDays,
        /** Whole months from the date to today. */
        public int $ageMonths,
        /** The input is older than its owner's threshold. */
        public bool $stale,
    ) {
    }

    /**
     * @param DateTimeImmutable $date calendar date of the input
     * @param DateTimeImmutable $today calendar date in the owner's time zone
     * @param ?int $staleAfterMonths the owner's threshold for this kind of input; null if it has none
     */
    public static function of(
        AsOfKind $kind,
        DateTimeImmutable $date,
        DateTimeImmutable $today,
        ?string $basis = null,
        ?int $staleAfterMonths = null,
    ): self {
        $past = $date < $today;
        $age = $past ? VehicleAge::between($date, $today) : null;

        return new self(
            $kind,
            $date,
            $basis,
            $date == $today,
            $past ? LocalTime::daysBetween($date, $today) : 0,
            $age === null ? 0 : $age->years * 12 + $age->months,
            $past && $staleAfterMonths !== null && LocalTime::addMonths($date, $staleAfterMonths) < $today,
        );
    }

    /**
     * The oldest of several (spec.md §8, decided 2026-10-13: a figure with
     * more than one dated input shows the oldest). Null for none.
     *
     * @param list<?self> $all
     */
    public static function oldest(array $all): ?self
    {
        $oldest = null;
        foreach ($all as $one) {
            if ($one !== null && ($oldest === null || $one->date < $oldest->date)) {
                $oldest = $one;
            }
        }

        return $oldest;
    }

    /**
     * Whether the date is worth showing: the input isn't from today.
     */
    public function shows(): bool
    {
        return !$this->isToday;
    }

    /**
     * For the API and Ask: the date, its kind, the basis and whether it is stale.
     *
     * @return array{date: string, kind: string, basis: ?string, stale: bool}
     */
    public function toArray(): array
    {
        return [
            'date' => $this->date->format('Y-m-d'),
            'kind' => $this->kind->value,
            'basis' => $this->basis,
            'stale' => $this->stale,
        ];
    }
}
