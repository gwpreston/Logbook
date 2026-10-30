<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Odometer;

use Logbook\Service\Odometer\OdometerService;
use Logbook\Support\Cache\RequestReads;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;

/**
 * A page works a vehicle's mileage history out once, however many widgets
 * ask (spec.md §8 *Page budgets*, Phase 54.5, K149); a new reading makes
 * it work it out again, and outside a request every call does.
 */
final class OdometerHistoryReadsTest extends AppTestCase
{
    use CostFixtures;

    public function testAPageGetsTheSameHistoryUntilAReadingIsWritten(): void
    {
        $app = $this->createApp();
        $this->signedIn($app);
        $vehicle = $this->vehicle($app);
        $this->reading($app, $vehicle, '10000', '2026-01-01T08:00:00Z');
        $odometer = $this->service($app, OdometerService::class);
        $reads = $this->service($app, RequestReads::class);

        $reads->begin();
        $history = $odometer->history($vehicle);
        self::assertSame($history, $odometer->history($vehicle), 'worked out once');
        $this->reading($app, $vehicle, '10500', '2026-02-01T08:00:00Z');
        $after = $odometer->history($vehicle);
        $reads->end();

        self::assertCount(1, $history->readings);
        self::assertCount(2, $after->readings, 'the new reading is in it');
        self::assertNotSame($after, $odometer->history($vehicle), 'outside a request every call works it out');
        self::assertEquals($after, $odometer->history($vehicle), 'to the same history');
    }
}
