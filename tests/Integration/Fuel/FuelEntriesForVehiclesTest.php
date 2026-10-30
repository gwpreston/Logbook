<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Fuel;

use Logbook\Domain\Fuel\FuelEntry;
use Logbook\Repository\FuelEntryRepository;
use Logbook\Support\Cache\RequestReads;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Logbook\Tests\Support\QueryCounter;

/**
 * Several vehicles' fill-ups at once (the price check's wider comparison,
 * spec.md §7.24 item 8): in a request the ones the page has read already
 * are not read again (Phase 54.5, K149), and outside one they are read in
 * one query; either way the same fill-ups.
 */
final class FuelEntriesForVehiclesTest extends AppTestCase
{
    use CostFixtures;

    public function testAPageReadsOnlyTheVehiclesItHasNotReadYet(): void
    {
        $app = $this->createApp();
        $counter = QueryCounter::install($app);
        $this->signedIn($app);
        $golf = $this->vehicle($app, 'Volkswagen', 'Golf');
        $polo = $this->vehicle($app, 'Volkswagen', 'Polo');
        $up = $this->vehicle($app, 'Volkswagen', 'Up');
        $this->fillUp($app, $golf, '2026-01-01T08:00:00Z', '10000', '40', '60');
        $this->fillUp($app, $polo, '2026-01-02T08:00:00Z', '20000', '30', '45');
        $this->fillUp($app, $golf, '2026-01-03T08:00:00Z', '10500', '35', '52.50');
        $this->fillUp($app, $up, '2026-01-04T08:00:00Z', '5000', '20', '30');
        $fuel = $this->service($app, FuelEntryRepository::class);
        $reads = $this->service($app, RequestReads::class);
        $ids = [$golf->id, $polo->id, $up->id];
        $expected = self::keyed($fuel->listForVehiclesBetween($ids, null, null));

        $reads->begin();
        $fuel->prime([$golf->id, $polo->id]);
        $entries = [];
        $queries = $counter->during(static function () use ($fuel, $ids, &$entries): void {
            $entries = $fuel->listForVehicles($ids);
        });
        $again = $counter->during(static fn () => $fuel->listForVehicles($ids));
        $reads->end();

        self::assertSame(1, $queries, 'only the Up is read');
        self::assertSame(0, $again, 'then nothing');
        self::assertEquals($expected, self::keyed($entries));
        $vehicles = array_map(static fn (FuelEntry $e): int => $e->vehicleId, $entries);
        self::assertSame([$golf->id, $golf->id, $polo->id, $up->id], $vehicles, 'by vehicle');

        $listed = [];
        $outside = $counter->during(static function () use ($fuel, $ids, &$listed): void {
            $listed = $fuel->listForVehicles($ids);
        });
        self::assertSame(1, $outside, 'outside a request, one query');
        self::assertEquals($expected, self::keyed($listed));
    }

    /**
     * @param list<FuelEntry> $entries
     * @return array<int, FuelEntry> by id
     */
    private static function keyed(array $entries): array
    {
        $keyed = [];
        foreach ($entries as $entry) {
            $keyed[$entry->id] = $entry;
        }
        ksort($keyed);

        return $keyed;
    }
}
