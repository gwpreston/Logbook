<?php

declare(strict_types=1);

namespace Logbook\Service\Tyre;

use Logbook\Support\Number\Decimal;

/**
 * Distance per tyre from its rolling segments, and cost per distance for a
 * retired one (spec.md §7.17). Pure; no maths in templates or Actions.
 */
final class TyreDistance
{
    private const int KM_SCALE = 3;
    /** Cost per km is small (£0.0049/km): keep enough places to show it per 1,000. */
    private const int COST_SCALE = 6;

    /**
     * The sum of the segments. An open segment runs to $currentKm (the
     * vehicle's current reading; none yet counts as 0). A segment that
     * would be negative counts as 0 and flags the figure; one whose ends are
     * unknown counts as 0.
     *
     * @param list<TyreSegment> $segments
     */
    public static function of(array $segments, ?string $currentKm): TyreDistanceFigure
    {
        $total = Decimal::round('0', self::KM_SCALE);
        $flagged = false;
        foreach ($segments as $segment) {
            $end = $segment->open ? $currentKm : $segment->endKm;
            if ($segment->startKm === null || $end === null) {
                continue;
            }
            $length = Decimal::subtract($end, $segment->startKm);
            if (Decimal::compare($length, '0') < 0) {
                $flagged = true;
                continue;
            }
            $total = Decimal::add($total, $length);
        }

        return new TyreDistanceFigure(Decimal::round($total, self::KM_SCALE), $flagged);
    }

    /**
     * Cost per km of a retired tyre: the fitting's cost split evenly across
     * the tyres it fitted, over the tyre's lifetime distance. None without
     * distance.
     *
     * @param string $cost the linked service record's cost
     * @param int $fitted the number of `on` lines of that change
     */
    public static function costPerKm(string $cost, int $fitted, string $km): ?string
    {
        if ($fitted < 1 || Decimal::compare($km, '0') <= 0) {
            return null;
        }

        return Decimal::divide(Decimal::divide($cost, (string) $fitted, self::COST_SCALE), $km, self::COST_SCALE);
    }
}
