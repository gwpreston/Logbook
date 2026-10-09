<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use DateTimeImmutable;
use Logbook\Domain\Access\ShareLevel;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Domain\Station\Station;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\VehicleShareRepository;
use Logbook\Service\Ai\Ask\ToolRegistry;
use Logbook\Service\Ai\Provider\ToolCall;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\FuelPrices\EffectiveCost;
use Logbook\Service\FuelPrices\FuelPriceConfig;
use Logbook\Service\FuelPrices\FuelPriceSettings;
use Logbook\Service\FuelPrices\StationLinker;
use Logbook\Service\Insights\FuelSaving;
use Logbook\Service\Insights\FuelSavingFigure;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Geo\Haversine;
use Logbook\Support\Number\Decimal;
use Logbook\Tests\Integration\FuelPrices\FuelPricesTestCase;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * *Fuel saving* (spec.md §7.8, Phase 42, #352, #353, #357): the yearly
 * volume × (the usual station's listed price − the cheapest nearby
 * effective price per unit), from *Cheapest near me*'s own figures. Around
 * the test *Home*, the fixture lists E10 at Tesco Antrim (£1.359, 0.74 km)
 * and Shell Junction One (£1.379, 2.39 km); Maxol is temporarily closed.
 * "Now" is 3 Oct 2026, 07:00 UTC.
 */
final class FuelSavingTest extends FuelPricesTestCase
{
    private const string HOME_LAT = '54.716000';
    private const string HOME_LON = '-6.208000';

    /** @var App<ContainerInterface> */
    private App $app;
    private User $owner;
    private Station $tesco;
    private Station $shell;

    public function testTheWorkedExampleToThePenny(): void
    {
        $golf = $this->scene();
        // 26 fill-ups of 60 L in the 12 months (an older one keeps it unscaled), all at Shell.
        $this->fortnightly($golf, $this->shell, 26, '2025-10-17', '60', '900');

        $figure = $this->only($golf);
        self::assertSame('1560', Decimal::round($figure->yearlyLitres, 0));
        self::assertNull($figure->scaledFromMonths);
        self::assertFalse($figure->usualFromAverage);
        self::assertSame('1.379', Decimal::round($figure->usualPrice, 3));

        // By hand: Tesco's effective cost for the usual 60 L, the detour at 900 km per 60 L.
        $km = Haversine::km((float) self::HOME_LAT, (float) self::HOME_LON, 54.718012, -6.219034);
        $detourLitres = Decimal::multiply(
            Decimal::fromFloat(EffectiveCost::detour($km), 4),
            Decimal::divide('60', '900', 8),
            4,
        );
        $effective = Decimal::add(Decimal::multiply('60', '1.359', 4), Decimal::multiply($detourLitres, '1.359', 4));
        $perUnit = Decimal::divide($effective, '60', 6);
        $saving = Decimal::multiply('1560', Decimal::subtract('1.379', $perUnit), 6);
        self::assertSame($perUnit, $figure->cheapestPerUnit);
        self::assertSame($saving, $figure->yearlySaving);
        // 1,560 L × (£1.379 − £1.36192): £26.65 a year.
        self::assertSame('26.65', Decimal::round($figure->yearlySaving, 2));

        $html = self::body($this->browserFor($this->app, 'owner')->get('/insights'));
        self::assertContains('fuel_saving', self::insights($html));
        self::assertStringContainsString('Could save about £27 a year on fuel', $html);
        self::assertStringContainsString(
            'Volkswagen Golf: filling at Tesco Antrim Extra instead of your usual Shell Junction One: £1.362/L counting '
            . 'the drive there, against £1.379/L, at your 1,560 L a year. Today’s prices.',
            self::text($html),
        );
        self::assertStringContainsString(
            '/stations/near?vehicle=' . $golf->id . '&amp;grade=e10_95&amp;from=place%3A',
            $html,
        );
    }

    public function testAskAnswersFromTheComputedFigure(): void
    {
        $golf = $this->scene();
        $this->fortnightly($golf, $this->shell, 26, '2025-10-17', '60', '900');
        $figure = $this->only($golf);

        $run = $this->service($this->app, ToolRegistry::class)
            ->run($this->owner, new ToolCall('t1', 'computed_insights', []));
        self::assertNull($run->error);
        $data = $run->result->data ?? [];
        $rows = is_array($data['insights'] ?? null) ? $data['insights'] : [];
        $saving = null;
        foreach ($rows as $row) {
            if (is_array($row) && ($row['kind'] ?? null) === 'fuel_saving') {
                $saving = $row;
            }
        }
        self::assertIsArray($saving);
        self::assertSame('Could save about £27 a year on fuel', $saving['title'] ?? null, 'the same words as the page');
        $figures = is_array($saving['figures'] ?? null) ? $saving['figures'] : [];
        self::assertSame($figure->yearlySaving, $figures['yearly_saving'] ?? null, 'worked out by Logbook');
        self::assertSame('1.379', $figures['usual_price_per_litre'] ?? null);
        self::assertStringContainsString('£27', $run->content(), 'Ask can quote it, grounded');
    }

    public function testNotShownWhenTheCheapestIsTheUsualStation(): void
    {
        $golf = $this->scene();
        $this->fortnightly($golf, $this->tesco, 26, '2025-10-17', '40', '600');

        self::assertSame([], $this->figures());
    }

    public function testNotShownBelowTheThreshold(): void
    {
        $golf = $this->scene();
        // 15 L a fill-up: about £7 a year.
        $this->fortnightly($golf, $this->shell, 26, '2025-10-17', '15', '225');

        self::assertSame([], $this->figures());
    }

    public function testWithoutAListedUsualPriceTheThirtyDayAverageIsUsed(): void
    {
        $golf = $this->scene();
        // The usual station isn't linked, so it lists nothing; £1.399 paid there.
        $own = $this->station($this->app, 'Corner Garage', '54.700000', '-6.200000');
        $this->fortnightly($golf, $own, 26, '2025-10-17', '60', '900', '1.399');

        $figure = $this->only($golf);
        self::assertTrue($figure->usualFromAverage);
        self::assertSame('1.399', Decimal::round($figure->usualPrice, 3));
        $html = self::text(self::body($this->browserFor($this->app, 'owner')->get('/insights')));
        self::assertStringContainsString(
            'against the £1.399/L you’ve paid on average in the last 30 days, at your 1,560 L a year.',
            $html,
        );

        // Nothing paid in the last 30 days, and no listed price: nothing to compare with.
        $this->clock->set(new DateTimeImmutable('2026-11-15T07:00:00Z'));
        self::assertSame([], $this->figures(), 'no fresh listed price either: the fixture is six weeks old');
    }

    public function testUnderSixFillUpsOrNinetyDaysNothingIsExtrapolated(): void
    {
        $golf = $this->scene();
        $this->fortnightly($golf, $this->shell, 5, '2026-06-01', '40', '600');
        self::assertSame([], $this->figures(), 'five fill-ups');

        $polo = $this->vehicle($this->app, 'Volkswagen', 'Polo');
        // Six fill-ups a week apart: 35 days.
        $this->fillUps($polo, $this->shell, 6, '2026-08-20', 7, '40', '600');
        self::assertSame([], $this->figures(), 'six fill-ups over 35 days');
    }

    public function testUnderTwelveMonthsTheVolumeIsScaledToAYear(): void
    {
        $golf = $this->scene();
        // 10 fill-ups a fortnight apart from 3 June: 126 days, 540 L after the first.
        $this->fillUps($golf, $this->shell, 10, '2026-06-03', 14, '60', '900');

        $figure = $this->only($golf);
        self::assertSame(4, $figure->scaledFromMonths);
        // 540 L × 365 ÷ 126 days.
        self::assertSame(Decimal::divide(Decimal::multiply('540', '365', 6), '126.000000', 6), $figure->yearlyLitres);
        $html = self::text(self::body($this->browserFor($this->app, 'owner')->get('/insights')));
        self::assertStringContainsString('at about 1,564 L a year from your last 4 months.', $html);
    }

    public function testAnAssumedFillAndAnUncountedDriveAreSaid(): void
    {
        $golf = $this->scene();
        // Partial fill-ups only: no full fill to take a usual fill from, and no economy.
        $this->fortnightly($golf, $this->shell, 26, '2025-10-17', '40', '600', partial: true);

        $figure = $this->only($golf);
        self::assertTrue($figure->fillAssumed);
        self::assertFalse($figure->detourCounted);
        $html = self::text(self::body($this->browserFor($this->app, 'owner')->get('/insights')));
        self::assertStringContainsString('£1.359/L, against £1.379/L', $html, 'no drive counted');
        self::assertStringContainsString(
            'Today’s prices. Your usual fill is assumed (40 L). The drive there isn’t counted without an economy.',
            $html,
        );
    }

    public function testNeverWithoutAPlaceCostsAProviderOrTheVehiclesCurrency(): void
    {
        $golf = $this->scene(home: false);
        $this->fortnightly($golf, $this->shell, 26, '2025-10-17', '60', '900');
        self::assertSame([], $this->figures(), 'without a place');

        $this->home($this->app, $this->owner);
        self::assertCount(1, $this->figures(), 'with one');

        // A viewer who may not see the costs never gets it, even with a place of their own.
        $partner = $this->createMember($this->app);
        $this->home($this->app, $partner);
        $this->service($this->app, VehicleShareRepository::class)
            ->insert($golf->id, $partner->id, ShareLevel::View, false, false, new DateTimeImmutable(self::NOW));
        self::assertSame([], $this->service($this->app, FuelSaving::class)->forVehicles($partner, [$golf]));

        // Fuel stations off.
        $this->service($this->app, FeatureToggles::class)->save(array_values(array_filter(
            Feature::cases(),
            static fn (Feature $f): bool => $f !== Feature::Stations,
        )));
        self::assertSame([], $this->figures(), 'Fuel stations off');
        $this->service($this->app, FeatureToggles::class)->save(Feature::cases());

        // No provider.
        $this->service($this->app, FuelPriceConfig::class)->save(new FuelPriceSettings(null));
        self::assertSame([], $this->figures(), 'no provider');
    }

    public function testNeverConvertedNorForAnElectricCar(): void
    {
        $this->scene();
        $seat = $this->vehicle($this->app, 'Seat', 'Ibiza', 'EUR');
        $this->fortnightly($seat, $this->shell, 26, '2025-10-17', '60', '900');
        self::assertSame([], $this->figures(), 'euros against a pound feed');

        $leaf = $this->vehicle($this->app, 'Nissan', 'Leaf', null, FuelType::Electric);
        self::assertSame([], $this->service($this->app, FuelSaving::class)->forVehicles($this->owner, [$leaf]));
    }

    /**
     * Prices synced, Tesco and Shell linked, every module on, a Golf and the
     * owner's *Home*.
     */
    private function scene(bool $home = true): Vehicle
    {
        [$this->app, $this->owner] = $this->pricesApp();
        $this->service($this->app, FeatureToggles::class)->save(Feature::cases());
        $this->sync($this->app);
        if ($home) {
            $this->home($this->app, $this->owner, self::HOME_LAT, self::HOME_LON);
        }
        $linker = $this->service($this->app, StationLinker::class);
        $this->tesco = $linker->addFromProvider($this->owner, self::ref('antrim-tesco'));
        $this->shell = $linker->addFromProvider($this->owner, self::ref('antrim-shell'));
        return $this->vehicle($this->app);
    }

    /**
     * $count fill-ups a fortnight apart from $from, after one 76 days
     * before it (so the vehicle's history starts over 12 months ago and
     * nothing is scaled), each $litres over $km.
     */
    private function fortnightly(
        Vehicle $vehicle,
        Station $station,
        int $count,
        string $from,
        string $litres,
        string $km,
        string $price = '1.389',
        bool $partial = false,
    ): void {
        $older = (new DateTimeImmutable($from . 'T08:00:00Z'))->modify('-76 days')->format('Y-m-d\TH:i:s\Z');
        $this->fillAt($vehicle, $station, $older, (string) (10000 - (int) $km), $litres, $price, $partial);
        $this->fillUps($vehicle, $station, $count, $from, 14, $litres, $km, $price, $partial);
    }

    private function fillUps(
        Vehicle $vehicle,
        Station $station,
        int $count,
        string $from,
        int $days,
        string $litres,
        string $km,
        string $price = '1.389',
        bool $partial = false,
    ): void {
        for ($n = 0; $n < $count; $n++) {
            $at = (new DateTimeImmutable($from . 'T08:00:00Z'))->modify(sprintf('+%d days', $days * $n));
            $odometer = (string) (10000 + (int) $km * $n);
            $this->fillAt($vehicle, $station, $at->format('Y-m-d\TH:i:s\Z'), $odometer, $litres, $price, $partial);
        }
    }

    private function fillAt(
        Vehicle $vehicle,
        Station $station,
        string $utc,
        string $odometer,
        string $litres,
        string $price,
        bool $partial = false,
    ): void {
        $entry = $this->fillUp(
            $this->app,
            $vehicle,
            $utc,
            $odometer,
            $litres,
            Decimal::multiply($litres, $price, 2),
            $partial,
            pricePerLitre: $price,
            grade: FuelGrade::E10_95,
        );
        $this->connection($this->app)->update(
            'fuel_entries',
            ['station_id' => $station->id, 'station' => $station->data->name],
            ['id' => $entry->id],
        );
    }

    /**
     * @return list<FuelSavingFigure>
     */
    private function figures(): array
    {
        $list = $this->service($this->app, VehicleService::class)->listFleet($this->owner);
        return $this->service($this->app, FuelSaving::class)->forVehicles($this->owner, $list);
    }

    private function only(Vehicle $vehicle): FuelSavingFigure
    {
        $figures = $this->figures();
        self::assertCount(1, $figures);
        self::assertSame($vehicle->id, $figures[0]->vehicle->id);

        return $figures[0];
    }

    /**
     * @return list<string>
     */
    private static function insights(string $html): array
    {
        preg_match_all('/data-insight="([a-z_]+)"/', $html, $matches);

        return $matches[1];
    }

    /** The page's text with tags and repeated spaces gone, entities decoded. */
    private static function text(string $html): string
    {
        return (string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5));
    }
}
