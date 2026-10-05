<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Report;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Compliance\ComplianceDocument;
use Logbook\Domain\Compliance\ComplianceDocumentData;
use Logbook\Domain\Compliance\ComplianceType;
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
use Logbook\Service\Expense\CostItem;
use Logbook\Service\Report\InsurancePayout;
use Logbook\Service\Report\OwnershipCost;
use Logbook\Service\Report\TrueCost;
use Logbook\Service\Report\TrueCostPeriod;
use Logbook\Service\Report\ValueCurve;
use Logbook\Service\Vehicle\Depreciation;
use Logbook\Support\Date\LocalTime;
use PHPUnit\Framework\Assert;

/**
 * Builders for the true cost tests (docs/phases/phase-32.md): vehicles,
 * ledger lines, readings and valuations in GBP, the owner in London.
 */
trait TrueCostFixtures
{
    private const string LONDON = 'Europe/London';

    private static function zone(): DateTimeZone
    {
        return new DateTimeZone(self::LONDON);
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

    private static function valuation(string $on, string $amount, int $id = 1): VehicleValuation
    {
        $now = new DateTimeImmutable('2026-01-01T00:00:00Z');

        return new VehicleValuation($id, 1, new VehicleValuationData(self::date($on), $amount), $now, $now);
    }

    private static function reading(int $id, string $km, string $utc): OdometerReading
    {
        $at = new DateTimeImmutable($utc);

        return new OdometerReading($id, 1, $km, $at, OdometerSource::Manual, null, null, $at, $at);
    }

    private static function expense(Vehicle $vehicle, string $date, string $amount, ExpenseCategory $category = ExpenseCategory::Parking): CostItem
    {
        $data = new ExpenseEntryData(self::date($date), $category, $amount);
        $entry = new ExpenseEntry(1, $vehicle->id, $data, self::date($date), self::date($date));

        return CostItem::fromExpense($entry, $vehicle, 'GBP');
    }

    private static function maintenance(
        Vehicle $vehicle,
        string $date,
        string $cost,
        MaintenanceCategory $category = MaintenanceCategory::Service,
    ): CostItem {
        $data = new MaintenanceEntryData(self::date($date), $category, 'Work', $cost);
        $entry = new MaintenanceEntry(1, $vehicle->id, $data, self::date($date), self::date($date));
        $item = CostItem::fromMaintenance($entry, $vehicle, 'GBP');
        Assert::assertNotNull($item);

        return $item;
    }

    private static function document(Vehicle $vehicle, string $start, string $cost, ?string $expiry = null): CostItem
    {
        $data = new ComplianceDocumentData(
            ComplianceType::Insurance,
            null,
            'Insurer',
            null,
            self::date($start),
            $expiry === null ? null : self::date($expiry),
            $cost,
        );
        $document = new ComplianceDocument(1, $vehicle->id, $data, self::date($start), self::date($start));
        $item = CostItem::fromCompliance($document, $vehicle, 'GBP', self::zone());
        Assert::assertNotNull($item);

        return $item;
    }

    private static function fill(
        Vehicle $vehicle,
        string $utc,
        string $total,
        string $volume = '40.000',
        Fuel $fuel = Fuel::Petrol,
    ): CostItem {
        $at = new DateTimeImmutable($utc);
        $data = new FuelEntryData($at, '10000.000', $fuel, $volume, '1.500000', $total);
        $entry = new FuelEntry(1, $vehicle->id, $data, $at, $at);

        return CostItem::fromFuel($entry, $vehicle, 'GBP', self::zone());
    }

    /**
     * @param list<CostItem> $items
     * @param list<OdometerReading> $readings
     * @param list<VehicleValuation> $valuations
     * @param list<InsurancePayout> $payouts
     */
    private static function ownership(
        Vehicle $vehicle,
        array $items,
        array $readings,
        array $valuations,
        string $today,
        array $payouts = [],
    ): OwnershipCost {
        $depreciation = Depreciation::of($vehicle, $valuations, $readings, self::date($today), self::zone(), 'GBP');
        $cost = OwnershipCost::of($vehicle, $items, $readings, $depreciation, self::date($today), self::zone(), $payouts);
        Assert::assertNotNull($cost);

        return $cost;
    }

    /**
     * @param list<CostItem> $items
     * @param list<OdometerReading> $readings
     * @param list<VehicleValuation> $valuations
     * @param list<InsurancePayout> $payouts
     */
    private static function period(
        Vehicle $vehicle,
        TrueCostPeriod $period,
        array $items,
        array $readings,
        array $valuations = [],
        array $payouts = [],
        int $minDays = 0,
    ): TrueCost {
        return TrueCost::forPeriod(
            $vehicle,
            'GBP',
            $period,
            $items,
            $readings,
            ValueCurve::of($vehicle, $valuations),
            $payouts,
            self::zone(),
            $minDays,
        );
    }

    private static function date(string $value): DateTimeImmutable
    {
        $date = LocalTime::parseDate($value);
        Assert::assertNotNull($date);

        return $date;
    }
}
