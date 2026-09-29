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
use Logbook\Domain\Valuation\VehicleValuation;
use Logbook\Domain\Valuation\VehicleValuationData;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleStatus;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Kernel;
use Logbook\Service\Expense\CostItem;
use Logbook\Service\Report\OwnershipCost;
use Logbook\Service\Report\OwnershipSection;
use Logbook\Service\Report\OwnershipStart;
use Logbook\Service\Report\ReportPeriod;
use Logbook\Service\Report\ReportRange;
use Logbook\Service\Vehicle\Depreciation;
use Logbook\Support\Config\AppSettings;
use Logbook\Support\Config\Env;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Display\DisplayContext;
use Logbook\Support\Display\DisplayFormatter;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\I18n\TranslatorFactory;
use Logbook\Support\Number\Decimal;
use Logbook\Support\Units\UnitPreset;
use PHPUnit\Framework\TestCase;

/**
 * Cost of ownership (phase-14.2.md): the ownership period, running costs
 * plus depreciation each over its own period, and nothing shown as complete
 * when a part is missing.
 */
final class OwnershipCostTest extends TestCase
{
    private const string LONDON = 'Europe/London';
    /** 36,000 and 39,000 miles in kilometres. */
    private const string MILES_36K = '57936.384';
    private const string MILES_39K = '62764.416';

    public function testTheWorkedExample(): void
    {
        // Bought £15,000 on 1 Mar 2023; valued £9,800 on 1 Mar 2026 (36,000 mi in between);
        // £11,700 of running costs and 39,000 mi from purchase to 1 Sep 2026.
        $golf = self::vehicle(purchased: '2023-03-01', price: '15000.000');
        $items = [
            self::expense($golf, '2023-02-28', '500'),  // a deposit the day before: outside the period
            self::fuel($golf, '2023-06-01T12:00:00Z', '7000.000'),
            self::maintenance($golf, '2024-05-10', '3000'),
            self::compliance($golf, '2025-03-01', '1700'),
        ];
        $readings = [
            self::reading(1, '10000', '2023-03-01T12:00:00Z'),
            self::reading(2, self::km('10000', self::MILES_36K), '2026-03-01T12:00:00Z'),
            self::reading(3, self::km('10000', self::MILES_39K), '2026-09-01T12:00:00Z'),
        ];
        $valuations = [self::valuation('2026-03-01', '9800.000')];

        $cost = self::of($golf, $items, $readings, $valuations, '2026-09-01');

        self::assertNotNull($cost);
        self::assertSame(OwnershipStart::Purchase, $cost->start);
        self::assertSame('2023-03-01', $cost->period->from?->format('Y-m-d'));
        self::assertSame('2026-09-01', $cost->period->to->format('Y-m-d'), 'running costs run to today');
        self::assertSame('11700.000', $cost->running->toDecimal(3), 'the deposit before the purchase is left out');
        self::assertSame(3, $cost->count);
        self::assertSame('7000.000', self::group($cost, CostGroup::Fuel));
        self::assertSame('3000.000', self::group($cost, CostGroup::Maintenance));
        self::assertSame('1700.000', self::group($cost, CostGroup::Compliance));
        self::assertSame('5200.000', $cost->depreciationCost?->toDecimal(3));
        self::assertSame('16900.000', $cost->total?->toDecimal(3));
        self::assertSame('2026-03-01', $cost->valuedOn()?->format('Y-m-d'), 'labelled "depreciation to 1 Mar 2026"');
        self::assertTrue($cost->isComplete());
        self::assertFalse($cost->isLifetime());
        self::assertSame(self::MILES_39K, $cost->distanceKm);
        self::assertSame(43, $cost->months, 'March 2023 to September 2026, the current month counting');
        self::assertSame(3, $cost->ownedFor->years);
        self::assertSame(6, $cost->ownedFor->months);

        $uk = self::formatter(UnitPreset::Uk);
        self::assertSame('£0.30/mi', $uk->perDistance($cost->runningPerKm, 'GBP'));
        self::assertSame('£0.144/mi', $uk->perDistance($cost->depreciationPerKm, 'GBP'));
        self::assertSame('£0.444/mi', $uk->perDistance($cost->perKm, 'GBP'));
        self::assertFalse($cost->perKmIsPartial);

        // £11,700 ÷ 43 months + £1,733.333 a year ÷ 12.
        self::assertSame('272.093023', $cost->runningPerMonth?->toDecimal(6));
        self::assertSame('144.444417', $cost->depreciationPerMonth?->toDecimal(6));
        self::assertSame('416.537440', $cost->perMonth?->toDecimal(6));
        self::assertFalse($cost->perMonthIsPartial);
    }

