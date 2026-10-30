<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Expense;

use Logbook\Domain\Expense\ExpenseCategory;
use Logbook\Domain\Expense\ExpenseEntry;
use Logbook\Domain\Expense\ExpenseEntryData;
use Logbook\Service\Expense\CostItem;
use Logbook\Tests\Unit\Service\Report\TrueCostFixtures;
use PHPUnit\Framework\TestCase;

/**
 * The ledger's sort (Phase 54.5, K149) puts lines in exactly the order
 * CostItem::compare() does with usort(): by date, then fuel, maintenance,
 * documents and the rest, then as logged, equals kept as given.
 */
final class CostItemSortTest extends TestCase
{
    use TrueCostFixtures;

    public function testTheLedgerIsInCompareOrder(): void
    {
        mt_srand(545);
        $car = self::vehicle('2020-01-01', '10000.000');
        $items = [];
        for ($i = 0; $i < 400; $i++) {
            $day = sprintf('2024-%02d-%02d', mt_rand(1, 3), mt_rand(1, 28));
            $items[] = match (mt_rand(0, 3)) {
                0 => self::fill($car, $day . sprintf('T%02d:00:00Z', mt_rand(0, 23)), '50.00'),
                1 => self::maintenance($car, $day, '80.000'),
                2 => self::document($car, $day, '300.000', '2025-01-01'),
                default => CostItem::fromExpense(new ExpenseEntry(
                    mt_rand(1, 50),
                    $car->id,
                    new ExpenseEntryData(self::date($day), ExpenseCategory::Parking, (string) mt_rand(1, 9)),
                    self::date($day),
                    self::date($day),
                ), $car, 'GBP'),
            };
        }
        // Fill-ups, services and documents all have id 1 here: as logged, they stay as given.
        shuffle($items);
        $expected = $items;
        usort($expected, CostItem::compare(...));

        self::assertSame($expected, CostItem::sort($items));
        self::assertSame([], CostItem::sort([]));
    }
}
