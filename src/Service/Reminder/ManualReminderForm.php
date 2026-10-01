<?php

declare(strict_types=1);

namespace Logbook\Service\Reminder;

use Logbook\Domain\Reminder\ManualReminderData;
use Logbook\Domain\Reminder\Reminder;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Service\Odometer\OdometerReadingForm;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\Validation\Validator;

/**
 * Add/edit manual reminder form ↔ ManualReminderData (one parse() for both).
 * A reminder is due on a date, at an odometer reading (typed in the user's
 * distance unit, stored in km), or both; at least one (spec.md §7.6).
 */
final class ManualReminderForm
{
    private const int TITLE_MAX = 150;
    private const int NOTES_MAX = 1000;
    private const int KM_SCALE = 3;

    /**
     * @return array<string, string>
     */
    public static function values(Reminder $reminder, DisplayPreferences $preferences): array
    {
        return [
            'vehicle_id' => (string) $reminder->vehicleId,
            'title' => $reminder->title,
            'due_on' => $reminder->dueOn?->format('Y-m-d') ?? '',
            'due_odometer' => $reminder->dueKm === null
                ? ''
                : OdometerReadingForm::distanceForDisplay($reminder->dueKm, $preferences),
            'lead_time_days' => (string) $reminder->leadTimeDays,
            'notes' => $reminder->notes ?? '',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function defaults(ReminderPreferences $preferences, ?int $vehicleId): array
    {
        return [
            'vehicle_id' => $vehicleId === null ? '' : (string) $vehicleId,
            'lead_time_days' => (string) $preferences->manualDays,
        ];
    }

    /**
     * @param array<array-key, mixed> $input
     * @param list<int> $vehicleIds the owner's active vehicles
     */
    public static function parse(
        array $input,
        DisplayPreferences $preferences,
        array $vehicleIds,
    ): ManualReminderData|ValidationErrors {
        $validator = new Validator($input, $preferences->locale);

        $vehicle = $validator->choice('vehicle_id', array_map(strval(...), $vehicleIds), true);
        $title = $validator->string('title', true, self::TITLE_MAX);
        $dueOn = $validator->date('due_on', false);
        $dueAt = $validator->decimal('due_odometer', false, self::KM_SCALE, '0', null, OdometerReadingForm::MAX_WHOLE_DIGITS);
        $lead = $validator->integer('lead_time_days', true, 0, ReminderPreferences::MAX_DAYS);
        $notes = $validator->string('notes', false, self::NOTES_MAX);
        $errors = $validator->errors();
        if ($dueOn === null && $dueAt === null && !$errors->has('due_on') && !$errors->has('due_odometer')) {
            $errors->add('due_on', 'reminders.validation.date_or_odometer');
        }

        if (!$errors->isEmpty() || $vehicle === null || $title === null || $lead === null) {
            return $errors;
        }

        return new ManualReminderData(
            (int) $vehicle,
            $title,
            $dueOn,
            $lead,
            $notes,
            $dueAt === null ? null : $preferences->distanceUnit->toKmDecimal($dueAt, self::KM_SCALE),
        );
    }
}
