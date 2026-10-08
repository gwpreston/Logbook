<?php

declare(strict_types=1);

namespace Logbook\Service\Api;

use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Compliance\ComplianceDocumentNotFound;
use Logbook\Service\Compliance\ComplianceService;
use Logbook\Service\Expense\ExpenseEntryNotFound;
use Logbook\Service\Expense\ExpenseService;
use Logbook\Service\Fuel\FuelEntryNotFound;
use Logbook\Service\Fuel\FuelService;
use Logbook\Service\Incident\IncidentNotFound;
use Logbook\Service\Incident\IncidentService;
use Logbook\Service\Maintenance\MaintenanceEntryNotFound;
use Logbook\Service\Maintenance\MaintenanceService;
use Logbook\Service\Odometer\OdometerReadingNotFound;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Service\Trip\TripNotFound;
use Logbook\Service\Trip\TripService;
use Logbook\Support\Api\ApiProblem;
use Logbook\Support\Api\EntityTag;
use Logbook\Support\Api\Serializer;

/**
 * Single-entry reads (spec.md §7.20 *Phase 39*): `GET …/{list}/{entry}`
 * returns the object exactly as its list does, with the stored entry's
 * `ETag`. The entry is looked up under the vehicle, so another vehicle's
 * id answers 404, and the list's own visibility applies: a trip the key's
 * user may not see is 404 too, and an incident's details follow
 * IncidentAccess.
 */
final readonly class ApiEntries
{
    /** The lists that have a single-entry read, as their path segment. */
    public const array LISTS = ['fuel', 'odometer', 'maintenance', 'documents', 'expenses', 'trips', 'incidents'];

    public function __construct(
        private ApiReader $reader,
        private ApiIncidents $incidentReader,
        private FuelService $fuel,
        private OdometerService $odometer,
        private MaintenanceService $maintenance,
        private ComplianceService $compliance,
        private ExpenseService $expenses,
        private TripService $trips,
        private IncidentService $incidents,
    ) {
    }

    /**
     * @param value-of<self::LISTS> $list
     * @throws ApiProblem 404 when the vehicle has no such entry the user may see
     */
    public function read(string $list, User $user, Vehicle $vehicle, int $id): ApiEntry
    {
        try {
            return match ($list) {
                'fuel' => $this->entry(
                    $entry = $this->fuel->get($vehicle, $id),
                    $this->reader->fuelEntry($user, $vehicle, $entry),
                ),
                'odometer' => $this->entry($entry = $this->odometer->get($vehicle, $id), Serializer::odometerReading($entry)),
                'maintenance' => $this->entry(
                    $entry = $this->maintenance->get($vehicle, $id),
                    $this->reader->maintenanceEntry($user, $vehicle, $entry),
                ),
                'documents' => $this->entry(
                    $entry = $this->compliance->get($vehicle, $id),
                    $this->reader->document($user, $vehicle, $entry),
                ),
                'expenses' => $this->entry(
                    $entry = $this->expenses->get($vehicle, $id),
                    $this->reader->expenseEntry($user, $vehicle, $entry),
                ),
                'trips' => $this->entry($entry = $this->trips->get($user, $vehicle, $id), Serializer::trip($entry)),
                'incidents' => $this->entry(
                    $entry = $this->incidents->get($vehicle, $id),
                    $this->incidentReader->one($user, $vehicle, $entry),
                ),
            };
        } catch (
            FuelEntryNotFound
            | OdometerReadingNotFound
            | MaintenanceEntryNotFound
            | ComplianceDocumentNotFound
            | ExpenseEntryNotFound
            | TripNotFound
            | IncidentNotFound
        ) {
            throw ApiProblem::notFound('The vehicle has no such entry.');
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private function entry(object $stored, array $body): ApiEntry
    {
        return new ApiEntry($body, EntityTag::of($stored));
    }
}
