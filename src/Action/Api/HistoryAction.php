<?php

declare(strict_types=1);

namespace Logbook\Action\Api;

use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Middleware\VehicleAccessMiddleware;
use Logbook\Service\Api\ApiHistory;
use Logbook\Service\Api\ApiReader;
use Logbook\Support\Api\ApiResponder;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/vehicles/{id}/history and GET /api/v1/history — the history
 * feed (spec.md §7.16, §7.20 *Phase 39*) of one vehicle, or of every
 * active vehicle the key's user can see (`?vehicle=` narrows it), as the
 * History tab and the fleet history.
 */
final readonly class HistoryAction
{
    public function __construct(
        private ApiHistory $history,
        private ApiReader $reader,
        private ApiResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = RequestContext::requireUser($request);
        $routed = $request->getAttribute(VehicleAccessMiddleware::ATTRIBUTE);
        $vehicle = $routed instanceof Vehicle ? $routed : VehicleFilter::fromRequest($request, $this->reader, $user);
        $vehicles = $vehicle !== null ? [$vehicle] : $this->reader->activeVehicles($user);
        $page = $this->history->page($user, $vehicles, $request);

        return $this->responder->page($request, $page['items'], $page['cursor']);
    }
}
