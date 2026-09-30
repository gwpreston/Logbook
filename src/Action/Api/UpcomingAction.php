<?php

declare(strict_types=1);

namespace Logbook\Action\Api;

use Logbook\Service\Api\ApiReader;
use Logbook\Support\Api\ApiResponder;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/upcoming — *Coming up* over the next 12 months (spec.md
 * §7.18, §7.20), for every active vehicle or `?vehicle=` one. Not paged.
 */
final readonly class UpcomingAction
{
    public function __construct(
        private ApiReader $reader,
        private ApiResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = RequestContext::requireUser($request);
        $vehicle = VehicleFilter::fromRequest($request, $this->reader, $user);

        return $this->responder->json([
            'items' => $this->reader->upcoming($user, $vehicle === null ? $this->reader->activeVehicles($user) : [$vehicle]),
        ]);
    }
}
