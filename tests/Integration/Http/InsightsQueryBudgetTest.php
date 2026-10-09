<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use DateTimeImmutable;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Domain\Station\Station;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\FuelPrices\StationLinker;
use Logbook\Tests\Integration\FuelPrices\FuelPricesTestCase;
use Logbook\Tests\Support\QueryCounter;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * Page budgets with every insight in play (spec.md §8, Phase 42, #280):
 * a price provider synced, linked stations with fresh prices, a *Home*
 * place and fill-ups at more than one station, so *Shopping around* and
 * *Fuel saving* do their work, with every module on. The Insights page
 * stays within 60 queries and the dashboard within 80 (#359), the same for
 * 1 vehicle as for 10. On master, with prices synced, the dashboard ran 109
 * queries for one vehicle and 469 for ten, and the Insights page 52 and 452.
 */
final class InsightsQueryBudgetTest extends FuelPricesTestCase
{
    private const int BUDGET = 60;
    /** The dashboard with every module on and prices synced (#359; 74 measured). */
    private const int ALL_MODULES_DASHBOARD = 80;

    private ?QueryCounter $counter = null;

    /**
     * The counter wraps the connection before anything uses it.
     *
     * @param array<string, string> $env
     * @return App<ContainerInterface>
     */
    protected function createApp(array $env = []): App
    {
        $app = parent::createApp($env);
        $this->counter = QueryCounter::install($app);

        return $app;
    }

    public function testTheDashboardAndTheInsightsPageStayWithinTheBudgetWithPricesOn(): void
    {
        $one = $this->cost(1);
        $ten = $this->cost(10);

        foreach (['dashboard' => self::ALL_MODULES_DASHBOARD, 'insights' => self::BUDGET] as $page => $budget) {
            self::assertGreaterThan(0, $one[$page], 'the counter counts');
            self::assertLessThanOrEqual($budget, $one[$page], "$page, one vehicle");
            self::assertLessThanOrEqual($budget, $ten[$page], "$page, ten vehicles");
            self::assertLessThanOrEqual($one[$page] + 2, $ten[$page], "$page: ten vehicles cost no more than one");
        }
    }

    /**
     * @return array{dashboard: int, insights: int}
     */
    private function cost(int $vehicles): array
    {
        [$app, $owner] = $this->pricesApp();
        $counter = $this->counter ?? self::fail('no counter');
        $this->service($app, FeatureToggles::class)->save(Feature::cases());
        $this->sync($app);
        $this->home($app, $owner);
        $linker = $this->service($app, StationLinker::class);
        $tesco = $linker->addFromProvider($owner, self::ref('antrim-tesco'));
        $shell = $linker->addFromProvider($owner, self::ref('antrim-shell'));
        for ($i = 0; $i < $vehicles; $i++) {
            $this->household($app, $this->vehicle($app, 'Make' . $i, 'Model' . $i), $tesco, $shell);
        }
        $browser = $this->browserFor($app, 'owner');
        $browser->get('/');
        $page = self::body($browser->get('/insights'));
        self::assertStringContainsString('data-insight="fuel_saving"', $page, 'the work is done');

        return [
            'dashboard' => $counter->during(static fn () => $browser->get('/')),
            'insights' => $counter->during(static fn () => $browser->get('/insights')),
        ];
    }

    /**
     * Twelve fill-ups of 60 L at the usual Shell a fortnight apart, then
     * three at Tesco (the cheapest nearby): *Shopping around* compares the
     * three, and *Fuel saving* has a figure.
     *
     * @param App<ContainerInterface> $app
     */
    private function household(App $app, Vehicle $vehicle, Station $tesco, Station $shell): void
    {
        for ($n = 0; $n < 15; $n++) {
            $at = (new DateTimeImmutable('2026-03-01T08:00:00Z'))->modify(sprintf('+%d days', 14 * $n));
            $station = $n < 12 ? $shell : $tesco;
            $entry = $this->fillUp(
                $app,
                $vehicle,
                $at->format('Y-m-d\TH:i:s\Z'),
                (string) (10000 + 900 * $n),
                '60',
                '83.94',
                pricePerLitre: '1.399',
                grade: FuelGrade::E10_95,
            );
            $this->connection($app)->update(
                'fuel_entries',
                ['station_id' => $station->id, 'station' => $station->data->name],
                ['id' => $entry->id],
            );
        }
    }
}
