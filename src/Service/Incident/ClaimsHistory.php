<?php

declare(strict_types=1);

namespace Logbook\Service\Incident;

use Logbook\Domain\Access\VehicleScope;
use Logbook\Domain\Incident\Incident;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\IncidentRepository;
use Logbook\Repository\VehicleRepository;
use Logbook\Service\Access\VehicleAccess;
use Logbook\Service\User\UserDirectory;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Date\LocalTime;
use Psr\Clock\ClockInterface;

/**
 * Every incident on every vehicle the user can see, archived and sold ones
 * included: the answer an insurer asks for at every quote (spec.md §7.29
 * *Claims history*). Filters that need a detail field (driver, fault,
 * claims only) match only rows whose details the user may see.
 */
final readonly class ClaimsHistory
{
    private const string NAME = 'name:';

    public function __construct(
        private IncidentRepository $incidents,
        private VehicleRepository $vehicles,
        private VehicleService $vehicleService,
        private VehicleAccess $access,
        private IncidentAccess $incidentAccess,
        private UserDirectory $directory,
        private ClockInterface $clock,
    ) {
    }

    public function report(User $user, ClaimsFilter $filter = new ClaimsFilter()): ClaimsHistoryReport
    {
        $today = LocalTime::today($this->clock, $user->preferences->timeZone());
        $from = $filter->start($today);
        $until = $filter->end($today);

        $vehicles = [];
        foreach ($this->vehicles->listByIds($this->access->visibleVehicleIds($user, VehicleScope::All)) as $vehicle) {
            $vehicles[$vehicle->id] = $vehicle;
        }
        $ids = $filter->vehicleId === null
            ? array_keys($vehicles)
            : (isset($vehicles[$filter->vehicleId]) ? [$filter->vehicleId] : []);

        $rows = [];
        $drivers = [];
        foreach ($this->incidents->listForVehicles($ids, $from, $until) as $incident) {
            $vehicle = $vehicles[$incident->vehicleId];
            $view = $this->incidentAccess->view($user, $vehicle, $incident);
            $driver = $view->details ? $this->driverKey($incident) : null;
            if ($driver !== null) {
                $drivers[$driver] = $this->driverName($incident) ?? '';
            }
            if (!$this->matches($filter, $view, $driver)) {
                continue;
            }
            $rows[] = new ClaimsRow(
                $view,
                $vehicle,
                $view->details ? $this->driverName($incident) : null,
                $this->vehicleService->currencyFor($user, $vehicle),
            );
        }
        asort($drivers);

        return new ClaimsHistoryReport($filter, $from, $until, $rows, $drivers);
    }

    /**
     * The driver filter's value for an incident: a user's id, or the typed name.
     */
    public function driverKey(Incident $incident): ?string
    {
        $data = $incident->data;
        if ($data->driverUserId !== null) {
            return (string) $data->driverUserId;
        }

        return $data->driverName === null ? null : self::NAME . $data->driverName;
    }

    public function driverName(Incident $incident): ?string
    {
        $data = $incident->data;

        return $data->driverUserId === null ? $data->driverName : $this->directory->displayName($data->driverUserId);
    }

    private function matches(ClaimsFilter $filter, IncidentView $view, ?string $driver): bool
    {
        if (!$filter->claimsOnly && $filter->fault === null && $filter->driver === null) {
            return true;
        }
        if (!$view->details) {
            return false;
        }

        return (!$filter->claimsOnly || ($view->claimStatus?->isClaim() ?? false))
            && ($filter->fault === null || $view->fault === $filter->fault)
            && ($filter->driver === null || $driver === $filter->driver);
    }
}
