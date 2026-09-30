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
 * GET|POST /vehicles/{id}/maintenance/schedules/new — add a recurring job
 * ("every 10,000 km or 12 months").
 */
final readonly class CreateScheduleAction
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

        if ($request->getMethod() !== 'POST') {
            return $this->page->render($request, $response, $vehicle, MaintenanceScheduleForm::defaults());
        }

        $preferences = RequestContext::requireUser($request)->preferences;
        $data = MaintenanceScheduleForm::parse(RequestContext::form($request), $preferences);
        if ($data instanceof ValidationErrors) {
            $values = RequestContext::formValues($request);

            return $this->page->render($request, $response, $vehicle, $values, null, $data, 422);
        }

        $schedule = $this->schedules->create($vehicle, $data);
        RequestContext::session($request)->flash('success', 'maintenance.schedule.created', ['title' => $schedule->data->title]);

        return $this->redirect->toRoute('maintenance.index', ['id' => (string) $vehicle->id]);
    }
}
