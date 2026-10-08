<?php

declare(strict_types=1);

namespace Logbook\Action\Compliance;

use Logbook\Action\EntryGuard;
use Logbook\Action\Attachment\AttachmentUpload;
use Logbook\Action\Odometer\OdometerWarningFlash;
use Logbook\Service\Compliance\ComplianceDocumentForm;
use Logbook\Service\Compliance\ComplianceService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Validation\ValidationErrors;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET|POST /vehicles/{id}/documents/{document}/edit — edit a compliance
 * document in place (same id, attachments kept). Guarded by a regression
 * test for the known "can't edit a compliance entry" bug: the form posts
 * back to this URL with every field, and the update targets this row.
 */
final readonly class EditComplianceDocumentAction
{
    public function __construct(
        private VehicleService $vehicles,
        private ComplianceService $compliance,
        private ComplianceFormPage $page,
        private AttachmentUpload $upload,
        private OdometerWarningFlash $warnings,
        private Redirector $redirect,
        private EntryGuard $guard,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $vehicle = RequestContext::vehicle($request);
        $document = ComplianceRoute::document($this->compliance, $vehicle, $request, $args);
        $this->guard->allowChange($request, $vehicle, $document->createdBy);
        $user = RequestContext::requireUser($request);
        $currency = $this->vehicles->currencyFor($user, $vehicle);

        if ($request->getMethod() !== 'POST') {
            $values = ComplianceDocumentForm::values($document, $user->preferences);

            return $this->page->render($request, $response, $vehicle, $currency, $values, $document);
        }

        $data = ComplianceDocumentForm::parse(RequestContext::form($request), $user->preferences, $document->data->odometerKm);
        $files = $this->upload->fromRequest($request);
        $errors = $this->upload->errors($data, $files);
        if ($errors !== null || $data instanceof ValidationErrors) {
            $values = RequestContext::formValues($request);

            return $this->page->render($request, $response, $vehicle, $currency, $values, $document, $errors, 422);
        }

        $updated = $this->compliance->update($vehicle, $document, $data, $user->preferences->timeZone(), $files);
        $session = RequestContext::session($request);
        $session->flash('success', 'compliance.updated');
        $this->warnings->queue($session, $this->compliance->odometerWarning($vehicle, $updated));

        return $this->redirect->backOr($request, 'compliance.index', ['id' => (string) $vehicle->id]);
    }
}
