<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Report;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Compliance\ComplianceDocument;
use Logbook\Domain\Compliance\ComplianceDocumentData;
use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\Expense\CostGroup;
use Logbook\Domain\Expense\ExpenseCategory;
use Logbook\Domain\Expense\ExpenseEntry;
use Logbook\Domain\Expense\ExpenseEntryData;
use Logbook\Domain\Fuel\Fuel;
use Logbook\Domain\Fuel\FuelEntry;
use Logbook\Domain\Fuel\FuelEntryData;
use Logbook\Domain\Maintenance\MaintenanceCategory;
use Logbook\Domain\Maintenance\MaintenanceEntry;
use Logbook\Domain\Maintenance\MaintenanceEntryData;
use Logbook\Domain\Odometer\OdometerReading;
use Logbook\Domain\Odometer\OdometerSource;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleStatus;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Kernel;
use Logbook\Service\Expense\CostItem;
use Logbook\Service\Report\CurrencyReport;
use Logbook\Service\Report\MonthTotal;
use Logbook\Service\Report\Report;
use Logbook\Service\Report\ReportCalculator;
use Logbook\Service\Report\ReportFilter;
use Logbook\Service\Report\ReportPeriod;
use Logbook\Service\Report\ReportRange;
use Logbook\Support\Config\AppSettings;
use Logbook\Support\Config\Env;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Display\DisplayContext;
use Logbook\Support\Display\DisplayFormatter;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\I18n\TranslatorFactory;
use Logbook\Support\Units\UnitPreset;
use PHPUnit\Framework\TestCase;

/**
 * Aggregation is where wrong totals hide (phase-5.md): exact sums per
 * currency, costs dated on the owner's calendar (across DST changes), the
 * per-month series, distance from the mileage log, and archived vehicles
 * left out unless asked for.
 */
final class ReportCalculatorTest extends TestCase
{
    private const string LONDON = 'Europe/London';

    public function testSumsAreExactAndSplitByGroupAndMonth(): void
    {
        $golf = self::vehicle(1);
        $items = [
            self::expense($golf, '2026-07-02', '0.1'),
            self::expense($golf, '2026-07-20', '0.2'),
            self::maintenance($golf, '2026-08-14', '189.99'),
            self::fuel($golf, '2026-09-01T08:00:00Z', '60.005'),
            self::compliance($golf, '2026-09-10', '420'),
        ];

        $report = self::report($items, [$golf], ReportPeriod::preset(ReportRange::ThreeMonths, self::date('2026-09-27')));
        $section = $report->currencies[0];

        self::assertSame('670.295000', $section->total->toDecimal(6), '0.1 + 0.2 is exactly 0.3; nothing drifts');
        self::assertSame(5, $section->count);
        self::assertSame('0.300', $section->group(CostGroup::Other)->toDecimal(3));
        self::assertSame('189.990', $section->group(CostGroup::Maintenance)->toDecimal(3));
        self::assertSame('60.005', $section->group(CostGroup::Fuel)->toDecimal(3));
        self::assertSame('420.000', $section->group(CostGroup::Compliance)->toDecimal(3));

        self::assertSame(['2026-07', '2026-08', '2026-09'], self::monthKeys($section));
        self::assertSame(['0.300', '189.990', '480.005'], self::monthTotals($section));
        self::assertSame('60.005', $section->months[2]->amount(CostGroup::Fuel)->toDecimal(3));

        $shares = array_map(static fn ($g): float => round($g->share, 2), $section->groups);
        self::assertSame([8.95, 28.34, 62.66, 0.04], $shares);
        self::assertEqualsWithDelta(100.0, array_sum(array_map(static fn ($g): float => $g->share, $section->groups)), 0.0001);
    }

