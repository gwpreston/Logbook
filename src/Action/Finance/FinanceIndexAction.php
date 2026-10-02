<?php

declare(strict_types=1);

namespace Logbook\Action\Finance;

use Logbook\Service\Finance\FinanceService;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /vehicles/{id}/finance — the vehicle's agreements (spec.md §7.32
 * *Finance page*): the active one first, then earlier ones, and *Add
 * finance* while none is active.
 */
final readonly class FinanceIndexAction
{
    public function __construct(
        private FinanceService $finance,
        private View $view,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $vehicle = RequestContext::vehicle($request);
        $user = RequestContext::requireUser($request);
        FinanceRoute::guard($this->finance, $user, $vehicle, $request);
        $agreements = $this->finance->forVehicle($user, $vehicle);
        $views = array_map(fn ($agreement) => $this->finance->view($user, $vehicle, $agreement), $agreements);

        return $this->view->render($request, $response, 'finance/index.twig', [
            'vehicle' => $vehicle,
            'agreements' => $views,
            'can_add' => !$vehicle->isArchived() && $this->finance->active($vehicle) === null,
        ]);
    }
}
