<?php

declare(strict_types=1);

namespace Logbook\Service\FuelPrices\Demo;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Domain\FuelPrices\FeedPrice;
use Logbook\Domain\FuelPrices\FeedStation;
use Logbook\Domain\FuelPrices\ProviderKind;
use Logbook\Service\FuelPrices\BulkPriceProvider;
use Logbook\Service\FuelPrices\FeedReport;
use Logbook\Service\FuelPrices\FeedSink;
use Logbook\Service\FuelPrices\ProviderCredentials;
use Logbook\Service\FuelPrices\ProviderLicence;
use Psr\Clock\ClockInterface;

/**
 * Sample prices for the demo (spec.md §7.34 *Sample data*): a dozen
 * clearly fake stations near the demo places, with prices that move a
 * little each hour. Nothing is fetched from anywhere. Registered only
 * outside production; DemoDataSeeder enables it.
 */
final readonly class DemoPriceProvider implements BulkPriceProvider
{
    public const string CODE = 'demo';

    /** ref => [name, brand, postcode, latitude, longitude, grade codes, pence off the base, temporarily closed, hours old] */
    public const array STATIONS = [
        'demo-1' => ['Sample Fuels Antrim Road', 'Sample Fuels', 'BT41 1XA', '54.718500', '-6.212500', ['e10_95', 'e5_97', 'b7'], 0, false, 2],
        'demo-2' => ['Example Petroleum Antrim', 'Example Petroleum', 'BT41 2BB', '54.712050', '-6.201050', ['e10_95', 'e5_97', 'b7'], 2, false, 5],
        'demo-3' => ['Demo Forecourt Station Road', 'Demo Forecourt', 'BT41 4LD', '54.718050', '-6.219050', ['e10_95', 'e5_97', 'b7'], -1, false, 1],
        'demo-4' => ['Placeholder Fuel Co Junction One', 'Placeholder Fuel Co', 'BT41 1AA', '54.705050', '-6.240050', ['e10_95', 'e5_97', 'b7', 'b7_premium'], 4, false, 3],
        'demo-5' => ['Mock Motors Fuel Stop', 'Mock Motors', 'BT41 3MM', '54.741000', '-6.170000', ['e10_95', 'b7'], -3, false, 6],
        'demo-6' => ['Sample Service Station Randalstown', 'Sample Fuels', 'BT41 3SS', '54.749000', '-6.318000', ['e10_95', 'e5_97', 'b7', 'xtl'], -5, false, 8],
        'demo-7' => ['Test Garage Templepatrick', 'Test Garage', 'BT39 0TG', '54.702000', '-6.095000', ['e10_95', 'b7'], -6, true, 9],
        'demo-8' => ['Fictional Fuels Crumlin', 'Fictional Fuels', 'BT29 4FF', '54.623000', '-6.215000', ['e10_95', 'b7'], -2, false, 72],
        'demo-9' => ['Sample Fuels City', 'Sample Fuels', 'BT1 1SF', '54.599000', '-5.928000', ['e10_95', 'e5_97', 'b7', 'b10'], 1, false, 2],
        'demo-10' => ['Example Petroleum Docks', 'Example Petroleum', 'BT3 9EP', '54.608000', '-5.915000', ['e10_95', 'b7', 'b7_premium'], -2, false, 4],
        'demo-11' => ['Demo Forecourt Ormeau', 'Demo Forecourt', 'BT7 1DF', '54.585000', '-5.922000', ['e10_95', 'e5_97', 'b7'], 0, false, 1],
    ];

    /** Base prices, pounds per litre. */
    public const array BASE = [
        'e10_95' => '1.389',
        'e5_97' => '1.519',
        'b7' => '1.459',
        'b7_premium' => '1.589',
        'b10' => '1.439',
        'xtl' => '1.699',
    ];

    public function __construct(private ClockInterface $clock)
    {
    }

    public function code(): string
    {
        return self::CODE;
    }

    public function kind(): ProviderKind
    {
        return ProviderKind::Bulk;
    }

    public function nameKey(): string
    {
        return 'fuel_prices.provider.demo.name';
    }

    public function descriptionKey(): string
    {
        return 'fuel_prices.provider.demo.description';
    }

    public function sendsKey(): string
    {
        return 'fuel_prices.provider.demo.sends';
    }

    public function licence(): ProviderLicence
    {
        return new ProviderLicence('Sample data', 'fuel_prices.attribution.demo', '');
    }

    public function credentials(): array
    {
        return [];
    }

    public function host(): string
    {
        return 'localhost';
    }

    public function minimumRefreshMinutes(): int
    {
        return 30;
    }

    public function currency(): string
    {
        return 'GBP';
    }

    public function gradeChoices(): array
    {
        return [];
    }

    public function gradeMap(array $chosen = []): array
    {
        $map = [];
        foreach (array_keys(self::BASE) as $code) {
            $map[$code] = FuelGrade::from($code);
        }

        return $map;
    }

    public function sync(
        ProviderCredentials $credentials,
        array $gradeMap,
        ?DateTimeImmutable $since,
        FeedSink $sink,
        Closure $cancelled,
    ): FeedReport {
        $report = new FeedReport();
        $stations = self::stations();
        $prices = self::prices($this->clock->now());
        $sink->stations($stations);
        $sink->prices($prices);
        $report->stations = count($stations);
        $report->prices = count($prices);

        return $report;
    }

    /**
     * @return list<FeedStation>
     */
    public static function stations(): array
    {
        $stations = [];
        foreach (self::STATIONS as $ref => [$name, $brand, $postcode, $lat, $lon, $grades, , $closed]) {
            $stations[] = new FeedStation(
                ref: $ref,
                name: $name,
                brand: $brand,
                address: $name . ', Co. Antrim',
                postcode: $postcode,
                latitude: $lat,
                longitude: $lon,
                openingHours: ['usual_days' => array_fill_keys(
                    ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
                    ['open' => '06:00', 'close' => '23:00', 'is_24_hours' => false],
                )],
                amenities: ['customer_toilets'],
                grades: array_map(static fn (string $code): FuelGrade => FuelGrade::from($code), $grades),
                temporarilyClosed: $closed,
            );
        }

        return $stations;
    }

    /**
     * The prices listed at a moment: each station's base plus its offset,
     * and a penny or two that moves with the hour.
     *
     * @return list<FeedPrice>
     */
    public static function prices(DateTimeImmutable $now): array
    {
        $hour = (int) floor($now->getTimestamp() / 3600);
        $prices = [];
        $index = 0;
        foreach (self::STATIONS as $ref => [, , , , , $grades, $offset, , $hoursOld]) {
            $index++;
            $base = $now->setTimezone(new DateTimeZone('UTC'))->modify(sprintf('-%d hours', $hoursOld));
            $reported = $base->setTime((int) $base->format('G'), (13 * $index) % 60);
            $wobble = (($hour + $index) % 3) - 1;
            foreach ($grades as $code) {
                $pence = (int) round((float) self::BASE[$code] * 1000) + ($offset + $wobble) * 10;
                $prices[] = new FeedPrice($ref, FuelGrade::from($code), number_format($pence / 1000, 3, '.', ''), $reported);
            }
        }

        return $prices;
    }
}