    public function testMonthsWithNothingSpentAreStillListed(): void
    {
        $golf = self::vehicle(1);
        $report = self::report(
            [self::expense($golf, '2026-01-15', '5')],
            [$golf],
            ReportPeriod::preset(ReportRange::TwelveMonths, self::date('2026-09-27')),
        );

        $section = $report->currencies[0];
        self::assertCount(12, $section->months, 'October 2025 to September 2026');
        self::assertSame('2025-10', $section->months[0]->month->format('Y-m'));
        self::assertSame('2026-09', $section->months[11]->month->format('Y-m'));
        self::assertSame('5.000', $section->total->toDecimal(3));
        self::assertSame('0.416667', $section->averagePerMonth->toDecimal(6), '£5 over 12 months');
    }

    public function testAFillUpCountsOnTheDayItHappenedForTheOwnerAcrossDst(): void
    {
        $golf = self::vehicle(1);
        $zone = self::london();

        // 23:30 UTC on 31 March is 00:30 BST on 1 April: an April cost.
        self::assertSame('2026-04-01', self::fuel($golf, '2026-03-31T23:30:00Z', '10')->date->format('Y-m-d'));
        // 00:30 UTC on 29 March 2026 is still GMT (clocks go forward at 01:00 UTC).
        self::assertSame('2026-03-29', self::fuel($golf, '2026-03-29T00:30:00Z', '10')->date->format('Y-m-d'));
        // 23:30 UTC on 25 October: GMT again, so still the 25th.
        self::assertSame('2026-10-25', self::fuel($golf, '2026-10-25T23:30:00Z', '10')->date->format('Y-m-d'));
        // Auckland is 12–13 hours ahead: 13:00 UTC on 30 June is 1 July there.
        $late = self::fuelEntry($golf, '2026-06-30T13:00:00Z', '10');
        $auckland = CostItem::fromFuel($late, $golf, 'NZD', new DateTimeZone('Pacific/Auckland'));
        self::assertSame('2026-07-01', $auckland->date->format('Y-m-d'));

        $items = [
            self::fuel($golf, '2026-03-31T22:30:00Z', '30'),  // 23:30 BST, 31 March
            self::fuel($golf, '2026-03-31T23:30:00Z', '40'),  // 00:30 BST, 1 April
        ];
        $period = new ReportPeriod(ReportRange::Custom, self::date('2026-03-01'), self::date('2026-04-30'));
        $section = ReportCalculator::build(new ReportFilter($period), [$golf], [1 => 'GBP'], $items, [], $zone)->currencies[0];

        self::assertSame(['30.000', '40.000'], self::monthTotals($section), 'split on the owner’s midnight, not UTC’s');

        // A period ending on 31 March leaves the 00:30 BST fill out.
        $march = new ReportPeriod(ReportRange::Custom, self::date('2026-03-01'), self::date('2026-03-31'));
        $marchOnly = ReportCalculator::build(new ReportFilter($march), [$golf], [1 => 'GBP'], $items, [], $zone);
        self::assertSame('30.000', $marchOnly->currencies[0]->total->toDecimal(3));
        self::assertCount(1, $marchOnly->items);
    }

    public function testCurrenciesAreReportedSeparatelyAndNeverAdded(): void
    {
        $golf = self::vehicle(1);
        $vespa = self::vehicle(2, currency: 'EUR');
        $items = [
            self::expense($golf, '2026-09-01', '10', 'GBP'),
            self::expense($vespa, '2026-09-02', '7.5', 'EUR'),
            self::expense($vespa, '2026-09-03', '2.5', 'EUR'),
        ];

        $report = ReportCalculator::build(
            new ReportFilter(ReportPeriod::preset(ReportRange::ThisMonth, self::date('2026-09-27'))),
            [$golf, $vespa],
            [1 => 'GBP', 2 => 'EUR'],
            $items,
            [],
            self::london(),
        );

        self::assertTrue($report->hasSeveralCurrencies());
        $totals = [];
        foreach ($report->currencies as $section) {
            $totals[$section->currency] = $section->total->toDecimal(2);
        }
        self::assertSame(['EUR' => '10.00', 'GBP' => '10.00'], $totals);

        // A currency with nothing in the period is left out…
        $only = ReportCalculator::build(
            new ReportFilter(ReportPeriod::preset(ReportRange::ThisMonth, self::date('2026-09-27'))),
            [$golf, $vespa],
            [1 => 'GBP', 2 => 'EUR'],
            [$items[0]],
            [],
            self::london(),
        );
        self::assertSame(['GBP'], array_map(static fn (CurrencyReport $s): string => $s->currency, $only->currencies));

        // …but an empty report still has one (empty) section to show.
        $none = ReportCalculator::build(
            new ReportFilter(ReportPeriod::preset(ReportRange::ThisMonth, self::date('2026-09-27'))),
            [$golf],
            [1 => 'GBP'],
            [],
            [],
            self::london(),
        );
        self::assertCount(1, $none->currencies);
        self::assertTrue($none->currencies[0]->isEmpty());
        self::assertTrue($none->currencies[0]->total->isZero());
    }

