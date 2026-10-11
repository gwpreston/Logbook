<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Property;

use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Money\Money;
use Logbook\Tests\Support\Property\PropertyTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Never mixed (Phase 45.1 property): no figure combines two currencies. In
 * every report each currency's total, groups, months and vehicles are in
 * that currency and hold only its vehicles; each vehicle's cost of
 * ownership and true cost are in the vehicle's own currency.
 */
#[Group('property')]
final class NeverMixedPropertyTest extends PropertyTestCase
{
    #[DataProvider('seeds')]
    public function testEveryFigureIsInOneCurrency(int $seed): void
    {
        [$app, $plan, $writer] = $this->household($seed);
        $why = $plan->describe();
        $figures = $this->figures($app);
        $vehicles = $writer->fleet();
        $currencyOf = [];
        foreach ($vehicles as $vehicle) {
            $currencyOf[$vehicle->id] = $this->service($app, VehicleService::class)->currencyFor($writer->owner, $vehicle);
        }

        foreach ($figures->reports($writer->owner, $vehicles) as $name => $report) {
            $seen = [];
            foreach ($report->currencies as $currency) {
                $code = $currency->currency;
                self::assertNotContains($code, $seen, "one section per currency, {$name}: {$why}");
                $seen[] = $code;
                $amounts = [$currency->total, $currency->averagePerMonth];
                foreach ($currency->groups as $group) {
                    $amounts[] = $group->amount;
                }
                foreach ($currency->months as $month) {
                    $amounts[] = $month->total;
                }
                foreach ($currency->vehicles as $line) {
                    $model = $line->vehicle->data->model;
                    self::assertSame($code, $currencyOf[$line->vehicle->id], "{$model} under {$code}, {$name}: {$why}");
                    $amounts[] = $line->total;
                }
                self::assertInCurrency($code, $amounts, "{$name} {$code}: {$why}");
            }
        }

        foreach ($figures->trueCosts($writer->owner, $vehicles) as $result) {
            $code = $currencyOf[$result->vehicle->id];
            $at = "{$result->vehicle->data->model}: {$why}";
            self::assertSame($code, $result->currency, $at);
            $ownership = $result->ownership;
            self::assertInCurrency($code, [
                $ownership->running, $ownership->total, $ownership->depreciationCost, $ownership->payouts, $ownership->perMonth,
            ], "ownership, {$at}");
            foreach (array_filter(array_merge([$result->sinceBought, $result->lastTwelveMonths], $result->years)) as $trueCost) {
                self::assertSame($code, $trueCost->currency, $at);
                self::assertInCurrency(
                    $code,
                    [...array_values($trueCost->amounts), $trueCost->payouts, $trueCost->depreciation, $trueCost->total()],
                    "true cost, {$at}",
                );
            }
        }
    }

    /**
     * @param list<Money|null> $amounts
     */
    private static function assertInCurrency(string $currency, array $amounts, string $at): void
    {
        foreach ($amounts as $amount) {
            if ($amount !== null) {
                self::assertSame($currency, $amount->currency, $at);
            }
        }
    }
}
