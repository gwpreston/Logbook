<?php

declare(strict_types=1);

namespace Logbook\Service\Fuel;

use DateTimeImmutable;
use Logbook\Domain\Fuel\Fuel;
use Logbook\Domain\Fuel\FuelChoice;
use Logbook\Domain\Fuel\FuelEntry;
use Logbook\Domain\Fuel\FuelEntryData;
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Odometer\OdometerReadingForm;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Money\Currency;
use Logbook\Support\Number\Decimal;
use Logbook\Support\Units\VolumeUnit;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\Validation\Validator;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Log/edit fill-up form ↔ FuelEntryData.
 *
 * Everything is typed in the user's units and stored in SI: the odometer in
 * their distance unit, volume and price per unit in their volume unit (kWh
 * for electricity, whatever the preference), the date and time in their
 * zone. Any two of volume, price and total derive the third (FuelAmounts),
 * computed in the units typed, then converted exactly.
 */
final class FuelEntryForm
{
    /** Digits before the point: volumes up to 999,999, prices up to 999,999. */
    private const int VOLUME_WHOLE_DIGITS = 6;
    private const int PRICE_WHOLE_DIGITS = 6;
    /** decimal(14,3) money column. */
    private const int MONEY_WHOLE_DIGITS = 11;
    private const int MONEY_SCALE = 3;

    /**
     * Form values for an existing entry, converted to the user's units.
     *
     * @return array<string, string>
     */
    public static function values(FuelEntry $entry, DisplayPreferences $preferences): array
    {
        $data = $entry->data;
        $unit = self::volumeUnit($data->fuel, $preferences);
        // Per litre: exact. Per gallon: 4 places recovers anything typed with up to 4.
        $priceScale = $unit === VolumeUnit::Litre ? FuelAmounts::PRICE_SCALE : 4;
        $filledAt = LocalTime::fromUtc($data->filledAt, $preferences->timeZone());

        return [
            'filled_at' => $filledAt->format(OdometerReadingForm::LOCAL_FORMAT),
            'odometer' => OdometerReadingForm::distanceForDisplay($data->odometerKm, $preferences),
            'fuel' => (new FuelChoice($data->fuel, $data->grade))->value(),
            'volume' => Decimal::trim($unit->fromLitresDecimal($data->volume, FuelAmounts::VOLUME_SCALE)),
            'price' => Decimal::trim($unit->pricePerUnit($data->pricePerUnit, $priceScale)),
            'total' => Decimal::trim($data->totalCost),
            'partial' => $data->isPartial ? '1' : '',
            'missed_previous' => $data->isMissedPrevious ? '1' : '',
            'station' => $data->station ?? '',
            'notes' => $data->notes ?? '',
        ];
    }

    /**
     * Defaults for a new fill-up: now, and the vehicle's usual fuel with the
     * grade last bought (GradeStatistics::formDefault).
     *
     * @param list<FuelEntry> $entries the vehicle's fill-ups, oldest first
     * @return array<string, string>
     */
    public static function defaults(
        Vehicle $vehicle,
        DateTimeImmutable $now,
        DisplayPreferences $preferences,
        array $entries = [],
    ): array {
        $fuel = Fuel::defaultFor($vehicle->data->fuelType);

        return [
            'filled_at' => LocalTime::fromUtc($now, $preferences->timeZone())->format(OdometerReadingForm::LOCAL_FORMAT),
            'fuel' => (new FuelChoice($fuel, GradeStatistics::formDefault($entries, $vehicle, $fuel)))->value(),
        ];
    }

