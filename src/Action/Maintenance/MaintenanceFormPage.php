<?php

declare(strict_types=1);

namespace Logbook\Action\Maintenance;

use Logbook\Action\Incident\IncidentPicker;
use Logbook\Action\Attachment\AttachmentUpload;
use Logbook\Domain\Attachment\AttachmentOwner;
use Logbook\Domain\Maintenance\MaintenanceCategory;
use Logbook\Domain\Maintenance\MaintenanceEntry;
use Logbook\Domain\Maintenance\MaintenanceSchedule;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Maintenance\ScheduleService;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Renders the add/edit maintenance entry form (shared by both Actions).
 */
final readonly class MaintenanceFormPage
{
    public function __construct(
        private View $view,
        private ScheduleService $schedules,
        private OdometerService $odometer,
        private AttachmentUpload $upload,
        private IncidentPicker $incidents,
    ) {
    }

    /**
     * The ids of the vehicle's schedules, which an entry may complete.
     *
     * @return list<int>
     */
    public function scheduleIds(Vehicle $vehicle): array
    {
        return array_map(static fn (MaintenanceSchedule $s): int => $s->id, $this->schedules->list($vehicle));
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
        ?MaintenanceEntry $entry = null,
        ?ValidationErrors $errors = null,
        int $status = 200,
    ): ResponseInterface {
        return $this->view->render($request, $response, 'maintenance/form.twig', [
            'vehicle' => $vehicle,
            'entry' => $entry,
            'currency' => $currency,
            'values' => $values,
            'errors' => $errors?->all() ?? [],
            'categories' => MaintenanceCategory::cases(),
            'schedules' => $this->schedules->list($vehicle),
            'latest' => $this->odometer->history($vehicle)->latest(),
        ] + $this->upload->formContext($vehicle, AttachmentOwner::Maintenance, $entry?->id)
            + $this->incidents->context($vehicle), $status);
    }
}
