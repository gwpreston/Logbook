<?php

declare(strict_types=1);

namespace Logbook\Service\Maintenance;

use Logbook\Domain\Maintenance\MaintenanceCategory;
use Logbook\Domain\Maintenance\MaintenanceSchedule;
use Logbook\Domain\Maintenance\MaintenanceScheduleData;
use Logbook\Service\Odometer\OdometerReadingForm;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Number\Decimal;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\Validation\Validator;

/**
 * Add/edit schedule form ↔ MaintenanceScheduleData: "every N distance and/or
 * M months", plus when it was last done before any entry was logged against
 * it. Distances are typed in the user's unit and stored in km.
 */
final class MaintenanceScheduleForm
{
    public const int MAX_MONTHS = 600;

    /**
     * @return array<string, string>
     */
    public static function values(MaintenanceSchedule $schedule, DisplayPreferences $preferences): array
    {
        $data = $schedule->data;

        return [
            'title' => $data->title,
            'category' => $data->category->value,
            'interval_distance' => self::distance($data->intervalKm, $preferences),
            'interval_months' => $data->intervalMonths === null ? '' : (string) $data->intervalMonths,
            'last_done_on' => $data->baselineDoneOn?->format('Y-m-d') ?? '',
            'last_done_odometer' => self::distance($data->baselineDoneKm, $preferences),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function defaults(): array
    {
        return ['category' => MaintenanceCategory::Service->value];
    }

    /**
     * @param array<array-key, mixed> $input
     */
    public static function parse(array $input, DisplayPreferences $preferences): MaintenanceScheduleData|ValidationErrors
    {
        $validator = new Validator($input, $preferences->locale);
        $km = OdometerReadingForm::KM_SCALE;
        $digits = OdometerReadingForm::MAX_WHOLE_DIGITS;

        $title = $validator->string('title', true, MaintenanceEntryForm::TITLE_MAX);
        $category = $validator->enum('category', MaintenanceCategory::class, true);
        $distance = $validator->decimal('interval_distance', false, $km, '0', null, $digits);
        $months = $validator->integer('interval_months', false, 1, self::MAX_MONTHS);
        $lastDoneOn = $validator->date('last_done_on');
        $lastDoneOdometer = $validator->decimal('last_done_odometer', false, $km, '0', null, $digits);

        if ($distance !== null && Decimal::compare($distance, '0') <= 0) {
            $validator->addError('interval_distance', 'validation.positive');
        }
        $errors = $validator->errors();
        if ($distance === null && $months === null && !$errors->has('interval_distance') && !$errors->has('interval_months')) {
            $validator->addError('interval_distance', 'maintenance.schedule.need_interval');
        }

        if (!$errors->isEmpty() || $title === null || $category === null) {
            return $errors;
        }

        return new MaintenanceScheduleData(
            category: $category,
            title: $title,
            intervalKm: $distance === null ? null : $preferences->distanceUnit->toKmDecimal($distance, $km),
            intervalMonths: $months,
            baselineDoneOn: $lastDoneOn,
            baselineDoneKm: $lastDoneOdometer === null ? null : $preferences->distanceUnit->toKmDecimal($lastDoneOdometer, $km),
        );
    }

    private static function distance(?string $km, DisplayPreferences $preferences): string
    {
        return $km === null ? '' : OdometerReadingForm::distanceForDisplay($km, $preferences);
    }
}
