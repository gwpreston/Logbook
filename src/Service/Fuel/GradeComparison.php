<?php

declare(strict_types=1);

namespace Logbook\Service\Fuel;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Fuel\EnergyKind;
use Logbook\Domain\Fuel\Fuel;
use Logbook\Domain\Fuel\FuelEntry;
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Domain\Fuel\GradeVerdict;
use Logbook\Domain\Fuel\GradeVerdictStatus;
use Logbook\Support\Number\Decimal;

/**
 * The grade verdict (spec.md §7.3): per liquid family, how much more or
 * less each grade costs per distance than the vehicle's usual (reference)
 * grade. Cost ratio = price premium × economy ratio, where
 *
 *  - the economy ratio is the existing *Economy by grade* figures (fuel used,
 *    L/100 km) of the two grades, read from the breakdown, not recomputed;
 *  - the price premium is the median price ratio of fills of the grade each
 *    paired with the nearest reference fill within PAIR_WINDOW_DAYS calendar
 *    days in the owner's time zone. All-time average prices are never used:
 *    they measure fuel price inflation as much as grade.
 *
 * Pure: no I/O; "now" and the time zone are passed in.
 */
final class GradeComparison
{
    /** A reference fill this many calendar days either side (or fewer) pairs. */
    public const int PAIR_WINDOW_DAYS = 30;

    /** Pairs needed before a price premium is given. */
    public const int MIN_PAIRS = 3;

    /** The reference grade is the most used over this period, up to today. */
    public const string REFERENCE_PERIOD = '-12 months';

    /** Families a verdict is given for (electricity has cost per distance by type instead). */
    private const array FAMILIES = [Fuel::Petrol, Fuel::Diesel];

    /**
     * @return list<GradeVerdict> per family, a SingleGrade verdict when it has one grade, else one per other grade
     */
    public static function verdicts(
        FuelHistory $history,
        GradeBreakdown $breakdown,
        DateTimeImmutable $now,
        DateTimeZone $timeZone,
    ): array {
        if ($breakdown->kind !== EnergyKind::Liquid) {
            return [];
        }

        $verdicts = [];
        foreach (self::FAMILIES as $family) {
            $fills = array_values(array_filter(
                array_map(static fn (FillEconomy $f): FuelEntry => $f->entry, $history->ofKind(EnergyKind::Liquid)),
                static fn (FuelEntry $e): bool => $e->data->grade !== null && $e->data->grade->family() === $family,
            ));
            $reference = self::reference($fills, $now);
            if ($reference === null) {
                continue;
            }

            $others = [];
            foreach ($breakdown->rows as $row) {
                if ($row->grade !== null && $row->grade !== $reference && $row->grade->family() === $family) {
                    $others[] = $row->grade;
                }
            }
            if ($others === []) {
                $verdicts[] = new GradeVerdict($reference, $reference, GradeVerdictStatus::SingleGrade);
                continue;
            }

            foreach ($others as $grade) {
                $verdicts[] = self::verdict($grade, $reference, $fills, $breakdown, $timeZone);
            }
        }

        return $verdicts;
    }

    /**
     * The family's most-used grade by volume over REFERENCE_PERIOD up to
     * $now (over all time when none falls in it); ties go to the grade used
     * most recently.
     *
     * @param list<FuelEntry> $fills graded fills of one family, oldest first
     */
    public static function reference(array $fills, DateTimeImmutable $now): ?FuelGrade
    {
        $since = $now->modify(self::REFERENCE_PERIOD);
        $recent = array_values(array_filter(
            $fills,
            static fn (FuelEntry $e): bool => $e->data->filledAt >= $since && $e->data->filledAt <= $now,
        ));

        /** @var array<string, array{grade: FuelGrade, volume: string, last: int}> $used */
        $used = [];
        foreach ($recent !== [] ? $recent : $fills as $position => $entry) {
            $grade = $entry->data->grade;
            if ($grade === null) {
                continue;
            }
            $used[$grade->value] ??= ['grade' => $grade, 'volume' => '0', 'last' => 0];
            $used[$grade->value]['volume'] = Decimal::add($used[$grade->value]['volume'], $entry->data->volume);
            $used[$grade->value]['last'] = $position;
        }
        if ($used === []) {
            return null;
        }

        usort(
            $used,
            static fn (array $a, array $b): int => Decimal::compare($b['volume'], $a['volume']) ?: $b['last'] <=> $a['last'],
        );

        return $used[0]['grade'];
    }

