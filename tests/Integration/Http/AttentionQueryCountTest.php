<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use Logbook\Domain\Compliance\ComplianceType;
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
 * readings and fill-ups each vehicle has. A query per reading or fill-up
 * would make this fail.
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
            $vehicles[] = $vehicle;
        }

        $before = $this->cost($browser, $counter);
        self::assertGreaterThan(0, $before['dashboard'], 'the counter counts');
        foreach ($vehicles as $vehicle) {
            $this->entries($app, $vehicle, 4, 16);
        }
        $after = $this->cost($browser, $counter);

        self::assertSame($before, $after, 'four times the readings and fill-ups, the same queries');
        self::assertStringContainsString('Needs attention: 2 items', self::body($browser->get('/garage')));
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
