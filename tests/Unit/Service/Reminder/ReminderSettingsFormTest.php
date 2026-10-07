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
            'digest' => '1',
        ], $uk);

        self::assertIsArray($parsed);
        [$reminders, $digest] = $parsed;
        self::assertSame(14, $reminders->scheduleDays);
        self::assertSame('804.672', $reminders->scheduleKm, '500 miles');
        self::assertSame(0, $reminders->documentDays, 'zero is a legitimate lead time');
        self::assertSame(3, $reminders->manualDays);
        self::assertTrue($digest);

        self::assertSame('500', ReminderSettingsForm::values($reminders, $uk)['schedule_distance']);
    }

    public function testTheDigestIsOffUnlessTicked(): void
    {
        $parsed = ReminderSettingsForm::parse(
            ['schedule_days' => '30', 'schedule_distance' => '1000', 'document_days' => '30', 'manual_days' => '7'],
            self::preferences(UnitPreset::Metric),
        );

        self::assertIsArray($parsed);
        self::assertFalse($parsed[1]);
    }

    public function testChannelChoicesKeepTheDigestAndAnUnsealedToken(): void
    {
        $preferences = NotificationPreferences::fromArray(['digest' => true, 'gotify_token' => 'left-by-upgrade']);

        $off = $preferences->withChannel('email', false);
        self::assertSame(['webhook'], $off->channels, 'from "both" to the server webhook only');
        self::assertFalse($off->isEnabled('email'));
        self::assertTrue($off->digest);
        self::assertSame('left-by-upgrade', $off->toArray()['gotify_token'] ?? null);
        self::assertSame(['webhook', 'email'], $off->withChannel('email', true)->channels);
        self::assertArrayNotHasKey('gotify_token', $off->withoutLegacyGotifyToken()->toArray());
        self::assertFalse($off->withDigest(false)->digest);
    }

    public function testInvalidInputIsRejectedWithClearErrors(): void
    {
        $errors = ReminderSettingsForm::parse([
            'schedule_days' => '366',
            'schedule_distance' => '-1',
            'document_days' => 'soon',
            'manual_days' => '',
        ], self::preferences(UnitPreset::Metric));

        self::assertInstanceOf(ValidationErrors::class, $errors);
        foreach (['schedule_days', 'schedule_distance', 'document_days', 'manual_days'] as $field) {
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

        $metric = self::preferences(UnitPreset::Metric);
        self::assertInstanceOf(ValidationErrors::class, ManualReminderForm::parse($input, $metric, [1, 2]));

        $data = ManualReminderForm::parse(['vehicle_id' => '2'] + $input, $metric, [1, 2]);
        self::assertNotInstanceOf(ValidationErrors::class, $data);
        self::assertSame(2, $data->vehicleId);
        self::assertSame('2026-10-01', $data->dueOn?->format('Y-m-d'));
        self::assertNull($data->dueKm);
        self::assertNull($data->notes);
    }

    public function testAManualReminderIsDueOnADateAtAnOdometerOrBoth(): void
    {
        $input = ['vehicle_id' => '1', 'title' => 'Front pads', 'lead_time_days' => '7'];
        $miles = self::preferences(UnitPreset::Uk);

        $neither = ManualReminderForm::parse($input, $miles, [1]);
        self::assertInstanceOf(ValidationErrors::class, $neither);
        self::assertSame('reminders.validation.date_or_odometer', $neither->all()['due_on']['key']);

        $atOdometer = ManualReminderForm::parse($input + ['due_odometer' => '50000'], $miles, [1]);
        self::assertNotInstanceOf(ValidationErrors::class, $atOdometer);
        self::assertNull($atOdometer->dueOn);
        self::assertSame('80467.200', $atOdometer->dueKm, 'typed in miles, stored in km');

        $both = ManualReminderForm::parse($input + ['due_on' => '2027-03-01', 'due_odometer' => '50000'], $miles, [1]);
        self::assertNotInstanceOf(ValidationErrors::class, $both);
        self::assertSame('2027-03-01', $both->dueOn?->format('Y-m-d'));
        self::assertNotNull($both->dueKm);

        $negative = ManualReminderForm::parse($input + ['due_odometer' => '-5'], $miles, [1]);
        self::assertInstanceOf(ValidationErrors::class, $negative);
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