    /**
     * Each fill of $grade with the nearest $reference fill within
     * PAIR_WINDOW_DAYS calendar days (owner's time zone; a reference fill may
     * pair more than once; nearest in time on a tie of days): the ratio of
     * their prices per litre, 6 places. A pair with a price of zero on
     * either side gives no ratio.
     *
     * @param list<FuelEntry> $fills graded fills of one family, oldest first
     * @return list<string>
     */
    public static function priceRatios(array $fills, FuelGrade $grade, FuelGrade $reference, DateTimeZone $timeZone): array
    {
        $references = array_values(array_filter($fills, static fn (FuelEntry $e): bool => $e->data->grade === $reference));
        $ratios = [];
        foreach ($fills as $entry) {
            if ($entry->data->grade !== $grade) {
                continue;
            }
            $day = self::day($entry->data->filledAt, $timeZone);
            $best = null;
            $bestKey = null;
            foreach ($references as $candidate) {
                $days = abs(self::day($candidate->data->filledAt, $timeZone) - $day);
                if ($days > self::PAIR_WINDOW_DAYS) {
                    continue;
                }
                $key = [$days, abs($candidate->data->filledAt->getTimestamp() - $entry->data->filledAt->getTimestamp())];
                if ($bestKey === null || $key < $bestKey) {
                    $best = $candidate;
                    $bestKey = $key;
                }
            }
            if (
                $best === null
                || Decimal::compare($best->data->pricePerUnit, '0') <= 0
                || Decimal::compare($entry->data->pricePerUnit, '0') <= 0
            ) {
                continue;
            }
            $ratios[] = Decimal::divide($entry->data->pricePerUnit, $best->data->pricePerUnit, 6);
        }

        return $ratios;
    }

    /**
     * @param list<string> $values non-empty
     */
    public static function median(array $values): string
    {
        usort($values, Decimal::compare(...));
        $count = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 1
            ? $values[$middle]
            : Decimal::divide(Decimal::add($values[$middle - 1], $values[$middle]), '2', 6);
    }

    /**
     * @param list<FuelEntry> $fills
     */
    private static function verdict(
        FuelGrade $grade,
        FuelGrade $reference,
        array $fills,
        GradeBreakdown $breakdown,
        DateTimeZone $timeZone,
    ): GradeVerdict {
        $gradeRow = $breakdown->row($grade);
        $referenceRow = $breakdown->row($reference);
        $economy = null;
        if ($gradeRow !== null && $referenceRow !== null && $gradeRow->hasEconomy() && $referenceRow->hasEconomy()) {
            $economy = Decimal::divide(self::per100Km($gradeRow), self::per100Km($referenceRow), 6);
        }

        $ratios = self::priceRatios($fills, $grade, $reference, $timeZone);
        $premium = count($ratios) >= self::MIN_PAIRS ? self::median($ratios) : null;

        $status = match (true) {
            $economy === null => GradeVerdictStatus::NotEnoughEconomy,
            $premium === null => GradeVerdictStatus::NotEnoughPricePairs,
            default => GradeVerdictStatus::Ok,
        };

        return new GradeVerdict(
            grade: $grade,
            reference: $reference,
            status: $status,
            pricePremium: $premium,
            economyRatio: $economy,
            costRatio: $economy !== null && $premium !== null ? Decimal::multiply($premium, $economy, 6) : null,
            gradeSegments: $gradeRow->segments ?? 0,
            referenceSegments: $referenceRow->segments ?? 0,
            pairs: count($ratios),
        );
    }

    private static function per100Km(GradeSummary $row): string
    {
        return Decimal::divide(Decimal::multiply($row->measuredVolume, '100', 6), $row->measuredDistanceKm, 7);
    }

    /**
     * Days since the epoch of the calendar date the instant falls on in the
     * owner's time zone (so two dates compare as the owner sees them).
     */
    private static function day(DateTimeImmutable $at, DateTimeZone $timeZone): int
    {
        $date = new DateTimeImmutable($at->setTimezone($timeZone)->format('Y-m-d'), new DateTimeZone('UTC'));

        return intdiv($date->getTimestamp(), 86400);
    }
}