    public function testCostPerDistanceUsesTheMileageLogAndDisplaysInTheOwnersUnits(): void
    {
        $golf = self::vehicle(1);
        $bike = self::vehicle(2);
        $unlogged = self::vehicle(3);
        $items = [
            self::expense($golf, '2026-09-05', '100'),
            self::expense($bike, '2026-09-06', '20.5'),
        ];
        $readings = [
            1 => [
                self::reading(1, '10000', '2026-08-20T12:00:00Z'),  // before the period: the baseline
                self::reading(1, '10400', '2026-09-10T12:00:00Z'),
                self::reading(1, '11000', '2026-09-25T12:00:00Z'),
            ],
            2 => [self::reading(2, '500', '2026-09-26T12:00:00Z')],  // a single reading: no distance
            // Driven, but no costs logged: its miles must not dilute the fleet figure.
            3 => [self::reading(3, '0', '2026-09-01T12:00:00Z'), self::reading(3, '5000', '2026-09-20T12:00:00Z')],
        ];

        $report = ReportCalculator::build(
            new ReportFilter(ReportPeriod::preset(ReportRange::ThisMonth, self::date('2026-09-27'))),
            [$golf, $bike, $unlogged],
            [1 => 'GBP', 2 => 'GBP', 3 => 'GBP'],
            $items,
            $readings,
            self::london(),
        );
        $section = $report->currencies[0];

        self::assertSame('1000.000', $section->distanceKm, 'the vehicle without costs is left out');
        self::assertCount(2, $section->vehicles);
        self::assertSame('0.120500', $section->costPerKm, '£120.50 over 1,000 km');
        self::assertSame(1, $section->vehicles[0]->vehicle->id, 'biggest spender first');
        self::assertSame('0.100000', $section->vehicles[0]->costPerKm);
        self::assertNull($section->vehicles[1]->costPerKm, 'no distance, no cost per distance');

        $formatter = self::formatter(UnitPreset::Uk);
        self::assertSame('621 mi', $formatter->distance($section->distanceKm));
        self::assertSame('£0.194/mi', $formatter->perDistance($section->costPerKm, 'GBP'), '£0.1205/km × 1.609344');
        self::assertSame('£0.161/mi', $formatter->perDistance($section->vehicles[0]->costPerKm, 'GBP'));
        self::assertSame('£0.10/km', self::formatter(UnitPreset::Metric)->perDistance($section->vehicles[0]->costPerKm, 'GBP'));
    }

