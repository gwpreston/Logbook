<?php

declare(strict_types=1);

namespace Logbook\Action\Api;

use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Service\Api\ApiReader;
use Logbook\Support\Api\ApiResponder;
use Logbook\Support\Api\ListQuery;
use Logbook\Support\Api\QueryParams;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/vehicles/{id}/documents — compliance documents with their
 * status (spec.md §7.20): newest first, paged, `since` / `until`, and
 * from Phase 39.1 `type` and `current` (in force today).
 */
final readonly class ListDocumentsAction
{
    public function __construct(
        private ApiReader $reader,
        private ApiResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $query = ListQuery::fromRequest($request);
        $page = $this->reader->documents(
            RequestContext::requireUser($request),
            RequestContext::vehicle($request),
            $query,
            QueryParams::code($request, 'type', ComplianceType::class),
            QueryParams::flag($request, 'current'),
        );

        return $this->responder->page($request, $page['items'], $page['cursor']);
    }
}
