<?php

declare(strict_types=1);

namespace Logbook\Action\Maintenance;

use Logbook\Action\Vehicle\VehicleRoute;
use Logbook\Service\Maintenance\ScheduleService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET|POST /vehicles/{id}/maintenance/schedules/{schedule}/delete — confirm
 * (works without JS), then delete a schedule. Its entries stay in the history.
 */
final readonly class DeleteScheduleAction
{
    public function __construct(
        private VehicleService $vehicles,
        private ScheduleService $schedules,
        private View $view,
        private Redirector $redirect,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $vehicle = VehicleRoute::vehicle($this->vehicles, $request, $args);
        $schedule = MaintenanceRoute::schedule($this->schedules, $vehicle, $request, $args);
        $description = ['title' => $schedule->data->title];

        if ($request->getMethod() !== 'POST') {
            return $this->view->render($request, $response, 'entries/delete.twig', [
                'vehicle' => $vehicle,
                'title' => 'maintenance.schedule.delete_title',
                'body' => 'maintenance.schedule.delete_body',
                'params' => $description,
                'action' => ['maintenance.schedules.delete', ['id' => $vehicle->id, 'schedule' => $schedule->id]],
                'cancel' => ['maintenance.index', ['id' => $vehicle->id]],
            ]);
        }

        $this->schedules->delete($vehicle, $schedule);
        RequestContext::session($request)->flash('success', 'maintenance.schedule.deleted', $description);

        return $this->redirect->toRoute('maintenance.index', ['id' => (string) $vehicle->id]);
    }
}