    public function testArchivedVehiclesAreLeftOutUnlessIncludedOrPicked(): void
    {
        $golf = self::vehicle(1);
        $sold = self::vehicle(2, archived: true);
        $today = self::date('2026-09-27');
        $period = ReportPeriod::preset(ReportRange::TwelveMonths, $today);

        self::assertSame([1], self::ids((new ReportFilter($period))->scope([$golf, $sold])));
        self::assertSame([1, 2], self::ids((new ReportFilter($period, null, true))->scope([$golf, $sold])));
        self::assertSame([2], self::ids((new ReportFilter($period, 2))->scope([$golf, $sold])), 'picking it is explicit');
        self::assertSame([1], self::ids((new ReportFilter($period, 99))->scope([$golf, $sold])), 'unknown: the fleet');

        $items = [self::expense($golf, '2026-09-01', '10'), self::expense($sold, '2026-09-01', '1000')];
        $filter = ReportFilter::fromQuery([], $today);
        $currencies = [1 => 'GBP', 2 => 'GBP'];
        $report = ReportCalculator::build($filter, $filter->scope([$golf, $sold]), $currencies, $items, [], self::london());
        self::assertSame('10.000', $report->currencies[0]->total->toDecimal(3), 'the sold car’s costs stay out of the total');

        $included = ReportFilter::fromQuery(['include_archived' => '1'], $today);
        $all = ReportCalculator::build($included, $included->scope([$golf, $sold]), $currencies, $items, [], self::london());
        self::assertSame('1010.000', $all->currencies[0]->total->toDecimal(3));
    }

    public function testZeroIsValidAndRollUpsNeverDoubleCount(): void
    {
        $golf = self::vehicle(1);

        $freeWork = self::maintenanceEntry($golf, '2026-09-01', '0');
        self::assertNull(CostItem::fromMaintenance($freeWork, $golf, 'GBP'), 'free work is history, not a cost');
        $freeDocument = self::complianceEntry($golf, '2026-09-01', '0.000');
        self::assertNull(CostItem::fromCompliance($freeDocument, $golf, 'GBP', self::london()));

        $free = self::expense($golf, '2026-09-02', '0');
        $freeCharge = self::fuel($golf, '2026-09-03T10:00:00Z', '0');
        $period = ReportPeriod::preset(ReportRange::ThisMonth, self::date('2026-09-27'));
        $report = self::report([$free, $freeCharge], [$golf], $period);
        $section = $report->currencies[0];
        self::assertSame(2, $section->count, 'a free car park and a free charge are still logged');
        self::assertTrue($section->total->isZero());
        self::assertSame(0.0, $section->groups[0]->share, 'no division by zero');
        self::assertCount(1, $section->vehicles);
    }

    public function testAllTimeStartsAtTheEarliestCost(): void
    {
        $golf = self::vehicle(1);
        $items = [self::expense($golf, '2025-11-20', '12'), self::expense($golf, '2026-09-01', '24')];

        $report = self::report($items, [$golf], ReportPeriod::preset(ReportRange::AllTime, self::date('2026-09-27')));

        self::assertSame('2025-11-20', $report->period->from?->format('Y-m-d'));
        self::assertSame(11, $report->monthCount(), 'November 2025 to September 2026');
        self::assertSame('36.000', $report->currencies[0]->total->toDecimal(3));
        self::assertSame('3.272727', $report->currencies[0]->averagePerMonth->toDecimal(6));
    }

    /**
     * @param list<CostItem> $items
     * @param list<Vehicle> $vehicles
     */
    private static function report(array $items, array $vehicles, ReportPeriod $period): Report
    {
        $currencies = [];
        foreach ($vehicles as $vehicle) {
            $currencies[$vehicle->id] = $vehicle->data->currency ?? 'GBP';
        }

        return ReportCalculator::build(new ReportFilter($period), $vehicles, $currencies, $items, [], self::london());
    }

    private static function london(): DateTimeZone
    {
        return new DateTimeZone(self::LONDON);
    }

    private static function vehicle(int $id, ?string $currency = null, bool $archived = false): Vehicle
    {
        $now = new DateTimeImmutable('2026-01-01 00:00:00 UTC');

        return new Vehicle(
            $id,
            1,
            new VehicleData(VehicleType::Car, 'Make', 'Model ' . $id, FuelType::Petrol, currency: $currency),
            $archived ? VehicleStatus::Archived : VehicleStatus::Active,
            null,
            null,
            $archived ? $now : null,
            $now,
            $now,
        );
    }

