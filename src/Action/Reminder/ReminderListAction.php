<?php

declare(strict_types=1);

namespace Logbook\Action\Reminder;

use Logbook\Domain\Reminder\ReminderStatus;
use Logbook\Service\Reminder\ReminderService;
use Logbook\Service\Reminder\ReminderWording;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /reminders — overdue, due and upcoming reminders of every active
 * vehicle, then the dismissed and done ones.
 */
final readonly class ReminderListAction
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
        $overview = $this->reminders->overview($user);

        return $this->view->render($request, $response, 'reminders/index.twig', [
            'overview' => $overview,
            'groups' => [
                'overdue' => $overview->withStatus(ReminderStatus::Overdue),
                'due' => $overview->withStatus(ReminderStatus::Due),
                'upcoming' => $overview->withStatus(ReminderStatus::Upcoming),
            ],
            'wording' => $this->wording,
            'has_vehicles' => $this->vehicles->counts($user)['active'] > 0,
        ]);
    }
}
