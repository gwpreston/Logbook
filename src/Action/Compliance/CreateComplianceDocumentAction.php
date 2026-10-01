<?php

declare(strict_types=1);

namespace Logbook\Action\Compliance;

use Logbook\Action\Ask\DraftPrefill;
use Logbook\Action\Scan\ScanPrefill;
use Logbook\Domain\Ai\Scan\ScanTarget;
use Logbook\Domain\Compliance\ComplianceDocument;
use Logbook\Service\Attachment\PendingUploads;
use Logbook\Domain\Ai\Draft\DraftKind;
use Logbook\Action\Attachment\AttachmentUpload;
use Logbook\Action\Odometer\OdometerWarningFlash;
use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Service\Compliance\ComplianceDocumentForm;
use Logbook\Service\Compliance\ComplianceService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Validation\ValidationErrors;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET|POST /vehicles/{id}/documents/new — add an insurance policy,
 * certificate or registration, optionally with the document attached.
 * `?type=insurance` pre-selects the type (the "Renew" button).
 */
final readonly class CreateComplianceDocumentAction
{
    public function __construct(
        private DraftPrefill $prefill,
        private ScanPrefill $scan,
        private VehicleService $vehicles,
        private ComplianceService $compliance,
        private ComplianceFormPage $page,
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
        $vehicle = RequestContext::vehicle($request);
        $user = RequestContext::requireUser($request);
        $currency = $this->vehicles->currencyFor($user, $vehicle);

        if ($request->getMethod() !== 'POST') {
            $type = $request->getQueryParams()['type'] ?? null;
            $defaults = ComplianceDocumentForm::defaults(is_string($type) ? ComplianceType::tryFrom($type) : null);
            $defaults = $this->prefill->values($request, DraftKind::Document, $vehicle->id, $defaults);
            $defaults = $this->scan->values($request, ScanTarget::Document, $vehicle, $defaults);

            return $this->page->render($request, $response, $vehicle, $currency, $defaults);
        }

        $data = ComplianceDocumentForm::parse(RequestContext::form($request), $user->preferences);
        $files = $this->scan->files($request, $this->upload->fromRequest($request));
        $errors = $this->upload->errors($data, $files);
        if ($errors !== null || $data instanceof ValidationErrors) {
            $values = RequestContext::formValues($request);

            return $this->page->render($request, $response, $vehicle, $currency, $values, null, $errors, 422);
        }

        [$document, $claimed] = $this->scan->save(
            $request,
            $files,
            fn (PendingUploads $files): ComplianceDocument => $this->compliance->create(
                $vehicle,
                $data,
                $user->preferences->timeZone(),
                $files,
            ),
        );
        $this->prefill->saved($request);
        $session = RequestContext::session($request);
        $session->flash('success', 'compliance.created');
        $this->warnings->queue($session, $this->compliance->odometerWarning($vehicle, $document));

        $done = $this->redirect->backOr($request, 'compliance.index', ['id' => (string) $vehicle->id]);

        return $this->scan->after($request, $claimed, $vehicle, $document->data->odometerKm, $done);
    }
}