    public function testWithoutAPurchaseDateTheFirstLineOrReadingStartsIt(): void
    {
        $car = self::vehicle(purchased: null, price: null);
        $items = [self::expense($car, '2024-05-10', '20')];

        $byReading = self::of($car, $items, [self::reading(1, '5000', '2024-05-04T12:00:00Z')], [], '2026-09-01');
        self::assertNotNull($byReading);
        self::assertSame(OwnershipStart::FirstLogged, $byReading->start);
        self::assertSame('2024-05-04', $byReading->period->from?->format('Y-m-d'), 'the reading came first');

        $byCost = self::of($car, $items, [self::reading(1, '5000', '2024-06-01T12:00:00Z')], [], '2026-09-01');
        self::assertSame('2024-05-10', $byCost?->period->from?->format('Y-m-d'), 'the cost came first');

        self::assertNull(self::of($car, [], [], [], '2026-09-01'), 'nothing logged: no period');
        $future = self::vehicle(purchased: '2026-10-01', price: '1000.000');
        self::assertNull(self::of($future, [], [], [], '2026-09-01'), 'bought after today: no period');
    }

    public function testASoldVehicleEndsOnTheSaleDateWithExactLifetimeFigures(): void
    {
        $fiesta = self::vehicle(purchased: '2016-06-30', price: '6500.000', sold: '2025-11-20', salePrice: '2100.000');
        $items = [
            self::expense($fiesta, '2020-01-01', '3000'),
            self::expense($fiesta, '2025-11-21', '60'),  // after the sale: outside the period
        ];
        $readings = [self::reading(1, '20000', '2016-06-30T12:00:00Z'), self::reading(2, '90000', '2025-11-20T12:00:00Z')];

        $cost = self::of($fiesta, $items, $readings, [self::valuation('2025-10-02', '2300.000')], '2026-09-01');

        self::assertNotNull($cost);
        self::assertSame('2025-11-20', $cost->period->to->format('Y-m-d'));
        self::assertTrue($cost->isLifetime(), 'labelled "Lifetime, sold 20 Nov 2025"');
        self::assertSame('2025-11-20', $cost->valuedOn()?->format('Y-m-d'), 'depreciation to the sale, not the valuation');
        self::assertSame('3000.000', $cost->running->toDecimal(3));
        self::assertSame('7400.000', $cost->total?->toDecimal(3), '£3,000 + (£6,500 − £2,100)');
        self::assertSame('0.105714', $cost->perKm, '(£3,000 + £4,400) ÷ 70,000 km');
    }

    public function testASaleDateWithoutAPriceEndsThePeriodButIsNotLifetime(): void
    {
        $car = self::vehicle(purchased: '2022-01-01', price: '10000.000', sold: '2025-06-30');

        $cost = self::of($car, [], [], [self::valuation('2025-01-01', '7000.000')], '2026-09-01');

        self::assertNotNull($cost);
        self::assertSame('2025-06-30', $cost->period->to->format('Y-m-d'));
        self::assertFalse($cost->isLifetime());
        self::assertSame('2025-01-01', $cost->valuedOn()?->format('Y-m-d'), 'depreciation to the latest valuation');
    }

