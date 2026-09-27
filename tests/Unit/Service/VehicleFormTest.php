<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service;

use DateTimeImmutable;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleStatus;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Service\Vehicle\VehicleForm;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Units\ConsumptionUnit;
use Logbook\Support\Units\DistanceUnit;
use Logbook\Support\Units\UnitPreset;
use Logbook\Support\Units\VolumeUnit;
use Logbook\Support\Validation\ValidationErrors;
use PHPUnit\Framework\TestCase;

final class VehicleFormTest extends TestCase
{
    private const array MINIMAL = ['type' => 'car', 'make' => 'Volkswagen', 'model' => 'Golf', 'fuel_type' => 'petrol'];

    public function testMinimalVehicle(): void
    {
        $data = $this->parse(self::MINIMAL);

        self::assertSame(VehicleType::Car, $data->type);
        self::assertSame('Golf', $data->model);
        self::assertNull($data->year);
        self::assertNull($data->registration);
        self::assertNull($data->capacity);
        self::assertNull($data->currency);
        self::assertNull($data->purchasePrice);
    }

    public function testRequiredFields(): void
    {
        $errors = VehicleForm::parse([], self::prefs(), 2026);

        self::assertInstanceOf(ValidationErrors::class, $errors);
        self::assertSame(['type', 'make', 'model', 'fuel_type'], array_keys($errors->all()));
    }

    public function testZeroPricesAreValid(): void
    {
        $data = $this->parse(self::MINIMAL + ['purchase_price' => '0', 'sale_price' => '0.00']);

        self::assertSame('0.000', $data->purchasePrice);
        self::assertSame('0.000', $data->salePrice);
    }

    public function testTankCapacityIsStoredInLitres(): void
    {
        $gallons = self::prefs(VolumeUnit::UkGallon);
        $data = $this->parse(self::MINIMAL + ['capacity' => '11'], $gallons);

        self::assertSame('50.007', $data->capacity);

        $vehicle = self::vehicle($data);
        self::assertSame('11', VehicleForm::values($vehicle, $gallons)['capacity']);
        self::assertSame('50.007', VehicleForm::values($vehicle, self::prefs())['capacity']);
    }

    public function testThreeDecimalCapacityRoundTripsInUsGallons(): void
    {
        $gallons = self::prefs(VolumeUnit::UsGallon);
        $data = $this->parse(self::MINIMAL + ['capacity' => '13.207'], $gallons);

        self::assertSame('13.207', VehicleForm::values(self::vehicle($data), $gallons)['capacity']);
    }

    public function testBatteryCapacityIsKwhWhateverTheVolumeUnit(): void
    {
        $ev = ['type' => 'car', 'make' => 'Kia', 'model' => 'EV6', 'fuel_type' => 'ev', 'capacity' => '77.4'];
        $data = $this->parse($ev, self::prefs(VolumeUnit::UsGallon));

        self::assertSame(FuelType::Electric, $data->fuelType);
        self::assertSame('77.400', $data->capacity);
    }

    public function testNormalisesRegistrationAndVin(): void
    {
        $data = $this->parse(self::MINIMAL + ['registration' => ' lb19   ktr ', 'vin' => 'wvw zzz 1k-z5w 123456']);

        self::assertSame('LB19 KTR', $data->registration);
        self::assertSame('WVWZZZ1KZ5W123456', $data->vin);
    }

    public function testFieldProblems(): void
    {
        $errors = VehicleForm::parse(self::MINIMAL + [
            'year' => '2028',
            'vin' => 'NOT-A-VIN-BECAUSE-TOO-LONG',
            'capacity' => '-5',
            'currency' => 'XXX',
            'purchase_date' => '2024-06-01',
            'sale_date' => '2024-05-31',
        ], self::prefs(), 2026);

        self::assertInstanceOf(ValidationErrors::class, $errors);
        $all = $errors->all();
        self::assertSame(['key' => 'validation.max', 'params' => ['max' => '2027']], $all['year']);
        self::assertSame('vehicle.vin_invalid', $all['vin']['key']);
        self::assertSame('validation.min', $all['capacity']['key']);
        self::assertSame('validation.choice', $all['currency']['key']);
        self::assertSame('vehicle.sale_before_purchase', $all['sale_date']['key']);
    }

    public function testNextYearsModelAndSameDaySaleAreFine(): void
    {
        $data = $this->parse(self::MINIMAL + [
            'year' => '2027',
            'purchase_date' => '2024-06-01',
            'sale_date' => '2024-06-01',
            'currency' => 'EUR',
        ]);

        self::assertSame(2027, $data->year);
        self::assertSame('EUR', $data->currency);
    }

    /**
     * @param array<string, string> $input
     */
    private function parse(array $input, ?DisplayPreferences $prefs = null): VehicleData
    {
        $data = VehicleForm::parse($input, $prefs ?? self::prefs(), 2026);
        $problems = $data instanceof ValidationErrors ? (string) json_encode($data->all()) : '';
        self::assertInstanceOf(VehicleData::class, $data, $problems);

        return $data;
    }

    private static function prefs(VolumeUnit $volume = VolumeUnit::Litre): DisplayPreferences
    {
        return new DisplayPreferences('en_GB', 'Europe/London', DistanceUnit::Mile, $volume, ConsumptionUnit::MpgUk, 'GBP');
    }

    private static function vehicle(VehicleData $data): Vehicle
    {
        $now = new DateTimeImmutable('2026-09-27T10:00:00Z');

        return new Vehicle(1, 1, $data, VehicleStatus::Active, null, null, null, $now, $now);
    }
}
