<?php

declare(strict_types=1);

namespace Logbook\Action\Maintenance;

use Logbook\Action\Incident\IncidentPicker;
use Logbook\Action\Issue\IssueFixPicker;
use Logbook\Action\Part\PartRoute;
use Logbook\Action\Part\CostInput;
use Logbook\Action\Part\RecordPartsPicker;
use Logbook\Service\Part\PartChangeRefused;
use Logbook\Service\Part\PartService;
use Logbook\Service\Part\RecordPartsForm;
use Logbook\Support\Display\DisplayFormatter;
use Logbook\Domain\Incident\LinkKind;
use Logbook\Action\Ask\DraftPrefill;
use Logbook\Action\Scan\ScanPrefill;
use Logbook\Domain\Ai\Scan\ScanTarget;
use Logbook\Service\Attachment\PendingUploads;
use Logbook\Domain\Ai\Draft\DraftKind;
use Logbook\Action\Attachment\AttachmentUpload;
use Logbook\Action\Odometer\OdometerWarningFlash;
use Logbook\Domain\Maintenance\MaintenanceEntry;
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
        private DraftPrefill $prefill,
        private ScanPrefill $scan,
        private VehicleService $vehicles,
        private MaintenanceService $maintenance,
        private ScheduleService $schedules,
        private MaintenanceFormPage $page,
        private AttachmentUpload $upload,
        private OdometerWarningFlash $warnings,
        private Redirector $redirect,
        private ClockInterface $clock,
        private IncidentPicker $incidents,
        private IssueFixPicker $fixes,
        private RecordPartsPicker $parts,
        private CostInput $costs,
        private DisplayFormatter $formatter,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $vehicle = RequestContext::vehicle($request);
        $user = RequestContext::requireUser($request);
        $currency = $this->vehicles->currencyFor($user, $vehicle);

        if ($request->getMethod() !== 'POST') {
            $today = LocalTime::today($this->clock, $user->preferences->timeZone());
            $defaults = MaintenanceEntryForm::defaults($today, $this->requestedSchedule($request, $vehicle));
            $defaults = $this->prefill->values($request, DraftKind::Maintenance, $vehicle->id, $defaults);
            $defaults = $this->scan->values($request, ScanTarget::Maintenance, $vehicle, $defaults);
            $defaults = $this->incidents->prefill($request, $vehicle, $defaults);
            // *Log the repair* on an issue (spec.md §7.37): its category and title, the issue ticked.
            $defaults = $this->fixes->prefill($request, $vehicle, $defaults);
            $ticked = $this->fixes->requestedTicks($request, $vehicle);

            return $this->page->render($request, $response, $vehicle, $currency, $defaults, ticked: $ticked);
        }

        // A service record's own cost follows the own-entry rule; only the parts' prices are guarded.
        $input = $this->costs->guard($request, $vehicle, RequestContext::form($request), withCost: false);
        $data = MaintenanceEntryForm::parse($input, $user->preferences, $this->page->scheduleIds($vehicle));
        $files = $this->scan->files($request, $this->upload->fromRequest($request));
        $errors = $this->upload->errors($data, $files);
        $today = LocalTime::today($this->clock, $user->preferences->timeZone());
        // *Add another* part row (spec.md §7.39): the form again, one row more, nothing saved.
        if (RecordPartsForm::more($input) !== null) {
            $ticked = $this->fixes->posted($input);

            return $this->page->render(
                $request,
                $response,
                $vehicle,
                $currency,
                RequestContext::formValues($request),
                null,
                null,
                200,
                $ticked,
            );
        }
        $parts = $this->parts->read($vehicle, PartService::MAINTENANCE, null, $input, $user->preferences, $today);
        if ($parts instanceof ValidationErrors) {
            $errors = ($errors ?? ($data instanceof ValidationErrors ? $data : new ValidationErrors()))->merge($parts);
        }
        if ($errors !== null || $data instanceof ValidationErrors || $parts instanceof ValidationErrors) {
            $values = RequestContext::formValues($request);

            $ticked = $this->fixes->posted($input);

            return $this->page->render(
                $request,
                $response,
                $vehicle,
                $currency,
                $values,
                null,
                $errors ?? ($data instanceof ValidationErrors ? $data : null),
                422,
                $ticked,
            );
        }

        $fixes = $this->fixes->toSave($vehicle, null, $input);
        try {
            [$entry, $claimed] = $this->scan->save(
                $request,
                $files,
                fn (PendingUploads $files): MaintenanceEntry => $this->maintenance->create(
                    $vehicle,
                    $data,
                    $user->preferences->timeZone(),
                    $files,
                    $fixes,
                    $parts,
                ),
            );
        } catch (PartChangeRefused $refused) {
            $values = RequestContext::formValues($request);
            $errors = PartRoute::refusal($refused, $this->formatter);

            return $this->page->render(
                $request,
                $response,
                $vehicle,
                $currency,
                $values,
                null,
                $errors,
                422,
                $this->fixes->posted($input),
            );
        }
        $this->incidents->save($vehicle, LinkKind::Maintenance, $entry->id, $input);
        $this->prefill->saved($request);
        $session = RequestContext::session($request);
        $session->flash('success', 'maintenance.created', ['title' => $entry->data->title]);
        $this->warnings->queue($session, $this->maintenance->odometerWarning($vehicle, $entry));

        $done = $this->redirect->backOr($request, 'maintenance.index', ['id' => (string) $vehicle->id]);

        return $this->scan->after($request, $claimed, $vehicle, $entry->data->odometerKm, $done, $entry->data->performedOn);
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
