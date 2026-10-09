<?php

declare(strict_types=1);

namespace Logbook\Service\Attention;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Fuel\EnergyKind;
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Service\Fuel\EconomyCheck;
use Logbook\Service\Fuel\EconomySegment;
use Logbook\Service\Fuel\FillEconomy;
use Logbook\Service\Fuel\FuelHistory;
use Logbook\Service\Fuel\SeasonalEconomy;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Number\Decimal;

/**
 * Economy drift (spec.md §7.24 item 7): the recent tanks of one series
 * against the vehicle's 12-month baseline, and, once a year of data exists,
 * against the same months a year earlier, so a normal winter stays quiet.
 *
 * Only checkable segments count (EconomyCheck::isCheckable). *Recent* is
 * the last RECENT segments that ended within RECENT_DAYS (at least
 * MIN_RECENT); *baseline* the segments that ended in the 12 months before
 * the first recent one ended (at least MIN_BASELINE). Both are weighted as
 * the averages are (total volume × 100 ÷ total distance) and compared
 * exactly. judge() gives both outcomes of the one test (Phase 42): worse
 * for *Needs attention*, better for the *Economy up* insight (spec.md §7.8),
 * so the two can never disagree; of() keeps only the worse.
 *
 * Pure: "now", the time zone and the facts from other modules are passed in.
 */
final class EconomyDrift
{
    public const int RECENT = 5;
    public const int MIN_RECENT = 3;
    public const int RECENT_DAYS = 120;
    public const int MIN_BASELINE = 8;
    public const int BASELINE_MONTHS = 12;
    public const int MIN_LAST_YEAR = 3;
    /** Months whose segments read as winter. */
    public const array WINTER = [11, 12, 1, 2];

    /**
     * @param int $percent flagged at least this much worse
     * @param (Closure(): list<DateTimeImmutable>)|null $tyreFits when tyres were fitted (calendar dates;
     *        tyres on), asked only for a finding
     * @param bool $serviceOverdue a service schedule is overdue (maintenance on)
     */
    public static function of(
        FuelHistory $history,
        EnergyKind $kind,
        int $percent,
        DateTimeImmutable $now,
        DateTimeZone $zone,
        ?Closure $tyreFits = null,
        bool $serviceOverdue = false,
    ): ?DriftFinding {
        $finding = self::judge($history, $kind, $percent, $now, $zone, $tyreFits, $serviceOverdue);

        return $finding === null || $finding->improved ? null : $finding;
    }

