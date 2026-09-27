<?php

declare(strict_types=1);

namespace Logbook\Action\Maintenance;

use Logbook\Domain\Maintenance\MaintenanceCategory;
use Logbook\Domain\Maintenance\MaintenanceSchedule;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Renders the add/edit schedule form (shared by both Actions).
 */
final readonly class ScheduleFormPage
{
    public function __construct(private View $view)
    {
    }

    /**
     * @param array<string, string> $values
     */
    public function render(
        ServerRequestInterface $request,
        ResponseInterface $response,
        Vehicle $vehicle,
        array $values,
        ?MaintenanceSchedule $schedule = null,
        ?ValidationErrors $errors = null,
        int $status = 200,
    ): ResponseInterface {
        return $this->view->render($request, $response, 'maintenance/schedule-form.twig', [
            'vehicle' => $vehicle,
            'schedule' => $schedule,
            'values' => $values,
            'errors' => $errors?->all() ?? [],
            'categories' => MaintenanceCategory::cases(),
        ], $status);
    }
}
