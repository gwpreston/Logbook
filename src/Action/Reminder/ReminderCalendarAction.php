<?php

declare(strict_types=1);

namespace Logbook\Action\Reminder;

use Logbook\Action\Forecast\ComingUpScope;
use Logbook\Service\Reminder\CalendarMonth;
use Logbook\Service\Reminder\ReminderService;
use Logbook\Service\Reminder\ReminderWording;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /reminders/calendar — the reminders the list shows, a month at a
 * time (spec.md §7.6 *Calendar view*). `?month=YYYY-MM` picks the month,
 * `?day=YYYY-MM-DD` opens a day in full, `?vehicle=` narrows it as the
 * dashboard's chips do and `?closed=0` hides done and dismissed ones.
 * Like the list it declares no vehicle ability: it shows only what the
 * reminder service lets this user see.
 */
final readonly class ReminderCalendarAction
{
    public function __construct(
        private ReminderService $reminders,
        private ReminderWording $wording,
        private VehicleService $vehicles,
        private View $view,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = RequestContext::requireUser($request);
        $query = $request->getQueryParams();
        $scope = ComingUpScope::of($this->vehicles->listFleet($user), $query);
        $overview = $this->reminders->overview($user)->forVehicle($scope->selected);
        $closed = ($query['closed'] ?? null) !== '0';
        $month = CalendarMonth::of(
            $overview,
            CalendarMonth::chosen($query['month'] ?? null, $query['day'] ?? null, $overview->today),
            $user->preferences->locale,
            $closed,
        );

        return $this->view->render($request, $response, 'reminders/calendar.twig', [
            'calendar' => $month,
            'day' => $month->day($query['day'] ?? null),
            'scope' => $scope,
            'vehicles' => $scope->active,
            'selected' => $scope->selected,
            // What every link on the page keeps: the vehicle and the closed choice.
            'keep' => $scope->query() + ($closed ? [] : ['closed' => '0']),
            'wording' => $this->wording,
            'has_vehicles' => $this->vehicles->counts($user)['active'] > 0,
        ]);
    }
}
