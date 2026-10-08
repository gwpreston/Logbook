<?php

declare(strict_types=1);

namespace Logbook\Action\Api;

use Logbook\Service\Api\ApiReports;
use Logbook\Support\Api\ApiResponder;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/reports/{costs|cost-per-distance|fuel|mileage} — the Reports
 * page's figures (spec.md §7.7, §7.20 *Phase 39*). The route names the
 * report in its `report` argument.
 */
final readonly class ReportAction
{
    public function __construct(
        private ApiReports $reports,
        private ApiResponder $responder,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = RequestContext::requireUser($request);

        return $this->responder->json(match ($args['report'] ?? '') {
            'costs' => $this->reports->costs($user, $request),
            'cost-per-distance' => $this->reports->costPerDistance($user, $request),
            'fuel' => $this->reports->fuel($user, $request),
            'mileage' => $this->reports->mileage($user, $request),
            default => throw new \LogicException('The route names no report.'),
        });
    }
}
