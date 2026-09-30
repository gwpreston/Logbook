<?php

declare(strict_types=1);

namespace Logbook\Action\Maintenance;

use Logbook\Service\Maintenance\MaintenanceScheduleForm;
use Logbook\Service\Maintenance\ScheduleService;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Validation\ValidationErrors;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET|POST /vehicles/{id}/maintenance/schedules/{schedule}/edit — change a
 * schedule's intervals or baseline; its next-due point is recomputed.
 */
final readonly class EditScheduleAction
{
    public function __construct(
        private ScheduleService $schedules,
        private ScheduleFormPage $page,
        private Redirector $redirect,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $vehicle = RequestContext::vehicle($request);
        $schedule = MaintenanceRoute::schedule($this->schedules, $vehicle, $request, $args);
        $preferences = RequestContext::requireUser($request)->preferences;

        if ($request->getMethod() !== 'POST') {
            $values = MaintenanceScheduleForm::values($schedule, $preferences);

            return $this->page->render($request, $response, $vehicle, $values, $schedule);
        }

        $data = MaintenanceScheduleForm::parse(RequestContext::form($request), $preferences);
        if ($data instanceof ValidationErrors) {
            $values = RequestContext::formValues($request);

            return $this->page->render($request, $response, $vehicle, $values, $schedule, $data, 422);
        }

        $updated = $this->schedules->update($vehicle, $schedule, $data);
        RequestContext::session($request)->flash('success', 'maintenance.schedule.updated', ['title' => $updated->data->title]);

        return $this->redirect->toRoute('maintenance.index', ['id' => (string) $vehicle->id]);
    }
}
