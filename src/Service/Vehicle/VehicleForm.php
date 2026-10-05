<?php

declare(strict_types=1);

namespace Logbook\Service\Vehicle;

use BackedEnum;
use DateTimeImmutable;
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Service\Odometer\OdometerReadingForm;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\InspectionRules;
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
 * The add form also takes an optional current odometer (parseNew()), typed in
 * the user's distance unit, with the date it was read (*As of*, default
 * today); the edit form never does.
 *
 * *First MOT due* (spec.md §7.1) is read only while the field is on the
 * form ($firstInspectionOnForm): with `compliance` off, or read-only once a
 * certificate exists, an edit keeps the stored date whatever is posted. On
 * add, a blank field without the script's marker gets the suggestion.
 */
final class VehicleForm
{
    public const int FIRST_YEAR = 1885;
    public const string FIRST_REGISTRATION = '1885-01-01';
    private const int QUANTITY_SCALE = 3;
    private const int MONEY_SCALE = 3;
    /** Posted by js/first-inspection.js: a blank *First MOT due* was the owner's choice. */
    public const string FIRST_INSPECTION_JS = 'first_inspection_js';

    /**
     * Form values for an existing vehicle, converted to the user's units.
     * $purchaseKm is its `purchase` reading (*Mileage when bought*), if any.
     *
     * @return array<string, string>
     */
    public static function values(Vehicle $vehicle, DisplayPreferences $preferences, ?string $purchaseKm = null): array
    {
        $data = $vehicle->data;

        return [
            'type' => $data->type->value,
            'nickname' => $data->nickname ?? '',
            'make' => $data->make,
            'model' => $data->model,
            'variant' => $data->variant ?? '',
            'year' => $data->year === null ? '' : (string) $data->year,
            'first_registered_on' => $data->firstRegisteredOn?->format('Y-m-d') ?? '',
            'first_inspection_due_on' => $data->firstInspectionDueOn?->format('Y-m-d') ?? '',
            'registration' => $data->registration ?? '',
            'vin' => $data->vin ?? '',
            'fuel_type' => $data->fuelType->value,
            'default_grade' => $data->defaultGrade->value ?? '',
            'capacity' => self::capacityForDisplay($data->capacity, $data->fuelType, $preferences->volumeUnit),
            'currency' => $data->currency ?? '',
            'purchase_date' => $data->purchaseDate?->format('Y-m-d') ?? '',
            'purchase_price' => $data->purchasePrice === null ? '' : Decimal::trim($data->purchasePrice),
            'purchase_seller' => $data->purchaseSeller ?? '',
            'purchase_odometer' => $purchaseKm === null
                ? ''
                : Decimal::trim($preferences->distanceUnit->fromKmDecimal($purchaseKm, OdometerReadingForm::KM_SCALE)),
            'sale_date' => $data->saleDate?->format('Y-m-d') ?? '',
            'sale_price' => $data->salePrice === null ? '' : Decimal::trim($data->salePrice),
        ];
    }

    /**
     * Defaults for a new vehicle. $today is today's date in the owner's time
     * zone, the current odometer's *As of*.
     *
     * @return array<string, string>
     */
    public static function defaults(DateTimeImmutable $today): array
    {
        return [
            'type' => VehicleType::Car->value,
            'fuel_type' => FuelType::Petrol->value,
            'currency' => '',
            'current_odometer_on' => $today->format('Y-m-d'),
        ];
    }

    /**
     * The edit form. $today is today's date in the owner's time zone
     * (LocalTime::today()). Without the *First MOT due* field on the form,
     * $keptFirstInspection (the stored date) is kept.
     *
     * @param array<array-key, mixed> $input
     */
    public static function parse(
        array $input,
        DisplayPreferences $preferences,
        DateTimeImmutable $today,
        bool $firstInspectionOnForm = true,
        ?DateTimeImmutable $keptFirstInspection = null,
    ): VehicleData|ValidationErrors {
        $edit = self::parseEdit($input, $preferences, $today, $firstInspectionOnForm, $keptFirstInspection);

        return $edit instanceof VehicleEdit ? $edit->data : $edit;
    }

    /**
     * The edit form with its *Mileage when bought* (blank removes the
     * reading), as parse().
     *
     * @param array<array-key, mixed> $input
     */
    public static function parseEdit(
        array $input,
        DisplayPreferences $preferences,
        DateTimeImmutable $today,
        bool $firstInspectionOnForm = true,
        ?DateTimeImmutable $keptFirstInspection = null,
    ): VehicleEdit|ValidationErrors {
        $validator = new Validator($input, $preferences->locale);
        $data = self::parseWith($validator, $preferences, $today, $firstInspectionOnForm, $keptFirstInspection);
        $mileage = self::purchaseMileage($validator, $preferences);

        return $data === null || !$validator->errors()->isEmpty()
            ? $validator->errors()
            : new VehicleEdit($data, $mileage);
    }

