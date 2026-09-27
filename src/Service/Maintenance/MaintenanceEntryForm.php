<?php

declare(strict_types=1);

namespace Logbook\Service\Maintenance;

use DateTimeImmutable;
use Logbook\Domain\Maintenance\MaintenanceCategory;
use Logbook\Domain\Maintenance\MaintenanceEntry;
use Logbook\Domain\Maintenance\MaintenanceEntryData;
use Logbook\Domain\Maintenance\MaintenanceSchedule;
use Logbook\Service\Odometer\OdometerReadingForm;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Number\Decimal;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\Validation\Validator;

/**
 * Add/edit maintenance entry form ↔ MaintenanceEntryData.
 *
 * The odometer is optional and typed in the user's distance unit (stored in
 * km). The cost is optional: blank means nothing was paid, and 0 is valid
 * (DIY, warranty work).
 */
final class MaintenanceEntryForm
{
    public const int TITLE_MAX = 150;
    public const int VENDOR_MAX = 100;
    public const int DESCRIPTION_MAX = 2000;
    /** decimal(14,3) money column. */
    public const int MONEY_WHOLE_DIGITS = 11;
    public const int MONEY_SCALE = 3;

    /**
     * @return array<string, string>
     */
    public static function values(MaintenanceEntry $entry, DisplayPreferences $preferences): array
    {
        $data = $entry->data;
        $km = $data->odometerKm;

        return [
            'performed_on' => $data->performedOn->format('Y-m-d'),
            'odometer' => $km === null ? '' : OdometerReadingForm::distanceForDisplay($km, $preferences),
            'category' => $data->category->value,
            'title' => $data->title,
            'cost' => Decimal::trim($data->cost),
            'vendor' => $data->vendor ?? '',
            'description' => $data->description ?? '',
            'schedule' => $data->scheduleId === null ? '' : (string) $data->scheduleId,
        ];
    }

    /**
     * A new entry: today, or the work a schedule asks for.
     *
     * @param DateTimeImmutable $today calendar date
     * @return array<string, string>
     */
    public static function defaults(DateTimeImmutable $today, ?MaintenanceSchedule $schedule = null): array
    {
        return [
            'performed_on' => $today->format('Y-m-d'),
            'category' => ($schedule?->data->category ?? MaintenanceCategory::Service)->value,
            'title' => $schedule?->data->title ?? '',
            'schedule' => $schedule === null ? '' : (string) $schedule->id,
        ];
    }

    /**
     * @param array<array-key, mixed> $input
     * @param list<int> $scheduleIds the vehicle's schedules (the only ones an entry may complete)
     */
    public static function parse(
        array $input,
        DisplayPreferences $preferences,
        array $scheduleIds,
    ): MaintenanceEntryData|ValidationErrors {
        $validator = new Validator($input, $preferences->locale);

        $performedOn = $validator->date('performed_on', true);
        $odometer = $validator->decimal(
            'odometer',
            false,
            OdometerReadingForm::KM_SCALE,
            '0',
            null,
            OdometerReadingForm::MAX_WHOLE_DIGITS,
        );
        $category = $validator->enum('category', MaintenanceCategory::class, true);
        $title = $validator->string('title', true, self::TITLE_MAX);
        $cost = $validator->decimal('cost', false, self::MONEY_SCALE, '0', null, self::MONEY_WHOLE_DIGITS);
        $vendor = $validator->string('vendor', false, self::VENDOR_MAX);
        $description = $validator->string('description', false, self::DESCRIPTION_MAX);
        $schedule = $validator->choice('schedule', array_map(strval(...), $scheduleIds));

        if (!$validator->errors()->isEmpty() || $performedOn === null || $category === null || $title === null) {
            return $validator->errors();
        }

        return new MaintenanceEntryData(
            performedOn: $performedOn,
            category: $category,
            title: $title,
            cost: $cost ?? Decimal::round('0', self::MONEY_SCALE),
            odometerKm: $odometer === null
                ? null
                : $preferences->distanceUnit->toKmDecimal($odometer, OdometerReadingForm::KM_SCALE),
            vendor: $vendor,
            description: $description,
            scheduleId: $schedule === null ? null : (int) $schedule,
        );
    }
}
