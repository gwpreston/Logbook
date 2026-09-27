<?php

declare(strict_types=1);

namespace Logbook\Action\Garage;

use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /garage — the owner's vehicles; active only unless `?archived=1`.
 */
final readonly class GarageAction
{
    public function __construct(
        private View $view,
        private VehicleService $vehicles,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = RequestContext::requireUser($request);
        $showArchived = ($request->getQueryParams()['archived'] ?? '') === '1';

        return $this->view->render($request, $response, 'garage/index.twig', [
            'vehicles' => $this->vehicles->listFleet($user, $showArchived),
            'counts' => $this->vehicles->counts($user),
            'show_archived' => $showArchived,
        ]);
    }
}
