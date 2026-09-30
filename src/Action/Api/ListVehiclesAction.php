<?php

declare(strict_types=1);

namespace Logbook\Action\Api;

use Logbook\Domain\Access\VehicleScope;
use Logbook\Service\Api\ApiReader;
use Logbook\Support\Api\ApiProblem;
use Logbook\Support\Api\ApiResponder;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/vehicles — the vehicles the key's user can see, active ones
 * unless `?status=archived|all` (spec.md §7.20). Not paged.
 */
final readonly class ListVehiclesAction
{
    public function __construct(
        private ApiReader $reader,
        private ApiResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $status = $request->getQueryParams()['status'] ?? 'active';
        $scope = is_string($status) ? VehicleScope::tryFrom($status) : null;
        if ($scope === null) {
            throw ApiProblem::invalidParameter('status', 'one of active, archived or all.');
        }

        return $this->responder->json([
            'items' => $this->reader->vehicles(RequestContext::requireUser($request), $scope),
        ]);
    }
}
