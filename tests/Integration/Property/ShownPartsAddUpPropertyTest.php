<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Property;

use Logbook\Domain\Expense\CostGroup;
use Logbook\Service\Report\OwnershipCard;
use Logbook\Support\Money\Currency;
use Logbook\Support\Money\Money;
use Logbook\Support\Money\ShownAmounts;
use Logbook\Tests\Support\Property\PropertyTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Shown parts add up (Phase 45.1 property, spec §8, #403): wherever a total
 * is shown with its parts, the parts as shown add up to the total as shown,
 * each is within a minor unit of its exact amount, keeps its sign, and is
 * unchanged when already a whole minor unit; the total is rounded once.
 * Reports' groups, vehicles and months × groups table, the ownership card,
 * and a vehicle's cost of ownership at each level.
 */
#[Group('property')]
final class ShownPartsAddUpPropertyTest extends PropertyTestCase
{
    #[DataProvider('seeds')]
    public function testShownPartsAddUpToTheirShownTotal(int $seed): void
    {
        [$app, $plan, $writer] = $this->household($seed);
        $why = $plan->describe();
        $figures = $this->figures($app);
        $vehicles = $writer->fleet();

        foreach ($figures->reports($writer->owner, $vehicles) as $name => $report) {
            foreach ($report->currencies as $currency) {
                $at = "{$name} {$currency->currency}: {$why}";
                $shown = $currency->shown;
                self::assertRoundedOnce($currency->total, $shown->total, "total, {$at}");
                $groups = [];
                foreach (CostGroup::cases() as $group) {
                    $groups[] = [$currency->group($group), $shown->group($group)];
                }
                self::assertAddUp($shown->total, $groups, "groups, {$at}");
                $rows = [];
                foreach ($currency->vehicles as $i => $row) {
                    $rows[] = [$row->total, $shown->vehicle($i)];
                }
                self::assertAddUp($shown->total, $rows, "vehicles, {$at}");
                $months = [];
                $columns = [];
                foreach ($currency->months as $month) {
                    $cells = [];
                    foreach (CostGroup::cases() as $group) {
                        $cell = [$month->amount($group), $shown->cell($month, $group)];
                        $cells[] = $cell;
                        $columns[$group->value][] = $cell;
                    }
                    self::assertAddUp($shown->month($month), $cells, "{$month->month->format('Y-m')}, {$at}");
                    $months[] = [$month->total, $shown->month($month)];
                }
                if ($months !== []) {
                    self::assertAddUp($shown->total, $months, "months, {$at}");
                    foreach (CostGroup::cases() as $group) {
                        self::assertAddUp($shown->group($group), $columns[$group->value], "{$group->value} column, {$at}");
                    }
                }
            }
        }

        foreach ($figures->trueCosts($writer->owner, $vehicles) as $result) {
            $at = "{$result->vehicle->data->model}: {$why}";
            $card = new OwnershipCard($result);
            [$total, $segments, $negatives] = $card->shown();
            self::assertRoundedOnce($card->total(), $total, "card total, {$at}");
            $parts = [];
            foreach ($card->segments() as $i => $segment) {
                $parts[] = [$segment['amount'], $segments[$i]];
            }
            foreach ($card->negatives() as $i => $line) {
                $parts[] = [$line['amount'], $negatives[$i]];
            }
            self::assertAddUp($total, $parts, "card, {$at}");

            $cost = $result->ownership;
            $shown = $cost->shown();
            $lines = array_map(static fn ($g): array => [$g->amount, $shown->group($g->group)], $cost->spentGroups());
            $zero = Money::zero($cost->currency);
            if ($cost->payouts !== null && $shown->payouts !== null) {
                $lines[] = [$zero->subtract($cost->payouts), $zero->subtract($shown->payouts)];
            }
            self::assertAddUp($shown->running, $lines, "running, {$at}");
            $depreciation = $cost->depreciationCost;
            if ($cost->total === null || $depreciation === null) {
                self::assertRoundedOnce($cost->running, $shown->running, "running, {$at}");
            }
            if ($cost->total !== null && $depreciation !== null && $shown->total !== null && $shown->depreciation !== null) {
                self::assertRoundedOnce($cost->total, $shown->total, "total, {$at}");
                $levels = [[$cost->running, $shown->running], [$depreciation, $shown->depreciation]];
                self::assertAddUp($shown->total, $levels, "total, {$at}");
            }
        }
    }

    /**
     * @param list<array{Money, Money}> $parts each exact, then as shown
     */
    private static function assertAddUp(Money $shownTotal, array $parts, string $at): void
    {
        $unit = 10 ** (Money::SCALE - Currency::fractionDigits($shownTotal->currency));
        $sum = 0;
        foreach ($parts as [$exact, $shown]) {
            self::assertSame(0, $shown->micros % $unit, "a whole minor unit, {$at}");
            self::assertLessThan($unit, abs($shown->micros - $exact->micros), "within a minor unit, {$at}");
            if ($exact->micros % $unit === 0) {
                self::assertSame($exact->micros, $shown->micros, "a whole amount is unchanged, {$at}");
            }
            if ($shown->micros !== 0) {
                self::assertSame($exact->micros < 0, $shown->micros < 0, "the sign is kept, {$at}");
            }
            $sum += $shown->micros;
        }
        self::assertSame($shownTotal->micros, $sum, "the shown parts add up to the shown total, {$at}");
    }

    private static function assertRoundedOnce(Money $exact, Money $shown, string $at): void
    {
        self::assertSame(ShownAmounts::round($exact)->micros, $shown->micros, "rounded once, {$at}");
    }
}
