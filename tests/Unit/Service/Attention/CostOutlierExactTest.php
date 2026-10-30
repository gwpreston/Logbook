<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Attention;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Maintenance\MaintenanceCategory;
use Logbook\Domain\Maintenance\MaintenanceEntry;
use Logbook\Domain\Maintenance\MaintenanceEntryData;
use Logbook\Service\Attention\CostFinding;
use Logbook\Service\Attention\CostOutlier;
use Logbook\Service\Attention\Outlier;
use Logbook\Support\Number\Decimal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The cost check in integers (Phase 54.5, K149) finds exactly what the
 * check on decimal strings found: the same records, medians, ratios and
 * digit slips, for costs of any number of places, free records, and the
 * owner's multiple and floor.
 */
final class CostOutlierExactTest extends TestCase
{
    /**
     * @return iterable<string, array{int, int, int}>
     */
    public static function runs(): iterable
    {
        foreach ([[2, 0], [3, 100], [5, 20], [2, 500]] as [$multiple, $floor]) {
            foreach ([1, 2] as $seed) {
                yield "×$multiple, +$floor, run $seed" => [$multiple, $floor, $seed];
            }
        }
    }

    #[DataProvider('runs')]
    public function testTheFindingsAreTheSameAsWithDecimalStrings(int $multiple, int $floor, int $seed): void
    {
        mt_srand($seed);
        $records = [];
        $start = new DateTimeImmutable('2015-01-10', new DateTimeZone('UTC'));
        $categories = [MaintenanceCategory::Service, MaintenanceCategory::Oil, MaintenanceCategory::Tyres];
        for ($i = 0; $i < 200; $i++) {
            $places = mt_rand(0, 6);
            $cost = number_format(mt_rand(20, 400) + mt_rand(0, 999999) / 1e6, $places, '.', '');
            $cost = match (mt_rand(0, 14)) {
                0 => Decimal::multiply($cost, '10', $places),
                1 => Decimal::multiply($cost, (string) $multiple, $places),
                2 => '0',
                default => $cost,
            };
            $on = $start->modify(sprintf('+%d days', 20 * $i));
            $category = $categories[mt_rand(0, 2)];
            $records[] = new MaintenanceEntry(500 - $i, 1, new MaintenanceEntryData($on, $category, 'Work', $cost), $on, $on);
        }
        // The owner's 12 months cover the last 80 records.
        $since = $start->modify(sprintf('+%d days', 20 * 120));

        $findings = CostOutlier::of($records, $since, $multiple, $floor);

        self::assertEquals(self::reference($records, $since, $multiple, $floor), $findings);
        self::assertNotSame([], $findings, 'the run has findings to compare');
    }

    public function testACostWithMorePlacesThanStoredIsJudgedOnItsStrings(): void
    {
        $on = new DateTimeImmutable('2026-01-01', new DateTimeZone('UTC'));
        $records = [];
        foreach (['100.0000001', '100.0000002', '100.0000003', '100.0000004', '900.00000005'] as $i => $cost) {
            $day = $on->modify("+$i days");
            $data = new MaintenanceEntryData($day, MaintenanceCategory::Service, 'Work', $cost);
            $records[] = new MaintenanceEntry($i + 1, 1, $data, $day, $day);
        }

        $findings = CostOutlier::of($records, $on, 3, 100);

        self::assertEquals(self::reference($records, $on, 3, 100), $findings);
        self::assertCount(1, $findings);
    }

    public function testTheEdgesAreTheSameAsWithDecimalStrings(): void
    {
        $on = new DateTimeImmutable('2026-01-01', new DateTimeZone('UTC'));
        $record = static function (int $id, string $cost) use ($on): MaintenanceEntry {
            $day = $on->modify("+$id days");
            $data = new MaintenanceEntryData($day, MaintenanceCategory::Service, 'Work', $cost);

            return new MaintenanceEntry($id, 1, $data, $day, $day);
        };
        $usual = [$record(1, '100.5'), $record(2, '100.50'), $record(3, '100.500')];
        // Exactly three times (not more), just over, and exactly the floor above.
        $cases = [['301.5', 3, 0], ['301.501', 3, 0], ['301.50', 2, 201], ['301.4', 2, 201], ['300.500001', 3, 200]];
        foreach ($cases as [$cost, $multiple, $floor]) {
            $records = [...$usual, $record(4, $cost)];
            $expected = self::reference($records, $on, $multiple, $floor);
            self::assertEquals($expected, CostOutlier::of($records, $on, $multiple, $floor), $cost);
        }
        $threeTimes = CostOutlier::of([...$usual, $record(4, '301.5')], $on, 3, 0);
        self::assertSame([], $threeTimes, 'three times is not more than three times');
        self::assertCount(1, CostOutlier::of([...$usual, $record(4, '301.5')], $on, 2, 201), 'exactly the floor above');
        self::assertSame([], CostOutlier::of([...$usual, $record(4, '301.4')], $on, 2, 201), 'under the floor');
    }

    /**
     * The check as it was before Phase 54.5, on decimal strings throughout.
     *
     * @param list<MaintenanceEntry> $entries
     * @return list<CostFinding>
     */
    private static function reference(array $entries, DateTimeImmutable $since, int $multiple, int $floor): array
    {
        usort(
            $entries,
            static fn (MaintenanceEntry $a, MaintenanceEntry $b): int => ($a->data->performedOn <=> $b->data->performedOn)
                ?: ($a->id <=> $b->id),
        );
        $sinceDay = $since->format('Y-m-d');
        $earlier = [];
        $findings = [];
        foreach ($entries as $entry) {
            $cost = $entry->data->cost;
            if (Decimal::compare($cost, '0') <= 0) {
                continue;
            }
            $category = $entry->data->category->value;
            $before = $earlier[$category] ?? [];
            $earlier[$category][] = $cost;
            if (count($before) < CostOutlier::MIN_EARLIER || $entry->data->performedOn->format('Y-m-d') < $sinceDay) {
                continue;
            }
            $median = Outlier::median($before);
            $over = Decimal::compare($cost, Decimal::multiply($median, (string) $multiple, Outlier::SCALE + 1)) > 0;
            $enough = Decimal::compare(Decimal::subtract($cost, $median), (string) $floor) >= 0;
            if ($over && $enough) {
                $ratio = Outlier::ratio($cost, $median);
                $findings[] = new CostFinding($entry, $median, $ratio, Outlier::isDigitSlip($ratio));
            }
        }

        return $findings;
    }
}
