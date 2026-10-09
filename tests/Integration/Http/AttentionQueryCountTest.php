<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Logbook\Tests\Support\QueryCounter;
use Logbook\Tests\Support\TestBrowser;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * Needs attention's cost (spec.md §7.24 *Cost*): with ten vehicles, the
 * dashboard and the garage send the same number of queries however many
 * readings, fill-ups and maintenance records each vehicle has (Phase 25's
 * checks included). A query per reading, fill-up or record would make this
 * fail; the price check's wider comparison loads the owner's fill-ups once
 * however many vehicles need it.
 */
final class AttentionQueryCountTest extends AppTestCase
{
    use CostFixtures;

    private const string NOW = '2026-10-01T10:00:00Z';

    public function testQueriesDoNotGrowWithReadingsOrFillUps(): void
    {
        $app = $this->createApp();
        $counter = QueryCounter::install($app);
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $vehicles = [];
        for ($i = 0; $i < 10; $i++) {
            $vehicle = $this->vehicle($app, 'Make' . $i, 'Model' . $i);
            $this->document($app, $vehicle, ComplianceType::Inspection, '2025-09-01', '2026-08-31', '0');
            $this->entries($app, $vehicle, 0, 4);
            $this->reading($app, $vehicle, '1000', '2026-05-01T09:00:00Z'); // lower: one check each
            $this->records($app, $vehicle, 0, 3);
            $this->maintenance($app, $vehicle, '2026-03-01', 'Service', '2000'); // ten times: a cost check each
            $vehicles[] = $vehicle;
        }

        $before = $this->cost($browser, $counter);
        self::assertGreaterThan(0, $before['dashboard'], 'the counter counts');
        foreach ($vehicles as $vehicle) {
            $this->entries($app, $vehicle, 4, 16);
            $this->records($app, $vehicle, 3, 12);
        }
        $after = $this->cost($browser, $counter);

        self::assertSame($before, $after, 'four times the readings, fill-ups and records, the same queries');
        self::assertStringContainsString('Needs attention: 3 items', self::body($browser->get('/garage')));
    }

    public function testTheOwnersFillUpsAreLoadedOnceForEveryVehicleThatNeedsThem(): void
    {
        $app = $this->createApp();
        $counter = QueryCounter::install($app);
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $vehicles = [];
        for ($i = 0; $i < 5; $i++) {
            $vehicle = $this->vehicle($app, 'Make' . $i, 'Model' . $i);
            $this->entries($app, $vehicle, 0, 4);
            $vehicles[] = $vehicle;
        }
        $none = $this->cost($browser, $counter);

        // One E5 98 fill-up each, the only one on its vehicle: each needs
        // the owner's other fill-ups of that grade.
        foreach ($vehicles as $vehicle) {
            $this->fillUp($app, $vehicle, '2026-02-01T08:00:00Z', '12000', '35', '56.00', grade: FuelGrade::E5_98);
        }
        $one = $this->cost($browser, $counter);
        // Their vehicles' ids and their fill-ups, once (the vehicles themselves are the page's, read already: Phase 41.7).
        self::assertSame(2, $one['dashboard'] - $none['dashboard'], 'their vehicles, their fill-ups: once');
    }

    /**
     * Services $from to $to at 200, a month apart from 2024.
     *
     * @param App<ContainerInterface> $app
     */
    private function records(App $app, Vehicle $vehicle, int $from, int $to): void
    {
        for ($n = $from; $n < $to; $n++) {
            $on = (new \DateTimeImmutable('2024-01-15'))->modify(sprintf('+%d months', $n));
            $this->maintenance($app, $vehicle, $on->format('Y-m-d'), 'Service', '200');
        }
    }

    /**
     * Queries for the dashboard and the garage, after a first load (which
     * may write the reminders' sync).
     *
     * @return array{dashboard: int, garage: int}
     */
    private function cost(TestBrowser $browser, QueryCounter $counter): array
    {
        $browser->get('/');
        $browser->get('/garage');

        return [
            'dashboard' => $counter->during(static fn () => $browser->get('/')),
            'garage' => $counter->during(static fn () => $browser->get('/garage')),
        ];
    }

    /**
     * Fill-ups $from to $to, each 500 km and a week apart in 2026.
     *
     * @param App<ContainerInterface> $app
     */
    private function entries(App $app, Vehicle $vehicle, int $from, int $to): void
    {
        for ($n = $from; $n < $to; $n++) {
            $at = (new \DateTimeImmutable('2026-01-01T08:00:00Z'))->modify(sprintf('+%d days', 7 * $n));
            $this->fillUp($app, $vehicle, $at->format('Y-m-d\TH:i:s\Z'), (string) (10000 + 500 * $n), '35', '52.50');
        }
    }
}