    public function testTheMonthCountMatchesReportsAcrossADstChange(): void
    {
        // Sold on 31 March: a fill at 00:30 BST on 1 April (23:30 UTC on 31 March) is an April cost, after the sale.
        $car = self::vehicle(purchased: '2025-10-20', price: '9000.000', sold: '2026-03-31', salePrice: '8000.000');
        $items = [
            self::fuel($car, '2026-03-31T22:30:00Z', '50.000'),  // 23:30 BST on 31 March: in
            self::fuel($car, '2026-03-31T23:30:00Z', '40.000'),  // 00:30 BST on 1 April: out
        ];
        $cost = self::of($car, $items, [], [], '2026-09-01');

        self::assertNotNull($cost);
        self::assertSame('50.000', $cost->running->toDecimal(3));
        $reports = new ReportPeriod(ReportRange::Custom, self::date('2025-10-20'), self::date('2026-03-31'));
        self::assertSame(count($reports->months()), $cost->months);
        self::assertSame(6, $cost->months, 'October to March, across both DST changes');
    }

    public function testWithoutAPurchasePriceOnlyRunningCostsAreShown(): void
    {
        // A leased EV: its lease payments are what it cost.
        $ev = self::vehicle(purchased: null, price: null);
        $items = [self::finance($ev, '2025-01-05', '399'), self::finance($ev, '2025-02-05', '399')];
        $readings = [self::reading(1, '1000', '2025-01-01T12:00:00Z'), self::reading(2, '9000', '2025-12-31T12:00:00Z')];

        $cost = self::of($ev, $items, $readings, [], '2026-01-01');

        self::assertNotNull($cost);
        self::assertFalse($cost->isComplete());
        self::assertNull($cost->total);
        self::assertNull($cost->depreciationCost);
        self::assertNull($cost->valuedOn());
        self::assertSame('798.000', $cost->running->toDecimal(3));
        self::assertSame('798.000', self::group($cost, CostGroup::Other), 'finance is an ad-hoc expense');
        self::assertSame('0.099750', $cost->perKm);
        self::assertTrue($cost->perKmIsPartial, 'marked "running costs only"');
        self::assertTrue($cost->perMonthIsPartial);
    }

    public function testWithoutDistanceThereIsNoPerDistance(): void
    {
        $car = self::vehicle(purchased: '2024-01-01', price: '10000.000');

        $cost = self::of(
            $car,
            [self::expense($car, '2024-02-01', '100')],
            [],
            [self::valuation('2025-01-01', '8000.000')],
            '2026-01-01',
        );

        self::assertNotNull($cost);
        self::assertNull($cost->distanceKm);
        self::assertNull($cost->perKm);
        self::assertNull($cost->runningPerKm);
        self::assertNotNull($cost->perMonth);
        self::assertSame('2100.000', $cost->total?->toDecimal(3));
    }

    public function testUnderNinetyDaysThereAreNoRatesButTheTotalsShow(): void
    {
        $car = self::vehicle(purchased: '2026-01-01', price: '10000.000');
        $readings = [self::reading(1, '100', '2026-01-01T12:00:00Z'), self::reading(2, '2000', '2026-03-30T12:00:00Z')];
        $valuations = [self::valuation('2026-03-01', '9500.000')];
        $items = [self::expense($car, '2026-02-01', '100')];

        $short = self::of($car, $items, $readings, $valuations, '2026-03-31');  // 89 days
        self::assertNotNull($short);
        self::assertTrue($short->isTooShort());
        self::assertNull($short->perKm);
        self::assertNull($short->perMonth);
        self::assertSame('600.000', $short->total?->toDecimal(3));

        $long = self::of($car, $items, $readings, $valuations, '2026-04-01');  // 90 days
        self::assertNotNull($long);
        self::assertFalse($long->isTooShort());
        self::assertNotNull($long->perKm);
        self::assertNotNull($long->perMonth);
        self::assertTrue($long->perKmIsPartial, 'the value came under 90 days after the purchase: no depreciation rate');
        self::assertTrue($long->perMonthIsPartial);
    }

