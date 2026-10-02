<?php

declare(strict_types=1);

namespace Logbook\Service\Forecast;

use DateTimeImmutable;
use Logbook\Domain\Compliance\ComplianceDocument;
use Logbook\Domain\Reminder\Reminder;
use Logbook\Domain\Vehicle\Vehicle;

/**
 * Everything one active vehicle's forecast is built from, already loaded
 * (ComingUp) and already filtered for switched-off modules.
 */
final readonly class VehicleSources
{
    /**
     * @param list<ScheduleDue> $schedules
     * @param list<ComplianceDocument> $documents current documents (none replaced)
     * @param list<TyreDue> $tyres
     * @param list<Reminder> $reminders open manual reminders
     */
    public function __construct(
        public Vehicle $vehicle,
        public string $currency,
        public array $schedules = [],
        public array $documents = [],
        public array $tyres = [],
        public array $reminders = [],
        /** Latest odometer reading, km. */
        public ?string $currentKm = null,
        /** Average daily distance (§7.4's projection); null under a week of history. */
        public ?float $kmPerDay = null,
        /** Null with `fuel` off: no estimate at all. */
        public ?FuelRate $fuel = null,
        /** False when the user may not see this vehicle's costs: no amounts (spec.md §5 Costs). */
        public bool $costs = true,
        /** The *First MOT due* date while it counts (FirstInspection::pending()); null with `compliance` off. */
        public ?DateTimeImmutable $firstInspection = null,
        /** The active agreement's payments due; null without one, with `finance` off or without costs. */
        public ?FinanceDue $finance = null,
    ) {
    }
}
