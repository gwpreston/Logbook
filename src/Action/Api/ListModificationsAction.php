<?php

declare(strict_types=1);

namespace Logbook\Action\Api;

use Logbook\Service\Api\ApiModifications;
use Logbook\Support\Api\ApiProblem;
use Logbook\Support\Api\ApiResponder;
use Logbook\Support\Api\ListQuery;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/vehicles/{id}/modifications (spec.md §7.20 *Parts and modifications*): newest
 * `done_on` first, paged, `?state=current|reverted` to filter.
 */
final readonly class ListModificationsAction
{
    public function __construct(
        private ApiModifications $modifications,
        private ApiResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $state = $request->getQueryParams()['state'] ?? null;
        if ($state !== null && $state !== 'current' && $state !== 'reverted') {
            throw ApiProblem::invalidParameter('state', 'current or reverted.');
        }
        $page = $this->modifications->list(
            RequestContext::requireUser($request),
            RequestContext::vehicle($request),
            ListQuery::fromRequest($request),
            $state,
        );

        return $this->responder->page($request, $page['items'], $page['cursor']);
    }
}
