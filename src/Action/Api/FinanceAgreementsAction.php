<?php

declare(strict_types=1);

namespace Logbook\Action\Api;

use Logbook\Domain\Finance\FinanceAgreement;
use Logbook\Service\Api\ApiFinanceWrites;
use Logbook\Service\Finance\FinanceService;
use Logbook\Support\Api\ApiResponder;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * GET /api/v1/vehicles/{id}/finance/agreements — every agreement, the
 * active one first, then ended ones newest first, each as
 * `GET …/finance` shows one, with its payment events and settlement
 * quotes (spec.md §7.20 *Phase 39*, §7.32). Never the agreement number;
 * 404 without §7.32's access, as the pages.
 */
final readonly class FinanceAgreementsAction
{
    public function __construct(
        private FinanceService $finance,
        private ApiFinanceWrites $writes,
        private ApiResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = RequestContext::requireUser($request);
        $vehicle = RequestContext::vehicle($request);
        if (!$this->finance->canSee($user, $vehicle)) {
            throw new HttpNotFoundException($request);
        }

        return $this->responder->json(['items' => array_map(
            fn (FinanceAgreement $agreement): array => $this->writes->read($user, $vehicle, $agreement),
            $this->finance->forVehicle($user, $vehicle),
        )]);
    }
}
