<?php

declare(strict_types=1);

namespace Logbook\Service\Dashboard;

use Logbook\Service\Reminder\ReminderEntry;
use Logbook\Service\Report\CurrencyReport;
use Logbook\Service\Vehicle\VehicleSnapshot;

/**
 * The card pinned under the vehicle filter when one vehicle is selected
 * (spec.md §7.8). It is not a widget: never in the saved layout, never
 * moved or hidden.
 */
final readonly class PinnedVehicle
{
    public function __construct(
        public VehicleSnapshot $snapshot,
        /** Economy over the segments that ended in the last 12 months (null: fuel off or no fills). */
        public ?VehicleEfficiency $economy,
        /** Costs and distance over the last 12 months (null: nothing to report). */
        public ?CurrencyReport $lastTwelveMonths,
        /** The most urgent open reminder (null: nothing, or reminders off). */
        public ?ReminderEntry $next,
    ) {
    }
}
