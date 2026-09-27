<?php

declare(strict_types=1);

namespace Logbook\Action\Maintenance;

use Logbook\Action\Attachment\AttachmentUpload;
use Logbook\Action\Odometer\OdometerWarningFlash;
use Logbook\Action\Vehicle\VehicleRoute;
use Logbook\Domain\Attachment\AttachmentOwner;
use Logbook\Service\Maintenance\MaintenanceEntryForm;
use Logbook\Service\Maintenance\MaintenanceService;
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
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $vehicle = VehicleRoute::vehicle($this->vehicles, $request, $args);
        $entry = MaintenanceRoute::entry($this->maintenance, $vehicle, $request, $args);
        $user = RequestContext::requireUser($request);
        $currency = $this->vehicles->currencyFor($user, $vehicle);

        if ($request->getMethod() !== 'POST') {
            $values = MaintenanceEntryForm::values($entry, $user->preferences);

            return $this->page->render($request, $response, $vehicle, $currency, $values, $entry);
        }

        $input = RequestContext::form($request);
        $data = MaintenanceEntryForm::parse($input, $user->preferences, $this->page->scheduleIds($vehicle));
        $file = $this->upload->fromRequest($request);
        $errors = $this->upload->errors($data, $file);
        if ($errors !== null || $data instanceof ValidationErrors) {
            $values = RequestContext::formValues($request);

            return $this->page->render($request, $response, $vehicle, $currency, $values, $entry, $errors, 422);
        }

        $updated = $this->maintenance->update($vehicle, $entry, $data, $user->preferences->timeZone());
        $this->upload->store($vehicle, AttachmentOwner::Maintenance, $entry->id, $file);
        $session = RequestContext::session($request);
        $session->flash('success', 'maintenance.updated', ['title' => $updated->data->title]);
        $this->warnings->queue($session, $this->maintenance->odometerWarning($vehicle, $updated));

        return $this->redirect->toRoute('maintenance.index', ['id' => (string) $vehicle->id]);
    }
}
