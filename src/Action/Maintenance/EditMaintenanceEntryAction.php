<?php

declare(strict_types=1);

namespace Logbook\Action\Maintenance;

use Logbook\Action\Incident\IncidentPicker;
use Logbook\Action\Issue\IssueFixPicker;
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
            $values = $this->incidents->current($entry->incidentId, MaintenanceEntryForm::values($entry, $user->preferences));

            return $this->page->render($request, $response, $vehicle, $currency, $values, $entry);
        }

        $input = RequestContext::form($request);
        $data = MaintenanceEntryForm::parse(
            $input,
            $user->preferences,
            $this->page->scheduleIds($vehicle),
            $entry->data->odometerKm,
        );
        $files = $this->upload->fromRequest($request);
        $errors = $this->upload->errors($data, $files);
        if ($errors !== null || $data instanceof ValidationErrors) {
            $values = RequestContext::formValues($request);

            $ticked = $this->fixes->posted($input);

            return $this->page->render($request, $response, $vehicle, $currency, $values, $entry, $errors, 422, $ticked);
        }

        try {
            $fixes = $this->fixes->toSave($vehicle, $entry, $input);
            $updated = $this->maintenance->update($vehicle, $entry, $data, $user->preferences->timeZone(), $files, $fixes);
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
