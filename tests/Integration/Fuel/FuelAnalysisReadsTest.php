<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Fuel;

use Logbook\Service\Fuel\FuelHistory;
use Logbook\Service\Fuel\FuelService;
use Logbook\Support\Cache\RequestReads;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;

/**
 * A page works a vehicle's fuel history and its economy checks out once,
 * however many widgets ask (spec.md §8 *Page budgets*, Phase 54.3, K149);
 * outside a request, or after a write to its fill-ups, it is worked out again.
 */
final class FuelAnalysisReadsTest extends AppTestCase
{
    use CostFixtures;

    public function testAPageGetsTheSameHistoryAndChecksEachTime(): void
    {
        $app = $this->createApp();
        $this->signedIn($app);
        $vehicle = $this->vehicle($app);
        $this->fillUp($app, $vehicle, '2026-01-01T08:00:00Z', '10000', '40', '60');
        $this->fillUp($app, $vehicle, '2026-02-01T08:00:00Z', '10500', '35', '52.50');
        $fuel = $this->service($app, FuelService::class);
        $reads = $this->service($app, RequestReads::class);

        $reads->begin();
        $history = $fuel->history($vehicle);
        self::assertSame($history, $fuel->history($vehicle), 'the history is worked out once');
        $checks = $fuel->checks($history);
        self::assertSame($checks, $fuel->checks($history), 'the checks are worked out once');
        $reads->end();

        self::assertNotSame($history, $fuel->history($vehicle), 'outside a request every call works it out');
        self::assertEquals($history, $fuel->history($vehicle), 'to the same figures');
    }

    public function testAWriteToTheFillUpsMakesTheRequestForgetThem(): void
    {
        $app = $this->createApp();
        $this->signedIn($app);
        $vehicle = $this->vehicle($app);
        $this->fillUp($app, $vehicle, '2026-01-01T08:00:00Z', '10000', '40', '60');
        $fuel = $this->service($app, FuelService::class);
        $reads = $this->service($app, RequestReads::class);

        $reads->begin();
        $before = $fuel->history($vehicle);
        $checksBefore = $fuel->checks($before);
        self::assertCount(1, $before->fills);

        $this->fillUp($app, $vehicle, '2026-02-01T08:00:00Z', '10500', '35', '52.50');
        $after = $fuel->history($vehicle);
        $reads->end();

        self::assertNotSame($before, $after);
        self::assertCount(2, $after->fills, 'the new fill-up is in the history');
        self::assertNotSame($checksBefore, $fuel->checks($after));
    }

    public function testChecksOfAnotherHistoryOfTheSameVehicleAreItsOwn(): void
    {
        $app = $this->createApp();
        $this->signedIn($app);
        $vehicle = $this->vehicle($app);
        $this->fillUp($app, $vehicle, '2026-01-01T08:00:00Z', '10000', '40', '60');
        $this->fillUp($app, $vehicle, '2026-02-01T08:00:00Z', '10500', '35', '52.50');
        $fuel = $this->service($app, FuelService::class);
        $reads = $this->service($app, RequestReads::class);

        $reads->begin();
        $remembered = $fuel->history($vehicle);
        $rememberedChecks = $fuel->checks($remembered);
        $other = new FuelHistory([$remembered->fills[0]], []);
        $otherChecks = $fuel->checks($other);
        self::assertNotSame($rememberedChecks, $otherChecks, 'a history that is not the remembered one gets its own checks');
        self::assertSame($rememberedChecks, $fuel->checks($remembered), 'and the remembered one keeps its checks');
        $reads->end();
    }
}
