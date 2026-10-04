<?php

declare(strict_types=1);

namespace Logbook\Service\Fuel;

use Logbook\Domain\Fuel\EnergyKind;
use Logbook\Support\Number\Decimal;

/**
 * Points out the full-to-full segments whose economy does not look right
 * (spec.md §7.3), before they quietly bend every figure built on them.
 *
 * Each checkable segment (at least MIN_DISTANCE_KM long) is compared, in
 * canonical consumption (litres or kWh per 100 km, never mpg: that is the
 * inverse of fuel used), with the median of the up-to-BASELINE_SEGMENTS
 * checkable segments of the same series that ended before it; with fewer
 * than MIN_BASELINE_SEGMENTS it is not checked. Later segments are never
 * used, so a verdict only changes when that segment or an earlier one is
 * edited. A flag followed by the opposite flag on the segment opening at
 * its closing fill-up is a pair: that shared fill-up is probably the mistake.
 *
 * Derived on every read from FuelEconomy's segments; it changes no figure.
 * Pure and exact (Decimal throughout), so the band edges are unit-tested.
 */
final class EconomyCheck
{
    public const string MIN_DISTANCE_KM = '100';
    public const int BASELINE_SEGMENTS = 10;
    public const int MIN_BASELINE_SEGMENTS = 5;
    public const int SCALE = 6;

    /** @var array<string, array{more: string, less: string}> by EnergyKind value */
    private const array BANDS = [
        'liquid' => ['more' => '1.25', 'less' => '0.80'],
        'electric' => ['more' => '1.35', 'less' => '0.74'],
        // CNG (Phase 31) varies like liquid fuel: the same band.
        'gas' => ['more' => '1.25', 'less' => '0.80'],
    ];

    public static function of(FuelHistory $history): EconomyChecks
    {
        $byId = [];
        foreach (EnergyKind::cases() as $kind) {
            foreach (self::series($history->measured($kind), $kind) as $check) {
                $byId[$check->entry()->id] = $check;
            }
        }

        // Both series back in the order the fill-ups happened.
        $checks = [];
        foreach ($history->fills as $fill) {
            if (isset($byId[$fill->entry->id])) {
                $checks[$fill->entry->id] = $byId[$fill->entry->id];
            }
        }

        return new EconomyChecks($checks);
    }

    /**
     * Canonical consumption of a segment: volume × 100 ÷ distance, 6 places.
     */
    public static function consumption(EconomySegment $segment): string
    {
        return self::per100Km($segment->volume, $segment->distanceKm);
    }

    public static function isCheckable(EconomySegment $segment): bool
    {
        return Decimal::compare($segment->distanceKm, self::MIN_DISTANCE_KM) >= 0;
    }

    /**
     * @param list<FillEconomy> $measured one kind, oldest first
     * @return list<SegmentCheck>
     */
    private static function series(array $measured, EnergyKind $kind): array
    {
        $earlier = [];   // consumption of the checkable segments so far
        $checks = [];

        foreach ($measured as $fill) {
            $segment = $fill->segment;
            if ($segment === null || !self::isCheckable($segment)) {
                continue;
            }
            $consumption = self::consumption($segment);
            $baseline = count($earlier) >= self::MIN_BASELINE_SEGMENTS
                ? self::median(array_slice($earlier, -self::BASELINE_SEGMENTS))
                : null;
            $earlier[] = $consumption;
            $confirmed = $fill->entry->economyConfirmed;

            $checks[] = new SegmentCheck(
                fill: $fill,
                verdict: $baseline === null ? EconomyVerdict::NotChecked : self::verdict($kind, $consumption, $baseline),
                consumption: $consumption,
                baseline: $baseline,
                ratio: $baseline === null ? null : Decimal::divide($consumption, $baseline, self::SCALE),
                confirmed: $confirmed !== null && Decimal::compare($confirmed, $consumption) === 0,
            );
        }

        // Pairs, left to right: a flag, then the opposite flag on the segment
        // that opens at its closing fill-up.
        for ($i = 0; $i + 1 < count($checks); $i++) {
            $first = $checks[$i];
            $next = $checks[$i + 1];
            $a = $first->fill->segment;
            $b = $next->fill->segment;
            if (
                !$first->isFlagged() || !$next->isFlagged()
                || $first->verdict === $next->verdict
                || $a === null || $b === null || $first->baseline === null
                || $b->opening?->id !== $first->entry()->id
            ) {
                continue;
            }
            $together = self::per100Km(Decimal::add($a->volume, $b->volume), Decimal::add($a->distanceKm, $b->distanceKm));
            $normal = self::verdict($kind, $together, $first->baseline) === EconomyVerdict::Normal;

            $checks[$i] = $first->withPair($first->entry(), $normal);
            $checks[$i + 1] = $next->withPair($first->entry(), $normal);
            $i++;
        }

        return array_values($checks);
    }

    /**
     * Exact band test: consumption against baseline × threshold, no rounded ratio.
     */
    private static function verdict(EnergyKind $kind, string $consumption, string $baseline): EconomyVerdict
    {
        $band = self::BANDS[$kind->value];

        return match (true) {
            Decimal::compare($consumption, Decimal::multiply($baseline, $band['more'], 12)) >= 0 => EconomyVerdict::More,
            Decimal::compare($consumption, Decimal::multiply($baseline, $band['less'], 12)) <= 0 => EconomyVerdict::Less,
            default => EconomyVerdict::Normal,
        };
    }

    /**
     * @param list<string> $values at least one
     */
    private static function median(array $values): string
    {
        usort($values, Decimal::compare(...));
        $count = count($values);
        $middle = intdiv($count, 2);

        // One place more than the segments: the mean of two middle ones is exact.
        return $count % 2 === 1
            ? Decimal::round($values[$middle], self::SCALE + 1)
            : Decimal::divide(Decimal::add($values[$middle - 1], $values[$middle]), '2', self::SCALE + 1);
    }

    private static function per100Km(string $volume, string $distanceKm): string
    {
        return Decimal::divide(Decimal::multiply($volume, '100', 3), $distanceKm, self::SCALE);
    }
}
