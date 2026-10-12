<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Forecast;

use DateTimeImmutable;
use Logbook\Service\Forecast\ForecastMonthTotal;
use Logbook\Service\Forecast\ForecastTotals;
use Logbook\Support\Money\Money;
use PHPUnit\Framework\TestCase;

/**
 * *Coming up*'s months table as shown (spec.md §8 *Shown parts add up*,
 * #407): each month's planned and fuel add up to its total, each column to
 * the year's, and *Next 3 months* to its three months.
 */
final class ShownForecastTotalsTest extends TestCase
{
    public function testTheTableAddsUpBothWaysAndTheCardsShowItsFigures(): void
    {
        $months = [];
        for ($i = 0; $i < 12; $i++) {
            // £20.005 planned and £30.005 of fuel: each month exactly £50.01.
            $months[] = new ForecastMonthTotal(
                new DateTimeImmutable(sprintf('2026-%02d-01', $i + 1)),
                Money::of('20.005', 'GBP'),
                Money::of('30.005', 'GBP'),
            );
        }
        $totals = new ForecastTotals('GBP', $months, true);
        $shown = $totals->shown();

        $planned = 0;
        $fuel = 0;
        foreach (array_keys($months) as $i) {
            self::assertSame('50.01', $shown->months[$i]->toDecimal(2), 'a whole month total never moves');
            $sum = $shown->planned[$i]->micros + $shown->fuel[$i]->micros;
            self::assertSame($shown->months[$i]->micros, $sum, "month {$i} adds up");
            self::assertContains($shown->planned[$i]->toDecimal(3), ['20.000', '20.010']);
            $planned += $shown->planned[$i]->micros;
            $fuel += $shown->fuel[$i]->micros;
        }
        self::assertSame('240.06', $shown->plannedTotal->toDecimal(2), '£240.06 exactly');
        self::assertSame($planned, $shown->plannedTotal->micros, 'the planned column adds up');
        self::assertSame('360.06', $shown->fuelTotal->toDecimal(2));
        self::assertSame($fuel, $shown->fuelTotal->micros, 'the fuel column adds up');
        self::assertSame('600.12', $shown->total->toDecimal(2));
        self::assertSame('150.03', $shown->soon->toDecimal(2), 'the first three months as shown');
        self::assertSame(
            $shown->fuel[0]->micros + $shown->fuel[1]->micros + $shown->fuel[2]->micros,
            $shown->soonFuel->micros,
            'its fuel as those months show it',
        );
    }

    public function testHalfPenniesThatDoNotAddUpExactly(): void
    {
        $months = [
            new ForecastMonthTotal(new DateTimeImmutable('2026-01-01'), Money::of('0.005', 'GBP'), Money::of('0.005', 'GBP')),
            new ForecastMonthTotal(new DateTimeImmutable('2026-02-01'), Money::of('0.005', 'GBP'), Money::zero('GBP')),
            new ForecastMonthTotal(new DateTimeImmutable('2026-03-01'), Money::zero('GBP'), Money::of('0.005', 'GBP')),
            new ForecastMonthTotal(new DateTimeImmutable('2026-04-01'), Money::of('0.005', 'GBP'), Money::zero('GBP')),
        ];
        $shown = (new ForecastTotals('GBP', $months, true))->shown();

        self::assertSame('0.03', $shown->total->toDecimal(2), '£0.025 rounded once, half away from zero');
        self::assertSame($shown->total->micros, $shown->plannedTotal->micros + $shown->fuelTotal->micros);
        self::assertSame($shown->total->micros, array_sum(array_map(static fn (Money $m): int => $m->micros, $shown->months)));
        self::assertSame('0.00', $shown->planned[2]->toDecimal(2), 'a zero cell never moves');
        self::assertSame('0.00', $shown->fuel[1]->toDecimal(2));
        $soon = array_sum(array_map(static fn (Money $m): int => $m->micros, array_slice($shown->months, 0, 3)));
        self::assertSame($soon, $shown->soon->micros);
    }
}
