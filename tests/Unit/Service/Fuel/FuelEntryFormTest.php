<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Fuel;

use DateTimeImmutable;
use Logbook\Domain\Fuel\Fuel;
use Logbook\Domain\Fuel\FuelEntry;
use Logbook\Domain\Fuel\FuelEntryData;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleStatus;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Service\Fuel\FuelEntryForm;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Units\UnitPreset;
use Logbook\Support\Validation\ValidationErrors;
use PHPUnit\Framework\TestCase;

final class FuelEntryFormTest extends TestCase
{
    private const array US_FILL = [
        'filled_at' => '2026-07-01T09:15',
        'odometer' => '12345.6',
        'fuel' => 'petrol',
        'volume' => '13.207',
        'price' => '3.499',
        'total' => '',
        'partial' => '1',
        'station' => ' Costco ',
        'notes' => '',
    ];

    public function testUsUnitsAreStoredInSiAndComeBackUnchanged(): void
    {
        $us = self::preferences(UnitPreset::Us, 'en_US', 'America/New_York');
        $data = FuelEntryForm::parse(self::US_FILL, $us, 'USD');

        self::assertInstanceOf(FuelEntryData::class, $data);
        self::assertSame('19868.317', $data->odometerKm, 'miles → km');
        self::assertSame('49.994', $data->volume, 'US gallons → litres');
        self::assertSame('0.924338', $data->pricePerUnit, 'per US gallon → per litre');
        self::assertSame('46.210', $data->totalCost, '13.207 gal × $3.499 = $46.2113, derived in the units typed');
        // 09:15 EDT (UTC−4) is 13:15 UTC.
        self::assertSame('2026-07-01 13:15:00', $data->filledAt->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $data->filledAt->getTimezone()->getName());
        self::assertTrue($data->isPartial);
        self::assertFalse($data->isMissedPrevious);
        self::assertSame('Costco', $data->station);
        self::assertNull($data->notes);

        $values = FuelEntryForm::values(self::entry($data), $us);
        self::assertSame('12345.6', $values['odometer']);
        self::assertSame('13.207', $values['volume']);
        self::assertSame('3.499', $values['price']);
        self::assertSame('46.21', $values['total']);
        self::assertSame('2026-07-01T09:15', $values['filled_at']);
        self::assertSame('1', $values['partial']);
    }

    public function testMetricValuesAreStoredAsTyped(): void
    {
        $metric = self::preferences(UnitPreset::Metric, 'en_GB', 'Europe/London');
        $input = ['odometer' => '48412', 'volume' => '', 'price' => '1.459', 'total' => '62.01', 'partial' => ''] + self::US_FILL;
        $data = FuelEntryForm::parse($input, $metric, 'GBP');

        self::assertInstanceOf(FuelEntryData::class, $data);
        self::assertSame('48412.000', $data->odometerKm);
        self::assertSame('42.502', $data->volume, 'derived: £62.01 at £1.459/L');
        self::assertSame('1.459000', $data->pricePerUnit);
        self::assertSame('62.010', $data->totalCost);
        self::assertFalse($data->isPartial);
        // 09:15 BST (UTC+1) is 08:15 UTC.
        self::assertSame('2026-07-01 08:15:00', $data->filledAt->format('Y-m-d H:i:s'));
    }

    public function testElectricityIsKwhWhateverTheVolumeUnit(): void
    {
        $us = self::preferences(UnitPreset::Us, 'en_US', 'America/New_York');
        $input = ['fuel' => 'ev', 'volume' => '58.3', 'price' => '0.289', 'total' => ''] + self::US_FILL;
        $data = FuelEntryForm::parse($input, $us, 'USD');

        self::assertInstanceOf(FuelEntryData::class, $data);
        self::assertSame(Fuel::Electricity, $data->fuel);
        self::assertSame('58.300', $data->volume, 'kWh, not converted from gallons');
        self::assertSame('0.289000', $data->pricePerUnit);
        self::assertSame('16.850', $data->totalCost, '58.3 × 0.289 = 16.8487');

        $values = FuelEntryForm::values(self::entry($data), $us);
        self::assertSame('58.3', $values['volume']);
        self::assertSame('0.289', $values['price']);
    }

    public function testZeroCostAndFirstOdometerAreValid(): void
    {
        $metric = self::preferences(UnitPreset::Metric, 'en_GB', 'Europe/London');
        $input = ['odometer' => '0', 'volume' => '30', 'price' => '', 'total' => '0'] + self::US_FILL;
        $data = FuelEntryForm::parse($input, $metric, 'GBP');

        self::assertInstanceOf(FuelEntryData::class, $data);
        self::assertSame('0.000', $data->odometerKm);
        self::assertSame('0.000000', $data->pricePerUnit);
        self::assertSame('0.000', $data->totalCost);
    }

    public function testValidationErrors(): void
    {
        $metric = self::preferences(UnitPreset::Metric, 'en_GB', 'Europe/London');

        $errors = FuelEntryForm::parse(['volume' => '40', 'price' => '', 'total' => ''] + self::US_FILL, $metric, 'GBP');
        self::assertInstanceOf(ValidationErrors::class, $errors);
        self::assertSame('fuel.need_two', $errors->all()['volume']['key']);

        $errors = FuelEntryForm::parse(['volume' => '0', 'price' => '1.5'] + self::US_FILL, $metric, 'GBP');
        self::assertInstanceOf(ValidationErrors::class, $errors);
        self::assertSame('validation.positive', $errors->all()['volume']['key']);

        $errors = FuelEntryForm::parse(['volume' => '', 'price' => '0', 'total' => '10'] + self::US_FILL, $metric, 'GBP');
        self::assertInstanceOf(ValidationErrors::class, $errors);
        self::assertSame('fuel.volume_unknown', $errors->all()['volume']['key']);

        $errors = FuelEntryForm::parse(
            ['filled_at' => '2026-02-30T10:00', 'odometer' => '', 'fuel' => 'diesel-ish', 'total' => '-5'] + self::US_FILL,
            $metric,
            'GBP',
        );
        self::assertInstanceOf(ValidationErrors::class, $errors);
        self::assertSame('validation.datetime', $errors->all()['filled_at']['key']);
        self::assertSame('validation.required', $errors->all()['odometer']['key']);
        self::assertSame('validation.choice', $errors->all()['fuel']['key']);
        self::assertSame('validation.min', $errors->all()['total']['key']);
    }

    public function testDefaultsUseTheVehiclesFuelAndTheUsersClock(): void
    {
        $prefs = self::preferences(UnitPreset::Uk, 'en_GB', 'Pacific/Auckland');
        $now = new DateTimeImmutable('2026-09-27 12:30:00 UTC');

        $vehicle = new Vehicle(
            1,
            1,
            new VehicleData(
                VehicleType::Car,
                'Toyota',
                'Corolla',
                FuelType::Hybrid,
            ),
            VehicleStatus::Active,
            null,
            null,
            null,
            $now,
            $now,
        );

        $defaults = FuelEntryForm::defaults($vehicle, $now, $prefs);
        self::assertSame('petrol', $defaults['fuel'], 'a hybrid fills with petrol');
        self::assertSame('2026-09-28T01:30', $defaults['filled_at'], 'already tomorrow in Auckland');
    }

    private static function preferences(UnitPreset $preset, string $locale, string $zone): DisplayPreferences
    {
        return new DisplayPreferences($locale, $zone, $preset->distance(), $preset->volume(), $preset->consumption(), 'GBP');
    }

    private static function entry(FuelEntryData $data): FuelEntry
    {
        return new FuelEntry(1, 1, $data, $data->filledAt, $data->filledAt);
    }
}
