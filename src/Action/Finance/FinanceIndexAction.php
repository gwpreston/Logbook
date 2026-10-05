<?php

declare(strict_types=1);

namespace Logbook\Action\Finance;

use Logbook\Service\Finance\FinanceService;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /vehicles/{id}/finance — the vehicle's Finance tab (spec.md §7.32
 * *Finance tab*): the active agreement's page in the prototype's cards,
 * then the earlier agreements; with no active one, *How did you buy it?*
 * and *Add finance*.
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

        return $this->view->render($request, $response, 'finance/index.twig', [
            'vehicle' => $vehicle,
            'page' => $this->finance->page($user, $vehicle),
        ]);
    }
}
