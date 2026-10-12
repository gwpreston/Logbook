<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Property;

use DateTimeZone;
use Logbook\Repository\OdometerReadingRepository;
use Logbook\Service\Notification\Digest\DigestSummary;
use Logbook\Service\Report\PeriodDistance;
use Logbook\Service\Report\ReportFilter;
use Logbook\Service\Report\ReportPeriod;
use Logbook\Service\Report\ReportService;
use Logbook\Service\Report\TrueCost;
use Logbook\Support\Money\Money;
use Logbook\Support\Number\Decimal;
use Logbook\Tests\Support\Property\Figures;
use Logbook\Tests\Support\Property\PropertyTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Totals add up (Phase 45.1 property): every total is exactly the sum of its
 * parts, in micro-units, for any household: a report's groups, months and
 * vehicles; cost of ownership's running costs and depreciation; true cost's
 * parts and rates; the yearly reports and the all-time one; each year's
 * depreciation and *Since bought*. A cost per distance is given only where
 * the log reaches back to the period's first day, and the fleet's adds up
 * the vehicles whose log does (#376, #398). The digest's *Last month*
 * lists every vehicle with spend in it as Reports counts it, archived or
 * not (#374), its fleet spend is the lines' spend, and it says so when a
 * listed vehicle's costs are hidden (#375).
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
        $zone = new DateTimeZone($plan->ownerZone);
        $logs = $this->service($app, OdometerReadingRepository::class);
        foreach ($reports as $name => $report) {
            $from = $report->period->from;
            foreach ($report->currencies as $currency) {
                $spend = Money::zero($currency->currency);
                $km = '0';
                foreach ($currency->vehicles as $row) {
                    $log = $logs->listForVehicle($row->vehicle->id);
                    // The period's first day, or the purchase when later (#405).
                    $bought = $row->vehicle->data->purchaseDate;
                    $start = $from === null || ($bought !== null && $bought > $from) ? $bought : $from;
                    $reaches = $start === null || PeriodDistance::reachesBack($log, $start, $zone);
                    $at = "{$name} {$row->vehicle->data->model}: {$why}";
                    if (!$reaches) {
                        self::assertNull($row->costPerKm, "no cost per distance without a log reaching back, {$at}");
                        continue;
                    }
                    $spend = $spend->add($row->total);
                    $km = Decimal::add($km, $row->distanceKm ?? '0');
                    if ($row->distanceKm !== null && Decimal::compare($row->distanceKm, '0') > 0) {
                        self::assertNotNull($row->costPerKm, "a log reaching back gives a cost per distance, {$at}");
                    }
                }
                $fleet = Decimal::compare($km, '0') > 0
                    ? Decimal::divide($spend->toDecimal(Money::SCALE), $km, 6)
                    : null;
                $at = "{$name}: {$why}";
                self::assertSame($fleet, $currency->costPerKm, "the fleet's rate is over the vehicles reaching back, {$at}");
            }
        }

        $digest = $this->service($app, DigestSummary::class);
        // Today is 15 June 2026 in every zone the generator uses: last month is May.
        $today = Figures::today();
        $month = ReportPeriod::month($today->modify('first day of last month'));
        $lastMonth = $digest->lastMonth($writer->owner, $vehicles, $today);
        $listed = [];
        $spent = [];
        foreach ($lastMonth === null ? [] : $lastMonth->lines as $line) {
            $listed[$line->vehicle->id] = true;
            if ($line->costs !== null) {
                $spent[$line->costs->currency] = ($spent[$line->costs->currency] ?? 0) + $line->costs->spend->micros;
            }
        }
        foreach ($vehicles as $vehicle) {
            $report = $this->service($app, ReportService::class)->build($writer->owner, new ReportFilter($month, $vehicle->id));
            if (!$report->currencies[0]->total->isZero()) {
                self::assertArrayHasKey($vehicle->id, $listed, "May's spend is in the digest, {$vehicle->data->model}: {$why}");
            }
        }
        if ($lastMonth !== null && $lastMonth->hasFleet()) {
            $fleetSpend = array_map(static fn (Money $m): int => $m->micros, $lastMonth->fleetSpend);
            self::assertSame($spent, $fleetSpend, "fleet spend, {$why}");
        }
        $members = $digest->lastMonth($writer->member, $vehicles, $today);
        if ($members !== null && $members->fleetSpend !== []) {
            $hidden = array_filter($members->lines, static fn ($l): bool => $l->costs === null) !== [];
            self::assertSame($hidden, $members->fleetSpendPartial, "the fleet spend says when costs are hidden, {$why}");
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
