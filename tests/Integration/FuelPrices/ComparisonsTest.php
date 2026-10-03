<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\FuelPrices;

use DateTimeImmutable;
use Logbook\Domain\Fuel\FuelEntry;
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Domain\FuelPrices\PriceChange;
use Logbook\Domain\FuelPrices\StationLink;
use Logbook\Domain\Station\Station;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\ListedPriceRepository;
use Logbook\Repository\StationRepository;
use Logbook\Service\Fuel\FuelService;
use Logbook\Service\FuelPrices\ComparisonWording;
use Logbook\Service\FuelPrices\FillUpComparisons;
use Logbook\Service\FuelPrices\Uk\FuelFinderProvider;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * "Was it worth it?" after a fill-up and *Shopping around* (spec.md §7.34,
 * #144): a fill-up at a linked station against the vehicle's usual
 * station, with the listed prices in effect at its time; the extra
 * driving from the viewer's Home place; nothing at the usual station or
 * without both prices; the Fuel tab's line from three comparisons on.
 */
final class ComparisonsTest extends FuelPricesTestCase
{
    private Station $tesco;
    private Station $shell;

    public function testAFillUpAwayFromTheUsualStationIsComparedWithIt(): void
    {
        [$app, $owner, $golf] = $this->scene();
        $away = $this->fillAt($app, $golf, $this->shell, '2026-09-15T10:00:00Z', '11800', '1.369');
        $comparisons = $this->service($app, FillUpComparisons::class);
        $wording = $this->service($app, ComparisonWording::class);

        // Without a Home place: the fuel saving alone.
        $comparison = $comparisons->forEntry($owner, $golf, $away);
        self::assertNotNull($comparison);
        self::assertSame($this->tesco->id, $comparison->usual->id);
        self::assertSame('1.399', $comparison->usualListed->price);
        self::assertSame('1.2000', $comparison->fuelSaving, '(1.399 − 1.369) × 40 L');
        self::assertFalse($comparison->hasDistance());
        self::assertSame(
            'Compared with your usual Tesco (£1.399/L): saved £1.20 on fuel, before the extra driving',
            $wording->sentence($comparison),
        );

        // With Home: the extra distance there and back × 1.3, at the price paid and the usual economy.
        $this->home($app, $owner);
        $comparison = $comparisons->forEntry($owner, $golf, $away);
        self::assertNotNull($comparison);
        self::assertTrue($comparison->hasDistance());
        self::assertEqualsWithDelta(4.32, (float) $comparison->extraRoadKm, 0.05, 'Shell is 1.66 km further from Home');
        self::assertSame('0.39', round((float) $comparison->extraCost, 2) . '', '4.32 km at 40 L per 600 km and £1.369');
        self::assertSame(
            'Compared with your usual Tesco (£1.399/L): saved £1.20 on fuel, about £0.39 for the extra 4 km, £0.81 better off',
            $wording->sentence($comparison),
        );
    }

    public function testNothingIsComparedAtTheUsualStationOrWithoutBothPrices(): void
    {
        [$app, $owner, $golf] = $this->scene();
        $comparisons = $this->service($app, FillUpComparisons::class);

        $atUsual = $this->fillAt($app, $golf, $this->tesco, '2026-09-15T10:00:00Z', '11800', '1.399');
        self::assertNull($comparisons->forEntry($owner, $golf, $atUsual), 'at the usual station');

        // Shell listed its price only after this fill-up, and Tesco's was three days old.
        $early = $this->fillAt($app, $golf, $this->shell, '2026-09-14T06:00:00Z', '11700', '1.369');
        self::assertNull($comparisons->forEntry($owner, $golf, $early));

        $unlinked = $this->station($app, 'Corner Garage');
        $elsewhere = $this->fillAt($app, $golf, $unlinked, '2026-09-16T10:00:00Z', '11900', '1.359');
        self::assertNull($comparisons->forEntry($owner, $golf, $elsewhere), 'not a linked station');
    }

    public function testShoppingAroundAddsUpFromThreeComparedFillUps(): void
    {
        [$app, $owner, $golf] = $this->scene();
        $comparisons = $this->service($app, FillUpComparisons::class);

        $this->fillAt($app, $golf, $this->shell, '2026-09-15T10:00:00Z', '11800', '1.369');
        $this->fillAt($app, $golf, $this->shell, '2026-09-20T10:00:00Z', '12400', '1.379');
        self::assertNull($comparisons->shoppingAround($owner, $golf), 'two are not enough');

        $this->fillAt($app, $golf, $this->shell, '2026-09-25T10:00:00Z', '13000', '1.389');
        $shopping = $comparisons->shoppingAround($owner, $golf);
        self::assertNotNull($shopping);
        self::assertSame(3, $shopping->fillUps);
        self::assertSame('2.40', $shopping->total, '£1.20 + £0.80 + £0.40 on fuel');
        self::assertFalse($shopping->withDistance);
        self::assertSame(
            'about £2.40 better off from 3 fill-ups away from your usual station in the last 12 months. (Before the extra driving: add a Home place to count it.)',
            $this->service($app, ComparisonWording::class)->shoppingAround($shopping),
        );
    }

    /**
     * A Golf that fills at Tesco (four times: its usual station) and a
     * listed price history for Tesco and Shell, both linked.
     *
     * @return array{App<ContainerInterface>, \Logbook\Domain\User\User, Vehicle}
     */
    private function scene(): array
    {
        [$app, $owner] = $this->pricesApp();
        $now = new DateTimeImmutable(self::NOW);
        $stations = $this->service($app, StationRepository::class);
        $this->tesco = $this->station($app, 'Tesco', '54.718012', '-6.219034');
        $this->shell = $this->station($app, 'Shell', '54.705000', '-6.240000');
        $stations->setLink($this->tesco->id, new StationLink(FuelFinderProvider::CODE, self::ref('antrim-tesco')), $now);
        $stations->setLink($this->shell->id, new StationLink(FuelFinderProvider::CODE, self::ref('antrim-shell')), $now);

        $golf = $this->vehicle($app);
        // 40 L every 600 km: the usual economy.
        foreach (['2026-06-01' => '9400', '2026-07-01' => '10000', '2026-08-01' => '10600', '2026-09-01' => '11200'] as $day => $km) {
            $this->fillAt($app, $golf, $this->tesco, $day . 'T08:00:00Z', $km, '1.399');
        }

        $history = $this->service($app, ListedPriceRepository::class);
        $history->record(FuelFinderProvider::CODE, self::ref('antrim-tesco'), new PriceChange(FuelGrade::E10_95, '1.399', new DateTimeImmutable('2026-09-11T07:00:00Z')));
        $history->record(FuelFinderProvider::CODE, self::ref('antrim-tesco'), new PriceChange(FuelGrade::E10_95, '1.399', new DateTimeImmutable('2026-09-15T07:00:00Z')));
        $history->record(FuelFinderProvider::CODE, self::ref('antrim-shell'), new PriceChange(FuelGrade::E10_95, '1.369', new DateTimeImmutable('2026-09-15T08:00:00Z')));
        foreach (['2026-09-20', '2026-09-25'] as $day) {
            $history->record(FuelFinderProvider::CODE, self::ref('antrim-tesco'), new PriceChange(FuelGrade::E10_95, '1.399', new DateTimeImmutable($day . 'T07:00:00Z')));
            $history->record(FuelFinderProvider::CODE, self::ref('antrim-shell'), new PriceChange(FuelGrade::E10_95, '1.379', new DateTimeImmutable($day . 'T07:00:00Z')));
        }

        return [$app, $owner, $golf];
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function fillAt(App $app, Vehicle $vehicle, Station $station, string $utc, string $km, string $price): FuelEntry
    {
        $entry = $this->fillUp($app, $vehicle, $utc, $km, '40', number_format(40 * (float) $price, 2, '.', ''), pricePerLitre: $price, grade: FuelGrade::E10_95);
        $this->connection($app)->update('fuel_entries', ['station_id' => $station->id, 'station' => $station->data->name], ['id' => $entry->id]);

        return $this->service($app, FuelService::class)->get($vehicle, $entry->id);
    }
}
