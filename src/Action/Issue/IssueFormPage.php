<?php

declare(strict_types=1);

namespace Logbook\Action\Issue;

use Logbook\Action\Attachment\AttachmentUpload;
use Logbook\Domain\Attachment\AttachmentOwner;
use Logbook\Domain\Issue\Issue;
use Logbook\Domain\Maintenance\MaintenanceCategory;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The issue form (spec.md §7.37 *Add / edit*), as a page and a desktop modal.
 */
final readonly class IssueFormPage
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
        array $values,
        ?Issue $issue = null,
        ?ValidationErrors $errors = null,
        int $status = 200,
    ): ResponseInterface {
        return $this->view->render($request, $response, 'issues/form.twig', [
            'vehicle' => $vehicle,
            'issue' => $issue,
            'values' => $values,
            'errors' => $errors?->all() ?? [],
            'categories' => MaintenanceCategory::cases(),
        ] + $this->upload->formContext($vehicle, AttachmentOwner::Issue, $issue?->id), $status);
    }
}
