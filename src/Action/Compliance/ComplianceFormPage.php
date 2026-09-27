<?php

declare(strict_types=1);

namespace Logbook\Action\Compliance;

use Logbook\Action\Attachment\AttachmentUpload;
use Logbook\Domain\Attachment\AttachmentOwner;
use Logbook\Domain\Compliance\ComplianceDocument;
use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Renders the add/edit compliance document form — one template for both,
 * so the edit form always posts every field the create form does.
 */
final readonly class ComplianceFormPage
{
    public function __construct(
        private View $view,
        private AttachmentUpload $upload,
    ) {
    }

    /**
     * @param array<string, string> $values
     */
    public function render(
        ServerRequestInterface $request,
        ResponseInterface $response,
        Vehicle $vehicle,
        string $currency,
        array $values,
        ?ComplianceDocument $document = null,
        ?ValidationErrors $errors = null,
        int $status = 200,
    ): ResponseInterface {
        return $this->view->render($request, $response, 'compliance/form.twig', [
            'vehicle' => $vehicle,
            'document' => $document,
            'currency' => $currency,
            'values' => $values,
            'errors' => $errors?->all() ?? [],
            'types' => ComplianceType::cases(),
        ] + $this->upload->formContext($vehicle, AttachmentOwner::Compliance, $document?->id), $status);
    }
}
