<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Report;

use DateTimeImmutable;
use Logbook\Domain\Fuel\Fuel;
use Logbook\Domain\Maintenance\MaintenanceCategory;
use Logbook\Service\Report\PeriodItems;
use Logbook\Service\Report\TrueCostPeriod;
use PHPUnit\Framework\TestCase;

/**
 * Each true-cost period is handed only the ledger lines that can count in
 * it (Phase 54.5, K150), and comes out exactly as from the whole ledger.
 */
final class PeriodItemsTest extends TestCase
{
    use TrueCostFixtures;

    public function testAPeriodGetsItsDatedLinesAndEveryCoveredOneInTheLedgersOrder(): void
    {
        $car = self::vehicle('2023-03-01', '10000.000');
        $items = [
            self::maintenance($car, '2025-02-01', '100.000'),
            self::document($car, '2023-03-01', '300.000', '2024-02-29'),
            self::expense($car, '2024-12-31', '5.000'),
            self::maintenance($car, '2024-01-01', '80.000'),
            self::fill($car, '2024-06-01T12:00:00Z', '60.00'),
            self::expense($car, '2024-01-01', '4.000'),
        ];

        [, $y2024] = TrueCostPeriod::years(self::date('2023-03-01'), self::date('2026-09-01'));
        $kept = (new PeriodItems($items))->for($y2024);

        // The 2023 policy is kept (it has a cover); the 2025 service is not.
        self::assertSame([$items[1], $items[2], $items[3], $items[4], $items[5]], $kept);
    }

    public function testEveryPeriodComesOutAsFromTheWholeLedger(): void
    {
        // Four years of lines on many dates, with documents across year ends and a PHEV's two energies.
        $car = self::vehicle('2022-05-10', '18000.000', '2026-03-15', '9000.000');
        $items = [];
        for ($i = 0; $i < 120; $i++) {
            $day = (new DateTimeImmutable('2022-05-01'))->modify(sprintf('+%d days', 13 * $i))->format('Y-m-d');
            $items[] = match ($i % 3) {
                0 => self::fill(
                    $car,
                    $day . 'T08:00:00Z',
                    sprintf('%d.%02d', 40 + $i % 17, $i),
                    '35.500',
                    $i % 2 === 0 ? Fuel::Petrol : Fuel::Electricity,
                ),
                1 => self::maintenance($car, $day, sprintf('%d.500', 50 + $i), MaintenanceCategory::Repair),
                default => self::expense($car, $day, sprintf('%d.250', 3 + $i % 9)),
            };
        }
        foreach (['2022-04-01', '2023-04-01', '2024-04-01', '2025-04-01'] as $n => $start) {
            $items[] = self::document($car, $start, sprintf('%d.000', 365 + 30 * $n), substr($start, 0, 4) + 1 . '-03-31');
        }
        $readings = [
            self::reading(1, '20000', '2022-05-10T12:00:00Z'),
            self::reading(2, '41000', '2024-01-02T12:00:00Z'),
            self::reading(3, '58123.4', '2026-03-15T12:00:00Z'),
        ];
        $valuations = [self::valuation('2024-06-01', '12000.000')];

        $index = new PeriodItems($items);
        $periods = TrueCostPeriod::years(self::date('2022-05-10'), self::date('2026-03-15'));
        [$bought, $sold] = [self::date('2022-05-10'), self::date('2026-03-15')];
        $periods[] = TrueCostPeriod::twelveMonths($sold, $bought, $sold);
        $periods[] = TrueCostPeriod::twelveMonths($sold, $bought, $sold, true);
        foreach ($periods as $period) {
            self::assertNotNull($period);
            self::assertLessThan(count($items), count($index->for($period)), 'a period is handed less than the whole ledger');
            self::assertEquals(
                self::period($car, $period, $items, $readings, $valuations),
                self::period($car, $period, $index->for($period), $readings, $valuations),
                $period->from->format('Y-m-d') . ' to ' . $period->to->format('Y-m-d'),
            );
        }
    }

    public function testAnEmptyLedgerGivesNothing(): void
    {
        [$year] = TrueCostPeriod::years(self::date('2024-01-01'), self::date('2024-12-31'));

        self::assertSame([], (new PeriodItems([]))->for($year));
    }
}
