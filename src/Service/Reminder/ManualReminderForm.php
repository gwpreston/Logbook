<?php

declare(strict_types=1);

namespace Logbook\Service\Reminder;

use Logbook\Domain\Reminder\ManualReminderData;
use Logbook\Domain\Reminder\Reminder;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\Validation\Validator;

/**
 * Add/edit manual reminder form ↔ ManualReminderData (one parse() for both).
 */
final class ManualReminderForm
{
    private const int TITLE_MAX = 150;
    private const int NOTES_MAX = 1000;

    /**
     * @return array<string, string>
     */
    public static function values(Reminder $reminder): array
    {
        return [
            'vehicle_id' => (string) $reminder->vehicleId,
            'title' => $reminder->title,
            'due_on' => $reminder->dueOn?->format('Y-m-d') ?? '',
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
    public static function parse(array $input, string $locale, array $vehicleIds): ManualReminderData|ValidationErrors
    {
        $validator = new Validator($input, $locale);

        $vehicle = $validator->choice('vehicle_id', array_map(strval(...), $vehicleIds), true);
        $title = $validator->string('title', true, self::TITLE_MAX);
        $dueOn = $validator->date('due_on', true);
        $lead = $validator->integer('lead_time_days', true, 0, ReminderPreferences::MAX_DAYS);
        $notes = $validator->string('notes', false, self::NOTES_MAX);

        if (!$validator->errors()->isEmpty() || $vehicle === null || $title === null || $dueOn === null || $lead === null) {
            return $validator->errors();
        }

        return new ManualReminderData((int) $vehicle, $title, $dueOn, $lead, $notes);
    }
}