    /**
     * @param array<array-key, mixed> $input
     * @param string $currency the vehicle's currency (a derived total is rounded to its minor unit)
     */
    public static function parse(array $input, DisplayPreferences $preferences, string $currency): FuelEntryData|ValidationErrors
    {
        $validator = new Validator($input, $preferences->locale);

        $filledAt = $validator->dateTime('filled_at', $preferences->timeZone(), true);
        $odometer = $validator->decimal(
            'odometer',
            true,
            OdometerReadingForm::KM_SCALE,
            '0',
            null,
            OdometerReadingForm::MAX_WHOLE_DIGITS,
        );
        [$fuel, $grade] = self::fuelAndGrade($validator);
        $volume = $validator->decimal('volume', false, FuelAmounts::VOLUME_SCALE, '0', null, self::VOLUME_WHOLE_DIGITS);
        $price = $validator->decimal('price', false, FuelAmounts::PRICE_SCALE, '0', null, self::PRICE_WHOLE_DIGITS);
        $total = $validator->decimal('total', false, self::MONEY_SCALE, '0', null, self::MONEY_WHOLE_DIGITS);
        $station = $validator->string('station', false, 100);
        $notes = $validator->string('notes', false, 1000);

        if ($volume !== null && Decimal::compare($volume, '0') <= 0) {
            $validator->addError('volume', 'validation.positive');
            $volume = null;
        }

        $amounts = null;
        if (!$validator->errors()->has('volume') && !$validator->errors()->has('price') && !$validator->errors()->has('total')) {
            $moneyScale = min(Currency::fractionDigits($currency), self::MONEY_SCALE);
            $amounts = FuelAmounts::complete($volume, $price, $total, $moneyScale);
            if ($amounts === FuelAmounts::NEED_TWO) {
                $validator->addError('volume', 'fuel.need_two');
            } elseif ($amounts === FuelAmounts::VOLUME_UNKNOWN) {
                $validator->addError('volume', 'fuel.volume_unknown');
            }
        }

        if (
            !$validator->errors()->isEmpty()
            || $filledAt === null
            || $odometer === null
            || $fuel === null
            || !$amounts instanceof FuelAmounts
        ) {
            return $validator->errors();
        }

        $unit = self::volumeUnit($fuel, $preferences);

        return new FuelEntryData(
            filledAt: $filledAt,
            odometerKm: $preferences->distanceUnit->toKmDecimal($odometer, OdometerReadingForm::KM_SCALE),
            fuel: $fuel,
            volume: $unit->toLitresDecimal($amounts->volume, FuelAmounts::VOLUME_SCALE),
            pricePerUnit: $unit->pricePerLitre($amounts->pricePerUnit, FuelAmounts::PRICE_SCALE),
            totalCost: Decimal::round($amounts->total, self::MONEY_SCALE),
            isPartial: $validator->checkbox('partial'),
            isMissedPrevious: $validator->checkbox('missed_previous'),
            station: $station,
            notes: $notes,
            grade: $grade,
        );
    }

    /**
     * The picker's value (spec.md §7.3): `family` or `family:grade`. A plain
     * family may come with a separate `grade` field (CSV import); a queued
     * offline entry from before grades has neither, which is still valid.
     *
     * @return array{0: ?Fuel, 1: ?FuelGrade}
     */
    private static function fuelAndGrade(Validator $validator): array
    {
        $value = $validator->raw('fuel');
        if ($value === '') {
            $validator->addError('fuel', 'validation.required');

            return [null, null];
        }

        $parts = explode(':', $value, 2);
        $fuel = Fuel::tryFrom($parts[0]);
        $gradeCode = $parts[1] ?? $validator->raw('grade');
        $grade = $gradeCode === '' ? null : FuelGrade::tryFrom($gradeCode);
        if ($fuel === null || ($gradeCode !== '' && $grade === null)) {
            $validator->addError('fuel', 'validation.choice');

            return [null, null];
        }

        if ($grade !== null && $grade->family() !== $fuel) {
            $validator->addError('fuel', 'fuel.grade_mismatch', [
                'grade' => new TranslatableMessage($grade->shortLabelKey()),
                'family' => $grade->family()->value,
                'fuel' => $fuel->value,
            ]);

            return [null, null];
        }

        return [$fuel, $grade];
    }

    /**
     * The unit volumes and prices are typed in: the user's volume unit for
     * liquid fuel; kWh (stored as-is, i.e. factor 1) for electricity.
     */
    private static function volumeUnit(Fuel $fuel, DisplayPreferences $preferences): VolumeUnit
    {
        return $fuel->isElectric() ? VolumeUnit::Litre : $preferences->volumeUnit;
    }
}
