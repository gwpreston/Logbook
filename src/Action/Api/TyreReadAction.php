<?php

declare(strict_types=1);

namespace Logbook\Action\Api;

use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Middleware\VehicleAccessMiddleware;
use Logbook\Service\Api\ApiReader;
use Logbook\Service\Api\ApiTyres;
use Logbook\Support\Api\ApiResponder;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/vehicles/{id}/tyres/changes, /vehicles/{id}/tyre-sets and
 * /tyre-sets (spec.md §7.17, §7.20 *Phase 39*). The route names the list
 * in its `list` argument; the fleet's sets are every active vehicle's the
 * key's user can see (`?vehicle=` narrows them).
 */
final readonly class TyreReadAction
{
    public function __construct(
        private ApiTyres $tyres,
        private ApiReader $reader,
        private ApiResponder $responder,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = RequestContext::requireUser($request);
        $routed = $request->getAttribute(VehicleAccessMiddleware::ATTRIBUTE);
        if (($args['list'] ?? '') === 'changes' && $routed instanceof Vehicle) {
            return $this->responder->json(['items' => $this->tyres->changes($routed)]);
        }
        $vehicle = $routed instanceof Vehicle ? $routed : VehicleFilter::fromRequest($request, $this->reader, $user);

        return $this->responder->json(['items' => $this->tyres->sets(
            $vehicle !== null ? [$vehicle] : $this->reader->activeVehicles($user),
        )]);
    }
}
