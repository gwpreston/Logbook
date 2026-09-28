<?php

declare(strict_types=1);

namespace Logbook\Action;

use Logbook\Action\Dashboard\DashboardCharts;
use Logbook\Service\Dashboard\DashboardService;
use Logbook\Service\Reminder\ReminderWording;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET / — the dashboard (spec.md §7.8): the owner's widgets in their saved
 * order. `?vehicle={id}` narrows every widget to one active vehicle and pins
 * its card (an unknown or archived id shows the fleet). `?customise=1`
 * shows the move / hide controls (plain forms, so the layout can be
 * arranged without JS), always over the whole fleet.
 */
final readonly class HomeAction
{
    public function __construct(
        private View $view,
        private DashboardService $dashboards,
        private DashboardCharts $charts,
        private ReminderWording $wording,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = RequestContext::requireUser($request);
        $query = $request->getQueryParams();
        $customise = ($query['customise'] ?? null) === '1';
        $vehicle = $query['vehicle'] ?? null;
        $vehicleId = !$customise && is_string($vehicle) && ctype_digit($vehicle) ? (int) $vehicle : null;
        $dashboard = $this->dashboards->build($user, $vehicleId);

        return $this->view->render($request, $response, 'home.twig', [
            'dashboard' => $dashboard,
            'customise' => $customise,
            'today' => LocalTime::today($this->clock, $user->preferences->timeZone()),
            'wording' => $this->wording,
            'efficiency_chart' => $this->charts->efficiency($dashboard->efficiency),
            'mileage_chart' => $this->charts->mileage($dashboard->mileage),
        ]);
    }
}
