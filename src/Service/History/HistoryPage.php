<?php

declare(strict_types=1);

namespace Logbook\Service\History;

use Logbook\Support\Date\LocalTime;

/**
 * Lays a History year page out (spec.md §7.16): month subheadings, and
 * back-to-back fill-ups of one vehicle folded into one run. Any other line —
 * another vehicle's fill-up, a service, a reading, a milestone — breaks a
 * run; a single fill-up is not folded. A run sits under the month of its
 * newest fill-up; pages are one year, so a run never crosses a year.
 */
final class HistoryPage
{
    /**
     * @param list<ActivityItem> $items one year, newest first
     * @return list<HistoryMonth> newest first
     */
    public static function months(array $items, bool $fold): array
    {
        $months = [];
        foreach ($fold ? self::fold($items) : $items as $row) {
            $first = $row instanceof FillUpRun ? $row->fills[0] : $row;
            $months[$first->date->format('Y-m')][] = $row;
        }

        $result = [];
        foreach ($months as $key => $rows) {
            $month = LocalTime::parseDate($key . '-01');
            assert($month !== null);
            $result[] = new HistoryMonth($month, $rows);
        }

        return $result;
    }

    /**
     * @param list<ActivityItem> $items newest first
     * @return list<ActivityItem|FillUpRun>
     */
    public static function fold(array $items): array
    {
        $rows = [];
        $run = [];
        foreach ($items as $item) {
            $continues = $item->kind === ActivityKind::Fuel && ($run === [] || $run[0]->vehicle->id === $item->vehicle->id);
            if (!$continues) {
                array_push($rows, ...self::close($run));
                $run = [];
            }
            if ($item->kind === ActivityKind::Fuel) {
                $run[] = $item;
            } else {
                $rows[] = $item;
            }
        }
        array_push($rows, ...self::close($run));

        return $rows;
    }

    /**
     * @param list<ActivityItem> $run back-to-back fill-ups of one vehicle
     * @return list<ActivityItem|FillUpRun>
     */
    private static function close(array $run): array
    {
        return count($run) > 1 ? [new FillUpRun($run)] : $run;
    }
}
