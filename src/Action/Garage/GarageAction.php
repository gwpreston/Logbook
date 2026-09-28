<?php

declare(strict_types=1);

namespace Logbook\Action\Garage;

use Logbook\Service\Reminder\DueCounter;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Service\Vehicle\VehicleSnapshots;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /garage — the owner's vehicles as cards with their due badge,
 * odometer and economy (spec.md §7.1). Archived vehicles are listed only
 * with `?archived=1`.
 */
final readonly class GarageAction
{
    public function __construct(
        private View $view,
        private VehicleService $vehicles,
        private VehicleSnapshots $snapshots,
        private DueCounter $counter,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = RequestContext::requireUser($request);
        $showArchived = ($request->getQueryParams()['archived'] ?? '') === '1';
        $vehicles = $this->vehicles->listFleet($user, $showArchived);

        return $this->view->render($request, $response, 'garage/index.twig', [
            'vehicles' => $this->snapshots->of($vehicles, $this->counter->counts($user)),
            'counts' => $this->vehicles->counts($user),
            'show_archived' => $showArchived,
        ]);
    }
}
