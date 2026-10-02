<?php

declare(strict_types=1);

namespace Logbook\Service\Forecast;

use DateTimeImmutable;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Support\Money\Money;

/**
 * One thing due in the next 12 months (spec.md §7.18): a schedule's next or
 * repeated occurrence, a document renewal, tyres wearing out or ageing, or a
 * manual reminder. Derived on every read, never stored.
 */
final readonly class ForecastItem
{
    public function __construct(
        public Vehicle $vehicle,
        public ForecastSource $source,
        /** The schedule, document, reminder or agreement id; the vehicle's id for tyres. */
        public int $sourceId,
        /** The source's own title; null for an untitled document (named by its type). */
        public ?string $title,
        /** A document's type, a schedule's maintenance category. */
        public ?string $category,
        public string $icon,
        /** Calendar date; null while it cannot be placed on the calendar. */
        public ?DateTimeImmutable $dueOn,
        /** The odometer it falls due at (km), for a distance limit. */
        public ?string $dueKm,
        /** The date is an estimate from the average daily distance. */
        public bool $projected,
        public bool $overdue,
        /** Last time's price; null when not known. */
        public ?Money $cost,
        public string $currency,
        /** 1 for the next occurrence, 2 for the one after it, … */
        public int $occurrence = 1,
        /** A finance item's payments (Phase 29.2). */
        public ?FinanceForecast $finance = null,
    ) {
    }

    public function isRepeat(): bool
    {
        return $this->occurrence > 1;
    }

    public function hasKnownCost(): bool
    {
        return $this->cost !== null;
    }

    /**
     * By date (undated last), then vehicle, then title.
     */
    public static function compare(self $a, self $b): int
    {
        return match (true) {
            $a->dueOn === null && $b->dueOn !== null => 1,
            $a->dueOn !== null && $b->dueOn === null => -1,
            default => 0,
        }
            ?: ($a->dueOn <=> $b->dueOn)
            ?: ((float) ($a->dueKm ?? '0') <=> (float) ($b->dueKm ?? '0'))
            ?: strcmp($a->vehicle->name(), $b->vehicle->name())
            ?: ($a->vehicle->id <=> $b->vehicle->id)
            ?: strcmp($a->source->value, $b->source->value)
            ?: ($a->sourceId <=> $b->sourceId)
            ?: ($a->occurrence <=> $b->occurrence);
    }
}
