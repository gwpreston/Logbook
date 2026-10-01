<?php

declare(strict_types=1);

namespace Logbook\Action\Api;

use Logbook\Service\Api\ApiIncidents;
use Logbook\Service\Incident\ClaimsFilter;
use Logbook\Support\Api\ApiResponder;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/incidents/history (spec.md §7.20, §7.29): the claims
 * history's rows, archived and sold vehicles included, never the other
 * party. Query: `years` (3, 5 or 10), or `from` and `to`; `vehicle_id`,
 * `driver`, `claims_only=true`, `fault`.
 */
final readonly class IncidentHistoryAction
{
    public function __construct(
        private ApiIncidents $incidents,
        private ApiResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $query = $request->getQueryParams();
        $range = isset($query['from']) || isset($query['to']);
        $filter = ClaimsFilter::fromQuery([
            'years' => $query['years'] ?? '',
            'period' => $range ? 'range' : '',
            'from' => $query['from'] ?? '',
            'to' => $query['to'] ?? '',
            'vehicle' => $query['vehicle_id'] ?? '',
            'driver' => $query['driver'] ?? '',
            'claims' => ($query['claims_only'] ?? '') === 'true' ? '1' : '',
            'fault' => $query['fault'] ?? '',
        ]);

        return $this->responder->json(['items' => $this->incidents->history(RequestContext::requireUser($request), $filter)]);
    }
}
