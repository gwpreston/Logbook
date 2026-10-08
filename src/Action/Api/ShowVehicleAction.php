<?php

declare(strict_types=1);

namespace Logbook\Action\Api;

use Logbook\Service\Api\ApiVehicleWrites;
use Logbook\Support\Api\ApiResponder;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/vehicles/{id} — one vehicle, as its edit form holds it
 * (spec.md §7.20).
 */
final readonly class ShowVehicleAction
{
    public function __construct(
        private ApiVehicleWrites $vehicles,
        private ApiResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        // With the stored vehicle's ETag, for If-Match on PATCH (Phase 39.2).
        $read = $this->vehicles->read(RequestContext::requireUser($request), RequestContext::vehicle($request));

        return $this->responder->json($read['body'])->withHeader('ETag', $read['tag']);
    }
}
