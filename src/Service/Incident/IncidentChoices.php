<?php

declare(strict_types=1);

namespace Logbook\Service\Incident;

use Logbook\Domain\Compliance\ComplianceDocument;
use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\ComplianceDocumentRepository;
use Logbook\Service\Sharing\SharingService;
use Logbook\Service\User\UserDirectory;

/**
 * What an incident may name (spec.md §7.29 *Form*): the driver, one of the
 * people who can view the vehicle, and the `insurance` document claimed on.
 * The form, the API and the draft tool offer and accept the same choices.
 */
final readonly class IncidentChoices
{
    public function __construct(
        private SharingService $sharing,
        private UserDirectory $directory,
        private ComplianceDocumentRepository $documents,
    ) {
    }

    /**
     * The owner and everyone the vehicle is shared with.
     *
     * @return array<int, string> id => display name
     */
    public function drivers(Vehicle $vehicle): array
    {
        $drivers = [];
        $owner = $this->directory->find($vehicle->userId);
        if ($owner !== null) {
            $drivers[$owner->id] = $owner->displayName;
        }
        foreach ($this->sharing->sharesOf($vehicle) as $row) {
            $drivers[$row->user->id] = $row->user->displayName;
        }

        return $drivers;
    }

    /**
     * @return list<ComplianceDocument> the vehicle's insurance documents, newest first
     */
    public function policies(Vehicle $vehicle): array
    {
        return array_reverse(array_values(array_filter(
            $this->documents->listForVehicle($vehicle->id),
            static fn (ComplianceDocument $document): bool => $document->data->type === ComplianceType::Insurance,
        )));
    }

    /**
     * @return list<int>
     */
    public function driverIds(Vehicle $vehicle): array
    {
        return array_keys($this->drivers($vehicle));
    }

    /**
     * @return list<int>
     */
    public function policyIds(Vehicle $vehicle): array
    {
        return array_map(static fn (ComplianceDocument $policy): int => $policy->id, $this->policies($vehicle));
    }
}
