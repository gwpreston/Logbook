<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Support\Number\Decimal;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * Fuel insights end to end (spec.md §7.3, Phase 16): the grade verdict in
 * every unit, cost per distance by charging type, the Economy | Cost switch
 * with and without JS (and at a subpath), and economy by month. The owner
 * starts with UK units (miles, litres, mpg UK, GBP, Europe/London).
 */
final class FuelInsightsTest extends AppTestCase
{
    use CostFixtures;

    private const string NOW = '2026-09-27T10:00:00Z';

    public function testTheGradeVerdictIsTheSameInEveryUnit(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->workedExample($app);
        $page = '/vehicles/' . $golf->id . '/fuel';

        $html = self::body($browser->get($page));
        self::assertStringContainsString('Compared with your usual grade', $html);
        self::assertStringContainsString('E5 98 costs about 10% more per mile than E10 95', $html);
        self::assertStringContainsString('7% more per litre, 3% more fuel used', $html);
        self::assertStringContainsString(
            'From 2 tanks of E5 98 and 3 of E10 95; prices from 3 fill-ups within a month of each other.',
            $html,
        );

        $this->units($app, 'km', 'l', 'l_per_100km');
        $html = self::body($browser->get($page));
        self::assertStringContainsString('E5 98 costs about 10% more per km than E10 95', $html);
        self::assertStringContainsString('7% more per litre, 3% more fuel used', $html);

        $this->units($app, 'mi', 'gal_us', 'mpg_us');
        $html = self::body($browser->get($page));
        self::assertStringContainsString('E5 98 costs about 10% more per mile than E10 95', $html);
        self::assertStringContainsString('7% more per gallon, 3% more fuel used', $html);
    }

    public function testTooFewFillUpsCloseInTimeGiveNoVerdict(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        // Enough tanks of each grade, but the E5 fills are months from any E10 fill but one.
        $fills = [
            ['2026-01-01', '0', FuelGrade::E10_95], ['2026-01-08', '500', FuelGrade::E10_95],
            ['2026-01-15', '1000', FuelGrade::E10_95], ['2026-01-22', '1500', FuelGrade::E5_98],
            ['2026-04-01', '2000', FuelGrade::E5_98], ['2026-04-08', '2500', FuelGrade::E5_98],
        ];
        foreach ($fills as $i => [$day, $km, $grade]) {
            // The first fill makes E10 the most used grade (the reference).
            $this->fillUp($app, $golf, $day . 'T08:00:00Z', $km, $i === 0 ? '45' : '30', $i === 0 ? '67.5' : '45', grade: $grade);
        }

        $html = self::body($browser->get('/vehicles/' . $golf->id . '/fuel'));
        self::assertStringContainsString(
            'E5 98 against E10 95: not enough fill-ups near each other in time to compare prices.',
            $html,
        );
        self::assertStringNotContainsString('per mile than', $html);
    }

    public function testEachChargingTypeShowsItsCostPerMile(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $ev = $this->vehicle($app, 'Kia', 'EV6', fuel: FuelType::Electric);
        // 0.20 kWh per km over the measured stretches.
        $this->fillUp($app, $ev, '2026-06-01T20:00:00Z', '1000', '50', '5.00', grade: FuelGrade::Home);
        $this->fillUp($app, $ev, '2026-06-05T12:00:00Z', '1250', '50', '35.00', grade: FuelGrade::DcRapid);
        $this->fillUp($app, $ev, '2026-06-10T20:00:00Z', '1500', '50', '5.00', grade: FuelGrade::Home);

        $html = self::body($browser->get('/vehicles/' . $ev->id . '/fuel'));
        self::assertStringContainsString('<th scope="col" class="table__num">Per mile</th>', $html);
        // Home: £0.10/kWh × 0.2 kWh/km × 1.609 km/mi; rapid: £0.70/kWh.
        self::assertStringContainsString('£0.032/mi', $html);
        self::assertStringContainsString('£0.225/mi', $html);
        self::assertStringNotContainsString('Compared with your usual grade', $html, 'no verdict for charging');
    }

