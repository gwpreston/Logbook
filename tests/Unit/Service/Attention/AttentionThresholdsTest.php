<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Attention;

use Logbook\Domain\Attention\AttentionKind;
use Logbook\Domain\Attention\AttentionSeverity;
use Logbook\Service\Attention\AttentionThresholds;
use Logbook\Service\Notification\NotificationPreferences;
use Logbook\Service\Reminder\ReminderPreferences;
use Logbook\Service\Reminder\ReminderSettingsForm;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Validation\ValidationErrors;
use PHPUnit\Framework\TestCase;

/**
 * The owner's *Needs attention* thresholds (spec.md §7.24): defaults, the
 * stored row read safely, and the Settings → Reminders fields.
 */
final class AttentionThresholdsTest extends TestCase
{
    public function testDefaultsAndAStoredRowReadSafely(): void
    {
        self::assertSame(['mileage_days' => 60, 'valuation_months' => 12], (new AttentionThresholds())->toArray());
        self::assertSame(
            ['mileage_days' => 90, 'valuation_months' => 24],
            AttentionThresholds::fromArray(['mileage_days' => 90, 'valuation_months' => 24])->toArray(),
        );
        self::assertSame(
            ['mileage_days' => 60, 'valuation_months' => 12],
            AttentionThresholds::fromArray(['mileage_days' => 3, 'valuation_months' => '24'])->toArray(),
            'out of range or not an int: the default',
        );
        self::assertSame(['mileage_days' => 60, 'valuation_months' => 12], AttentionThresholds::fromArray('garbage')->toArray());
    }

    public function testTheSettingsFormParsesAndChecksTheRanges(): void
    {
        $display = DisplayPreferences::defaults('en_GB', 'Europe/London', 'GBP');
        $input = ['schedule_days' => '30', 'schedule_distance' => '1000', 'document_days' => '30', 'manual_days' => '7'];

        $parsed = ReminderSettingsForm::parse($input + ['mileage_days' => '90', 'valuation_months' => '6'], $display, []);
        self::assertIsArray($parsed);
        self::assertSame(['mileage_days' => 90, 'valuation_months' => 6], $parsed[2]->toArray());

        $blank = ReminderSettingsForm::parse($input + ['mileage_days' => '', 'valuation_months' => ''], $display, []);
        self::assertIsArray($blank);
        self::assertSame(['mileage_days' => 60, 'valuation_months' => 12], $blank[2]->toArray(), 'blank: the defaults');

        $errors = ReminderSettingsForm::parse($input + ['mileage_days' => '6', 'valuation_months' => '61'], $display, []);
        self::assertInstanceOf(ValidationErrors::class, $errors);
        self::assertArrayHasKey('mileage_days', $errors->all());
        self::assertArrayHasKey('valuation_months', $errors->all());

        $values = ReminderSettingsForm::values(
            new ReminderPreferences(),
            new NotificationPreferences(),
            $display,
            new AttentionThresholds(45, 18),
        );
        self::assertSame('45', $values['mileage_days']);
        self::assertSame('18', $values['valuation_months']);
    }

    public function testOnlyChecksAboutDataCanBeHiddenAndOverdueWorkIsNow(): void
    {
        self::assertSame(AttentionSeverity::Now, AttentionKind::Overdue->severity());
        foreach (AttentionKind::cases() as $kind) {
            if ($kind !== AttentionKind::Overdue) {
                self::assertSame(AttentionSeverity::Check, $kind->severity());
            }
        }
        self::assertSame(
            [AttentionKind::Reading, AttentionKind::MileageStale, AttentionKind::ValuationStale],
            array_values(array_filter(AttentionKind::cases(), static fn (AttentionKind $k): bool => $k->isHideable())),
        );
        self::assertNull(AttentionKind::hideable('economy'));
        self::assertNull(AttentionKind::hideable('overdue'));
        self::assertSame(AttentionKind::Reading, AttentionKind::hideable('reading'));
    }
}
