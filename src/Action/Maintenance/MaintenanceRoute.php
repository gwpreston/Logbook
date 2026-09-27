<?php

declare(strict_types=1);

namespace Logbook\Action\Maintenance;

use Logbook\Domain\Maintenance\MaintenanceEntry;
use Logbook\Domain\Maintenance\MaintenanceSchedule;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Maintenance\MaintenanceEntryNotFound;
use Logbook\Service\Maintenance\MaintenanceScheduleNotFound;
use Logbook\Service\Maintenance\MaintenanceService;
use Logbook\Service\Maintenance\ScheduleService;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * Shared request plumbing for the /vehicles/{id}/maintenance routes.
 */
final class MaintenanceRoute
{
    /**
     * The entry named by the route on this vehicle, or a 404.
     *
     * @param array<string, string> $args
     */
    public static function entry(
        MaintenanceService $maintenance,
        Vehicle $vehicle,
        ServerRequestInterface $request,
        array $args,
    ): MaintenanceEntry {
        try {
            return $maintenance->get($vehicle, (int) ($args['entry'] ?? 0));
        } catch (MaintenanceEntryNotFound) {
            throw new HttpNotFoundException($request);
        }
    }

    /**
     * The schedule named by the route on this vehicle, or a 404.
     *
     * @param array<string, string> $args
     */
    public static function schedule(
        ScheduleService $schedules,
        Vehicle $vehicle,
        ServerRequestInterface $request,
        array $args,
    ): MaintenanceSchedule {
        try {
            return $schedules->get($vehicle, (int) ($args['schedule'] ?? 0));
        } catch (MaintenanceScheduleNotFound) {
            throw new HttpNotFoundException($request);
        }
    }
}
