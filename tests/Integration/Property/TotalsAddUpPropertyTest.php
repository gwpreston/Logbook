<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Property;

use Logbook\Service\Report\TrueCost;
use Logbook\Support\Money\Money;
use Logbook\Support\Number\Decimal;
use Logbook\Tests\Support\Property\PropertyTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Totals add up (Phase 45.1 property): every total is exactly the sum of its
 * parts, in micro-units, for any household: a report's groups, months and
 * vehicles; cost of ownership's running costs and depreciation; true cost's
 * parts and rates; the yearly reports and the all-time one; each year's
 * depreciation and *Since bought*.
 */
#[Group('property')]
final class TotalsAddUpPropertyTest extends PropertyTestCase
{
    #[DataProvider('seeds')]
    public function testEveryTotalIsTheSumOfItsParts(int $seed): void
    {
        [$app, $plan, $writer] = $this->household($seed);
        $why = $plan->describe();
        $figures = $this->figures($app);
        $vehicles = $writer->fleet();

        $reports = $figures->reports($writer->owner, $vehicles);
        $byYear = [];
        foreach ($reports as $name => $report) {
            foreach ($report->currencies as $currency) {
                $at = "{$name} {$currency->currency}: {$why}";
                $total = $currency->total->micros;
                self::assertSame($total, self::sum(array_map(fn ($g) => $g->amount, $currency->groups)), "groups, {$at}");
                self::assertSame($total, self::sum(array_map(fn ($m) => $m->total, $currency->months)), "months, {$at}");
                self::assertSame($total, self::sum(array_map(fn ($v) => $v->total, $currency->vehicles)), "vehicles, {$at}");
                $counts = array_sum(array_map(fn ($v) => $v->count, $currency->vehicles));
                self::assertSame($currency->count, $counts, "count, {$at}");
                if (str_starts_with($name, 'year ')) {
                    $byYear[$currency->currency] = ($byYear[$currency->currency] ?? 0) + $currency->total->micros;
                }
            }
        }
        foreach ($reports['all']->currencies as $currency) {
            self::assertSame($currency->total->micros, $byYear[$currency->currency] ?? 0, "years add up to all time, {$why}");
        }

        foreach ($figures->trueCosts($writer->owner, $vehicles) as $result) {
            $at = $result->vehicle->data->model . ': ' . $why;
            $ownership = $result->ownership;
            $gross = self::sum(array_map(fn ($g) => $g->amount, $ownership->groups));
            $payouts = $ownership->payouts === null ? 0 : $ownership->payouts->micros;
            self::assertSame($gross - $payouts, $ownership->running->micros, "ownership running, {$at}");
            if ($ownership->total !== null && $ownership->depreciationCost !== null) {
                self::assertSame(
                    $ownership->running->micros + $ownership->depreciationCost->micros,
                    $ownership->total->micros,
                    "ownership total, {$at}",
                );
            }
            // Without depreciation, cost of ownership is "running costs only": its running costs.
            $since = $ownership->total === null ? $result->sinceBought->running() : $result->sinceBought->total();
            self::assertSame(($ownership->total ?? $ownership->running)->micros, $since->micros, "since bought, {$at}");

            $periods = array_merge([$result->sinceBought, $result->lastTwelveMonths], $result->years);
            foreach (array_filter($periods) as $trueCost) {
                self::assertRatesAddUp($trueCost, $at);
            }
            if ($result->sinceBought->depreciation !== null) {
                $years = self::sum(array_map(fn (TrueCost $t) => $t->depreciation ?? Money::zero($t->currency), $result->years));
                self::assertSame($result->sinceBought->depreciation->micros, $years, "years' depreciation, {$at}");
            }
        }
    }

    /** The parts' rates and the payouts' (negative) are the rate per distance to the last of its 6 places. */
    private static function assertRatesAddUp(TrueCost $trueCost, string $at): void
    {
        if ($trueCost->perKm === null) {
            return;
        }
        $sum = '0';
        foreach ($trueCost->rates as $rate) {
            $sum = Decimal::add($sum, $rate);
        }
        $sum = Decimal::add($sum, $trueCost->payoutsPerKm ?? '0');
        self::assertSame(0, Decimal::compare($sum, $trueCost->perKm), "rates {$sum} ≠ {$trueCost->perKm}, {$at}");
    }

    /**
     * @param array<Money|null> $amounts
     */
    private static function sum(array $amounts): int
    {
        return array_sum(array_map(static fn (?Money $m): int => $m === null ? 0 : $m->micros, $amounts));
    }
}
