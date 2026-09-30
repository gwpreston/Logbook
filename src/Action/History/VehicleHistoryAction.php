<?php

declare(strict_types=1);

namespace Logbook\Action\History;

use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /vehicles/{id}/history — the History tab (spec.md §7.16): everything
 * logged against the vehicle, one year per page, newest first, bookended by
 * its milestones. Archived vehicles have theirs too.
 */
final readonly class VehicleHistoryAction
{
    public function __construct(
        private HistoryView $history,
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

        return $this->view->render($request, $response, 'history/vehicle.twig', [
            'vehicle' => $vehicle,
        ] + $this->history->context($user, [$vehicle], $request->getQueryParams()));
    }
}
