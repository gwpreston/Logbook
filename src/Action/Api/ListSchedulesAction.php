<?php

declare(strict_types=1);

namespace Logbook\Action\Api;

use Logbook\Service\Api\ApiVehicleFigures;
use Logbook\Support\Api\ApiResponder;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/vehicles/{id}/schedules — maintenance schedules with their
 * due state, most urgent first, as the Maintenance tab (spec.md §7.4,
 * §7.20 *Phase 39*). Short, so not paged.
 */
final readonly class ListSchedulesAction
{
    public function __construct(
        private ApiVehicleFigures $figures,
        private ApiResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->responder->json(['items' => $this->figures->schedules(
            RequestContext::requireUser($request),
            RequestContext::vehicle($request),
        )]);
    }
}
