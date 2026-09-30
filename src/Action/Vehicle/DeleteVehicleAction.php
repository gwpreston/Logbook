<?php

declare(strict_types=1);

namespace Logbook\Action\Vehicle;

use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET|POST /vehicles/{id}/delete — a confirmation page (works without JS),
 * then permanent deletion of the vehicle, its history and its photo.
 */
final readonly class DeleteVehicleAction
{
    public function __construct(
        private VehicleService $vehicles,
        private View $view,
        private Redirector $redirect,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $vehicle = RequestContext::vehicle($request);

        if ($request->getMethod() !== 'POST') {
            return $this->view->render($request, $response, 'vehicles/delete.twig', ['vehicle' => $vehicle]);
        }

        $this->vehicles->delete(RequestContext::requireUser($request), $vehicle);
        RequestContext::session($request)->flash('success', 'vehicle.deleted', ['name' => $vehicle->name()]);

        return $this->redirect->toRoute('garage');
    }
}
