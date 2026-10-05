<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Report;

use Logbook\Domain\User\User;
use Logbook\Repository\UserRepository;
use Logbook\Service\Report\ChangeCause;
use Logbook\Service\Report\ChangeLine;
use Logbook\Service\Report\TrueCostService;
use Logbook\Service\Report\TruePart;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Number\Decimal;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\Migrator;

/**
 * True cost on the sample data (docs/phases/phase-32.md *Breakdown* and
 * *Sample data*): every demo vehicle's five parts and payouts add up
 * exactly to its cost of ownership per distance, and the Golf's trend has
 * depreciation every year and a 2024 whose change shows fuel price,
 * economy and distance.
 */
final class DemoTrueCostTest extends AppTestCase
{
    public function testTheSampleDataAddsUpAndTellsTheGolfsStory(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, '2026-10-05T10:00:00Z');
        $this->resetDatabase($app);
        Migrator::run('seed:run', ['--seed' => ['DemoDataSeeder']]);
        $demo = $this->service($app, UserRepository::class)->findByUsername('demo');
        self::assertInstanceOf(User::class, $demo);
        $today = LocalTime::parseDate('2026-10-05');
        self::assertNotNull($today);
        $vehicles = $this->service($app, VehicleService::class)->listFleet($demo, true);

        $results = $this->service($app, TrueCostService::class)->forVehicles($demo, $vehicles, $today);
        self::assertCount(count($vehicles), $results, 'every demo vehicle has an ownership period');
        $golf = null;
        foreach ($results as $vtc) {
            $since = $vtc->sinceBought;
            self::assertSame($vtc->ownership->perKm, $since->perKm, $vtc->vehicle->name());
            if ($since->perKm === null) {
                continue;
            }
            $sum = $since->payoutsPerKm ?? '0';
            foreach (TruePart::cases() as $part) {
                $sum = Decimal::add($sum, $since->rate($part) ?? '0');
            }
            self::assertSame(Decimal::round($since->perKm, 6), Decimal::round($sum, 6), $vtc->vehicle->name() . ' adds up');
            if ($vtc->vehicle->data->registration === 'LB19 KTR') {
                $golf = $vtc;
            }
        }
        self::assertNotNull($golf);

        $years = [];
        foreach ($golf->years as $year) {
            $years[(int) $year->period->year] = $year;
        }
        foreach ([2022, 2023, 2024, 2025] as $year) {
            self::assertNotNull($years[$year]->rate(TruePart::Depreciation), $year . ' has depreciation');
            self::assertTrue($years[$year]->isComparable(), $year . ' is compared');
            self::assertNotNull($years[$year]->rate(TruePart::Fuel), $year . ' has fuel');
        }
        self::assertTrue(
            Decimal::compare((string) $years[2024]->distanceKm, (string) $years[2023]->distanceKm) < 0,
            'less driving in 2024',
        );

        $change = $golf->changeFor(2024);
        self::assertNotNull($change);
        $causes = [];
        foreach ($change->lines as $line) {
            $causes[] = $line->cause->value . ':' . ($line->part->value ?? '');
            foreach ($line->details as $detail) {
                $causes[] = $detail->cause->value . ':' . ($detail->part->value ?? '');
            }
        }
        self::assertContains('distance:compliance', $causes);
        self::assertContains('distance:depreciation', $causes);
        $fuel = self::first($change->lines, static fn (ChangeLine $l): bool => $l->part === TruePart::Fuel);
        self::assertNotNull($fuel);
        $price = self::first($fuel->details, static fn (ChangeLine $l): bool => $l->cause === ChangeCause::Price);
        $economy = self::first($fuel->details, static fn (ChangeLine $l): bool => $l->cause === ChangeCause::Economy);
        self::assertNotNull($price);
        self::assertNotNull($economy);
        self::assertTrue(Decimal::compare((string) $price->fraction, '0') > 0, 'fuel cost more a litre');
        self::assertTrue(Decimal::compare((string) $economy->fraction, '0') < 0, 'and economy improved');
    }

    /**
     * @param list<ChangeLine> $lines
     * @param callable(ChangeLine): bool $match
     */
    private static function first(array $lines, callable $match): ?ChangeLine
    {
        foreach ($lines as $line) {
            if ($match($line)) {
                return $line;
            }
        }

        return null;
    }
}
