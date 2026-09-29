<?php

declare(strict_types=1);

namespace Logbook\Action\History;

use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /history — the fleet history (spec.md §7.16): the History page across
 * every active vehicle, each row naming its vehicle. `?vehicle=` narrows it
 * to one active vehicle, as the dashboard's chips do (an unknown or archived
 * id means every vehicle; an archived vehicle's history is on its own tab).
 */
final readonly class FleetHistoryAction
{
    public function __construct(
        private VehicleService $vehicles,
        private HistoryView $history,
        private View $view,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = RequestContext::requireUser($request);
        $query = $request->getQueryParams();
        $active = $this->vehicles->listFleet($user);
        $selected = self::find($active, $query['vehicle'] ?? null);

        return $this->view->render($request, $response, 'history/fleet.twig', [
            'vehicles' => $active,
            'selected' => $selected,
        ] + $this->history->context($user, $selected !== null ? [$selected] : $active, $query));
    }

    /**
     * @param list<Vehicle> $active
     */
    private static function find(array $active, mixed $id): ?Vehicle
    {
        foreach ($active as $vehicle) {
            if (is_string($id) && (string) $vehicle->id === $id) {
                return $vehicle;
            }
        }

        return null;
    }
}