    /**
     * The add form: the vehicle plus its optional current odometer, converted
     * to km, and the date it was read. Blank means no starting reading (and
     * the date is ignored); 0 is a valid one. A blank date means today.
     * A blank *First MOT due* gets the suggestion for the owner's locale
     * (InspectionRules) unless the script says the owner cleared it.
     *
     * @param array<array-key, mixed> $input
     */
    public static function parseNew(
        array $input,
        DisplayPreferences $preferences,
        DateTimeImmutable $today,
        bool $firstInspectionOnForm = true,
    ): NewVehicle|ValidationErrors {
        $validator = new Validator($input, $preferences->locale);
        $data = self::parseWith($validator, $preferences, $today, $firstInspectionOnForm);
        $odometer = $validator->decimal(
            'current_odometer',
            false,
            OdometerReadingForm::KM_SCALE,
            '0',
            null,
            OdometerReadingForm::MAX_WHOLE_DIGITS,
        );

        $readOn = $odometer === null ? null : self::readOn($validator, $today);
        $mileage = self::purchaseMileage($validator, $preferences);

        if ($data === null || !$validator->errors()->isEmpty()) {
            return $validator->errors();
        }

        $suggested = null;
        $blank = $data->firstInspectionDueOn === null && ($input[self::FIRST_INSPECTION_JS] ?? '') !== '1';
        if ($firstInspectionOnForm && $blank) {
            $suggested = InspectionRules::suggest($preferences->locale, $data->firstRegisteredOn, $today);
            $data = $data->withFirstInspectionDueOn($suggested);
        }

        return new NewVehicle(
            $data,
            $odometer === null ? null : new StartingReading(
                $preferences->distanceUnit->toKmDecimal($odometer, OdometerReadingForm::KM_SCALE),
                $readOn,
            ),
            $suggested,
            $mileage->km,
        );
    }

    /**
     * True when the starting reading is dated before first registration:
     * saved (delivery mileage exists before registration), with a warning.
     */
    public static function startingReadingWarning(NewVehicle $new): bool
    {
        $on = $new->startingReading?->on;
        $registered = $new->data->firstRegisteredOn;

        return $on !== null && $registered !== null && $on < $registered;
    }

    /**
     * The model year and registration year when the model year is more than
     * one year after first registration, which cannot be right (a model year
     * well before it is normal: imports, late registration). Saved anyway,
     * with a warning.
     *
     * @return array{year: string, registered: string}|null
     */
    public static function modelYearWarning(VehicleData $data): ?array
    {
        if ($data->year === null || $data->firstRegisteredOn === null) {
            return null;
        }
        $registered = (int) $data->firstRegisteredOn->format('Y');

        return $data->year > $registered + 1 ? ['year' => (string) $data->year, 'registered' => (string) $registered] : null;
    }

    private static function parseWith(
        Validator $validator,
        DisplayPreferences $preferences,
        DateTimeImmutable $today,
        bool $firstInspectionOnForm,
        ?DateTimeImmutable $keptFirstInspection = null,
    ): ?VehicleData {
        $currentYear = (int) $today->format('Y');

        $type = $validator->enum('type', VehicleType::class, true);
        $nickname = $validator->string('nickname', false, 100);
        $make = $validator->string('make', true, 100);
        $model = $validator->string('model', true, 100);
        $variant = $validator->string('variant', false, 100);
        $year = $validator->integer('year', false, self::FIRST_YEAR, $currentYear + 1);
        $firstRegistered = self::firstRegistered($validator, $today);
        $firstInspection = $firstInspectionOnForm
            ? self::firstInspection($validator, $firstRegistered)
            : $keptFirstInspection;
        $registration = $validator->string('registration', false, 20);
        $vin = self::vin($validator);
        $fuelType = $validator->enum('fuel_type', FuelType::class, true);
        $defaultGrade = $validator->enum('default_grade', FuelGrade::class);
        $capacity = $validator->decimal('capacity', false, self::QUANTITY_SCALE, '0', null, 9);
        $currency = $validator->choice('currency', Currency::SUPPORTED);
        $purchaseDate = $validator->date('purchase_date');
        $purchasePrice = $validator->decimal('purchase_price', false, self::MONEY_SCALE, '0', null, 11);
        $purchaseSeller = $validator->string('purchase_seller', false, 100);
        $saleDate = $validator->date('sale_date');
        $salePrice = $validator->decimal('sale_price', false, self::MONEY_SCALE, '0', null, 11);

        if ($purchaseDate !== null && $saleDate !== null && $saleDate < $purchaseDate) {
            $validator->addError('sale_date', 'vehicle.sale_before_purchase');
        }

        if (!$validator->errors()->isEmpty() || $type === null || $make === null || $model === null || $fuelType === null) {
            return null;
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
            variant: $variant,
            firstRegisteredOn: $firstRegistered,
            firstInspectionDueOn: $firstInspection,
            purchaseSeller: $purchaseSeller,
        );
    }

