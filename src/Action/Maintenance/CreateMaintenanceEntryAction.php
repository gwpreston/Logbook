<?php

declare(strict_types=1);

namespace Logbook\Action\Maintenance;

use Logbook\Action\Attachment\AttachmentUpload;
use Logbook\Action\Odometer\OdometerWarningFlash;
use Logbook\Action\Vehicle\VehicleRoute;
use Logbook\Domain\Attachment\AttachmentOwner;
use Logbook\Domain\Maintenance\MaintenanceSchedule;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Maintenance\MaintenanceEntryForm;
use Logbook\Service\Maintenance\MaintenanceScheduleNotFound;
use Logbook\Service\Maintenance\MaintenanceService;
use Logbook\Service\Maintenance\ScheduleService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Validation\ValidationErrors;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET|POST /vehicles/{id}/maintenance/new — log maintenance work, optionally
 * with an invoice attached. `?schedule={id}` (the "Log it" button on a
 * schedule) pre-fills the form to complete that schedule.
 */
final readonly class CreateMaintenanceEntryAction
{
    public function __construct(
        private VehicleService $vehicles,
        private MaintenanceService $maintenance,
        private ScheduleService $schedules,
        private MaintenanceFormPage $page,
        private AttachmentUpload $upload,
        private OdometerWarningFlash $warnings,
        private Redirector $redirect,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $vehicle = VehicleRoute::vehicle($this->vehicles, $request, $args);
        $user = RequestContext::requireUser($request);
        $currency = $this->vehicles->currencyFor($user, $vehicle);

        if ($request->getMethod() !== 'POST') {
            $today = LocalTime::today($this->clock, $user->preferences->timeZone());
            $defaults = MaintenanceEntryForm::defaults($today, $this->requestedSchedule($request, $vehicle));

            return $this->page->render($request, $response, $vehicle, $currency, $defaults);
        }

        $input = RequestContext::form($request);
        $data = MaintenanceEntryForm::parse($input, $user->preferences, $this->page->scheduleIds($vehicle));
        $file = $this->upload->fromRequest($request);
        $errors = $this->upload->errors($data, $file);
        if ($errors !== null || $data instanceof ValidationErrors) {
            $values = RequestContext::formValues($request);

            return $this->page->render($request, $response, $vehicle, $currency, $values, null, $errors, 422);
        }

        $entry = $this->maintenance->create($vehicle, $data, $user->preferences->timeZone());
        $this->upload->store($vehicle, AttachmentOwner::Maintenance, $entry->id, $file);
        $session = RequestContext::session($request);
        $session->flash('success', 'maintenance.created', ['title' => $entry->data->title]);
        $this->warnings->queue($session, $this->maintenance->odometerWarning($vehicle, $entry));

        return $this->redirect->toRoute('maintenance.index', ['id' => (string) $vehicle->id]);
    }

    /**
     * The schedule named by ?schedule=, if it is one of this vehicle's.
     */
    private function requestedSchedule(ServerRequestInterface $request, Vehicle $vehicle): ?MaintenanceSchedule
    {
        $id = $request->getQueryParams()['schedule'] ?? null;
        if (!is_string($id) || !ctype_digit($id)) {
            return null;
        }

        try {
            return $this->schedules->get($vehicle, (int) $id);
        } catch (MaintenanceScheduleNotFound) {
            return null;
        }
    }
}
