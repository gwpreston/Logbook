<?php

declare(strict_types=1);

namespace Logbook\Service\Incident;

use DateTimeImmutable;
use Logbook\Domain\Compliance\ComplianceDocument;
use Logbook\Domain\Incident\Claim;
use Logbook\Domain\Incident\ClaimStatus;
use Logbook\Domain\Incident\DamageArea;
use Logbook\Domain\Incident\Fault;
use Logbook\Domain\Incident\Incident;
use Logbook\Domain\Incident\IncidentData;
use Logbook\Domain\Incident\IncidentStatus;
use Logbook\Domain\Incident\IncidentType;
use Logbook\Domain\Incident\NcdEffect;
use Logbook\Domain\Incident\Severity;
use Logbook\Domain\Incident\WriteOffCategory;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Service\Odometer\OdometerReadingForm;
use Logbook\Support\Number\Decimal;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\Validation\Validator;

/**
 * The incident form (spec.md §7.29 *Form*): what happened, the damage, the
 * other party and the insurance claim. The odometer is in the user's
 * distance unit, amounts in the vehicle's currency; 0 is a valid amount.
 */
final class IncidentForm
{
    public const int LOCATION_MAX = 200;
    public const int TEXT_MAX = 2000;
    public const int NAME_MAX = 100;
    private const int MONEY_SCALE = 3;
    private const int MONEY_WHOLE_DIGITS = 11;
    private const string TIME = '/^([01][0-9]|2[0-3]):[0-5][0-9]$/';

    /**
     * A new incident: today, and the insurer of the policy current today.
     *
     * @return array<string, string>
     */
    public static function defaults(DateTimeImmutable $today, ?ComplianceDocument $policy): array
    {
        return [
            'occurred_on' => $today->format('Y-m-d'),
            'fault' => Fault::Unknown->value,
            'status' => IncidentStatus::Open->value,
            'write_off_category' => WriteOffCategory::None->value,
            'claim_status' => ClaimStatus::NotClaimed->value,
            'ncd_affected' => NcdEffect::Unknown->value,
        ] + self::policyFields($policy);
    }

    /**
     * The insurer and policy fields for a policy (the default), or none.
     *
     * @return array<string, string>
     */
    public static function policyFields(?ComplianceDocument $policy): array
    {
        return $policy === null ? [] : [
            'insurer' => $policy->data->provider ?? '',
            'insurance_document_id' => (string) $policy->id,
        ];
    }

    /**
     * An existing incident as the edit form shows it.
     *
     * @return array<string, string|list<string>>
     */
    public static function values(Incident $incident, ?string $odometerKm, DisplayPreferences $preferences): array
    {
        $data = $incident->data;
        $claim = $data->claim;

        return [
            'occurred_on' => $data->occurredOn->format('Y-m-d'),
            'occurred_at_time' => $data->occurredAtTime ?? '',
            'location' => $data->location ?? '',
            'type' => $data->type->value,
            'fault' => $data->fault->value,
            'description' => $data->description ?? '',
            'odometer' => $odometerKm === null ? '' : OdometerReadingForm::distanceForDisplay($odometerKm, $preferences),
            'driver_user_id' => $data->driverUserId === null ? '' : (string) $data->driverUserId,
            'driver_name' => $data->driverName ?? '',
            'damage_areas' => array_map(static fn (DamageArea $area): string => $area->value, $data->damageAreas),
            'severity' => $data->severity->value ?? '',
            'write_off_category' => $data->writeOff->value,
            'other_party_name' => $data->otherPartyName ?? '',
            'other_party_registration' => $data->otherPartyRegistration ?? '',
            'other_party_insurer' => $data->otherPartyInsurer ?? '',
            'police_reference' => $data->policeReference ?? '',
            'status' => $data->status->value,
            'closed_on' => $data->closedOn?->format('Y-m-d') ?? '',
            'notes' => $data->notes ?? '',
            'claim_status' => $claim->status->value,
            'insurer' => $claim->insurer ?? '',
            'insurance_document_id' => $claim->insuranceDocumentId === null ? '' : (string) $claim->insuranceDocumentId,
            'claim_number' => $claim->claimNumber ?? '',
            'excess' => $claim->excess === null ? '' : Decimal::trim($claim->excess),
            'payout' => $claim->payout === null ? '' : Decimal::trim($claim->payout),
            'ncd_affected' => $claim->ncdAffected->value,
            'claim_updated_on' => $claim->updatedOn?->format('Y-m-d') ?? '',
            'repair_estimate' => $claim->repairEstimate === null ? '' : Decimal::trim($claim->repairEstimate),
        ];
    }

    /**
     * The values as flat strings, the damage areas comma-joined (a draft
     * card's *Edit* carries them so, spec.md §7.26).
     *
     * @param array<string, string|list<string>> $values
     * @return array<string, string>
     */
    public static function flatValues(array $values): array
    {
        return array_map(static fn (string|array $value): string => is_array($value) ? implode(',', $value) : $value, $values);
    }

    /**
     * The values back with the damage areas as a list.
     *
     * @param array<string, string> $values
     * @return array<string, string|list<string>>
     */
    public static function listValues(array $values): array
    {
        $areas = $values['damage_areas'] ?? '';
        unset($values['damage_areas']);

        return $values + ['damage_areas' => $areas === '' ? [] : explode(',', $areas)];
    }

