<?php

declare(strict_types=1);

namespace Logbook\Action\Part;

use Logbook\Action\Attachment\AttachmentGuard;
use Logbook\Domain\Attachment\Attachment;
use Logbook\Domain\Attachment\AttachmentOwner;
use Logbook\Domain\Access\VehicleAbility;
use Logbook\Repository\MaintenanceEntryRepository;
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
 * GET /vehicles/{id}/parts/{part} — the part page (spec.md §7.39): its
 * details, warranty, distance on the vehicle, photos and files, its
 * fittings as a timeline with the records that made them, and the actions
 * the viewer may take.
 */
final readonly class ShowPartAction
{
    public function __construct(
        private PartService $parts,
        private ModificationService $modifications,
        private MaintenanceEntryRepository $records,
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
        $zone = $user->preferences->timeZone();
        $part = PartRoute::part($this->parts, $vehicle, $request, $args);
        $fittings = $this->parts->fittings($part);
        $active = !$vehicle->isArchived();

        // The records that fitted or removed it, by kind and id, for their titles and links.
        $services = [];
        foreach ($this->records->listForVehicle($vehicle->id) as $entry) {
            $services[$entry->id] = $entry;
        }
        $mods = [];
        foreach ($this->modifications->list($vehicle) as $modification) {
            $mods[$modification->id] = $modification;
        }
        $replacements = [];
        foreach ($fittings as $fitting) {
            if ($fitting->replacedByPartId !== null) {
                $replacements[$fitting->replacedByPartId] = $this->parts->find($vehicle, $fitting->replacedByPartId);
            }
        }

        return $this->view->render($request, $response, 'parts/part_show.twig', [
            'vehicle' => $vehicle,
            'part' => $part,
            'fittings' => array_reverse($fittings),
            'services' => $services,
            'modifications' => $mods,
            'replacements' => array_filter($replacements),
            'distance' => $this->parts->distance($vehicle, $fittings, $zone),
            'warranty' => $this->parts->warranty($vehicle, $part, $fittings, $zone),
            // Invoices and receipts follow the cost-file rule (AttachmentGuard).
            'files' => array_values(array_filter(
                $this->attachments->forOwner($vehicle, AttachmentOwner::Part, $part->id),
                fn (Attachment $file): bool => $this->guard->mayUse($user, $vehicle, $file),
            )),
            'currency' => $this->vehicleService->currencyFor($user, $vehicle),
            'can_change' => $active && $this->entries->canChange($user, $vehicle, $part->createdBy),
            'can_log' => $active && $this->vehicles->can($user, VehicleAbility::Log, $vehicle),
        ]);
    }
}