    /**
     * The drift test with both outcomes: the recent tanks at least $percent
     * worse than the baseline (and than last year's months, when there
     * are enough), or at least $percent better by the same test with the
     * sign flipped (baseline ≥ recent × (1 + percent), in distance per unit
     * the recent figure at least that much higher). Null when neither.
     *
     * @param (Closure(): list<DateTimeImmutable>)|null $tyreFits
     */
    public static function judge(
        FuelHistory $history,
        EnergyKind $kind,
        int $percent,
        DateTimeImmutable $now,
        DateTimeZone $zone,
        ?Closure $tyreFits = null,
        bool $serviceOverdue = false,
    ): ?DriftFinding {
        /** @var list<FillEconomy> $fills closing fill-ups of checkable segments, oldest first */
        $fills = array_values(array_filter(
            $history->measured($kind),
            static fn (FillEconomy $f): bool => $f->segment !== null && EconomyCheck::isCheckable($f->segment),
        ));
        $cutoff = $now->modify('-' . self::RECENT_DAYS . ' days');
        $recent = array_values(array_filter(
            array_slice($fills, -self::RECENT),
            static fn (FillEconomy $f): bool => $f->segment !== null && $f->segment->endedAt >= $cutoff,
        ));
        if (count($recent) < self::MIN_RECENT) {
            return null;
        }

        $first = self::segment($recent[0]);
        $from = $first->endedAt->modify('-' . self::BASELINE_MONTHS . ' months');
        $baseline = array_values(array_filter(
            $fills,
            static fn (FillEconomy $f): bool => self::segment($f)->endedAt < $first->endedAt
                && self::segment($f)->endedAt >= $from,
        ));
        if (count($baseline) < self::MIN_BASELINE) {
            return null;
        }

        [$recentKm, $recentVolume] = self::totals($recent);
        [$baseKm, $baseVolume] = self::totals($baseline);
        $factor = Decimal::divide((string) (100 + $percent), '100', 2);
        $recentFigure = self::per100Km($recentVolume, $recentKm);
        $baseFigure = self::per100Km($baseVolume, $baseKm);
        if (self::isWorse($recentFigure, $baseFigure, $factor)) {
            $improved = false;
        } elseif (self::isWorse($baseFigure, $recentFigure, $factor)) {
            $improved = true;
        } else {
            return null;
        }

        $lastYear = self::lastYear($fills, $recent, $zone);
        if (
            $lastYear !== null
            && !($improved ? self::isWorse($lastYear, $recentFigure, $factor) : self::isWorse($recentFigure, $lastYear, $factor))
        ) {
            return null;
        }

        $gradeTo = self::commonGrade($recent);
        $gradeFrom = self::commonGrade($baseline);
        $differ = $gradeTo !== null && $gradeFrom !== null && $gradeTo !== $gradeFrom;

        return new DriftFinding(
            kind: $kind,
            tanks: count($recent),
            recentDistanceKm: $recentKm,
            recentVolume: $recentVolume,
            baselineDistanceKm: $baseKm,
            baselineVolume: $baseVolume,
            closingIds: array_map(static fn (FillEconomy $f): int => $f->entry->id, $recent),
            seasonChecked: $lastYear !== null,
            gradeFrom: $differ ? $gradeFrom : null,
            gradeTo: $differ ? $gradeTo : null,
            tyresFittedOn: $tyreFits === null ? null : self::tyresFitted($recent, $tyreFits(), $zone),
            // Winter, an overdue service and short tanks only explain a fall.
            winter: !$improved && self::allWinter($recent, $zone) && !self::allWinter($baseline, $zone),
            serviceOverdue: !$improved && $serviceOverdue,
            shortTanks: !$improved && self::shortTanks($recent, $baseline, $recentKm),
            improved: $improved,
            longTanks: $improved && self::longTanks($recent, $baseline, $recentKm),
        );
    }

    /**
     * Canonical consumption over a set: volume × 100 ÷ distance, 6 places.
     */
    public static function per100Km(string $volume, string $distanceKm): string
    {
        return Decimal::divide(Decimal::multiply($volume, '100', 6), $distanceKm, EconomyCheck::SCALE);
    }

    private static function isWorse(string $figure, string $against, string $factor): bool
    {
        return Decimal::compare($figure, Decimal::multiply($against, $factor, EconomyCheck::SCALE + 2)) >= 0;
    }

    /**
     * The same calendar months a year earlier (the months the recent
     * segments span, in the owner's time zone): their figure through the
     * month split, or null when fewer than MIN_LAST_YEAR segments fall in
     * them.
     *
     * @param list<FillEconomy> $fills
     * @param list<FillEconomy> $recent
     */
    private static function lastYear(array $fills, array $recent, DateTimeZone $zone): ?string
    {
        $months = [];
        foreach ($recent as $fill) {
            foreach (self::split(self::segment($fill), $zone) as [$year, $month]) {
                $months[($year - 1) . '-' . $month] = true;
            }
        }

        $count = 0;
        $volume = '0';
        $distance = '0';
        foreach ($fills as $fill) {
            $segment = self::segment($fill);
            $inside = false;
            foreach (self::split($segment, $zone) as [$year, $month, $seconds, $total]) {
                if (!isset($months[$year . '-' . $month])) {
                    continue;
                }
                $inside = true;
                $volume = Decimal::add($volume, self::share($segment->volume, $seconds, $total));
                $distance = Decimal::add($distance, self::share($segment->distanceKm, $seconds, $total));
            }
            $count += $inside ? 1 : 0;
        }

        return $count >= self::MIN_LAST_YEAR && Decimal::compare($distance, '0') > 0
            ? self::per100Km($volume, $distance)
            : null;
    }

