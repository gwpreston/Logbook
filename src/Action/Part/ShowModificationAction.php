<?php

declare(strict_types=1);

namespace Logbook\Action\Part;

use Logbook\Action\Attachment\AttachmentGuard;
use Logbook\Domain\Attachment\Attachment;
use Logbook\Domain\Attachment\AttachmentOwner;
use Logbook\Domain\Access\VehicleAbility;
use Logbook\Service\Access\EntryAccess;
use Logbook\Service\Access\VehicleAccess;
use Logbook\Service\Attachment\AttachmentService;
use Logbook\Service\Part\ModificationService;
use Logbook\Service\Part\PartService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /vehicles/{id}/modifications/{modification} — the modification page
 * (spec.md §7.39): what was done, its cost, photos and files, the parts it
 * fitted and took off, and the actions the viewer may take.
 */
final readonly class ShowModificationAction
{
    public function __construct(
        private ModificationService $modifications,
        private PartService $parts,
        private AttachmentService $attachments,
        private VehicleAccess $vehicles,
        private VehicleService $vehicleService,
        private EntryAccess $entries,
        private View $view,
        private AttachmentGuard $guard,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $vehicle = RequestContext::vehicle($request);
        $user = RequestContext::requireUser($request);
        $modification = PartRoute::modification($this->modifications, $vehicle, $request, $args);
        $active = !$vehicle->isArchived();
        $fitted = [];
        $removed = [];
        foreach ($this->parts->byRecord($vehicle, PartService::MODIFICATION, $modification->id) as $row) {
            if ($row['fitting']->fittedModificationId === $modification->id) {
                $fitted[] = $row;
            }
            if ($row['fitting']->removedModificationId === $modification->id) {
                $removed[] = $row;
            }
        }

        return $this->view->render($request, $response, 'parts/modification_show.twig', [
            'vehicle' => $vehicle,
            'modification' => $modification,
            'fitted' => $fitted,
            'removed' => $removed,
            // Invoices and receipts follow the cost-file rule (AttachmentGuard).
            'files' => array_values(array_filter(
                $this->attachments->forOwner($vehicle, AttachmentOwner::Modification, $modification->id),
                fn (Attachment $file): bool => $this->guard->mayUse($user, $vehicle, $file),
            )),
            'currency' => $this->vehicleService->currencyFor($user, $vehicle),
            'can_change' => $active && $this->entries->canChange($user, $vehicle, $modification->createdBy),
            'can_log' => $active && $this->vehicles->can($user, VehicleAbility::Log, $vehicle),
        ]);
    }
}
