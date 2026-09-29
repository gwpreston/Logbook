<?php

declare(strict_types=1);

namespace Logbook\Action\Odometer;

use Logbook\Action\Attachment\AttachmentUpload;
use Logbook\Action\Vehicle\VehicleRoute;
use Logbook\Domain\Odometer\OdometerReading;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Odometer\OdometerReadingForm;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Validation\ValidationErrors;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * GET|POST /vehicles/{id}/odometer/{reading}/edit — edit a manual reading.
 * A reading that belongs to a fill-up or maintenance entry is changed by
 * editing that entry.
 */
final readonly class EditOdometerReadingAction
{
    public function __construct(
        private VehicleService $vehicles,
        private OdometerService $odometer,
        private OdometerFormPage $page,
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
        $reading = OdometerRoute::reading($this->odometer, $vehicle, $request, $args);
        if (!$reading->isManual()) {
            return $this->ownerOf($request, $vehicle, $reading);
        }
        $preferences = RequestContext::requireUser($request)->preferences;

        if ($request->getMethod() !== 'POST') {
            $values = OdometerReadingForm::values($reading, $preferences);

            return $this->page->render($request, $response, $vehicle, $values, $reading);
        }

        $data = OdometerReadingForm::parse(RequestContext::form($request), $preferences);
        $files = $this->upload->fromRequest($request);
        $errors = $this->upload->errors($data, $files);
        if ($errors !== null || $data instanceof ValidationErrors) {
            $values = RequestContext::formValues($request);

            return $this->page->render($request, $response, $vehicle, $values, $reading, $errors, 422);
        }

        $this->odometer->update($vehicle, $reading, $data, $files);
        $session = RequestContext::session($request);
        $session->flash('success', 'odometer.updated');
        $this->warnings->queue($session, $this->odometer->warningFor($vehicle, $reading->id));

        return $this->redirect->backOr($request, 'odometer.index', ['id' => (string) $vehicle->id]);
    }

    /**
     * Send the user to the entry that owns a derived reading.
     */
    private function ownerOf(ServerRequestInterface $request, Vehicle $vehicle, OdometerReading $reading): ResponseInterface
    {
        return match (true) {
            $reading->fuelEntryId !== null => $this->redirect->toRoute('fuel.edit', [
                'id' => (string) $vehicle->id,
                'entry' => (string) $reading->fuelEntryId,
            ]),
            $reading->maintenanceEntryId !== null => $this->redirect->toRoute('maintenance.edit', [
                'id' => (string) $vehicle->id,
                'entry' => (string) $reading->maintenanceEntryId,
            ]),
            $reading->complianceDocumentId !== null => $this->redirect->toRoute('compliance.edit', [
                'id' => (string) $vehicle->id,
                'document' => (string) $reading->complianceDocumentId,
            ]),
            default => throw new HttpNotFoundException($request),
        };
    }
}