    public function testAGainReducesTheTotalAndLeavesPerDistanceToRunningCosts(): void
    {
        $classic = self::vehicle(purchased: '2020-03-01', price: '12000.000');
        $readings = [self::reading(1, '1000', '2020-03-01T12:00:00Z'), self::reading(2, '11000', '2026-03-01T12:00:00Z')];

        $cost = self::of(
            $classic,
            [self::expense($classic, '2021-01-01', '2000')],
            $readings,
            [self::valuation('2026-03-01', '13100.000')],
            '2026-03-01',
        );

        self::assertNotNull($cost);
        self::assertSame('-1100.000', $cost->depreciationCost?->toDecimal(3), 'money back');
        self::assertSame('900.000', $cost->total?->toDecimal(3), '£2,000 − £1,100');
        self::assertNull($cost->depreciationPerKm);
        self::assertSame('0.200000', $cost->perKm, '£2,000 ÷ 10,000 km');
        self::assertTrue($cost->perKmIsPartial);
        self::assertTrue($cost->perMonthIsPartial);
    }

    public function testALossWithoutMileageBackToThePurchaseIsNotAddedPerDistance(): void
    {
        $car = self::vehicle(purchased: '2023-03-01', price: '15000.000');
        // The first reading is after the purchase: depreciation per distance is unknown.
        $readings = [self::reading(1, '20000', '2024-01-01T12:00:00Z'), self::reading(2, '40000', '2026-03-01T12:00:00Z')];

        $cost = self::of(
            $car,
            [self::expense($car, '2024-06-01', '2000')],
            $readings,
            [self::valuation('2026-03-01', '9800.000')],
            '2026-03-01',
        );

        self::assertNotNull($cost);
        self::assertSame('0.100000', $cost->perKm, 'running only: £2,000 ÷ 20,000 km');
        self::assertTrue($cost->perKmIsPartial);
        self::assertFalse($cost->perMonthIsPartial, 'per year is still known');
    }

    public function testTheFleetRowSumsAndDividesOnlyWhatIsComplete(): void
    {
        $golf = self::vehicle(purchased: '2023-03-01', price: '15000.000', id: 1);
        $lease = self::vehicle(purchased: null, price: null, id: 2);
        $noMiles = self::vehicle(purchased: '2024-01-01', price: '5000.000', id: 3);
        $golfReadings = [self::reading(1, '0', '2023-03-01T12:00:00Z', 1), self::reading(2, '10000', '2026-03-01T12:00:00Z', 1)];
        $leaseReadings = [self::reading(3, '0', '2025-01-01T12:00:00Z', 2), self::reading(4, '5000', '2026-01-01T12:00:00Z', 2)];

        $rows = [
            self::of(
                $golf,
                [self::expense($golf, '2024-01-01', '1000')],
                $golfReadings,
                [self::valuation('2026-03-01', '14000.000', 1)],
                '2026-03-01',
            ),
            self::of($lease, [self::finance($lease, '2025-01-05', '400')], $leaseReadings, [], '2026-03-01'),
            self::of(
                $noMiles,
                [self::expense($noMiles, '2024-02-01', '300')],
                [],
                [self::valuation('2025-06-01', '4500.000', 3)],
                '2026-03-01',
            ),
        ];
        $section = OwnershipSection::of('GBP', array_values(array_filter($rows)));

        self::assertSame('1700.000', $section->running->toDecimal(3));
        self::assertSame('1500.000', $section->depreciation->toDecimal(3));
        self::assertSame('2800.000', $section->total?->toDecimal(3), '£2,000 + £800; the lease has no total');
        self::assertSame(2, $section->completeCount);
        self::assertTrue($section->isTotalPartial(), '"2 of 3 vehicles"');
        self::assertSame('15000', $section->distanceKm);
        self::assertSame('0.200000', $section->perKm, 'only the Golf has a total and a distance: £2,000 ÷ 10,000 km');
    }

    /**
     * @param list<CostItem> $items
     * @param list<OdometerReading> $readings
     * @param list<VehicleValuation> $valuations
     */
    private static function of(Vehicle $vehicle, array $items, array $readings, array $valuations, string $today): ?OwnershipCost
    {
        $zone = new DateTimeZone(self::LONDON);
        $depreciation = Depreciation::of($vehicle, $valuations, $readings, self::date($today), $zone, 'GBP');

        return OwnershipCost::of($vehicle, $items, $readings, $depreciation, self::date($today), $zone);
    }