    /**
     * @return list<array{0: int, 1: int, 2: int, 3: int}>
     */
    private static function split(EconomySegment $segment, DateTimeZone $zone): array
    {
        return $segment->opening === null
            ? []
            : SeasonalEconomy::split($segment->opening->data->filledAt, $segment->endedAt, $zone);
    }

    private static function share(string $amount, int $seconds, int $total): string
    {
        return $seconds === $total
            ? $amount
            : Decimal::divide(Decimal::multiply($amount, (string) $seconds, 6), (string) $total, 6);
    }

    /**
     * @param list<FillEconomy> $fills
     * @return array{0: string, 1: string} distance, volume
     */
    private static function totals(array $fills): array
    {
        $distance = '0';
        $volume = '0';
        foreach ($fills as $fill) {
            $distance = Decimal::add($distance, self::segment($fill)->distanceKm);
            $volume = Decimal::add($volume, self::segment($fill)->volume);
        }

        return [$distance, $volume];
    }

    /**
     * The grade most of the segments burned; ties go to the latest. Null
     * when none has a single grade.
     *
     * @param list<FillEconomy> $fills
     */
    private static function commonGrade(array $fills): ?FuelGrade
    {
        $counts = [];
        $best = null;
        // Oldest first, so ">=" hands a tie to the later grade.
        foreach ($fills as $fill) {
            $grade = self::segment($fill)->grade;
            if ($grade === null) {
                continue;
            }
            $counts[$grade->value] = ($counts[$grade->value] ?? 0) + 1;
            if ($best === null || $counts[$grade->value] >= ($counts[$best->value] ?? 0)) {
                $best = $grade;
            }
        }

        return $best;
    }

    /**
     * The latest tyre fitting from the first recent segment's opening
     * fill-up to the last one's close.
     *
     * @param non-empty-list<FillEconomy> $recent
     * @param list<DateTimeImmutable> $fits calendar dates (midnight UTC)
     */
    private static function tyresFitted(array $recent, array $fits, DateTimeZone $zone): ?DateTimeImmutable
    {
        $first = self::segment($recent[0]);
        $start = LocalTime::dateOf($first->opening->data->filledAt ?? $first->endedAt, $zone)->format('Y-m-d');
        $end = LocalTime::dateOf(self::segment($recent[count($recent) - 1])->endedAt, $zone)->format('Y-m-d');
        $found = null;
        foreach ($fits as $fit) {
            $day = $fit->format('Y-m-d');
            if ($day >= $start && $day <= $end && ($found === null || $fit > $found)) {
                $found = $fit;
            }
        }

        return $found;
    }

    /**
     * @param list<FillEconomy> $fills
     */
    private static function allWinter(array $fills, DateTimeZone $zone): bool
    {
        foreach ($fills as $fill) {
            $month = (int) self::segment($fill)->endedAt->setTimezone($zone)->format('n');
            if (!in_array($month, self::WINTER, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The recent mean distance under half the baseline median.
     *
     * @param list<FillEconomy> $recent
     * @param non-empty-list<FillEconomy> $baseline
     */
    private static function shortTanks(array $recent, array $baseline, string $recentKm): bool
    {
        $mean = Decimal::divide($recentKm, (string) count($recent), 6);
        $median = Outlier::median(array_map(static fn (FillEconomy $f): string => self::segment($f)->distanceKm, $baseline));

        return Decimal::compare(Decimal::multiply($mean, '2', 6), $median) < 0;
    }

    /**
     * The recent mean distance over twice the baseline median (Phase 42).
     *
     * @param list<FillEconomy> $recent
     * @param non-empty-list<FillEconomy> $baseline
     */
    private static function longTanks(array $recent, array $baseline, string $recentKm): bool
    {
        $mean = Decimal::divide($recentKm, (string) count($recent), 6);
        $median = Outlier::median(array_map(static fn (FillEconomy $f): string => self::segment($f)->distanceKm, $baseline));

        return Decimal::compare($mean, Decimal::multiply($median, '2', 6)) > 0;
    }

    private static function segment(FillEconomy $fill): EconomySegment
    {
        // Only fills with a segment reach here (filtered above).
        return $fill->segment ?? throw new \LogicException('A drift fill-up without a segment.');
    }
}
