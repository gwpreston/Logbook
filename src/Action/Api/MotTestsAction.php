<?php

declare(strict_types=1);

namespace Logbook\Action\Api;

use Logbook\Service\Api\ApiMotTests;
use Logbook\Support\Api\ApiResponder;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/vehicles/{id}/mot-tests — the vehicle's stored MOT history
 * (spec.md §7.20, §7.38), `View`; a 404 while MOT history is off.
 */
final readonly class MotTestsAction
{
    public function __construct(
        private ApiMotTests $tests,
        private ApiResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->responder->json($this->tests->forVehicle(RequestContext::vehicle($request)));
    }
}
