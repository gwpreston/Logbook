<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleStatus;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Service\Vehicle\NewVehicle;
use Logbook\Service\Vehicle\VehicleForm;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Units\ConsumptionUnit;
use Logbook\Support\Units\DistanceUnit;
use Logbook\Support\Units\UnitPreset;
use Logbook\Support\Units\VolumeUnit;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Tests\Support\MutableClock;
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
        $errors = VehicleForm::parse([], self::prefs(), self::today());

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
        ], self::prefs(), self::today());

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

    public function testDescriptiveLineSkipsWhatIsNotSet(): void
    {
        $full = $this->parse(self::MINIMAL + ['year' => '2019', 'variant' => '1.5 TSI Life']);
        self::assertSame('2019 Volkswagen Golf 1.5 TSI Life', self::vehicle($full)->description());

        self::assertSame('Volkswagen Golf', self::vehicle($this->parse(self::MINIMAL))->description());
        self::assertSame('Volkswagen Golf GTI', self::vehicle($this->parse(self::MINIMAL + ['variant' => 'GTI']))->description());
        self::assertSame('2019 Volkswagen Golf', self::vehicle($this->parse(self::MINIMAL + ['year' => '2019']))->description());
    }

    public function testVariantIsTrimmedAndBlankIsNull(): void
    {
        self::assertSame('ST-Line X', $this->parse(self::MINIMAL + ['variant' => "  ST-Line X \t"])->variant);
        self::assertNull($this->parse(self::MINIMAL + ['variant' => '   '])->variant);
        self::assertSame(str_repeat('v', 100), $this->parse(self::MINIMAL + ['variant' => str_repeat('v', 100)])->variant);

        $errors = VehicleForm::parse(self::MINIMAL + ['variant' => str_repeat('v', 101)], self::prefs(), self::today());
        self::assertInstanceOf(ValidationErrors::class, $errors);
        self::assertSame('validation.too_long', $errors->all()['variant']['key']);
    }

    public function testFirstRegistrationIsACalendarDate(): void
    {
        $data = $this->parse(self::MINIMAL + ['first_registered_on' => '2019-03-14']);

        self::assertSame('2019-03-14', $data->firstRegisteredOn?->format('Y-m-d'));
        self::assertSame('2019-03-14', VehicleForm::values(self::vehicle($data), self::prefs())['first_registered_on']);
        self::assertNull($this->parse(self::MINIMAL)->firstRegisteredOn);
    }

    public function testFirstRegistrationCannotBeAfterTodayInTheOwnersTimeZone(): void
    {
        // 23:30 UTC on 27 September: already the 28th in Auckland, still the 27th in New York.
        $clock = new MutableClock(new DateTimeImmutable('2026-09-27T23:30:00Z'));
        $auckland = LocalTime::today($clock, new DateTimeZone('Pacific/Auckland'));
        $newYork = LocalTime::today($clock, new DateTimeZone('America/New_York'));
        $input = self::MINIMAL + ['first_registered_on' => '2026-09-28'];

        self::assertInstanceOf(VehicleData::class, VehicleForm::parse($input, self::prefs(), $auckland));

        $errors = VehicleForm::parse($input, self::prefs(), $newYork);
        self::assertInstanceOf(ValidationErrors::class, $errors);
        self::assertSame('vehicle.registered_in_future', $errors->all()['first_registered_on']['key']);

        self::assertInstanceOf(
            VehicleData::class,
            VehicleForm::parse(self::MINIMAL + ['first_registered_on' => '2026-09-27'], self::prefs(), $newYork),
        );
    }

    public function testFirstRegistrationBefore1885IsRejected(): void
    {
        $errors = VehicleForm::parse(self::MINIMAL + ['first_registered_on' => '1884-12-31'], self::prefs(), self::today());
        self::assertInstanceOf(ValidationErrors::class, $errors);
        self::assertSame('vehicle.registered_too_early', $errors->all()['first_registered_on']['key']);

        $first = $this->parse(self::MINIMAL + ['first_registered_on' => '1885-01-01']);
        self::assertSame('1885-01-01', $first->firstRegisteredOn?->format('Y-m-d'));

        $errors = VehicleForm::parse(self::MINIMAL + ['first_registered_on' => '2021-02-30'], self::prefs(), self::today());
        self::assertInstanceOf(ValidationErrors::class, $errors);
        self::assertSame('validation.date', $errors->all()['first_registered_on']['key']);
    }

    public function testModelYearWellAfterRegistrationWarnsButSaves(): void
    {
        $plusTwo = $this->parse(self::MINIMAL + ['year' => '2023', 'first_registered_on' => '2021-06-01']);
        self::assertSame(['year' => '2023', 'registered' => '2021'], VehicleForm::modelYearWarning($plusTwo));

        // Next year's model registered this year is normal, and so is an old model registered late.
        $plusOne = $this->parse(self::MINIMAL + ['year' => '2022', 'first_registered_on' => '2021-09-01']);
        self::assertNull(VehicleForm::modelYearWarning($plusOne));
        $older = $this->parse(self::MINIMAL + ['year' => '1998', 'first_registered_on' => '2021-06-01']);
        self::assertNull(VehicleForm::modelYearWarning($older));
        self::assertNull(VehicleForm::modelYearWarning($this->parse(self::MINIMAL + ['year' => '2023'])));
        self::assertNull(VehicleForm::modelYearWarning($this->parse(self::MINIMAL + ['first_registered_on' => '2021-06-01'])));
    }

    public function testCurrentOdometerIsTypedInTheOwnersUnitAndStoredInKm(): void
    {
        $miles = $this->parseNew(self::MINIMAL + ['current_odometer' => '10000']);
        self::assertSame('16093.440', $miles->startingOdometerKm);

        self::assertSame('0.000', $this->parseNew(self::MINIMAL + ['current_odometer' => '0'])->startingOdometerKm);
        self::assertNull($this->parseNew(self::MINIMAL + ['current_odometer' => ''])->startingOdometerKm);
        self::assertNull($this->parseNew(self::MINIMAL)->startingOdometerKm);
    }

    public function testCurrentOdometerErrorsShowWithTheVehicleErrors(): void
    {
        $errors = VehicleForm::parseNew(['current_odometer' => '-1'], self::prefs(), self::today());

        self::assertInstanceOf(ValidationErrors::class, $errors);
        self::assertSame(['type', 'make', 'model', 'fuel_type', 'current_odometer'], array_keys($errors->all()));

        $errors = VehicleForm::parseNew(self::MINIMAL + ['current_odometer' => 'lots'], self::prefs(), self::today());
        self::assertInstanceOf(ValidationErrors::class, $errors);
        self::assertSame(['current_odometer'], array_keys($errors->all()));
    }

    public function testTheEditFormIgnoresACurrentOdometer(): void
    {
        $data = VehicleForm::parse(self::MINIMAL + ['current_odometer' => 'lots'], self::prefs(), self::today());
        self::assertInstanceOf(VehicleData::class, $data);
    }

    /**
     * @param array<string, string> $input
     */
    private function parseNew(array $input): NewVehicle
    {
        $new = VehicleForm::parseNew($input, self::prefs(), self::today());
        $problems = $new instanceof ValidationErrors ? (string) json_encode($new->all()) : '';
        self::assertInstanceOf(NewVehicle::class, $new, $problems);

        return $new;
    }

    private static function today(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-27', new DateTimeZone('UTC'));
    }

    /**
     * @param array<string, string> $input
     */
    private function parse(array $input, ?DisplayPreferences $prefs = null): VehicleData
    {
        $data = VehicleForm::parse($input, $prefs ?? self::prefs(), self::today());
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
