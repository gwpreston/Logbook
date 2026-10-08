<?php

declare(strict_types=1);

namespace Logbook\Action\Api;

use Logbook\Service\Api\ApiVehicleFigures;
use Logbook\Support\Api\ApiResponder;
use Logbook\Support\Api\ListQuery;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/vehicles/{id}/valuations — valuations, newest first, paged as
 * the entry lists, `since` / `until` on the valuation's date (spec.md §7.1,
 * §7.20 *Phase 39*). Needs ViewCosts.
 */
final readonly class ListValuationsAction
{
    public function __construct(
        private ApiVehicleFigures $figures,
        private ApiResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $page = $this->figures->valuations(
            RequestContext::requireUser($request),
            RequestContext::vehicle($request),
            ListQuery::fromRequest($request),
        );

        return $this->responder->page($request, $page['items'], $page['cursor']);
    }
}
