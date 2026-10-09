<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use DateTimeImmutable;
use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Support\Cache\RequestReads;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Logbook\Tests\Support\QueryCounter;
use Logbook\Tests\Support\TestBrowser;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * Page budgets (spec.md §8, Phase 41.7, #350): the dashboard and a vehicle's
 * overview run a bounded number of queries, however many vehicles the owner
 * has and however much each holds, and show exactly what they showed when
 * every widget read every table itself. Before the batching, ten vehicles
 * cost 812 queries on the dashboard and 274 on an overview.
 */
final class PageQueryBudgetTest extends AppTestCase
{
    use CostFixtures;

    private const string NOW = '2026-10-01T10:00:00Z';
    private const int BUDGET = 60;

    public function testTheDashboardAndTheOverviewStayWithinTheBudgetWhateverTheNumberOfVehicles(): void
    {
        // One household at a time: a new app empties the database.
        $oneCost = $this->cost($this->household(1, 12));
        $tenCost = $this->cost($this->household(10, 12));

        foreach (['dashboard', 'overview'] as $page) {
            self::assertGreaterThan(0, $oneCost[$page], 'the counter counts');
            self::assertLessThanOrEqual(self::BUDGET, $oneCost[$page], "$page, one vehicle");
            self::assertLessThanOrEqual(self::BUDGET, $tenCost[$page], "$page, ten vehicles");
            self::assertLessThanOrEqual(
                $oneCost[$page] + 2,
                $tenCost[$page],
                "$page: ten vehicles cost no more than one (a query per vehicle would add nine or more)",
            );
        }
    }

    public function testQueriesDoNotGrowWithWhatEachVehicleHolds(): void
    {
        $household = $this->household(10, 4);
        $before = $this->cost($household);

        foreach ($household['vehicles'] as $vehicle) {
            $this->entries($household['app'], $vehicle, 4, 16);
            $this->maintenance($household['app'], $vehicle, '2026-04-01', 'Brakes', '120');
            $this->maintenance($household['app'], $vehicle, '2026-05-01', 'Tyres', '300');
        }
        $after = $this->cost($household);

        self::assertSame($before, $after, 'four times the fill-ups, more services: the same queries');
    }

    public function testThePagesShowTheSameWithAndWithoutTheSharedReads(): void
    {
        $household = $this->household(4, 8);
        $app = $household['app'];
        $first = $household['vehicles'][0];
        $browser = $household['browser'];
        $paths = ['/', '/?vehicle=' . $first->id, '/vehicles/' . $first->id, '/garage'];

        $shared = [];
        foreach ($paths as $path) {
            $shared[$path] = self::normalised(self::body($browser->get($path)));
        }
        $this->service($app, RequestReads::class)->switchOff();
        foreach ($paths as $path) {
            self::assertSame($shared[$path], self::normalised(self::body($browser->get($path))), $path);
            self::assertNotSame('', $shared[$path]);
        }
    }

    public function testAFinishedRequestLeavesNothingRemembered(): void
    {
        $household = $this->household(2, 3);
        $reads = $this->service($household['app'], RequestReads::class);
        $household['browser']->get('/');

        self::assertFalse($reads->isActive(), 'a finished request leaves nothing remembered');
    }

    /**
     * @return array{app: App<ContainerInterface>, counter: QueryCounter, browser: TestBrowser, vehicles: list<Vehicle>}
     */
    private function household(int $vehicles, int $fillUps): array
    {
        $app = $this->createApp();
        $counter = QueryCounter::install($app);
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $made = [];
        for ($i = 0; $i < $vehicles; $i++) {
            $vehicle = $this->vehicle($app, 'Make' . $i, 'Model' . $i);
            $this->document($app, $vehicle, ComplianceType::Inspection, '2025-09-01', '2026-10-20', '0');
            $this->document($app, $vehicle, ComplianceType::Insurance, '2025-09-01', '2026-11-20', '300');
            $this->entries($app, $vehicle, 0, $fillUps);
            $this->reading($app, $vehicle, '9000', '2025-12-01T09:00:00Z');
            $this->maintenance($app, $vehicle, '2026-03-01', 'Service', '200');
            $made[] = $vehicle;
        }

        return ['app' => $app, 'counter' => $counter, 'browser' => $browser, 'vehicles' => $made];
    }

    /**
     * Queries for the dashboard and the first vehicle's overview, after a
     * first load of each (which may write the reminders' sync).
     *
     * @param array{app: App<ContainerInterface>, counter: QueryCounter, browser: TestBrowser, vehicles: list<Vehicle>} $household
     * @return array{dashboard: int, overview: int}
     */
    private function cost(array $household): array
    {
        $browser = $household['browser'];
        $overview = '/vehicles/' . $household['vehicles'][0]->id;
        $browser->get('/');
        $browser->get($overview);

        return [
            'dashboard' => $household['counter']->during(static fn () => $browser->get('/')),
            'overview' => $household['counter']->during(static fn () => $browser->get($overview)),
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
            $at = (new DateTimeImmutable('2026-01-01T08:00:00Z'))->modify(sprintf('+%d days', 7 * $n));
            $this->fillUp($app, $vehicle, $at->format('Y-m-d\TH:i:s\Z'), (string) (10000 + 500 * $n), '35', '52.50');
        }
    }

    /** A page without what changes on every request (the CSRF and CSP tokens). */
    private static function normalised(string $html): string
    {
        $html = preg_replace('/(name="csrf_(?:name|value)" value=")[^"]*"/', '$1"', $html);
        $html = preg_replace('/nonce="[^"]*"/', 'nonce=""', $html ?? '');

        return $html ?? '';
    }
}
