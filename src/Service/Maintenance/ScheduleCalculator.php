<?php

declare(strict_types=1);

namespace Logbook\Service\Maintenance;

use Logbook\Domain\Maintenance\DonePoint;
use Logbook\Domain\Maintenance\MaintenanceEntry;
use Logbook\Domain\Maintenance\MaintenanceScheduleData;
use Logbook\Domain\Maintenance\NextDue;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Number\Decimal;

/**
 * Where a recurring schedule stands (spec.md §7.4), as pure functions:
 *
 *  - **last done** is the latest entry that completes the schedule (by date,
 *    then odometer); with none yet, the "last done" the owner typed on the
 *    schedule (its baseline).
 *  - **next due** is last done + the months interval (a calendar date,
 *    end-of-month clamped) and/or + the distance interval (an odometer
 *    reading). Each part needs its half of the last-done point: a service
 *    logged without an odometer gives no distance due point.
 *
 * Which of the two comes first is decided against today's date and odometer
 * by DueState.
 */
final class ScheduleCalculator
{
    /**
     * @param list<MaintenanceEntry> $entries entries completing this schedule, any order
     */
    public static function lastDone(MaintenanceScheduleData $schedule, array $entries): DonePoint
    {
        $latest = null;
        foreach ($entries as $entry) {
            if ($latest === null || self::isLater($entry, $latest)) {
                $latest = $entry;
            }
        }

        return $latest === null
            ? new DonePoint($schedule->baselineDoneOn, $schedule->baselineDoneKm)
            : new DonePoint($latest->data->performedOn, $latest->data->odometerKm);
    }

    public static function nextDue(DonePoint $lastDone, ?string $intervalKm, ?int $intervalMonths): NextDue
    {
        $on = $lastDone->on !== null && $intervalMonths !== null && $intervalMonths > 0
            ? LocalTime::addMonths($lastDone->on, $intervalMonths)
            : null;
        $km = $lastDone->km !== null && $intervalKm !== null && Decimal::compare($intervalKm, '0') > 0
            ? Decimal::add($lastDone->km, $intervalKm)
            : null;

        return new NextDue($on, $km);
    }

    private static function isLater(MaintenanceEntry $a, MaintenanceEntry $b): bool
    {
        $byDate = $a->data->performedOn <=> $b->data->performedOn;
        if ($byDate !== 0) {
            return $byDate > 0;
        }
        $byKm = Decimal::compare($a->data->odometerKm ?? '0', $b->data->odometerKm ?? '0');

        return $byKm !== 0 ? $byKm > 0 : $a->id > $b->id;
    }
}
