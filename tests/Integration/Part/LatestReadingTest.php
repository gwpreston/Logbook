<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Part;

use Logbook\Repository\OdometerReadingRepository;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;

/**
 * Merge review (Phase 50.2): a part's warranty and distance need only the vehicle's latest reading, so
 * they must not read the whole mileage log for every part (the `parts` tool took 12 s over 20,000
 * readings). The latest is the end of the log's own order.
 */
final class LatestReadingTest extends AppTestCase
{
    use CostFixtures;

    public function testTheLatestReadingIsTheEndOfTheLogWithoutReadingItAll(): void
    {
        $app = $this->createApp();
        $this->resetDatabase($app);
        $this->createOwner($app);
        $golf = $this->vehicle($app);
        $readings = $this->service($app, OdometerReadingRepository::class);
        self::assertNull($readings->latestKm($golf->id), 'no readings');

        $this->reading($app, $golf, '1000', '2026-01-01T09:00:00Z');
        $this->reading($app, $golf, '900', '2026-02-01T09:00:00Z');
        $this->reading($app, $golf, '1200', '2026-02-01T09:00:00Z');

        $log = $readings->listForVehicle($golf->id);
        self::assertSame($log[count($log) - 1]->readingKm, $readings->latestKm($golf->id));
        self::assertSame('1200.000', $readings->latestKm($golf->id), 'the later date, then the higher reading');
    }
}
