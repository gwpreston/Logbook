<?php

declare(strict_types=1);

namespace Logbook\Service\Vehicle;

use BackedEnum;
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Money\Currency;
use Logbook\Support\Number\Decimal;
use Logbook\Support\Units\VolumeUnit;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\Validation\Validator;

/**
 * Add/edit vehicle form ↔ VehicleData.
 *
 * Tank capacity is typed in the user's volume unit and stored in litres
 * (battery capacity is kWh either way). Prices are in the vehicle's currency.
 */
final class VehicleForm
{
    public const int FIRST_YEAR = 1885;
    private const int QUANTITY_SCALE = 3;
    private const int MONEY_SCALE = 3;

    /**
     * Form values for an existing vehicle, converted to the user's units.
     *
     * @return array<string, string>
     */
    public static function values(Vehicle $vehicle, DisplayPreferences $preferences): array
    {
        $data = $vehicle->data;

        return [
            'type' => $data->type->value,
            'nickname' => $data->nickname ?? '',
            'make' => $data->make,
            'model' => $data->model,
            'year' => $data->year === null ? '' : (string) $data->year,
            'registration' => $data->registration ?? '',
            'vin' => $data->vin ?? '',
            'fuel_type' => $data->fuelType->value,
            'default_grade' => $data->defaultGrade->value ?? '',
            'capacity' => self::capacityForDisplay($data->capacity, $data->fuelType, $preferences->volumeUnit),
            'currency' => $data->currency ?? '',
            'purchase_date' => $data->purchaseDate?->format('Y-m-d') ?? '',
            'purchase_price' => $data->purchasePrice === null ? '' : Decimal::trim($data->purchasePrice),
            'sale_date' => $data->saleDate?->format('Y-m-d') ?? '',
            'sale_price' => $data->salePrice === null ? '' : Decimal::trim($data->salePrice),
        ];
    }

    /**
     * Defaults for a new vehicle.
     *
     * @return array<string, string>
     */
    public static function defaults(): array
    {
        return ['type' => VehicleType::Car->value, 'fuel_type' => FuelType::Petrol->value, 'currency' => ''];
    }

    /**
     * @param array<array-key, mixed> $input
     */
    public static function parse(array $input, DisplayPreferences $preferences, int $currentYear): VehicleData|ValidationErrors
    {
        $validator = new Validator($input, $preferences->locale);

        $type = $validator->enum('type', VehicleType::class, true);
        $nickname = $validator->string('nickname', false, 100);
        $make = $validator->string('make', true, 100);
        $model = $validator->string('model', true, 100);
        $year = $validator->integer('year', false, self::FIRST_YEAR, $currentYear + 1);
        $registration = $validator->string('registration', false, 20);
        $vin = self::vin($validator);
        $fuelType = $validator->enum('fuel_type', FuelType::class, true);
        $defaultGrade = $validator->enum('default_grade', FuelGrade::class);
        $capacity = $validator->decimal('capacity', false, self::QUANTITY_SCALE, '0', null, 9);
        $currency = $validator->choice('currency', Currency::SUPPORTED);
        $purchaseDate = $validator->date('purchase_date');
        $purchasePrice = $validator->decimal('purchase_price', false, self::MONEY_SCALE, '0', null, 11);
        $saleDate = $validator->date('sale_date');
        $salePrice = $validator->decimal('sale_price', false, self::MONEY_SCALE, '0', null, 11);

        if ($purchaseDate !== null && $saleDate !== null && $saleDate < $purchaseDate) {
            $validator->addError('sale_date', 'vehicle.sale_before_purchase');
        }

        if (!$validator->errors()->isEmpty() || $type === null || $make === null || $model === null || $fuelType === null) {
            return $validator->errors();
        }

        return new VehicleData(
            type: $type,
            make: $make,
            model: $model,
            fuelType: $fuelType,
            nickname: $nickname,
            year: $year,
            registration: $registration === null ? null : mb_strtoupper((string) preg_replace('/\s+/u', ' ', $registration)),
            vin: $vin,
            capacity: $capacity === null ? null : self::capacityForStorage($capacity, $fuelType, $preferences->volumeUnit),
            currency: $currency,
            purchaseDate: $purchaseDate,
            purchasePrice: $purchasePrice,
            saleDate: $saleDate,
            salePrice: $salePrice,
            defaultGrade: self::fittingGrade($defaultGrade, $fuelType),
        );
    }

    /**
     * The default grade when it fits the fuel type; a default left over
     * from another fuel type (the type was changed, possibly without JS to
     * clear it) is dropped rather than refused.
     */
    private static function fittingGrade(?BackedEnum $grade, FuelType $fuelType): ?FuelGrade
    {
        return $grade instanceof FuelGrade && $grade->family() === FuelGrade::defaultFamilyFor($fuelType) ? $grade : null;
    }

    private static function vin(Validator $validator): ?string
    {
        $vin = $validator->string('vin', false, 40);
        if ($vin === null) {
            return null;
        }

        $vin = strtoupper((string) preg_replace('/[\s-]+/', '', $vin));
        if (preg_match('/^[A-Z0-9]{1,17}$/', $vin) !== 1) {
            $validator->addError('vin', 'vehicle.vin_invalid');

            return null;
        }

        return $vin;
    }

    /**
     * Litres (or kWh) for storage from the value typed in the user's unit.
     */
    private static function capacityForStorage(string $value, FuelType $fuelType, VolumeUnit $unit): string
    {
        if ($fuelType->isElectric() || $unit === VolumeUnit::Litre) {
            return $value;
        }

        return Decimal::fromFloat($unit->toLitres((float) $value), self::QUANTITY_SCALE);
    }

    private static function capacityForDisplay(?string $stored, FuelType $fuelType, VolumeUnit $unit): string
    {
        if ($stored === null) {
            return '';
        }
        if ($fuelType->isElectric() || $unit === VolumeUnit::Litre) {
            return Decimal::trim($stored);
        }

        return Decimal::trim(Decimal::fromFloat($unit->fromLitres((float) $stored), self::QUANTITY_SCALE));
    }
}
