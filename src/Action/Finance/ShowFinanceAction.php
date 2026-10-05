<?php

declare(strict_types=1);

namespace Logbook\Action\Finance;

use Logbook\Service\Finance\FinanceService;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /vehicles/{id}/finance/{agreement} — the agreement page (spec.md
 * §7.32 *Agreement page*, *Finance tab*): the Finance tab's cards for this
 * agreement, then the schedule with *Mark missed* and *Mark paid late*,
 * extra payments, settlement quotes, the consistency check and the overlap
 * warning, and the other earlier agreements. Printable.
 */
final readonly class ShowFinanceAction
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
        $agreement = FinanceRoute::agreement($this->finance, $user, $vehicle, $request, $args);

        return $this->view->render($request, $response, 'finance/index.twig', [
            'vehicle' => $vehicle,
            'page' => $this->finance->page($user, $vehicle, $agreement),
        ]);
    }
}
