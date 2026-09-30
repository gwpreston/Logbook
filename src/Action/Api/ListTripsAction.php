<?php

declare(strict_types=1);

namespace Logbook\Action\Api;

use Logbook\Service\Api\ApiReader;
use Logbook\Support\Api\ApiResponder;
use Logbook\Support\Api\ListQuery;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/vehicles/{id}/trips — the trips the key's user may see
 * (spec.md §7.20, §7.22): their own, or everyone's with ViewOthersTrips.
 * Newest first, paged, `since` / `until` on the trip's date.
 */
final readonly class ListTripsAction
{
    public function __construct(
        private ApiReader $reader,
        private ApiResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $query = ListQuery::fromRequest($request);
        $page = $this->reader->trips(RequestContext::requireUser($request), RequestContext::vehicle($request), $query);

        return $this->responder->page($request, $page['items'], $page['cursor']);
    }
}
