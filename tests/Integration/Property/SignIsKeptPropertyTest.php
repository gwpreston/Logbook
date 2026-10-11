<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Property;

use DateTimeZone;
use Logbook\Support\Display\DisplayContext;
use Logbook\Support\Display\DisplayFormatter;
use Logbook\Support\Money\Money;
use Logbook\Support\Number\Decimal;
use Logbook\Tests\Support\Property\PropertyTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Sign is kept (Phase 45.1 property, spec §8 *Money edge cases*, #387,
 * #400): an insurance payout takes exactly its amount off cost of
 * ownership and true cost, whatever else was spent, so a total may go below
 * zero; a sale above the price is a negative depreciation; and every figure
 * below zero is shown with a minus sign.
 */
#[Group('property')]
final class SignIsKeptPropertyTest extends PropertyTestCase
{
    #[DataProvider('seeds')]
    public function testPayoutsAndGainsReduceTotalsAndShowTheirSign(int $seed): void
    {
        [$app, $plan, $writer] = $this->household($seed);
        $why = $plan->describe();
        $figures = $this->figures($app);
        $this->service($app, DisplayContext::class)->apply($writer->owner->preferences);
        $format = $this->service($app, DisplayFormatter::class);

        $with = [];
        foreach ($figures->trueCosts($writer->owner, $writer->fleet()) as $result) {
            $model = $result->vehicle->data->model;
            $with[$model] = $result->ownership->running->micros;
            foreach (
                [
                    $result->ownership->total, $result->ownership->running, $result->ownership->depreciationCost,
                    $result->sinceBought->total(), $result->lastTwelveMonths?->total(),
                ] as $amount
            ) {
                self::assertMinusShown($format, $amount, "{$model}: {$why}");
            }
            foreach ([$result->ownership->perKm, $result->sinceBought->perKm] as $perKm) {
                if ($perKm !== null && Decimal::compare($perKm, '0') < 0) {
                    $shown = $format->perDistance($perKm, $result->currency);
                    self::assertMatchesRegularExpression('/[-−]/u', $shown, "{$model}: {$why}");
                }
            }
        }
        foreach ($plan->vehicles as $vehicle) {
            $sold = $vehicle->saleDate !== null && Decimal::compare((string) $vehicle->salePrice, $vehicle->purchasePrice) > 0;
            $depreciation = null;
            foreach ($figures->trueCosts($writer->owner, $writer->fleet()) as $result) {
                if ($result->vehicle->data->model === $vehicle->key) {
                    $depreciation = $result->ownership->depreciationCost;
                }
            }
            if ($sold && $depreciation !== null) {
                self::assertTrue($depreciation->isNegative(), "a sale above the price is a gain, {$vehicle->key}: {$why}");
            }
        }

        // Take every payout away: running costs go up by exactly what was paid out.
        $zone = new DateTimeZone($plan->ownerZone);
        $paidOut = [];
        foreach ($writer->stored as $key => $entries) {
            for ($i = count($entries) - 1; $i >= 0; $i--) {
                if ($entries[$i][0]->kind === 'payout') {
                    $entry = $writer->remove($key, $i, $zone);
                    $paidOut[$key] = ($paidOut[$key] ?? 0) + Money::of($entry->amount, 'XXX')->micros;
                }
            }
        }
        foreach ($figures->trueCosts($writer->owner, $writer->fleet()) as $result) {
            $model = $result->vehicle->data->model;
            self::assertSame(
                $with[$model] + ($paidOut[$model] ?? 0),
                $result->ownership->running->micros,
                "payouts taken off exactly, {$model}: {$why}",
            );
        }
    }

    private static function assertMinusShown(DisplayFormatter $format, ?Money $amount, string $at): void
    {
        if ($amount === null || !$amount->isNegative()) {
            return;
        }
        $shown = $format->money($amount);
        self::assertMatchesRegularExpression('/[-−]/u', $shown, "{$amount->micros} shown as {$shown}, {$at}");
    }
}
