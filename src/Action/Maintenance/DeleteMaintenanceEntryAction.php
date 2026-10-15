<?php

declare(strict_types=1);

namespace Logbook\Action\Maintenance;

use Logbook\Action\EntryGuard;
use Logbook\Action\Part\PartRoute;
use Logbook\Domain\Feature\Feature;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Part\PartChangeRefused;
use Logbook\Service\Part\PartService;
use Logbook\Service\Maintenance\MaintenanceService;
use Logbook\Support\Display\DisplayFormatter;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET|POST /vehicles/{id}/maintenance/{entry}/delete — confirm (works
 * without JS), then delete an entry with its odometer reading and files.
 */
final readonly class DeleteMaintenanceEntryAction
{
    public function __construct(
        private MaintenanceService $maintenance,
        private DisplayFormatter $formatter,
        private View $view,
        private Redirector $redirect,
        private EntryGuard $guard,
        private PartService $parts,
        private FeatureToggles $features,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $vehicle = RequestContext::vehicle($request);
        $entry = MaintenanceRoute::entry($this->maintenance, $vehicle, $request, $args);
        $this->guard->allowChange($request, $vehicle, $entry->createdBy);
        $description = [
            'title' => $entry->data->title,
            'date' => $this->formatter->date($entry->data->performedOn),
        ];

        // The parts it fitted or took off (spec.md §7.39 *Deleting a record*), named on the confirmation.
        $parts = $this->features->isEnabled(Feature::Parts)
            ? array_values(array_unique(array_map(
                static fn (array $row): string => $row['part']->label(),
                $this->parts->byRecord($vehicle, PartService::MAINTENANCE, $entry->id),
            )))
            : [];
        $page = [
            'vehicle' => $vehicle,
            'title' => 'maintenance.delete_title',
            'body' => 'maintenance.delete_body',
            'params' => $description,
            'parts' => $parts,
            'action' => ['maintenance.delete', ['id' => $vehicle->id, 'entry' => $entry->id]],
            'cancel' => ['maintenance.index', ['id' => $vehicle->id]],
        ];
        if ($request->getMethod() !== 'POST') {
            return $this->view->render($request, $response, 'parts/delete.twig', $page);
        }

        try {
            $this->maintenance->delete($vehicle, $entry, RequestContext::requireUser($request)->preferences->timeZone());
        } catch (PartChangeRefused $refused) {
            $refusal = ['key' => $refused->key, 'params' => PartRoute::params($refused, $this->formatter)];

            return $this->view->render($request, $response, 'parts/delete.twig', $page + ['refusal' => $refusal], 422);
        }
        RequestContext::session($request)->flash('success', 'maintenance.deleted', $description);

        return $this->redirect->toRoute('maintenance.index', ['id' => (string) $vehicle->id]);
    }
}
