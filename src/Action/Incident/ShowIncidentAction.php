<?php

declare(strict_types=1);

namespace Logbook\Action\Incident;

use Logbook\Domain\Attachment\AttachmentOwner;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Access\VehicleAbility;
use Logbook\Service\Access\VehicleAccess;
use Logbook\Service\Attachment\AttachmentService;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Incident\IncidentAccess;
use Logbook\Service\Incident\IncidentRecords;
use Logbook\Service\Incident\IncidentService;
use Logbook\Service\User\UserDirectory;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /vehicles/{id}/incidents/{incident} — the incident page (spec.md
 * §7.29): its details as the viewer may see them, the photos, the linked
 * records with their costs, *Link a record* and the add buttons.
 */
final readonly class ShowIncidentAction
{
    public function __construct(
        private IncidentService $incidents,
        private IncidentAccess $access,
        private IncidentRecords $records,
        private AttachmentService $attachments,
        private VehicleAccess $vehicles,
        private FeatureToggles $features,
        private UserDirectory $directory,
        private VehicleService $vehicleService,
        private View $view,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $vehicle = RequestContext::vehicle($request);
        $user = RequestContext::requireUser($request);
        $incident = IncidentRoute::incident($this->incidents, $vehicle, $request, $args);
        $view = $this->access->view($user, $vehicle, $incident, $this->incidents->costs($user, $vehicle, $incident));
        $records = $this->records->all($user, $vehicle);
        $canChange = $this->access->canChange($user, $vehicle, $incident);
        $driver = $view->driverUserId === null ? $view->driverName : $this->directory->displayName($view->driverUserId);

        return $this->view->render($request, $response, 'incidents/show.twig', [
            'vehicle' => $vehicle,
            'incident' => $view,
            'currency' => $this->vehicleService->currencyFor($user, $vehicle),
            'driver' => $driver,
            'odometer' => $this->incidents->odometerOf($vehicle, $incident),
            'files' => $this->attachments->forOwner($vehicle, AttachmentOwner::Incident, $incident->id),
            'linked' => IncidentRecords::linkedTo($incident, $records),
            'candidates' => $canChange && !$vehicle->isArchived() ? IncidentRecords::candidates($incident, $records) : [],
            'can_change' => $canChange,
            'can_log' => !$vehicle->isArchived() && $this->vehicles->can($user, VehicleAbility::Log, $vehicle),
            'can_remind' => $this->features->isEnabled(Feature::Reminders)
                && $this->vehicles->can($user, VehicleAbility::Manage, $vehicle),
        ]);
    }
}
