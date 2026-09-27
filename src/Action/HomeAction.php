<?php

declare(strict_types=1);

namespace Logbook\Action;

use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET / — the dashboard. Phase 1 shows the garage at a glance; the widget
 * dashboard arrives in Phase 5.
 */
final readonly class HomeAction
{
    public function __construct(
        private View $view,
        private VehicleService $vehicles,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = RequestContext::requireUser($request);

        return $this->view->render($request, $response, 'home.twig', [
            'vehicles' => $this->vehicles->listFleet($user),
            'counts' => $this->vehicles->counts($user),
            'today' => LocalTime::today($this->clock, $user->preferences->timeZone()),
        ]);
    }
}
