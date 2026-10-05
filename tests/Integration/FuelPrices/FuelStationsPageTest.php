<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\FuelPrices;

use DateTimeImmutable;
use Logbook\Domain\Access\ShareLevel;
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Repository\StationRepository;
use Logbook\Repository\VehicleShareRepository;
use Logbook\Service\Station\StationService;

/**
 * The Fuel stations page (spec.md §7.33 *Fuel stations page*, Phase 33.4):
 * *Prices nearby* above *Your stations* while a provider is on, each row
 * against the area average; the saving banner against what was paid, with
 * costs only; favourite, directions and *Log fill-up here* for a station
 * not yet in Logbook; the fill-up form's `?station=` prefill.
 */
final class FuelStationsPageTest extends FuelPricesTestCase
{
    public function testWithoutAProviderItIsYourStationsOnly(): void
    {
        [$app, $owner] = $this->pricesApp(enable: false);
        $this->home($app, $owner);
        $this->vehicle($app);

        $page = (string) $this->browserFor($app, 'owner')->get('/stations')->getBody();

        self::assertStringNotContainsString('data-prices-nearby', $page);
        self::assertStringNotContainsString('Your stations', $page);
    }

    public function testPricesNearbyFromTheFirstPlaceAgainstTheAreaAverage(): void
    {
        [$app, $owner] = $this->pricesApp();
        $this->sync($app);
        $this->home($app, $owner);
        $this->vehicle($app);

        $page = (string) $this->browserFor($app, 'owner')->get('/stations')->getBody();

        self::assertStringContainsString('Prices nearby', $page);
        self::assertLessThan(strpos($page, 'Your stations'), strpos($page, 'data-prices-nearby'), 'prices first');
        $tesco = self::row($page, 'Tesco Antrim Extra');
        self::assertStringContainsString('data-cheapest', $tesco, 'the lowest listed price');
        self::assertStringContainsString('£1.359/L', $tesco);
        self::assertStringContainsString('£0.010/L below average', $tesco, 'against 1.369, the mean of the fresh prices');
        $shell = self::row($page, 'Shell Junction One');
        self::assertStringNotContainsString('data-cheapest', $shell);
        self::assertStringContainsString('£0.010/L above average', $shell);
        self::assertStringNotContainsString('Maxol', $page, 'temporarily closed');
        self::assertMatchesRegularExpression(
            '#href="https://www\.openstreetmap\.org/directions\?route=%3B54\.\d+%2C-6\.\d+"#',
            $tesco,
            'directions to the station only',
        );
        self::assertStringContainsString('data-geo-href="geo:', $tesco);
        self::assertStringContainsString('href="/stations/near?from=place%3A', $page, 'See all');
        self::assertStringContainsString('data-near-sync', $page);
        self::assertLessThan(strpos($page, '£1.379/L'), strpos($page, '£1.359/L'), 'Cheapest first');

        $nearest = (string) $this->browserFor($app, 'owner')->get('/stations?sort=distance')->getBody();
        self::assertStringContainsString('aria-current="true">Nearest<', $nearest);
    }

    public function testTheSavingBannerComparesWithWhatWasPaidOnlyWithCosts(): void
    {
        [$app, $owner] = $this->pricesApp();
        $this->sync($app);
        $this->home($app, $owner);
        $golf = $this->vehicle($app);
        $this->fillUp($app, $golf, '2026-08-01T08:00:00Z', '10000', '40', '58.36', grade: FuelGrade::E10_95);

        $page = (string) $this->browserFor($app, 'owner')->get('/stations')->getBody();

        self::assertStringContainsString('data-near-saving', $page);
        self::assertStringContainsString('The cheapest E10 95 nearby is £1.359/L at Tesco Antrim Extra', $page);
        $paid = 'You’ve paid £1.459/L on average in the Volkswagen Golf over the last 12 months';
        self::assertStringContainsString($paid, $page);
        self::assertStringContainsString('save about £4.00 a tank', $page, '£0.100 × the usual 40 L');
        self::assertStringContainsString('Not counting the trip there.', $page);

        $viewer = $this->createMember($app, 'viewer');
        $this->service($app, VehicleShareRepository::class)
            ->insert($golf->id, $viewer->id, ShareLevel::View, false, false, new DateTimeImmutable(self::NOW));
        $this->home($app, $viewer);
        $theirs = (string) $this->browserFor($app, 'viewer')->get('/stations')->getBody();
        self::assertStringContainsString('data-prices-nearby', $theirs);
        self::assertStringNotContainsString('data-near-saving', $theirs, 'what was paid is spending');
    }

    public function testAStationNotYetInLogbookIsAddedToFavouriteOrToFillUpThere(): void
    {
        [$app, $owner] = $this->pricesApp();
        $this->sync($app);
        $this->home($app, $owner);
        $golf = $this->vehicle($app);
        $browser = $this->browserFor($app, 'owner');
        $tescoRef = self::refIn((string) $browser->get('/stations')->getBody(), 'Tesco Antrim Extra');

        $starred = $browser->post('/stations/near/add', [
            'ref' => $tescoRef,
            'then' => 'favourite',
            'return' => '/stations?sort=price',
        ]);
        self::assertSame('/stations?sort=price', $starred->getHeaderLine('Location'));
        $stations = $this->service($app, StationService::class);
        $tesco = $this->service($app, StationRepository::class)->linked('uk_fuel_finder')[0] ?? self::fail('not linked');
        self::assertTrue($stations->isFavourite($owner, $tesco));
        $row = self::row((string) $browser->get('/stations')->getBody(), 'Tesco Antrim Extra');
        self::assertStringContainsString('aria-pressed="true"', $row);

        $shellRef = self::refIn((string) $browser->get('/stations')->getBody(), 'Shell Junction One');
        $fill = $browser->post('/stations/near/add', ['ref' => $shellRef, 'then' => 'fuel', 'vehicle' => (string) $golf->id]);
        $location = $fill->getHeaderLine('Location');
        self::assertMatchesRegularExpression('#^/vehicles/' . $golf->id . '/fuel/new\?station=(\d+)$#', $location);
        $form = (string) $browser->follow($fill)->getBody();
        $chosen = '/<option value="\d+" data-name="Shell Junction One"[^>]*selected>/';
        self::assertMatchesRegularExpression($chosen, $form, 'the station chosen');

        $linked = (string) $browser->get('/vehicles/' . $golf->id . '/fuel/new?station=999999')->getBody();
        $any = '/<option value="\d+" data-name="[^"]*"[^>]*selected>/';
        self::assertDoesNotMatchRegularExpression($any, $linked, 'an unknown station is ignored');
    }

    private static function row(string $page, string $name): string
    {
        $at = strpos($page, $name, (int) strpos($page, 'data-nearby-rows'));
        self::assertNotFalse($at, $name);
        $start = strrpos(substr($page, 0, $at), '<li class="nearby-row"');
        self::assertNotFalse($start, $name);
        $end = strpos($page, '</li>', $at);

        return substr($page, $start, ($end === false ? strlen($page) : $end) - $start);
    }

    private static function refIn(string $page, string $name): string
    {
        self::assertSame(1, preg_match('/data-nearby-row="([^"]+)"/', self::row($page, $name), $match));

        return $match[1] ?? '';
    }
}
