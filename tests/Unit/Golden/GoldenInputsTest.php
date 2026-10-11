<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Golden;

use DateTimeImmutable;
use Logbook\Tests\Support\Golden\ExpectedSkeleton;
use Logbook\Tests\Support\Golden\InputBuilder;
use PHPUnit\Framework\TestCase;

/**
 * The golden household's inputs (Phase 45.1): the committed CSVs are exactly
 * what InputBuilder writes, so the owner's spreadsheet and the golden test
 * start from the same data, and the data is possible (odometers never go
 * back, every row names a known vehicle and user, every planned edge case
 * is in it).
 */
final class GoldenInputsTest extends TestCase
{
    private const string DIR = __DIR__ . '/../../Fixtures/golden';

    public function testTheCommittedCsvFilesAreWhatTheBuilderWrites(): void
    {
        foreach ((new InputBuilder())->build() as $name => $rows) {
            self::assertSame($rows, ExpectedSkeleton::read(self::DIR . '/inputs/' . $name . '.csv'), "{$name}.csv differs");
        }
    }

    public function testEveryExpectedRowIsListedOnceWithItsColumns(): void
    {
        $skeleton = (new ExpectedSkeleton())->rows(ExpectedSkeleton::read(self::DIR . '/inputs/fillups.csv'));
        $committed = ExpectedSkeleton::read(self::DIR . '/expected.csv');

        $key = self::key(...);
        self::assertSame(array_map($key, $skeleton), array_map($key, $committed));
        self::assertSame(count($committed), count(array_unique(array_map($key, $committed))));
    }

    public function testOdometersNeverGoBackInTime(): void
    {
        $points = [];
        foreach (ExpectedSkeleton::read(self::DIR . '/inputs/fillups.csv') as $row) {
            $points[$row['vehicle']][] = [$row['at'], $row['odometer_km']];
        }
        foreach (ExpectedSkeleton::read(self::DIR . '/inputs/readings.csv') as $row) {
            $points[$row['vehicle']][] = [$row['at'], $row['odometer_km']];
        }
        foreach (ExpectedSkeleton::read(self::DIR . '/inputs/maintenance.csv') as $row) {
            $points[$row['vehicle']][] = [$row['date'] . ' 12:00', $row['odometer_km']];
        }
        foreach ($points as $vehicle => $list) {
            sort($list);
            for ($i = 1, $n = count($list); $i < $n; $i++) {
                self::assertGreaterThanOrEqual(
                    (float) $list[$i - 1][1],
                    (float) $list[$i][1],
                    "{$vehicle}: {$list[$i][0]} is below {$list[$i - 1][0]}"
                );
            }
        }
    }

    public function testEveryRowNamesAKnownVehicleAndUserAndNothingPredatesItsPurchase(): void
    {
        $vehicles = [];
        foreach (ExpectedSkeleton::read(self::DIR . '/inputs/vehicles.csv') as $row) {
            $vehicles[$row['vehicle']] = $row;
        }
        $users = array_column(ExpectedSkeleton::read(self::DIR . '/inputs/users.csv'), 'user');
        foreach (glob(self::DIR . '/inputs/*.csv') ?: [] as $file) {
            foreach (ExpectedSkeleton::read($file) as $row) {
                foreach (['owner', 'by', 'user'] as $column) {
                    if (isset($row[$column])) {
                        self::assertContains($row[$column], $users, basename($file));
                    }
                }
                if (!isset($row['vehicle']) || basename($file) === 'vehicles.csv') {
                    continue;
                }
                self::assertArrayHasKey($row['vehicle'], $vehicles, basename($file));
                $on = substr($row['at'] ?? $row['date'] ?? $row['valued_on'] ?? $row['start_on'] ?? '', 0, 10);
                if ($on !== '') {
                    // #391: no reading or entry before the purchase (Phase 41.8 writes that test).
                    self::assertGreaterThanOrEqual($vehicles[$row['vehicle']]['purchase_date'], $on, basename($file));
                }
            }
        }
    }

    public function testThePlannedEdgeCasesAreInTheHousehold(): void
    {
        $fills = ExpectedSkeleton::read(self::DIR . '/inputs/fillups.csv');
        $at = array_column($fills, 'at');
        self::assertContains('2025-08-31 23:30', $at, 'Alex at 23:30 on the last day of a month');
        self::assertContains('2024-03-31 23:30', $at, 'Sam at 23:30 on the day summer time starts');
        self::assertContains('0.00', array_column($fills, 'total_cost'), 'a free charge');
        $golfFills = array_filter($fills, static fn (array $r): bool => $r['vehicle'] === 'golf');
        self::assertContains('sam', array_column($golfFills, 'by'), 'Sam logs Golf fill-ups');

        $trips = array_column(ExpectedSkeleton::read(self::DIR . '/inputs/trips.csv'), 'date');
        self::assertContains('2025-04-05', $trips);
        self::assertContains('2025-04-06', $trips);

        $valuations = ExpectedSkeleton::read(self::DIR . '/inputs/valuations.csv');
        self::assertContains(['vehicle' => 'focus', 'valued_on' => '2023-09-20'], array_map(
            static fn (array $r): array => ['vehicle' => $r['vehicle'], 'valued_on' => $r['valued_on']],
            $valuations
        ), 'a valuation on the purchase day (#389)');
        $golf = array_filter($valuations, static fn (array $r): bool => $r['vehicle'] === 'golf');
        $dates = array_column($golf, 'valued_on');
        $latest = new DateTimeImmutable($dates === [] ? 'now' : max($dates));
        self::assertLessThan(new DateTimeImmutable(InputBuilder::TODAY_UTC), $latest->modify('+12 months'), 'a stale valuation');

        self::assertContains('0.00', array_column(ExpectedSkeleton::read(self::DIR . '/inputs/documents.csv'), 'cost'));
        self::assertContains('0.00', array_column(ExpectedSkeleton::read(self::DIR . '/inputs/expenses.csv'), 'amount'));
    }

    public function testBusinessMilesCrossTheBandInTwoTaxYears(): void
    {
        $miles = [];
        $crossed = [];
        $trips = ExpectedSkeleton::read(self::DIR . '/inputs/trips.csv');
        usort($trips, static fn (array $a, array $b): int => $a['date'] <=> $b['date']);
        foreach ($trips as $trip) {
            $year = (int) substr($trip['date'], 0, 4) - (substr($trip['date'], 5) < '04-06' ? 1 : 0);
            $before = $miles[$year] ?? 0;
            $miles[$year] = $before + 2 * (int) $trip['typed_miles_one_way'];
            if ($before < 10000 && $miles[$year] >= 10000) {
                $crossed[$year] = $trip['date'];
            }
        }

        self::assertArrayHasKey(2024, $crossed);
        self::assertArrayHasKey(2025, $crossed);
        self::assertGreaterThan('2025-10-01', $crossed[2025], 'crossed after the rate change');
    }

    /**
     * @param array<string, string> $row
     */
    private static function key(array $row): string
    {
        return implode('|', [$row['figure'], $row['viewer'], $row['vehicle'], $row['period'], $row['currency']]);
    }
}
