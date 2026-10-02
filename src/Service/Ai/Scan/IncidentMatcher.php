<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Scan;

use Logbook\Domain\Ai\Scan\ScanKind;
use Logbook\Domain\Ai\Scan\ScanUpload;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Incident\Incident;
use Logbook\Domain\Incident\IncidentStatus;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\IncidentRepository;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Incident\IncidentAccess;

/**
 * Which incident a claim letter or repair estimate updates (spec.md §7.27
 * *Mapping*, §7.29 *Reading claim letters*): the one it was scanned for,
 * else the one whose claim number matches, else (an estimate only) the
 * vehicle's most recent open incident; null opens *Log incident*. Only
 * incidents the user may change are ever chosen, as for *Part of an
 * incident*.
 */
final readonly class IncidentMatcher
{
    /** `?incident=new` on the estimate's choice: *Log incident* instead. */
    public const string NEW = 'new';

    public function __construct(
        private IncidentRepository $incidents,
        private IncidentAccess $access,
        private FeatureToggles $features,
    ) {
    }

    /**
     * The vehicle's incidents the user may change, open ones first, then newest first.
     *
     * @return list<Incident>
     */
    public function changeable(User $user, Vehicle $vehicle): array
    {
        if (!$this->features->isEnabled(Feature::Incidents)) {
            return [];
        }

        return array_values(array_filter(
            $this->incidents->listForVehicle($vehicle->id),
            fn (Incident $incident): bool => $this->access->canChange($user, $vehicle, $incident),
        ));
    }

    public function find(User $user, Vehicle $vehicle, int|string|null $id): ?Incident
    {
        if (is_string($id) && !ctype_digit($id) || $id === null) {
            return null;
        }
        foreach ($this->changeable($user, $vehicle) as $incident) {
            if ($incident->id === (int) $id) {
                return $incident;
            }
        }

        return null;
    }

    /**
     * @param string|null $chosen `?incident=` from the estimate's choice: an id, or NEW
     */
    public function forScan(
        User $user,
        Vehicle $vehicle,
        ScanUpload $upload,
        ?Extraction $reading,
        ScanKind $kind,
        ?string $chosen = null,
    ): ?Incident {
        if ($chosen === self::NEW) {
            return null;
        }
        $picked = $this->find($user, $vehicle, $chosen) ?? $this->find($user, $vehicle, $upload->incidentId);
        if ($picked !== null) {
            return $picked;
        }
        $incidents = $this->changeable($user, $vehicle);
        $number = $reading?->value('claim_number');
        if ($number !== null && self::claimKey($number) !== '') {
            foreach ($incidents as $incident) {
                $theirs = $incident->data->claim->claimNumber;
                if ($theirs !== null && self::claimKey($theirs) === self::claimKey($number)) {
                    return $incident;
                }
            }
        }
        if ($kind === ScanKind::RepairEstimate) {
            // An estimate seldom carries a claim number: the latest open incident, which the form lets the user change.
            foreach ($incidents as $incident) {
                if ($incident->data->status === IncidentStatus::Open) {
                    return $incident;
                }
            }
        }

        return null;
    }

    /**
     * A claim number as compared: upper case, letters and digits only
     * ("clm-4417" matches "CLM 4417").
     */
    public static function claimKey(string $number): string
    {
        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtoupper($number));
    }
}
