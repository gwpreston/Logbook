<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Property;

use Logbook\Support\Number\Decimal;
use Logbook\Tests\Support\Golden\Rng;
use Logbook\Tests\Support\Property\Entry;
use Logbook\Tests\Support\Property\HouseholdGenerator;
use Logbook\Tests\Support\Property\Plan;
use Logbook\Tests\Support\Property\PropertyTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Rounded once (Phase 45.1 property): no figure moves when the same
 * household is entered in another order, or with each expense split in two
 * (spec §8 *Money edge cases*: amounts are summed exactly and rounded once,
 * at display). Splitting changes how many costs there are, so counts are
 * left out of that comparison.
 */
#[Group('property')]
final class RoundedOncePropertyTest extends PropertyTestCase
{
    #[DataProvider('seeds')]
    public function testTheOrderEntriesGoInChangesNoFigure(int $seed): void
    {
        [$app, $plan, $writer] = $this->household($seed);
        $before = $this->figures($app)->snapshot($writer->owner, $writer->fleet());

        $rng = new Rng($seed + 1);
        [$app, , $writer] = $this->household($seed, static fn (array $entries): array => self::shuffle($entries, $rng));

        self::assertSame($before, $this->figures($app)->snapshot($writer->owner, $writer->fleet()), $plan->describe());
    }

    #[DataProvider('seeds')]
    public function testSplittingAnExpenseInTwoChangesNoFigure(int $seed): void
    {
        $plan = (new HouseholdGenerator())->generate($seed);
        [$app, , $writer] = $this->household($seed, plan: $plan);
        $before = $this->figures($app)->snapshot($writer->owner, $writer->fleet(), withCounts: false);

        [$app, , $writer] = $this->household($seed, plan: self::split($plan, new Rng($seed + 2)));

        self::assertSame(
            $before,
            $this->figures($app)->snapshot($writer->owner, $writer->fleet(), withCounts: false),
            $plan->describe(),
        );
    }

    /**
     * @param list<Entry> $entries
     * @return list<Entry>
     */
    private static function shuffle(array $entries, Rng $rng): array
    {
        for ($i = count($entries) - 1; $i > 0; $i--) {
            $j = $rng->between(0, $i);
            [$entries[$i], $entries[$j]] = [$entries[$j], $entries[$i]];
        }

        return array_values($entries);
    }

    /** Each expense above zero as two on the same day, in the same category, adding up to it exactly. */
    private static function split(Plan $plan, Rng $rng): Plan
    {
        $vehicles = [];
        foreach ($plan->vehicles as $vehicle) {
            $entries = [];
            foreach ($vehicle->entries as $entry) {
                $thousandths = Decimal::toScaledInt($entry->amount, 3);
                if ($entry->kind !== 'expense' || $thousandths < 2) {
                    $entries[] = $entry;
                    continue;
                }
                $first = intdiv($thousandths * $rng->between(1, 99), 100);
                $entries[] = $entry->withAmount(Decimal::fromScaledInt($first, 3));
                $entries[] = $entry->withAmount(Decimal::fromScaledInt($thousandths - $first, 3));
            }
            $vehicles[] = $vehicle->withEntries($entries);
        }

        return new Plan($plan->seed, $plan->today, $plan->ownerZone, $plan->memberZone, $vehicles);
    }
}
