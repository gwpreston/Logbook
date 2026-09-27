<?php

declare(strict_types=1);

namespace Logbook\Action\Maintenance;

use Logbook\Action\Vehicle\VehicleRoute;
use Logbook\Service\Maintenance\MaintenanceService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Display\DisplayFormatter;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET|POST /vehicles/{id}/maintenance/{entry}/delete — confirm (works
 * without JS), then delete an entry with its odometer reading and files.
 */
final readonly class DeleteMaintenanceEntryAction
{
    public function __construct(
        private VehicleService $vehicles,
        private MaintenanceService $maintenance,
        private DisplayFormatter $formatter,
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
        $entry = MaintenanceRoute::entry($this->maintenance, $vehicle, $request, $args);
        $description = [
            'title' => $entry->data->title,
            'date' => $this->formatter->date($entry->data->performedOn),
        ];

        if ($request->getMethod() !== 'POST') {
            return $this->view->render($request, $response, 'entries/delete.twig', [
                'vehicle' => $vehicle,
                'title' => 'maintenance.delete_title',
                'body' => 'maintenance.delete_body',
                'params' => $description,
                'action' => ['maintenance.delete', ['id' => $vehicle->id, 'entry' => $entry->id]],
                'cancel' => ['maintenance.index', ['id' => $vehicle->id]],
            ]);
        }

        $this->maintenance->delete($vehicle, $entry);
        RequestContext::session($request)->flash('success', 'maintenance.deleted', $description);

        return $this->redirect->toRoute('maintenance.index', ['id' => (string) $vehicle->id]);
    }
}