    public function testTheTrendSwitchWorksWithoutJsAndSurvivesARefreshAtASubpath(): void
    {
        $app = $this->createApp(['APP_BASE_PATH' => '/logbook']);
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->workedExample($app);
        $page = '/vehicles/' . $golf->id . '/fuel';

        $economy = self::body($browser->get('/logbook' . $page));
        self::assertStringContainsString('href="/logbook' . $page . '?trend=cost"', $economy);
        $link = '~data-trend-link="%s"\s+href="/logbook' . preg_quote($page, '~') . '%s" aria-current="true"~';
        self::assertMatchesRegularExpression(sprintf($link, 'economy', ''), $economy);
        self::assertStringContainsString('<div data-trend-panel="cost" hidden>', $economy);

        // A hard refresh of the cost view (the proxy strips the prefix).
        $cost = self::body($browser->get($page . '?trend=cost'));
        self::assertMatchesRegularExpression(sprintf($link, 'cost', '\?trend=cost'), $cost);
        self::assertStringContainsString('<div data-trend-panel="economy" hidden>', $cost);
        self::assertStringContainsString('<div data-trend-panel="cost">', $cost);
        // The same points as a table for readers without JS.
        self::assertStringContainsString('<table class="table chart-table">', $cost);
        self::assertStringContainsString('Fuel used in this tank', $cost, 'the chart series');
        $table = explode('</table>', explode('data-trend-panel="cost"', $cost)[1])[0];
        self::assertSame(5, substr_count($table, '<th scope="row">'), 'one row per tank');

        // Anything else is the economy view.
        $other = self::body($browser->get($page . '?trend=nope'));
        self::assertStringContainsString('<div data-trend-panel="cost" hidden>', $other);
    }

    public function testEconomyByMonthShowsATableWithAWeightedAverage(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $page = '/vehicles/' . $golf->id . '/fuel';

        // One short January stretch: under 200 km, no figure yet.
        $this->fillUp($app, $golf, '2026-01-05T08:00:00Z', '1000', '40', '60');
        $this->fillUp($app, $golf, '2026-01-10T08:00:00Z', '1150', '10', '15');
        self::assertStringNotContainsString('Economy by month', self::body($browser->get($page)));

        // Then 500 km at 6 L/100 km: January is 40 L over 650 km (45.9 mpg),
        // weighted, not the mean of 42.4 and 47.1 mpg.
        $this->fillUp($app, $golf, '2026-01-25T08:00:00Z', '1650', '30', '45');

        $html = self::body($browser->get($page));
        self::assertStringContainsString('Economy by month', $html);
        $table = explode('</table>', explode('Economy by month</caption>', $html)[1])[0];
        self::assertStringContainsString('<th scope="col" class="table__num">2026</th>', $table);
        self::assertStringContainsString('<th scope="col" class="table__num">Average</th>', $table);
        self::assertStringContainsString('<th scope="row">December</th>', $table);
        $january = '~January</th>\s*<td[^>]*>45\.9 mpg</td>\s*<td[^>]*><strong>45\.9 mpg</strong>~';
        self::assertMatchesRegularExpression($january, $table);
        self::assertMatchesRegularExpression('~February</th>\s*<td[^>]*>—</td>~', $table);
    }

    public function testNothingAboutInsightsWithTheFuelModuleOff(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->workedExample($app);
        $this->service($app, FeatureToggles::class)
            ->save([Feature::Maintenance, Feature::Compliance, Feature::Reminders, Feature::Reports]);

        self::assertSame(404, $browser->get('/vehicles/' . $golf->id . '/fuel?trend=cost')->getStatusCode());
        foreach (['/', '/vehicles/' . $golf->id, '/reports'] as $page) {
            $html = self::body($browser->get($page));
            foreach (['grade-verdicts', 'data-trend-link', 'Economy by month', 'Compared with your usual grade'] as $marker) {
                self::assertStringNotContainsString($marker, $html, $page);
            }
        }
    }

    /**
     * E10 95 at 42.1 mpg (UK) over three tanks, then E5 98 at 40.8 over
     * two, 7% dearer; every E5 fill is within 21 days of the last E10 one.
     *
     * @param App<ContainerInterface> $app
     */
    private function workedExample(App $app): Vehicle
    {
        $golf = $this->vehicle($app);
        $e10 = '33.549'; // litres per 500 km at 42.1 mpg (UK)
        $e5 = '34.618';  // at 40.8 mpg (UK)
        $fills = [
            ['2026-03-01', '0', '45', '1.400000', FuelGrade::E10_95],
            ['2026-03-08', '500', $e10, '1.400000', FuelGrade::E10_95],
            ['2026-03-15', '1000', $e10, '1.400000', FuelGrade::E10_95],
            ['2026-03-22', '1500', $e10, '1.498000', FuelGrade::E5_98],
            ['2026-03-29', '2000', $e5, '1.498000', FuelGrade::E5_98],
            ['2026-04-05', '2500', $e5, '1.498000', FuelGrade::E5_98],
        ];
        foreach ($fills as [$day, $km, $litres, $price, $grade]) {
            $total = Decimal::multiply($litres, $price, 2);
            $this->fillUp($app, $golf, $day . 'T08:00:00Z', $km, $litres, $total, pricePerLitre: $price, grade: $grade);
        }

        return $golf;
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function units(App $app, string $distance, string $volume, string $consumption): void
    {
        $this->connection($app)->update('users', [
            'distance_unit' => $distance,
            'volume_unit' => $volume,
            'consumption_unit' => $consumption,
        ], ['username' => 'owner']);
    }
}
