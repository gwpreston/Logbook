<?php

declare(strict_types=1);

namespace Logbook\Action\Odometer;

use Logbook\Action\EntryGuard;
use Logbook\Action\Attachment\AttachmentUpload;
use Logbook\Domain\Odometer\OdometerReading;
use Logbook\Domain\Odometer\OdometerSource;
use Logbook\Repository\PartRepository;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Odometer\OdometerReadingForm;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Validation\ValidationErrors;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * GET|POST /vehicles/{id}/odometer/{reading}/edit — edit a manual reading.
 * A reading that belongs to a fill-up or maintenance entry is changed by
 * editing that entry (or incident); the mileage when bought, on the vehicle
 * form.
 */
final readonly class EditOdometerReadingAction
{
    public function __construct(
        private OdometerService $odometer,
        private OdometerFormPage $page,
        private AttachmentUpload $upload,
        private OdometerWarningFlash $warnings,
        private Redirector $redirect,
        private EntryGuard $guard,
        private PartRepository $parts,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $vehicle = RequestContext::vehicle($request);
        $reading = OdometerRoute::reading($this->odometer, $vehicle, $request, $args);
        $this->guard->allowChange($request, $vehicle, $reading->createdBy);
        if (!$reading->isManual()) {
            return $this->ownerOf($request, $vehicle, $reading);
        }
        $preferences = RequestContext::requireUser($request)->preferences;

        if ($request->getMethod() !== 'POST') {
            $values = OdometerReadingForm::values($reading, $preferences);

            return $this->page->render($request, $response, $vehicle, $values, $reading);
        }

        $data = OdometerReadingForm::parse(RequestContext::form($request), $preferences, $reading->readingKm);
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

    private function toPart(ServerRequestInterface $request, Vehicle $vehicle, OdometerReading $reading): ResponseInterface
    {
        $part = $this->parts->partOfFitting($vehicle->id, (int) ($reading->partFittedId ?? $reading->partRemovedId))
            ?? throw new HttpNotFoundException($request);

        return $this->redirect->toRoute('parts.show', ['id' => (string) $vehicle->id, 'part' => (string) $part]);
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
            $reading->tyreChangeId !== null => $this->redirect->toRoute('tyres.changes.edit', [
                'id' => (string) $vehicle->id,
                'change' => (string) $reading->tyreChangeId,
            ]),
            $reading->incidentId !== null => $this->redirect->toRoute('incidents.edit', [
                'id' => (string) $vehicle->id,
                'incident' => (string) $reading->incidentId,
            ]),
            $reading->issueId !== null => $this->redirect->toRoute('issues.edit', [
                'id' => (string) $vehicle->id,
                'issue' => (string) $reading->issueId,
            ]),
            $reading->issueUpdateId !== null => $this->redirect->toRoute('issues.updates.edit', [
                'id' => (string) $vehicle->id,
                'update' => (string) $reading->issueUpdateId,
            ]),
            // An MOT test's reading changes only by refreshing (spec.md §7.38): its page says so.
            $reading->motTestId !== null => $this->redirect->toRoute('mot_history.show', [
                'id' => (string) $vehicle->id,
            ]),
            // Phase 50.1 (spec.md §7.39): a modification's form, its revert's page, a fitting's part.
            $reading->modificationId !== null => $this->redirect->toRoute('modifications.edit', [
                'id' => (string) $vehicle->id,
                'modification' => (string) $reading->modificationId,
            ]),
            $reading->modificationRevertId !== null => $this->redirect->toRoute('modifications.show', [
                'id' => (string) $vehicle->id,
                'modification' => (string) $reading->modificationRevertId,
            ]),
            ($reading->partFittedId ?? $reading->partRemovedId) !== null => $this->toPart($request, $vehicle, $reading),
            // Mileage when bought is set on the vehicle form (spec.md §6 OdometerReading).
            $reading->source === OdometerSource::Purchase => $this->redirect->toRoute('vehicles.edit', [
                'id' => (string) $vehicle->id,
            ]),
            default => throw new HttpNotFoundException($request),
        };
    }
}
