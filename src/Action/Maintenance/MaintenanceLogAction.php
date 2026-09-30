<?php

declare(strict_types=1);

namespace Logbook\Action\Maintenance;

use Logbook\Domain\Maintenance\MaintenanceCategory;
use Logbook\Service\Attachment\AttachmentService;
use Logbook\Service\Maintenance\MaintenanceService;
use Logbook\Service\Maintenance\ScheduleService;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Service\Reminder\ReminderSettingsStore;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\Pagination;
use Logbook\Support\View\View;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /vehicles/{id}/maintenance — the maintenance tab: recurring schedules
 * with where each one is due, and the service history newest first,
 * filterable by category (`?category=tyres`).
 */
final readonly class MaintenanceLogAction
{
    public function __construct(
        private VehicleService $vehicles,
        private MaintenanceService $maintenance,
        private ScheduleService $schedules,
        private OdometerService $odometer,
        private ReminderSettingsStore $reminderSettings,
        private AttachmentService $attachments,
        private View $view,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $vehicle = RequestContext::vehicle($request);
        $user = RequestContext::requireUser($request);
        $today = LocalTime::today($this->clock, $user->preferences->timeZone());
        $history = $this->maintenance->history($vehicle);
        // The owner's lead times, as the vehicle's reminders use (Phase 19).
        $lead = $this->reminderSettings->reminderPreferences($vehicle->userId);

        $query = $request->getQueryParams();
        $category = is_string($query['category'] ?? null) ? MaintenanceCategory::tryFrom($query['category']) : null;
        $rows = $history->newestFirst($category);
        $pagination = Pagination::fromQuery($query, count($rows));

        return $this->view->render($request, $response, 'maintenance/index.twig', [
            'vehicle' => $vehicle,
            'currency' => $this->vehicles->currencyFor($user, $vehicle),
            'history' => $history,
            'category' => $category,
            'rows' => $pagination->slice($rows),
            'pagination' => $pagination,
            'schedules' => $this->schedules->states(
                $vehicle,
                $today,
                $this->odometer->history($vehicle),
                $lead->scheduleDays,
                $lead->scheduleKm,
            ),
            'attachment_counts' => $this->attachments->counts($vehicle),
        ]);
    }
}