    private static function date(string $date): DateTimeImmutable
    {
        $parsed = LocalTime::parseDate($date);
        self::assertNotNull($parsed);

        return $parsed;
    }

    private static function expense(Vehicle $vehicle, string $date, string $amount, string $currency = 'GBP'): CostItem
    {
        $data = new ExpenseEntryData(self::date($date), ExpenseCategory::Parking, $amount);
        $entry = new ExpenseEntry(1, $vehicle->id, $data, self::date($date), self::date($date));

        return CostItem::fromExpense($entry, $vehicle, $currency);
    }

    private static function maintenanceEntry(Vehicle $vehicle, string $date, string $cost): MaintenanceEntry
    {
        $data = new MaintenanceEntryData(self::date($date), MaintenanceCategory::Service, 'Service', $cost);

        return new MaintenanceEntry(1, $vehicle->id, $data, self::date($date), self::date($date));
    }

    private static function maintenance(Vehicle $vehicle, string $date, string $cost): CostItem
    {
        $item = CostItem::fromMaintenance(self::maintenanceEntry($vehicle, $date, $cost), $vehicle, 'GBP');
        self::assertNotNull($item);

        return $item;
    }

    private static function complianceEntry(Vehicle $vehicle, string $start, string $cost): ComplianceDocument
    {
        $data = new ComplianceDocumentData(ComplianceType::Insurance, null, 'Insurer', null, self::date($start), null, $cost);

        return new ComplianceDocument(1, $vehicle->id, $data, self::date($start), self::date($start));
    }

    private static function compliance(Vehicle $vehicle, string $start, string $cost): CostItem
    {
        $item = CostItem::fromCompliance(self::complianceEntry($vehicle, $start, $cost), $vehicle, 'GBP', self::london());
        self::assertNotNull($item);

        return $item;
    }

    private static function fuelEntry(Vehicle $vehicle, string $utc, string $total): FuelEntry
    {
        $at = new DateTimeImmutable($utc);
        $data = new FuelEntryData($at, '10000.000', Fuel::Petrol, '40.000', '1.500000', $total);

        return new FuelEntry(1, $vehicle->id, $data, $at, $at);
    }

    private static function fuel(Vehicle $vehicle, string $utc, string $total): CostItem
    {
        return CostItem::fromFuel(self::fuelEntry($vehicle, $utc, $total), $vehicle, 'GBP', self::london());
    }

    private static function reading(int $vehicleId, string $km, string $utc): OdometerReading
    {
        $at = new DateTimeImmutable($utc);

        return new OdometerReading(1, $vehicleId, $km . '.000', $at, OdometerSource::Manual, null, null, $at, $at);
    }

    private static function formatter(UnitPreset $preset): DisplayFormatter
    {
        $settings = AppSettings::fromEnv(new Env(['APP_TIMEZONE' => 'UTC']), '/app');
        $context = new DisplayContext($settings);
        $context->apply(new DisplayPreferences(
            'en_GB',
            self::LONDON,
            $preset->distance(),
            $preset->volume(),
            $preset->consumption(),
            'GBP',
        ));

        return new DisplayFormatter($context, TranslatorFactory::create(Kernel::rootDir() . '/translations', 'en', null, false));
    }

    /**
     * @return list<string>
     */
    private static function monthKeys(CurrencyReport $section): array
    {
        return array_map(static fn (MonthTotal $m): string => $m->month->format('Y-m'), $section->months);
    }

    /**
     * @return list<string>
     */
    private static function monthTotals(CurrencyReport $section): array
    {
        return array_map(static fn (MonthTotal $m): string => $m->total->toDecimal(3), $section->months);
    }

    /**
     * @param list<Vehicle> $vehicles
     * @return list<int>
     */
    private static function ids(array $vehicles): array
    {
        return array_map(static fn (Vehicle $v): int => $v->id, $vehicles);
    }
}
