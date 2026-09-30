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
 * GET /api/v1/vehicles/{id}/odometer — readings with their source (spec.md §7.20): newest first, paged, `since` / `until`.
 */
final readonly class ListOdometerAction
{
    public function __construct(
        private ApiReader $reader,
        private ApiResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $query = ListQuery::fromRequest($request);
        $page = $this->reader->odometer(RequestContext::vehicle($request), $query);

        return $this->responder->page($request, $page['items'], $page['cursor']);
    }
}
