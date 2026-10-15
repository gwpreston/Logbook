<?php

declare(strict_types=1);

namespace Logbook\Action\Part;

use Logbook\Action\Attachment\AttachmentUpload;
use Logbook\Domain\Attachment\AttachmentOwner;
use Logbook\Domain\Part\Part;
use Logbook\Domain\Part\PartCondition;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The part form (spec.md §7.39 *Parts*), as a page and a desktop modal.
 */
final readonly class PartFormPage
{
    public function __construct(
        private View $view,
        private AttachmentUpload $upload,
        private VehicleService $vehicles,
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
        ?Part $part = null,
        ?ValidationErrors $errors = null,
        int $status = 200,
    ): ResponseInterface {
        return $this->view->render($request, $response, 'parts/part_form.twig', [
            'vehicle' => $vehicle,
            'part' => $part,
            'values' => $values,
            'errors' => $errors?->all() ?? [],
            'conditions' => PartCondition::cases(),
            'currency' => $this->vehicles->currencyFor(RequestContext::requireUser($request), $vehicle),
        ] + $this->upload->formContext($vehicle, AttachmentOwner::Part, $part?->id), $status);
    }
}
