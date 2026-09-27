<?php

declare(strict_types=1);

namespace Logbook\Action\Vehicle;

use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /vehicles/{id} — vehicle detail. Sections for fuel, maintenance and
 * documents are placeholders until their phases land.
 */
final readonly class ShowVehicleAction
{
    public function __construct(
        private VehicleService $vehicles,
        private View $view,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $vehicle = VehicleRoute::vehicle($this->vehicles, $request, $args);

        return $this->view->render($request, $response, 'vehicles/show.twig', [
            'vehicle' => $vehicle,
            'currency' => $this->vehicles->currencyFor(RequestContext::requireUser($request), $vehicle),
        ]);
    }
}
