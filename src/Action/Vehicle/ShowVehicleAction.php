<?php

declare(strict_types=1);

namespace Logbook\Action\Vehicle;

use Logbook\Domain\Fuel\EnergyKind;
use Logbook\Domain\Fuel\Fuel;
use Logbook\Service\Compliance\ComplianceService;
use Logbook\Service\Compliance\DocumentState;
use Logbook\Service\Fuel\FuelService;
use Logbook\Service\History\ActivityFeed;
use Logbook\Service\Maintenance\MaintenanceService;
use Logbook\Service\Maintenance\ScheduleService;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Service\Reminder\ReminderSettingsStore;
use Logbook\Service\Vehicle\VehicleAge;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /vehicles/{id} — vehicle overview: current odometer and fuel figures,
 * the latest history, what maintenance is due next, where each document
 * stands, the latest fill-ups, and the vehicle's details. Each area has its
 * own tab.
 */
final readonly class ShowVehicleAction
{
    private const int RECENT_FILLS = 3;
    private const int RECENT_HISTORY = 5;
    private const int SCHEDULES_SHOWN = 3;

    public function __construct(
        private VehicleService $vehicles,
        private OdometerService $odometer,
        private FuelService $fuel,
        private MaintenanceService $maintenance,
        private ScheduleService $schedules,
        private ComplianceService $compliance,
        private View $view,
        private ClockInterface $clock,
        private ReminderSettingsStore $reminderSettings,
        private ActivityFeed $feed,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $vehicle = VehicleRoute::vehicle($this->vehicles, $request, $args);
        $user = RequestContext::requireUser($request);
        $today = LocalTime::today($this->clock, $user->preferences->timeZone());
        $odometer = $this->odometer->history($vehicle);
        $lead = $this->reminderSettings->reminderPreferences($user->id);
        $fuel = $this->fuel->history($vehicle);
        $kind = Fuel::defaultFor($vehicle->data->fuelType)->kind();
        $documents = array_filter(
            $this->compliance->states($vehicle, $today, $lead->documentDays),
            static fn (DocumentState $s): bool => $s->status->isCurrent(),
        );

        return $this->view->render($request, $response, 'vehicles/show.twig', [
            'vehicle' => $vehicle,
            'currency' => $this->vehicles->currencyFor($user, $vehicle),
            'odometer' => $odometer,
            'fuel' => $fuel,
            'fuel_summary' => $fuel->summary($kind),
            'electric' => $kind === EnergyKind::Electric,
            'recent_fills' => array_slice($fuel->newestFirst(), 0, self::RECENT_FILLS),
            'maintenance' => $this->maintenance->history($vehicle),
            'schedules' => array_slice(
                $this->schedules->states($vehicle, $today, $odometer, $lead->scheduleDays, $lead->scheduleKm),
                0,
                self::SCHEDULES_SHOWN,
            ),
            'documents' => array_values($documents),
            'age' => VehicleAge::of($vehicle, $today),
            'recent_history' => $this->feed->latest($user, [$vehicle], self::RECENT_HISTORY),
        ]);
    }
}
