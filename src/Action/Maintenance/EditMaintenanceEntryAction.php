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
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Display\DisplayFormatter;
use Psr\Clock\ClockInterface;
use Logbook\Domain\Incident\LinkKind;
use Logbook\Action\EntryGuard;
use Logbook\Action\Attachment\AttachmentUpload;
use Logbook\Action\Odometer\OdometerWarningFlash;
use Logbook\Action\Tyre\TyreFormPage;
use Logbook\Service\Maintenance\MaintenanceEntryForm;
use Logbook\Service\Maintenance\MaintenanceService;
use Logbook\Service\Tyre\TyreChangeRefused;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Validation\ValidationErrors;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET|POST /vehicles/{id}/maintenance/{entry}/edit — edit an entry; its
 * odometer reading and the schedule(s) it completes follow. A file chosen
 * here is added to its attachments.
 */
final readonly class EditMaintenanceEntryAction
{
    public function __construct(
        private VehicleService $vehicles,
        private MaintenanceService $maintenance,
        private MaintenanceFormPage $page,
        private AttachmentUpload $upload,
        private OdometerWarningFlash $warnings,
        private Redirector $redirect,
        private TyreFormPage $tyreErrors,
        private EntryGuard $guard,
        private IncidentPicker $incidents,
        private IssueFixPicker $fixes,
        private RecordPartsPicker $parts,
        private CostInput $costs,
        private DisplayFormatter $formatter,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $vehicle = RequestContext::vehicle($request);
        $entry = MaintenanceRoute::entry($this->maintenance, $vehicle, $request, $args);
        $this->guard->allowChange($request, $vehicle, $entry->createdBy);
        $user = RequestContext::requireUser($request);
        $currency = $this->vehicles->currencyFor($user, $vehicle);

        if ($request->getMethod() !== 'POST') {
            $values = $this->incidents->current($entry->incidentId, MaintenanceEntryForm::values($entry, $user->preferences))
                + $this->parts->values($vehicle, PartService::MAINTENANCE, $entry->id);

            return $this->page->render($request, $response, $vehicle, $currency, $values, $entry);
        }

        // A service record's own cost follows the own-entry rule; only the parts' prices are guarded.
        $input = $this->costs->guard(
            $request,
            $vehicle,
            RequestContext::form($request),
            $this->parts->values($vehicle, PartService::MAINTENANCE, $entry->id),
            withCost: false,
        );
        $data = MaintenanceEntryForm::parse(
            $input,
            $user->preferences,
            $this->page->scheduleIds($vehicle),
            $entry->data->odometerKm,
        );
        $files = $this->upload->fromRequest($request);
        $errors = $this->upload->errors($data, $files);
        $zone = $user->preferences->timeZone();
        // *Add another* part row (spec.md §7.39): the form again, one row more, nothing saved.
        if (RecordPartsForm::more($input) !== null) {
            $values = RequestContext::formValues($request);

            return $this->page->render(
                $request,
                $response,
                $vehicle,
                $currency,
                $values,
                $entry,
                null,
                200,
                $this->fixes->posted($input),
            );
        }
        $today = LocalTime::today($this->clock, $zone);
        $parts = $this->parts->read($vehicle, PartService::MAINTENANCE, $entry->id, $input, $user->preferences, $today);
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
                $entry,
                $errors ?? ($data instanceof ValidationErrors ? $data : null),
                422,
                $ticked,
            );
        }

        try {
            $fixes = $this->fixes->toSave($vehicle, $entry, $input);
            $updated = $this->maintenance->update($vehicle, $entry, $data, $zone, $files, $fixes, $parts);
        } catch (PartChangeRefused $refused) {
            $values = RequestContext::formValues($request);
            $errors = PartRoute::refusal($refused, $this->formatter);

            return $this->page->render(
                $request,
                $response,
                $vehicle,
                $currency,
                $values,
                $entry,
                $errors,
                422,
                $this->fixes->posted($input),
            );
        } catch (TyreChangeRefused $refused) {
            // A linked tyre change cannot move to the new date or odometer (spec.md §7.17).
            $values = RequestContext::formValues($request);
            $errors = $this->tyreErrors->errors($refused);

            $ticked = $this->fixes->posted($input);

            return $this->page->render($request, $response, $vehicle, $currency, $values, $entry, $errors, 422, $ticked);
        }
        $this->incidents->save($vehicle, LinkKind::Maintenance, $entry->id, $input);
        $session = RequestContext::session($request);
        $session->flash('success', 'maintenance.updated', ['title' => $updated->data->title]);
        $this->warnings->queue($session, $this->maintenance->odometerWarning($vehicle, $updated));

        return $this->redirect->backOr($request, 'maintenance.index', ['id' => (string) $vehicle->id]);
    }
}
