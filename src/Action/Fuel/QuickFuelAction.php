<?php

declare(strict_types=1);

namespace Logbook\Action\Fuel;

use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /fuel/new — the fast "log a fill-up" path behind the Log button: with
 * one active vehicle it goes straight to that vehicle's form, with several it
 * asks which one (one tap), with none it offers to add a vehicle.
 */
final readonly class QuickFuelAction
{
    public function __construct(
        private VehicleService $vehicles,
        private View $view,
        private Redirector $redirect,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $vehicles = $this->vehicles->listFleet(RequestContext::requireUser($request));

        if ($vehicles === []) {
            RequestContext::session($request)->flash('info', 'fuel.quick_no_vehicles');

            return $this->redirect->toRoute('vehicles.create');
        }
        if (count($vehicles) === 1) {
            return $this->redirect->toRoute('fuel.create', ['id' => (string) $vehicles[0]->id]);
        }

        return $this->view->render($request, $response, 'fuel/quick.twig', ['vehicles' => $vehicles]);
    }
}