    private static function group(OwnershipCost $cost, CostGroup $group): string
    {
        foreach ($cost->groups as $total) {
            if ($total->group === $group) {
                return $total->amount->toDecimal(3);
            }
        }

        return '';
    }

    private static function vehicle(
        ?string $purchased,
        ?string $price,
        ?string $sold = null,
        ?string $salePrice = null,
        int $id = 1,
    ): Vehicle {
        $now = new DateTimeImmutable('2026-01-01T00:00:00Z');

        return new Vehicle($id, 1, new VehicleData(
            type: VehicleType::Car,
            make: 'Volkswagen',
            model: 'Golf ' . $id,
            fuelType: FuelType::Petrol,
            purchaseDate: $purchased === null ? null : self::date($purchased),
            purchasePrice: $price,
            saleDate: $sold === null ? null : self::date($sold),
            salePrice: $salePrice,
        ), VehicleStatus::Active, null, null, null, $now, $now);
    }

    private static function valuation(string $on, string $amount, int $vehicleId = 1): VehicleValuation
    {
        $now = new DateTimeImmutable('2026-01-01T00:00:00Z');

        return new VehicleValuation(1, $vehicleId, new VehicleValuationData(self::date($on), $amount), $now, $now);
    }

    private static function reading(int $id, string $km, string $utc, int $vehicleId = 1): OdometerReading
    {
        $at = new DateTimeImmutable($utc);

        return new OdometerReading($id, $vehicleId, $km, $at, OdometerSource::Manual, null, null, $at, $at);
    }

    private static function km(string $start, string $more): string
    {
        return Decimal::add($start, $more);
    }

    private static function expense(Vehicle $vehicle, string $date, string $amount): CostItem
    {
        return self::adHoc($vehicle, $date, $amount, ExpenseCategory::Parking);
    }

    private static function finance(Vehicle $vehicle, string $date, string $amount): CostItem
    {
        return self::adHoc($vehicle, $date, $amount, ExpenseCategory::Finance);
    }

    private static function adHoc(Vehicle $vehicle, string $date, string $amount, ExpenseCategory $category): CostItem
    {
        $data = new ExpenseEntryData(self::date($date), $category, $amount);

        $entry = new ExpenseEntry(1, $vehicle->id, $data, self::date($date), self::date($date));

        return CostItem::fromExpense($entry, $vehicle, 'GBP');
    }

    private static function maintenance(Vehicle $vehicle, string $date, string $cost): CostItem
    {
        $data = new MaintenanceEntryData(self::date($date), MaintenanceCategory::Service, 'Service', $cost);
        $entry = new MaintenanceEntry(1, $vehicle->id, $data, self::date($date), self::date($date));
        $item = CostItem::fromMaintenance($entry, $vehicle, 'GBP');
        self::assertNotNull($item);

        return $item;
    }

    private static function compliance(Vehicle $vehicle, string $start, string $cost): CostItem
    {
        $data = new ComplianceDocumentData(ComplianceType::Insurance, null, 'Insurer', null, self::date($start), null, $cost);
        $document = new ComplianceDocument(1, $vehicle->id, $data, self::date($start), self::date($start));
        $item = CostItem::fromCompliance($document, $vehicle, 'GBP', new DateTimeZone(self::LONDON));
        self::assertNotNull($item);

        return $item;
    }

    private static function fuel(Vehicle $vehicle, string $utc, string $total): CostItem
    {
        $at = new DateTimeImmutable($utc);
        $data = new FuelEntryData($at, '10000.000', Fuel::Petrol, '40.000', '1.500000', $total);

        $entry = new FuelEntry(1, $vehicle->id, $data, $at, $at);

        return CostItem::fromFuel($entry, $vehicle, 'GBP', new DateTimeZone(self::LONDON));
    }

    private static function date(string $value): DateTimeImmutable
    {
        $date = LocalTime::parseDate($value);
        self::assertNotNull($date);

        return $date;
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
}
