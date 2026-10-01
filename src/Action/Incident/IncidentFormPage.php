<?php

declare(strict_types=1);

namespace Logbook\Action\Incident;

use Logbook\Action\Attachment\AttachmentUpload;
use Logbook\Domain\Attachment\AttachmentOwner;
use Logbook\Domain\Compliance\ComplianceDocument;
use Logbook\Domain\Incident\ClaimStatus;
use Logbook\Domain\Incident\DamageArea;
use Logbook\Domain\Incident\Fault;
use Logbook\Domain\Incident\Incident;
use Logbook\Domain\Incident\IncidentStatus;
use Logbook\Domain\Incident\IncidentType;
use Logbook\Domain\Incident\NcdEffect;
use Logbook\Domain\Incident\Severity;
use Logbook\Domain\Incident\WriteOffCategory;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Incident\IncidentChoices;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The incident form (spec.md §7.29 *Form*), as a page and a desktop modal.
 */
final readonly class IncidentFormPage
{
    public function __construct(
        private View $view,
        private AttachmentUpload $upload,
        private IncidentChoices $choices,
        private VehicleService $vehicles,
    ) {
    }

    /**
     * @return array<int, string> id => display name
     */
    public function drivers(Vehicle $vehicle): array
    {
        return $this->choices->drivers($vehicle);
    }

    /**
     * @return list<ComplianceDocument>
     */
    public function policies(Vehicle $vehicle): array
    {
        return $this->choices->policies($vehicle);
    }

    /**
     * @param array<string, string|list<string>> $values
     */
    public function render(
        ServerRequestInterface $request,
        ResponseInterface $response,
        User $user,
        Vehicle $vehicle,
        array $values,
        ?Incident $incident = null,
        ?ValidationErrors $errors = null,
        int $status = 200,
    ): ResponseInterface {
        return $this->view->render($request, $response, 'incidents/form.twig', [
            'vehicle' => $vehicle,
            'incident' => $incident,
            'currency' => $this->vehicles->currencyFor($user, $vehicle),
            'values' => $values,
            'errors' => $errors?->all() ?? [],
            'drivers' => $this->drivers($vehicle),
            'policies' => $this->policies($vehicle),
            'types' => IncidentType::cases(),
            'faults' => Fault::cases(),
            'areas' => DamageArea::cases(),
            'severities' => Severity::cases(),
            'write_offs' => WriteOffCategory::cases(),
            'statuses' => IncidentStatus::cases(),
            'claim_statuses' => ClaimStatus::cases(),
            'ncd_effects' => NcdEffect::cases(),
        ] + $this->upload->formContext($vehicle, AttachmentOwner::Incident, $incident?->id), $status);
    }
}
