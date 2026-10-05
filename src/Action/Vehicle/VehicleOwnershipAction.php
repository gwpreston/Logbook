<?php

declare(strict_types=1);

namespace Logbook\Action\Vehicle;

use Logbook\Service\Report\OwnershipOverviewService;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /vehicles/{id}/ownership — the vehicle's *Cost of ownership* tab
 * (spec.md §7.1, Phase 33.4, #191): the overview card's figures as tiles,
 * a row per part and how they are worked out. The route needs ViewCosts.
 */
final readonly class VehicleOwnershipAction
{
    public function __construct(
        private OwnershipOverviewService $ownership,
        private View $view,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $vehicle = RequestContext::vehicle($request);
        $user = RequestContext::requireUser($request);
        $today = LocalTime::today($this->clock, $user->preferences->timeZone());

        return $this->view->render($request, $response, 'vehicles/ownership.twig', [
            'vehicle' => $vehicle,
            'card' => $this->ownership->forVehicle($user, $vehicle, $today),
        ]);
    }
}
