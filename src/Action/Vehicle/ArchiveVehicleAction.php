<?php

declare(strict_types=1);

namespace Logbook\Action\Vehicle;

use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /vehicles/{id}/archive — hide a sold/retired vehicle from active views,
 * keeping its history.
 */
final readonly class ArchiveVehicleAction
{
    public function __construct(
        private VehicleService $vehicles,
        private Redirector $redirect,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $vehicle = RequestContext::vehicle($request);

        $this->vehicles->archive(RequestContext::requireUser($request), $vehicle);
        RequestContext::session($request)->flash('success', 'vehicle.archived', ['name' => $vehicle->name()]);

        return $this->redirect->toRoute('vehicles.show', ['id' => (string) $vehicle->id]);
    }
}
