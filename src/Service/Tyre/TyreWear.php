<?php

declare(strict_types=1);

namespace Logbook\Service\Tyre;

use DateTimeImmutable;
use Logbook\Support\Number\Decimal;
use Logbook\Support\Units\DepthUnit;

/**
 * The wear estimate (spec.md §7.17), as pure functions over a tyre's
 * measurements: the points are (the tyre's own distance at the change,
 * depth), so time in storage or as the spare adds nothing.
 *
 * - Enough data: at least two points spanning MIN_SPAN_KM of the tyre's
 *   distance; the rate is the least-squares slope of depth over distance,
 *   and must be negative.
 * - Depth now anchors on the latest measurement (not the fitted line) plus
 *   the wear since it.
 * - Distance left, the wear-out odometer and date exist only while the tyre
 *   is wearing (fitted at a road position).
 */
final class TyreWear
{
    public const string MIN_SPAN_KM = '1000';
    /** "Deeper than last time" once a depth is more than this over the previous one. */
    public const string DEEPER_MM = '0.5';
    /** Places for the rate in mm per km (a typical rate is 0.0002). */
    private const int RATE_SCALE = 10;
    private const int KM_SCALE = 3;

    /**
     * @param list<TyreMeasurement> $measurements oldest first
     * @param string $distanceKm the tyre's distance now
     * @param bool $wearing fitted at a road position
     * @param string|null $currentKm the vehicle's current reading
     * @param float|null $kmPerDay average daily distance, for the date
     * @param DateTimeImmutable $today the owner's calendar date
     */
    public static function estimate(
        array $measurements,
        string $distanceKm,
        bool $wearing,
        string $replaceAtMm,
        string $legalMm,
        ?string $currentKm,
        ?float $kmPerDay,
        DateTimeImmutable $today,
    ): TyreWearEstimate {
        $latest = $measurements === [] ? null : $measurements[array_key_last($measurements)];
        if ($latest === null) {
            return new TyreWearEstimate(replaceAtMm: $replaceAtMm);
        }
        $measuredWorn = Decimal::compare($latest->treadMm, $replaceAtMm) <= 0;
        $measuredBelow = Decimal::compare($latest->treadMm, $legalMm) <= 0;
        $rate = $wearing ? self::rate($measurements) : null;
        if ($rate === null) {
            return new TyreWearEstimate(
                latest: $latest,
                replaceAtMm: $replaceAtMm,
                worn: $measuredWorn,
                legal: $measuredBelow ? TyreLegalFlag::Below : null,
            );
        }

        $since = Decimal::subtract($distanceKm, $latest->distanceKm);
        if (Decimal::compare($since, '0') < 0) {
            $since = '0';
        }
        $depthNow = Decimal::subtract($latest->treadMm, Decimal::multiply($rate, $since, DepthUnit::MM_SCALE));
        $margin = Decimal::subtract($depthNow, $replaceAtMm);
        $kmLeft = Decimal::compare($margin, '0') <= 0
            ? Decimal::round('0', self::KM_SCALE)
            : Decimal::divide($margin, $rate, self::KM_SCALE);
        $wearOutOn = null;
        if ($kmPerDay !== null && $kmPerDay > 0) {
            $days = Decimal::compare($kmLeft, '0') <= 0 ? 0 : (int) ceil((float) $kmLeft / $kmPerDay);
            $wearOutOn = $today->modify(sprintf('+%d days', $days));
        }

        return new TyreWearEstimate(
            latest: $latest,
            replaceAtMm: $replaceAtMm,
            ratePer1000Km: Decimal::multiply($rate, '1000', DepthUnit::MM_SCALE),
            depthNowMm: $depthNow,
            kmLeft: $kmLeft,
            wearOutKm: $currentKm === null ? null : Decimal::round(Decimal::add($currentKm, $kmLeft), self::KM_SCALE),
            wearOutOn: $wearOutOn,
            worn: $measuredWorn || Decimal::compare($depthNow, $replaceAtMm) <= 0,
            legal: match (true) {
                $measuredBelow => TyreLegalFlag::Below,
                Decimal::compare($depthNow, $legalMm) <= 0 => TyreLegalFlag::MayBeBelow,
                default => null,
            },
        );
    }

    /**
     * Wear in mm per km (positive), or null when not known yet: fewer than
     * two points, a span under MIN_SPAN_KM, or no measurable wear.
     *
     * @param list<TyreMeasurement> $measurements
     */
    public static function rate(array $measurements): ?string
    {
        if (count($measurements) < 2) {
            return null;
        }
        $xs = array_map(static fn (TyreMeasurement $m): float => (float) $m->distanceKm, $measurements);
        $ys = array_map(static fn (TyreMeasurement $m): float => (float) $m->treadMm, $measurements);
        if (Decimal::compare(Decimal::fromFloat(max($xs) - min($xs), self::KM_SCALE), self::MIN_SPAN_KM) < 0) {
            return null;
        }

        $meanX = array_sum($xs) / count($xs);
        $meanY = array_sum($ys) / count($ys);
        $covariance = 0.0;
        $variance = 0.0;
        foreach ($xs as $i => $x) {
            $covariance += ($x - $meanX) * ($ys[$i] - $meanY);
            $variance += ($x - $meanX) ** 2;
        }
        $slope = Decimal::fromFloat($covariance / $variance, self::RATE_SCALE);
        if (Decimal::compare($slope, '0') >= 0) {
            return null;
        }

        return Decimal::trim(ltrim($slope, '-'));
    }

    /**
     * The measurement a new depth is more than DEEPER_MM deeper than (the
     * one before it), or null.
     *
     * @param list<TyreMeasurement> $measurements oldest first, including the new one
     */
    public static function deeperThan(array $measurements, int $changeId): ?TyreMeasurement
    {
        $previous = null;
        foreach ($measurements as $measurement) {
            if ($measurement->changeId === $changeId) {
                return $previous !== null
                    && Decimal::compare(Decimal::subtract($measurement->treadMm, $previous->treadMm), self::DEEPER_MM) > 0
                    ? $previous
                    : null;
            }
            $previous = $measurement;
        }

        return null;
    }
}
