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
 * Pure and exact (Decimal, and integers where the figures are always at
 * the same scale), so the band edges are unit-tested.
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
        // Consumption of the checkable segments so far, in millionths: always
        // exactly SCALE places (per100Km()), so the integers order and add
        // as the strings do, a few times faster (Phase 54.5, K149).
        $earlier = [];
        $checks = [];

        foreach ($measured as $fill) {
            $segment = $fill->segment;
            if ($segment === null || !self::isCheckable($segment)) {
                continue;
            }
            $consumption = self::consumption($segment);
            $scaled = Decimal::toScaledInt($consumption, self::SCALE);
            $baseline = count($earlier) >= self::MIN_BASELINE_SEGMENTS
                ? self::scaledMedian(array_slice($earlier, -self::BASELINE_SEGMENTS))
                : null;
            $earlier[] = $scaled;
            $confirmed = $fill->entry->economyConfirmed;
            $verdict = $baseline === null
                ? EconomyVerdict::NotChecked
                : self::scaledVerdict($kind, $scaled, $baseline)
                    ?? self::verdict($kind, $consumption, Decimal::fromScaledInt($baseline, self::SCALE + 1));
            $baseline = $baseline === null ? null : Decimal::fromScaledInt($baseline, self::SCALE + 1);

            $checks[] = new SegmentCheck(
                fill: $fill,
                verdict: $verdict,
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
     * The verdict in integers: consumption (SCALE places) against the
     * baseline (SCALE + 1) × the band (2), exactly as verdict(); null when
     * the figures are too large for that (verdict() then decides).
     */
    private static function scaledVerdict(EnergyKind $kind, int $consumption, int $baseline): ?EconomyVerdict
    {
        $band = self::BANDS[$kind->value];
        $more = Decimal::toScaledInt($band['more'], 2);
        $less = Decimal::toScaledInt($band['less'], 2);
        $limit = intdiv(PHP_INT_MAX, 1000);
        if (abs($consumption) > $limit || abs($baseline) > intdiv($limit, max(abs($more), abs($less), 1))) {
            return null;
        }

        // Both sides at SCALE + 3 places.
        return match (true) {
            $consumption * 1000 >= $baseline * $more => EconomyVerdict::More,
            $consumption * 1000 <= $baseline * $less => EconomyVerdict::Less,
            default => EconomyVerdict::Normal,
        };
    }

    /**
     * The median, one place more than the segments (SCALE + 1), so the
     * mean of two middle ones is exact.
     *
     * @param list<int> $values at SCALE places, at least one
     */
    private static function scaledMedian(array $values): int
    {
        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 1 ? $values[$middle] * 10 : ($values[$middle - 1] + $values[$middle]) * 5;
    }

    private static function per100Km(string $volume, string $distanceKm): string
    {
        return Decimal::divide(Decimal::multiply($volume, '100', 3), $distanceKm, self::SCALE);
    }
}
