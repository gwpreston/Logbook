<?php

declare(strict_types=1);

namespace Logbook\Tests\Support;

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
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Domain\Maintenance\MaintenanceCategory;
use Logbook\Domain\Maintenance\MaintenanceEntry;
use Logbook\Domain\Maintenance\MaintenanceEntryData;
use Logbook\Domain\Odometer\OdometerReadingData;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Repository\UserRepository;
use Logbook\Service\Compliance\ComplianceService;
use Logbook\Service\Expense\ExpenseService;
use Logbook\Service\Fuel\FuelService;
use Logbook\Service\Maintenance\MaintenanceService;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Number\Decimal;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * Builders for the owner's vehicles and the entries that cost money, written
 * through the real services (so fill-ups and maintenance get their odometer
 * readings). For AppTestCase subclasses.
 */
trait CostFixtures
{
    /**
     * @param App<ContainerInterface> $app
     */
    protected function owner(App $app): User
    {
        $owner = $this->service($app, UserRepository::class)->findByUsername('owner');
        self::assertNotNull($owner);

        return $owner;
    }

    /**
     * @param App<ContainerInterface> $app
     */
    protected function vehicle(
        App $app,
        string $make = 'Volkswagen',
        string $model = 'Golf',
        ?string $currency = null,
        FuelType $fuel = FuelType::Petrol,
        VehicleType $type = VehicleType::Car,
    ): Vehicle {
        return $this->service($app, VehicleService::class)->create(
            $this->owner($app),
            new VehicleData(
                $type,
                $make,
                $model,
                $fuel,
                registration: strtoupper(substr($model, 0, 2)) . '19 ABC',
                currency: $currency,
            ),
        );
    }

    /**
     * @param App<ContainerInterface> $app
     * @param string $utc when, as a UTC instant ("2026-09-10T08:00:00Z")
     */
    protected function fillUp(
        App $app,
        Vehicle $vehicle,
        string $utc,
        string $odometerKm,
        string $litres,
        string $total,
        bool $partial = false,
        ?string $pricePerLitre = null,
        ?FuelGrade $grade = null,
    ): FuelEntry {
        $at = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
        $price = $pricePerLitre ?? Decimal::divide($total, $litres, 6);
        $fuel = $grade?->family() ?? Fuel::defaultFor($vehicle->data->fuelType);

        return $this->service($app, FuelService::class)->create(
            $vehicle,
            new FuelEntryData($at, $odometerKm, $fuel, $litres, $price, $total, $partial, grade: $grade),
        );
    }

    /**
     * @param App<ContainerInterface> $app
     */
    protected function maintenance(
        App $app,
        Vehicle $vehicle,
        string $date,
        string $title,
        string $cost,
        ?string $odometerKm = null,
    ): MaintenanceEntry {
        return $this->service($app, MaintenanceService::class)->create(
            $vehicle,
            new MaintenanceEntryData(self::day($date), MaintenanceCategory::Service, $title, $cost, $odometerKm),
            new DateTimeZone('Europe/London'),
        );
    }

    /**
     * @param App<ContainerInterface> $app
     */
    protected function document(
        App $app,
        Vehicle $vehicle,
        ComplianceType $type,
        ?string $start,
        ?string $expiry,
        string $cost,
        ?string $provider = null,
    ): ComplianceDocument {
        return $this->service($app, ComplianceService::class)->create(
            $vehicle,
            new ComplianceDocumentData(
                $type,
                null,
                $provider,
                null,
                $start === null ? null : self::day($start),
                $expiry === null ? null : self::day($expiry),
                $cost,
            ),
        );
    }

    /**
     * @param App<ContainerInterface> $app
     */
    protected function expense(
        App $app,
        Vehicle $vehicle,
        string $date,
        string $amount,
        ExpenseCategory $category = ExpenseCategory::Parking,
        ?string $note = null,
    ): ExpenseEntry {
        return $this->service($app, ExpenseService::class)
            ->create($vehicle, new ExpenseEntryData(self::day($date), $category, $amount, $note));
    }

    /**
     * @param App<ContainerInterface> $app
     */
    protected function reading(App $app, Vehicle $vehicle, string $km, string $utc): void
    {
        $this->service($app, OdometerService::class)->create(
            $vehicle,
            new OdometerReadingData($km, new DateTimeImmutable($utc, new DateTimeZone('UTC'))),
        );
    }

    private static function day(string $date): DateTimeImmutable
    {
        $day = LocalTime::parseDate($date);
        self::assertNotNull($day);

        return $day;
    }
}
