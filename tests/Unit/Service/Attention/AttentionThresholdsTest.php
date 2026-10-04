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
    private const array DEFAULT_CHECKS = [
        'drift_percent' => 10,
        'drift_percent_electric' => 15,
        'price_percent' => 35,
        'cost_multiple' => 3,
        'cost_floor' => 100,
    ];

    public function testDefaultsAndAStoredRowReadSafely(): void
    {
        self::assertSame(
            ['mileage_days' => 60, 'valuation_months' => 12] + self::DEFAULT_CHECKS,
            (new AttentionThresholds())->toArray(),
        );
        self::assertSame(
            ['mileage_days' => 90, 'valuation_months' => 24] + self::DEFAULT_CHECKS,
            AttentionThresholds::fromArray(['mileage_days' => 90, 'valuation_months' => 24])->toArray(),
            'a row saved before Phase 25: the new keys read their defaults',
        );
        self::assertSame(
            ['mileage_days' => 60, 'valuation_months' => 12] + self::DEFAULT_CHECKS,
            AttentionThresholds::fromArray(['mileage_days' => 3, 'valuation_months' => '24'])->toArray(),
            'out of range or not an int: the default',
        );
        self::assertSame(
            ['mileage_days' => 60, 'valuation_months' => 12] + self::DEFAULT_CHECKS,
            AttentionThresholds::fromArray('garbage')->toArray(),
        );

        $checks = [
            'drift_percent' => 20,
            'drift_percent_electric' => 25,
            'price_percent' => 50,
            'cost_multiple' => 5,
            'cost_floor' => 0,
        ];
        self::assertSame(
            ['mileage_days' => 60, 'valuation_months' => 12] + $checks,
            AttentionThresholds::fromArray($checks)->toArray(),
        );
        self::assertSame(
            ['mileage_days' => 60, 'valuation_months' => 12] + self::DEFAULT_CHECKS,
            AttentionThresholds::fromArray([
                'drift_percent' => 4,
                'drift_percent_electric' => 51,
                'price_percent' => 91,
                'cost_multiple' => 1,
                'cost_floor' => 10001,
            ])->toArray(),
            'each out of range: its default',
        );
    }

    public function testTheSettingsFormParsesAndChecksTheRanges(): void
    {
        $display = DisplayPreferences::defaults('en_GB', 'Europe/London', 'GBP');
        $input = ['schedule_days' => '30', 'schedule_distance' => '1000', 'document_days' => '30', 'manual_days' => '7'];

        $parsed = ReminderSettingsForm::parse($input + ['mileage_days' => '90', 'valuation_months' => '6'], $display, []);
        self::assertIsArray($parsed);
        self::assertSame(['mileage_days' => 90, 'valuation_months' => 6] + self::DEFAULT_CHECKS, $parsed[2]->toArray());

        $checks = [
            'drift_percent' => '12',
            'drift_percent_electric' => '20',
            'price_percent' => '40',
            'cost_multiple' => '4',
            'cost_floor' => '250',
        ];
        $custom = ReminderSettingsForm::parse($input + $checks, $display, []);
        self::assertIsArray($custom);
        self::assertSame(
            [
                'drift_percent' => 12,
                'drift_percent_electric' => 20,
                'price_percent' => 40,
                'cost_multiple' => 4,
                'cost_floor' => 250,
            ],
            array_slice($custom[2]->toArray(), 2),
        );
        $wrong = ReminderSettingsForm::parse(
            $input + [
                'drift_percent' => '4',
                'drift_percent_electric' => '51',
                'price_percent' => '95',
                'cost_multiple' => '1',
                'cost_floor' => '-1',
            ],
            $display,
            [],
        );
        self::assertInstanceOf(ValidationErrors::class, $wrong);
        foreach (array_keys($checks) as $field) {
            self::assertArrayHasKey($field, $wrong->all());
        }

        $blank = ReminderSettingsForm::parse($input + ['mileage_days' => '', 'valuation_months' => ''], $display, []);
        self::assertIsArray($blank);
        self::assertSame(
            ['mileage_days' => 60, 'valuation_months' => 12] + self::DEFAULT_CHECKS,
            $blank[2]->toArray(),
            'blank: the defaults',
        );

        $errors = ReminderSettingsForm::parse($input + ['mileage_days' => '6', 'valuation_months' => '61'], $display, []);
        self::assertInstanceOf(ValidationErrors::class, $errors);
        self::assertArrayHasKey('mileage_days', $errors->all());
        self::assertArrayHasKey('valuation_months', $errors->all());

        $values = ReminderSettingsForm::values(
            new ReminderPreferences(),
            new NotificationPreferences(),
            $display,
            new AttentionThresholds(45, 18, 12, 20, 40, 4, 250),
        );
        self::assertSame('45', $values['mileage_days']);
        self::assertSame('18', $values['valuation_months']);
        self::assertSame('12', $values['drift_percent']);
        self::assertSame('20', $values['drift_percent_electric']);
        self::assertSame('40', $values['price_percent']);
        self::assertSame('4', $values['cost_multiple']);
        self::assertSame('250', $values['cost_floor']);
    }

    public function testOnlyChecksAboutDataCanBeHiddenAndOverdueWorkIsNow(): void
    {
        self::assertSame(AttentionSeverity::Now, AttentionKind::Overdue->severity());
        self::assertSame(AttentionSeverity::Now, AttentionKind::FinanceMissed->severity(), 'a missed payment is Now');
        foreach (AttentionKind::cases() as $kind) {
            if ($kind !== AttentionKind::Overdue && $kind !== AttentionKind::FinanceMissed) {
                self::assertSame(AttentionSeverity::Check, $kind->severity());
            }
        }
        self::assertSame(
            [
                AttentionKind::Reading,
                AttentionKind::MileageStale,
                AttentionKind::ValuationStale,
                AttentionKind::DriftLiquid,
                AttentionKind::DriftElectric,
                AttentionKind::DriftGas,
                AttentionKind::FuelPrice,
                AttentionKind::MaintenanceCost,
                AttentionKind::StalledClaim,
                AttentionKind::FinanceMileage,
            ],
            array_values(array_filter(AttentionKind::cases(), static fn (AttentionKind $k): bool => $k->isHideable())),
        );
        self::assertNull(AttentionKind::hideable('economy'));
        self::assertNull(AttentionKind::hideable('overdue'));
        self::assertSame(AttentionKind::Reading, AttentionKind::hideable('reading'));
        self::assertSame(AttentionKind::FuelPrice, AttentionKind::hideable('fuel_price'));
        foreach (AttentionKind::cases() as $kind) {
            self::assertLessThanOrEqual(32, strlen($kind->value), 'fits attention_hidden.kind');
        }
    }
}
