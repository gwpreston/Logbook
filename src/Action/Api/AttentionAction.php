<?php

declare(strict_types=1);

namespace Logbook\Action\Api;

use Logbook\Service\Api\ApiAttention;
use Logbook\Service\Api\ApiReader;
use Logbook\Support\Api\ApiResponder;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/attention — *Needs attention* for every active vehicle the
 * key's user can see, or one (`?vehicle=`) (spec.md §7.24, §7.20 *Phase 39*).
 */
final readonly class AttentionAction
{
    public function __construct(
        private ApiAttention $attention,
        private ApiReader $reader,
        private ApiResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = RequestContext::requireUser($request);
        $vehicle = VehicleFilter::fromRequest($request, $this->reader, $user);

        return $this->responder->json(['items' => $this->attention->items(
            $user,
            $vehicle !== null ? [$vehicle] : $this->reader->activeVehicles($user),
        )]);
    }
}
