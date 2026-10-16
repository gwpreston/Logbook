<?php

declare(strict_types=1);

namespace Logbook\Action\Api;

use Logbook\Domain\Part\PartStatus;
use Logbook\Service\Api\ApiParts;
use Logbook\Support\Api\ApiProblem;
use Logbook\Support\Api\ApiResponder;
use Logbook\Support\Api\ListQuery;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/vehicles/{id}/parts (spec.md §7.20 *Parts and modifications*): newest added
 * first, paged, `?status=fitted|stored|gone` to filter.
 */
final readonly class ListPartsAction
{
    public function __construct(
        private ApiParts $parts,
        private ApiResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $raw = $request->getQueryParams()['status'] ?? null;
        $status = null;
        if ($raw !== null) {
            $status = (is_string($raw) ? PartStatus::tryFrom($raw) : null)
                ?? throw ApiProblem::invalidParameter('status', 'fitted, stored or gone.');
        }
        $page = $this->parts->list(
            RequestContext::requireUser($request),
            RequestContext::vehicle($request),
            ListQuery::fromRequest($request),
            $status,
        );

        return $this->responder->page($request, $page['items'], $page['cursor']);
    }
}
