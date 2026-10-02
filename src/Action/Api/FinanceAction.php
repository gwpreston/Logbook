<?php

declare(strict_types=1);

namespace Logbook\Action\Api;

use Logbook\Service\Finance\FinanceService;
use Logbook\Support\Api\ApiResponder;
use Logbook\Support\Api\Serializer;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * GET /api/v1/vehicles/{id}/finance — the active agreement's summary and
 * schedule, else the latest ended one's (spec.md §7.20 *Finance*, §7.32).
 * Read only; never the agreement number. Without `Manage` and `ViewCosts`
 * it answers 404, as the pages do; `agreement` is null when the vehicle
 * has none.
 */
final readonly class FinanceAction
{
    public function __construct(
        private FinanceService $finance,
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
        $view = $this->finance->latestView($user, $vehicle);

        return $this->responder->json([
            'agreement' => $view === null ? null : Serializer::financeAgreement($view),
        ]);
    }
}
