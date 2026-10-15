<?php

declare(strict_types=1);

namespace Logbook\Action\Part;

use Logbook\Action\Attachment\AttachmentUpload;
use Logbook\Domain\Attachment\AttachmentOwner;
use Logbook\Domain\Part\Modification;
use Logbook\Domain\Part\ModificationCategory;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Service\Part\PartService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The modification form (spec.md §7.39 *Add / edit*), as a page and a
 * desktop modal, with its *Parts fitted* and *Parts removed* lists.
 */
final readonly class ModificationFormPage
{
    public function __construct(
        private View $view,
        private AttachmentUpload $upload,
        private RecordPartsPicker $parts,
        private VehicleService $vehicles,
        private OdometerService $odometer,
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
        ?Modification $modification = null,
        ?ValidationErrors $errors = null,
        int $status = 200,
    ): ResponseInterface {
        $user = RequestContext::requireUser($request);

        $parts = $this->parts->context($vehicle, PartService::MODIFICATION, $modification?->id, RequestContext::form($request));

        return $this->view->render($request, $response, 'parts/modification_form.twig', [
            'vehicle' => $vehicle,
            'modification' => $modification,
            'values' => $values,
            'errors' => $errors?->all() ?? [],
            'categories' => ModificationCategory::cases(),
            'currency' => $this->vehicles->currencyFor($user, $vehicle),
            'latest' => $this->odometer->history($vehicle)->latest(),
        ] + $this->upload->formContext($vehicle, AttachmentOwner::Modification, $modification?->id) + $parts, $status);
    }
}