    /**
     * *Mileage when bought*: typed in the user's distance unit, ≥ 0 (0 is
     * valid), stored in km; blank is none. Whether it has its purchase date
     * is the save's to check (PurchaseMileageNeedsDate), which knows whether
     * the date is being cleared.
     */
    private static function purchaseMileage(Validator $validator, DisplayPreferences $preferences): PurchaseMileage
    {
        $km = $validator->decimal(
            'purchase_odometer',
            false,
            OdometerReadingForm::KM_SCALE,
            '0',
            null,
            OdometerReadingForm::MAX_WHOLE_DIGITS,
        );

        return new PurchaseMileage(
            $km === null ? null : $preferences->distanceUnit->toKmDecimal($km, OdometerReadingForm::KM_SCALE),
        );
    }

    /**
     * First registration: a calendar date, not after today (owner's time
     * zone) and not before the first registered cars.
     */
    private static function firstRegistered(Validator $validator, DateTimeImmutable $today): ?DateTimeImmutable
    {
        $date = $validator->date('first_registered_on');
        if ($date === null) {
            return null;
        }
        if ($date > $today) {
            $validator->addError('first_registered_on', 'vehicle.registered_in_future');

            return null;
        }
        if ($date < LocalTime::parseDate(self::FIRST_REGISTRATION)) {
            $validator->addError('first_registered_on', 'vehicle.registered_too_early');

            return null;
        }

        return $date;
    }

    /**
     * *First MOT due*: a calendar date, not before first registration when
     * both are set, and not before the first registered cars. Any later
     * date is fine: it is a date the owner expects, not a record.
     */
    private static function firstInspection(Validator $validator, ?DateTimeImmutable $firstRegistered): ?DateTimeImmutable
    {
        $date = $validator->date('first_inspection_due_on');
        if ($date === null) {
            return null;
        }
        if ($date < LocalTime::parseDate(self::FIRST_REGISTRATION)) {
            $validator->addError('first_inspection_due_on', 'vehicle.first_inspection_too_early');

            return null;
        }
        if ($firstRegistered !== null && $date < $firstRegistered) {
            $validator->addError('first_inspection_due_on', 'vehicle.first_inspection_before_registration');

            return null;
        }

        return $date;
    }

    /**
     * The current odometer's *As of*: a calendar date, not after today
     * (owner's time zone) and not before 1885. Blank is today.
     */
    private static function readOn(Validator $validator, DateTimeImmutable $today): ?DateTimeImmutable
    {
        $date = $validator->date('current_odometer_on');
        if ($date === null) {
            return null;
        }
        if ($date > $today) {
            $validator->addError('current_odometer_on', 'vehicle.reading_in_future');

            return null;
        }
        if ($date < LocalTime::parseDate(self::FIRST_REGISTRATION)) {
            $validator->addError('current_odometer_on', 'vehicle.reading_too_early');

            return null;
        }

        return $date;
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
     * Litres (or kWh, or kg) for storage from the value typed in the user's
     * unit.
     */
    private static function capacityForStorage(string $value, FuelType $fuelType, VolumeUnit $unit): string
    {
        if (!$fuelType->primaryKind()->followsVolumeUnit() || $unit === VolumeUnit::Litre) {
            return $value;
        }

        return Decimal::fromFloat($unit->toLitres((float) $value), self::QUANTITY_SCALE);
    }

    private static function capacityForDisplay(?string $stored, FuelType $fuelType, VolumeUnit $unit): string
    {
        if ($stored === null) {
            return '';
        }
        if (!$fuelType->primaryKind()->followsVolumeUnit() || $unit === VolumeUnit::Litre) {
            return Decimal::trim($stored);
        }

        return Decimal::trim(Decimal::fromFloat($unit->fromLitres((float) $stored), self::QUANTITY_SCALE));
    }
}
