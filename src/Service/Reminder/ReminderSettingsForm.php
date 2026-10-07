<?php

declare(strict_types=1);

namespace Logbook\Service\Reminder;

use Logbook\Service\Attention\AttentionThresholds;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Number\Decimal;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\Validation\Validator;

/**
 * Settings → Reminders form: lead times (the distance in the owner's unit),
 * the digest, and the *Needs attention* thresholds (Phase 24; left blank,
 * the defaults). Where notifications go is on Settings → Account →
 * Notifications from Phase 36.2.
 */
final class ReminderSettingsForm
{
    private const int KM_SCALE = 3;

    /**
     * @return array<string, string>
     */
    public static function values(
        ReminderPreferences $reminders,
        DisplayPreferences $display,
        AttentionThresholds $attention = new AttentionThresholds(),
    ): array {
        return [
            'schedule_days' => (string) $reminders->scheduleDays,
            // Whole units: "621 mi" rather than the exact 621.371.
            'schedule_distance' => Decimal::trim($display->distanceUnit->fromKmDecimal($reminders->scheduleKm, 0)),
            'document_days' => (string) $reminders->documentDays,
            'manual_days' => (string) $reminders->manualDays,
            'mileage_days' => (string) $attention->mileageDays,
            'valuation_months' => (string) $attention->valuationMonths,
            'drift_percent' => (string) $attention->driftPercent,
            'drift_percent_electric' => (string) $attention->driftPercentElectric,
            'price_percent' => (string) $attention->pricePercent,
            'cost_multiple' => (string) $attention->costMultiple,
            'cost_floor' => (string) $attention->costFloor,
        ];
    }

    /**
     * @param array<array-key, mixed> $input
     * @return array{0: ReminderPreferences, 1: bool, 2: AttentionThresholds}|ValidationErrors the digest as 1
     */
    public static function parse(array $input, DisplayPreferences $display): array|ValidationErrors
    {
        $validator = new Validator($input, $display->locale);
        $max = ReminderPreferences::MAX_DAYS;
        $maxDistance = $display->distanceUnit->fromKmDecimal(ReminderPreferences::MAX_KM, 0);

        $scheduleDays = $validator->integer('schedule_days', true, 0, $max);
        $distance = $validator->decimal('schedule_distance', true, 0, '0', $maxDistance);
        $documentDays = $validator->integer('document_days', true, 0, $max);
        $manualDays = $validator->integer('manual_days', true, 0, $max);
        $mileageDays = $validator->integer(
            'mileage_days',
            false,
            AttentionThresholds::MIN_MILEAGE_DAYS,
            AttentionThresholds::MAX_MILEAGE_DAYS,
        );
        $valuationMonths = $validator->integer(
            'valuation_months',
            false,
            AttentionThresholds::MIN_VALUATION_MONTHS,
            AttentionThresholds::MAX_VALUATION_MONTHS,
        );
        $driftPercent = $validator->integer(
            'drift_percent',
            false,
            AttentionThresholds::MIN_DRIFT_PERCENT,
            AttentionThresholds::MAX_DRIFT_PERCENT,
        );
        $driftPercentElectric = $validator->integer(
            'drift_percent_electric',
            false,
            AttentionThresholds::MIN_DRIFT_PERCENT,
            AttentionThresholds::MAX_DRIFT_PERCENT,
        );
        $pricePercent = $validator->integer(
            'price_percent',
            false,
            AttentionThresholds::MIN_PRICE_PERCENT,
            AttentionThresholds::MAX_PRICE_PERCENT,
        );
        $costMultiple = $validator->integer(
            'cost_multiple',
            false,
            AttentionThresholds::MIN_COST_MULTIPLE,
            AttentionThresholds::MAX_COST_MULTIPLE,
        );
        $costFloor = $validator->integer(
            'cost_floor',
            false,
            AttentionThresholds::MIN_COST_FLOOR,
            AttentionThresholds::MAX_COST_FLOOR,
        );

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
            $validator->checkbox('digest'),
            new AttentionThresholds(
                $mileageDays ?? AttentionThresholds::DEFAULT_MILEAGE_DAYS,
                $valuationMonths ?? AttentionThresholds::DEFAULT_VALUATION_MONTHS,
                $driftPercent ?? AttentionThresholds::DEFAULT_DRIFT_PERCENT,
                $driftPercentElectric ?? AttentionThresholds::DEFAULT_DRIFT_PERCENT_ELECTRIC,
                $pricePercent ?? AttentionThresholds::DEFAULT_PRICE_PERCENT,
                $costMultiple ?? AttentionThresholds::DEFAULT_COST_MULTIPLE,
                $costFloor ?? AttentionThresholds::DEFAULT_COST_FLOOR,
            ),
        ];
    }
}
