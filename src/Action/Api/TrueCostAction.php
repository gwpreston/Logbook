<?php

declare(strict_types=1);

namespace Logbook\Action\Api;

use Logbook\Service\Api\ApiTrueCost;
use Logbook\Service\Report\TrueCostRange;
use Logbook\Support\Api\ApiResponder;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/vehicles/{id}/true-cost?period=last_12_months|since_bought —
 * the vehicle's true cost per distance, its parts, each year and what
 * changed (spec.md §7.20, §7.35). Needs ViewCosts (403 without).
 */
final readonly class TrueCostAction
{
    public function __construct(
        private ApiTrueCost $trueCost,
        private ApiResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->responder->json($this->trueCost->show(
            RequestContext::requireUser($request),
            RequestContext::vehicle($request),
            TrueCostRange::chosen($request->getQueryParams()['period'] ?? null),
        ));
    }
}
