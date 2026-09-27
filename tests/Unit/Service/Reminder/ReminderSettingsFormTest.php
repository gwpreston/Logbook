<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Reminder;

use Logbook\Service\Notification\NotificationPreferences;
use Logbook\Service\Reminder\ManualReminderForm;
use Logbook\Service\Reminder\ReminderPreferences;
use Logbook\Service\Reminder\ReminderSettingsForm;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Units\UnitPreset;
use Logbook\Support\Validation\ValidationErrors;
use PHPUnit\Framework\TestCase;

final class ReminderSettingsFormTest extends TestCase
{
    public function testLeadDistanceIsTypedInTheOwnersUnitAndStoredInKm(): void
    {
        $uk = self::preferences(UnitPreset::Uk);
        $parsed = ReminderSettingsForm::parse([
            'schedule_days' => '14',
            'schedule_distance' => '500',
            'document_days' => '0',
            'manual_days' => '3',
            'channels' => ['ntfy', 'bogus'],
            'email' => 'pat@example.com',
            'digest' => '1',
        ], $uk, ['email', 'ntfy', 'gotify']);

        self::assertIsArray($parsed);
        [$reminders, $notifications] = $parsed;
        self::assertSame(14, $reminders->scheduleDays);
        self::assertSame('804.672', $reminders->scheduleKm, '500 miles');
        self::assertSame(0, $reminders->documentDays, 'zero is a legitimate lead time');
        self::assertSame(3, $reminders->manualDays);
        self::assertSame(['ntfy'], $notifications->channels, 'unknown channels are ignored');
        self::assertSame('pat@example.com', $notifications->email);
        self::assertTrue($notifications->digest);

        self::assertSame('500', ReminderSettingsForm::values($reminders, $notifications, $uk)['schedule_distance']);
    }

    public function testUncheckingEveryChannelTurnsThemAllOff(): void
    {
        $parsed = ReminderSettingsForm::parse(
            ['schedule_days' => '30', 'schedule_distance' => '1000', 'document_days' => '30', 'manual_days' => '7'],
            self::preferences(UnitPreset::Metric),
            ['email'],
        );

        self::assertIsArray($parsed);
        self::assertSame([], $parsed[1]->channels);
        self::assertFalse($parsed[1]->isEnabled('email'));
        self::assertFalse($parsed[1]->digest);
    }

    public function testInvalidInputIsRejectedWithClearErrors(): void
    {
        $errors = ReminderSettingsForm::parse([
            'schedule_days' => '366',
            'schedule_distance' => '-1',
            'document_days' => 'soon',
            'manual_days' => '',
            'email' => 'not an address',
        ], self::preferences(UnitPreset::Metric), []);

        self::assertInstanceOf(ValidationErrors::class, $errors);
        foreach (['schedule_days', 'schedule_distance', 'document_days', 'manual_days', 'email'] as $field) {
            self::assertTrue($errors->has($field), $field);
        }
    }

    public function testStoredPreferencesFallBackToDefaultsWhenDamaged(): void
    {
        $preferences = ReminderPreferences::fromArray(['schedule_days' => 999, 'schedule_km' => 'lots', 'document_days' => 10]);

        self::assertSame(ReminderPreferences::DEFAULT_SCHEDULE_DAYS, $preferences->scheduleDays);
        self::assertSame(ReminderPreferences::DEFAULT_SCHEDULE_KM, $preferences->scheduleKm);
        self::assertSame(10, $preferences->documentDays);
        self::assertEquals($preferences, ReminderPreferences::fromArray($preferences->toArray()), 'round trip');
        self::assertEquals(new NotificationPreferences(), NotificationPreferences::fromArray(null));
    }

    public function testManualReminderNeedsAVehicleOfTheOwner(): void
    {
        $input = ['vehicle_id' => '9', 'title' => 'Road tax', 'due_on' => '2026-10-01', 'lead_time_days' => '7'];

        self::assertInstanceOf(ValidationErrors::class, ManualReminderForm::parse($input, 'en', [1, 2]));

        $data = ManualReminderForm::parse(['vehicle_id' => '2'] + $input, 'en', [1, 2]);
        self::assertNotInstanceOf(ValidationErrors::class, $data);
        self::assertSame(2, $data->vehicleId);
        self::assertSame('2026-10-01', $data->dueOn->format('Y-m-d'));
        self::assertNull($data->notes);
    }

    private static function preferences(UnitPreset $preset): DisplayPreferences
    {
        return new DisplayPreferences(
            'en_GB',
            'Europe/London',
            $preset->distance(),
            $preset->volume(),
            $preset->consumption(),
            'GBP',
        );
    }
}
