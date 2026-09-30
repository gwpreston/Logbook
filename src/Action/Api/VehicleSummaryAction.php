<?php

declare(strict_types=1);

namespace Logbook\Action\Api;

use Logbook\Service\Api\ApiReader;
use Logbook\Support\Api\ApiResponder;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/vehicles/{id}/summary — what a sensor shows: odometer,
 * economy, last fill-up, cost per distance, what is due next, reminders,
 * documents and tyres, plus `display` text in the owner's units
 * (spec.md §7.20).
 */
final readonly class VehicleSummaryAction
{
    public function __construct(
        private ApiReader $reader,
        private ApiResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->responder->json(
            $this->reader->summary(RequestContext::requireUser($request), RequestContext::vehicle($request)),
        );
    }
}
