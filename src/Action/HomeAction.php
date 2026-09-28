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
 * order. `?customise=1` shows the move / hide controls (plain forms, so the
 * layout can be arranged without JS).
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
        $customise = ($request->getQueryParams()['customise'] ?? null) === '1';
        $dashboard = $this->dashboards->build($user);

        return $this->view->render($request, $response, 'home.twig', [
            'dashboard' => $dashboard,
            'customise' => $customise,
            'today' => LocalTime::today($this->clock, $user->preferences->timeZone()),
            'wording' => $this->wording,
            'efficiency_chart' => $this->charts->efficiency($dashboard->efficiency),
        ]);
    }
}
