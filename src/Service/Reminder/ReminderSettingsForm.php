<?php

declare(strict_types=1);

namespace Logbook\Service\Reminder;

use Logbook\Service\Notification\Channel\NtfyChannel;
use Logbook\Service\Notification\NotificationPreferences;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Number\Decimal;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\Validation\Validator;

/**
 * Settings → Reminders form: lead times (the distance in the owner's unit),
 * which channels to use, their email address, personal ntfy topic URL and
 * Gotify token (Phase 19), and the digest.
 */
final class ReminderSettingsForm
{
    private const int EMAIL_MAX = 254;
    private const int URL_MAX = 500;
    private const int TOKEN_MAX = 200;
    private const int KM_SCALE = 3;

    /**
     * @return array<string, string>
     */
    public static function values(
        ReminderPreferences $reminders,
        NotificationPreferences $notifications,
        DisplayPreferences $display,
    ): array {
        return [
            'schedule_days' => (string) $reminders->scheduleDays,
            // Whole units: "621 mi" rather than the exact 621.371.
            'schedule_distance' => Decimal::trim($display->distanceUnit->fromKmDecimal($reminders->scheduleKm, 0)),
            'document_days' => (string) $reminders->documentDays,
            'manual_days' => (string) $reminders->manualDays,
            'email' => $notifications->email ?? '',
            'ntfy_url' => $notifications->ntfyUrl ?? '',
            'gotify_token' => $notifications->gotifyToken ?? '',
        ];
    }

    /**
     * @param array<array-key, mixed> $input
     * @param list<string> $channelKeys the channels that can be chosen
     * @return array{0: ReminderPreferences, 1: NotificationPreferences}|ValidationErrors
     */
    public static function parse(array $input, DisplayPreferences $display, array $channelKeys): array|ValidationErrors
    {
        $validator = new Validator($input, $display->locale);
        $max = ReminderPreferences::MAX_DAYS;
        $maxDistance = $display->distanceUnit->fromKmDecimal(ReminderPreferences::MAX_KM, 0);

        $scheduleDays = $validator->integer('schedule_days', true, 0, $max);
        $distance = $validator->decimal('schedule_distance', true, 0, '0', $maxDistance);
        $documentDays = $validator->integer('document_days', true, 0, $max);
        $manualDays = $validator->integer('manual_days', true, 0, $max);
        $email = $validator->string('email', false, self::EMAIL_MAX);
        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $validator->addError('email', 'validation.email');
        }
        $ntfyUrl = $validator->string('ntfy_url', false, self::URL_MAX);
        if ($ntfyUrl !== null && !NtfyChannel::isTopicUrl($ntfyUrl)) {
            $validator->addError('ntfy_url', 'reminders.settings.ntfy_url_invalid');
        }
        $gotifyToken = $validator->string('gotify_token', false, self::TOKEN_MAX);

        $submitted = $input['channels'] ?? [];
        $channels = is_array($submitted)
            ? array_values(array_intersect($channelKeys, array_filter($submitted, is_string(...))))
            : [];

        if (
            !$validator->errors()->isEmpty()
            || $scheduleDays === null || $distance === null || $documentDays === null || $manualDays === null
        ) {
            return $validator->errors();
        }

        // The maximum typed in miles can round a hair above the limit in km.
        $km = $display->distanceUnit->toKmDecimal($distance, self::KM_SCALE);
        if (Decimal::compare($km, ReminderPreferences::MAX_KM) > 0) {
            $km = Decimal::round(ReminderPreferences::MAX_KM, self::KM_SCALE);
        }

        return [
            new ReminderPreferences(
                $scheduleDays,
                $km,
                $documentDays,
                $manualDays,
            ),
            new NotificationPreferences($channels, $email, $validator->checkbox('digest'), $ntfyUrl, $gotifyToken),
        ];
    }
}
