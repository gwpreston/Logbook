<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Report;

use Logbook\Service\Report\OwnershipSection;
use Logbook\Support\Money\Money;
use PHPUnit\Framework\TestCase;

/**
 * The ownership report's print table as shown (spec.md §8 *Shown parts add
 * up*, #407): each vehicle's running costs and depreciation add up to its
 * total, each column to the fleet row.
 */
final class ShownOwnershipSectionTest extends TestCase
{
    use TrueCostFixtures;

    public function testEachRowAndColumnAddsUpToItsShownTotal(): void
    {
        $rows = [];
        // Each: running £10.005 and depreciation £100.005, a total of exactly £110.01.
        foreach ([1, 2, 3] as $id) {
            $vehicle = self::vehicle('2025-01-01', '1000.000', id: $id);
            $rows[] = self::ownership(
                $vehicle,
                [self::expense($vehicle, '2025-06-01', '10.005')],
                [],
                [self::valuation('2026-01-01', '899.995', $id)],
                '2026-01-01',
            );
        }
        // No price, so no depreciation and no total: running £5.005.
        $lease = self::vehicle(null, null, id: 4);
        $rows[] = self::ownership($lease, [self::expense($lease, '2025-06-01', '5.005')], [], [], '2026-01-01');

        $section = OwnershipSection::of('GBP', $rows);
        $shown = $section->shown();

        foreach ([0, 1, 2] as $i) {
            self::assertSame('110.01', $shown->total[$i]?->toDecimal(2), 'a whole total never moves');
            self::assertSame(
                $shown->total[$i]->micros,
                $shown->running[$i]->micros + ($shown->depreciation[$i]->micros ?? 0),
                "row {$i}: running costs and depreciation add up to its total",
            );
            self::assertContains($shown->running[$i]->toDecimal(3), ['10.000', '10.010']);
        }
        self::assertNull($shown->depreciation[3]);
        self::assertNull($shown->total[3]);
        self::assertContains($shown->running[3]->toDecimal(3), ['5.000', '5.010']);

        self::assertSame('35.02', $shown->fleetRunning->toDecimal(2), '£35.02 exactly is £35.02');
        self::assertSame(self::sum($shown->running), $shown->fleetRunning->micros, 'the running column adds up');
        self::assertNotNull($shown->fleetDepreciation);
        self::assertSame('300.02', $shown->fleetDepreciation->toDecimal(2), '£300.015 rounded once');
        self::assertSame(self::sum(array_slice($shown->depreciation, 0, 3)), $shown->fleetDepreciation->micros);
        self::assertSame('330.03', $shown->fleetTotal?->toDecimal(2), 'the three complete rows as shown');
        self::assertTrue($section->isTotalPartial());

        $row = $shown->row(0);
        self::assertSame($shown->running[0], $row['running']);
        self::assertSame(-($shown->depreciation[0]->micros ?? 0), $row['gain']?->micros);
        self::assertNull($shown->row(3)['gain']);
    }

    public function testAnEmptySectionShowsNothing(): void
    {
        $shown = OwnershipSection::of('GBP', [])->shown();

        self::assertSame([], $shown->running);
        self::assertTrue($shown->fleetRunning->isZero());
        self::assertNull($shown->fleetDepreciation);
        self::assertNull($shown->fleetTotal);
    }

    /**
     * @param list<?Money> $amounts
     */
    private static function sum(array $amounts): int
    {
        return array_sum(array_map(static fn (?Money $m): int => $m->micros ?? 0, $amounts));
    }
}
