<?php

declare(strict_types=1);

namespace Logbook\Action\Api;

use Logbook\Service\Api\ApiIncidents;
use Logbook\Support\Api\ApiResponder;
use Logbook\Support\Api\ListQuery;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/vehicles/{id}/incidents (spec.md §7.20, §7.29): newest first,
 * each as the key's user may see it.
 */
final readonly class ListIncidentsAction
{
    public function __construct(
        private ApiIncidents $incidents,
        private ApiResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $page = $this->incidents->list(
            RequestContext::requireUser($request),
            RequestContext::vehicle($request),
            ListQuery::fromRequest($request),
        );

        return $this->responder->page($request, $page['items'], $page['cursor']);
    }
}