    /**
     * @param array<array-key, mixed> $input
     * @param DateTimeImmutable $today calendar date in the user's time zone
     * @param list<int> $drivers the users who may be named as the driver
     * @param list<int> $policies the vehicle's `insurance` documents
     * @param ?string $storedKm the edited incident's odometer, kept unless changed
     */
    public static function parse(
        array $input,
        DisplayPreferences $preferences,
        DateTimeImmutable $today,
        array $drivers,
        array $policies,
        ?string $storedKm = null,
    ): IncidentInput|ValidationErrors {
        $validator = new Validator($input, $preferences->locale);

        $occurredOn = $validator->date('occurred_on', true);
        $time = $validator->string('occurred_at_time', false, 5);
        $location = $validator->string('location', false, self::LOCATION_MAX);
        $type = $validator->enum('type', IncidentType::class, true);
        $fault = $validator->enum('fault', Fault::class);
        $description = $validator->string('description', false, self::TEXT_MAX);
        $odometer = $validator->decimal(
            'odometer',
            false,
            OdometerReadingForm::KM_SCALE,
            '0',
            null,
            OdometerReadingForm::MAX_WHOLE_DIGITS,
        );
        $driverUser = $validator->choice('driver_user_id', array_map(strval(...), $drivers));
        $driverName = $validator->string('driver_name', false, self::NAME_MAX);
        $severity = $validator->enum('severity', Severity::class);
        $writeOff = $validator->enum('write_off_category', WriteOffCategory::class);
        $otherName = $validator->string('other_party_name', false, self::NAME_MAX);
        $otherRegistration = $validator->string('other_party_registration', false, self::NAME_MAX);
        $otherInsurer = $validator->string('other_party_insurer', false, self::NAME_MAX);
        $police = $validator->string('police_reference', false, self::NAME_MAX);
        $status = $validator->enum('status', IncidentStatus::class);
        $closedOn = $validator->date('closed_on');
        $notes = $validator->string('notes', false, self::TEXT_MAX);
        $claimStatus = $validator->enum('claim_status', ClaimStatus::class);
        $insurer = $validator->string('insurer', false, self::NAME_MAX);
        $policy = $validator->choice('insurance_document_id', array_map(strval(...), $policies));
        $claimNumber = $validator->string('claim_number', false, self::NAME_MAX);
        $excess = $validator->decimal('excess', false, self::MONEY_SCALE, '0', null, self::MONEY_WHOLE_DIGITS);
        $payout = $validator->decimal('payout', false, self::MONEY_SCALE, '0', null, self::MONEY_WHOLE_DIGITS);
        $estimate = $validator->decimal('repair_estimate', false, self::MONEY_SCALE, '0', null, self::MONEY_WHOLE_DIGITS);
        $ncd = $validator->enum('ncd_affected', NcdEffect::class);
        $updatedOn = $validator->date('claim_updated_on');
        $areas = self::areas($input['damage_areas'] ?? []);

        if ($occurredOn !== null && $occurredOn > $today) {
            $validator->addError('occurred_on', 'incident.error.future');
        }
        if ($time !== null && preg_match(self::TIME, $time) !== 1) {
            $validator->addError('occurred_at_time', 'incident.error.time');
        }
        if ($driverUser !== null && $driverName !== null) {
            $validator->addError('driver_name', 'incident.error.driver_both');
        }
        if ($closedOn !== null && $occurredOn !== null && $closedOn < $occurredOn) {
            $validator->addError('closed_on', 'incident.error.closed_before');
        }
        if ($areas === null) {
            $validator->addError('damage_areas', 'validation.choice');
        }

        if (!$validator->errors()->isEmpty() || $occurredOn === null || $type === null || $areas === null) {
            return $validator->errors();
        }

        return new IncidentInput(new IncidentData(
            occurredOn: $occurredOn,
            type: $type,
            occurredAtTime: $time,
            location: $location,
            fault: $fault ?? Fault::Unknown,
            description: $description,
            damageAreas: $areas,
            severity: $severity,
            driverUserId: $driverUser === null ? null : (int) $driverUser,
            driverName: $driverName,
            otherPartyName: $otherName,
            otherPartyRegistration: $otherRegistration,
            otherPartyInsurer: $otherInsurer,
            policeReference: $police,
            status: $status ?? IncidentStatus::Open,
            closedOn: $closedOn,
            writeOff: $writeOff ?? WriteOffCategory::None,
            notes: $notes,
            claim: new Claim(
                status: $claimStatus ?? ClaimStatus::NotClaimed,
                insurer: $insurer,
                insuranceDocumentId: $policy === null ? null : (int) $policy,
                claimNumber: $claimNumber,
                excess: $excess,
                payout: $payout,
                ncdAffected: $ncd ?? NcdEffect::Unknown,
                updatedOn: $updatedOn,
                repairEstimate: $estimate,
            ),
        ), $odometer === null ? null : OdometerReadingForm::distanceToKm($odometer, $preferences, $storedKm));
    }

    /**
     * The ticked damage areas in DamageArea order; null when one is unknown.
     *
     * @return list<DamageArea>|null
     */
    private static function areas(mixed $given): ?array
    {
        $codes = is_array($given) ? $given : [$given];
        $codes = array_values(array_filter($codes, static fn (mixed $code): bool => $code !== '' && $code !== null));
        foreach ($codes as $code) {
            if (!is_string($code) || DamageArea::tryFrom($code) === null) {
                return null;
            }
        }

        return array_values(array_filter(
            DamageArea::cases(),
            static fn (DamageArea $area): bool => in_array($area->value, $codes, true),
        ));
    }
}
