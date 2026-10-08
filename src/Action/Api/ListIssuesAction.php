<?php

declare(strict_types=1);

namespace Logbook\Action\Api;

use Logbook\Domain\Issue\IssueStatus;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Middleware\VehicleAccessMiddleware;
use Logbook\Service\Api\ApiIssues;
use Logbook\Service\Api\ApiReader;
use Logbook\Support\Api\ApiProblem;
use Logbook\Support\Api\ApiResponder;
use Logbook\Support\Api\ListQuery;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/vehicles/{id}/issues and GET /api/v1/issues (spec.md §7.20
 * *Issues*): newest noticed first, paged, `?status=` to filter; the fleet
 * list takes every visible active vehicle, or `?vehicle=`.
 */
final readonly class ListIssuesAction
{
    public function __construct(
        private ApiIssues $issues,
        private ApiReader $reader,
        private ApiResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $status = self::status($request);
        $query = ListQuery::fromRequest($request);
        $vehicle = $request->getAttribute(VehicleAccessMiddleware::ATTRIBUTE);
        if ($vehicle instanceof Vehicle) {
            $page = $this->issues->list($vehicle, $query, $status);
        } else {
            $user = RequestContext::requireUser($request);
            $one = VehicleFilter::fromRequest($request, $this->reader, $user);
            $page = $this->issues->fleet($one === null ? $this->reader->activeVehicles($user) : [$one], $query, $status);
        }

        return $this->responder->page($request, $page['items'], $page['cursor']);
    }

    private static function status(ServerRequestInterface $request): ?IssueStatus
    {
        $raw = $request->getQueryParams()['status'] ?? null;
        if ($raw === null) {
            return null;
        }

        return (is_string($raw) ? IssueStatus::tryFrom($raw) : null)
            ?? throw ApiProblem::invalidParameter('status', 'open, watching or fixed.');
    }
}
