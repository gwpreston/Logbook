<?php

declare(strict_types=1);

namespace Logbook\Action\Api;

use Logbook\Service\Api\ApiVehicleFigures;
use Logbook\Support\Api\ApiResponder;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/vehicles/{id}/ownership — Phase 14.2's cost of ownership and
 * depreciation (spec.md §7.20 *Phase 39*). Needs ViewCosts (403 without).
 */
final readonly class OwnershipAction
{
    public function __construct(
        private ApiVehicleFigures $figures,
        private ApiResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->responder->json($this->figures->ownership(
            RequestContext::requireUser($request),
            RequestContext::vehicle($request),
        ));
    }
}
